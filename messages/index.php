<?php
/**
 * ============================================
 * MESSAGES/CHAT - Main Entry Point
 * Family messaging interface
 * ============================================
 */

session_start();

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

// Get current user
try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
} catch (Exception $e) {
    error_log("Auth error: " . $e->getMessage());
    header('Location: /login.php');
    exit;
}

// Load family members
$familyMembers = [];
try {
    $stmt = $db->prepare("
        SELECT id, full_name, avatar_color, has_avatar, last_login
        FROM users
        WHERE family_id = ? AND status = 'active' AND id != ?
        ORDER BY full_name
    ");
    $stmt->execute([$user['family_id'], $user['id']]);
    $familyMembers = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading family members: " . $e->getMessage());
}

// Page configuration
$pageTitle = 'Family Chat';
$activePage = 'messages';

$cacheVersion = '10.1.0';
$pageCSS = [
    '/messages/css/messages.css?v=' . $cacheVersion,
    '/messages/css/messages-enhanced.css?v=' . $cacheVersion
];

$pageJS = [
    '/messages/js/messages.js?v=' . $cacheVersion,
    '/messages/js/messages-enhanced.js?v=' . $cacheVersion
];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Animated Background -->
<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<!-- Main Content -->
<main class="main-content">
    <div class="container">
        <div class="messages-container">
            
            <!-- Sidebar -->
            <aside class="messages-sidebar">
                <div class="sidebar-header">
                    <h2>💬 Family Chat</h2>
                </div>

                <div class="online-status">
                    <div class="status-indicator online"></div>
                    <span><?php echo count($familyMembers) + 1; ?> members</span>
                </div>

                <div class="members-list">
                    <h3>👨‍👩‍👧‍👦 Family Members</h3>
                    
                    <!-- Current User -->
                    <div class="member-item current-user">
                        <div class="member-avatar" style="background: <?php echo $user['has_avatar'] ? 'transparent' : htmlspecialchars($user['avatar_color']); ?>">
                            <?php
                            $myAvatarPath = __DIR__ . "/../saves/{$user['id']}/avatar/avatar.webp";
                            if (file_exists($myAvatarPath)):
                            ?>
                                <img src="/saves/<?php echo $user['id']; ?>/avatar/avatar.webp" alt="You">
                            <?php else: ?>
                                <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="member-info">
                            <div class="member-name">You</div>
                            <div class="member-status online">Online</div>
                        </div>
                    </div>

                    <!-- Family Members -->
                    <?php foreach ($familyMembers as $member):
                        $lastSeen = $member['last_login'] ? new DateTime($member['last_login']) : null;
                        $isOnline = $lastSeen && (time() - $lastSeen->getTimestamp()) < 300;
                        $memberAvatarPath = __DIR__ . "/../saves/{$member['id']}/avatar/avatar.webp";
                        $memberHasAvatar = file_exists($memberAvatarPath);
                    ?>
                        <div class="member-item" data-user-id="<?php echo $member['id']; ?>">
                            <div class="member-avatar" style="background: <?php echo $memberHasAvatar ? 'transparent' : htmlspecialchars($member['avatar_color']); ?>">
                                <?php if ($memberHasAvatar): ?>
                                    <img src="/saves/<?php echo $member['id']; ?>/avatar/avatar.webp" alt="<?php echo htmlspecialchars($member['full_name']); ?>">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($member['full_name'], 0, 2)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="member-info">
                                <div class="member-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                <div class="member-status <?php echo $isOnline ? 'online' : 'offline'; ?>">
                                    <?php
                                    if ($isOnline) {
                                        echo 'Online';
                                    } elseif ($lastSeen) {
                                        $diff = time() - $lastSeen->getTimestamp();
                                        if ($diff < 3600) echo floor($diff/60) . 'm ago';
                                        elseif ($diff < 86400) echo floor($diff/3600) . 'h ago';
                                        else echo $lastSeen->format('M j');
                                    } else {
                                        echo 'Offline';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>

            <!-- Chat Main Area -->
            <main class="chat-main">
                
		<!-- Connection Status Indicator -->
		    <div class="connection-status" id="connectionStatus" style="display: none;">
    			<span class="status-dot"></span>
    			<span class="status-text">Connecting...</span>
		    </div>
                <!-- Messages List -->
                <div class="messages-list" id="messagesList">
                    <div class="loading-messages">
                        <div class="spinner"></div>
                        <p>Loading messages...</p>
                    </div>
                </div>

                <!-- Typing Indicator -->
                <div class="typing-indicator" id="typingIndicator" style="display: none;">
                    <div class="typing-dots">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    <span class="typing-text"></span>
                </div>

                <!-- Message Input Container -->
                <div class="message-input-container">
                    <!-- Reply Preview -->
                    <div class="reply-preview" id="replyPreview" style="display: none;">
                        <div class="reply-content">
                            <div class="reply-header">
                                <span>↩️ Replying to <strong id="replyToName"></strong></span>
                                <button onclick="cancelReply()" class="cancel-reply">✕</button>
                            </div>
                            <div class="reply-text" id="replyToText"></div>
                        </div>
                    </div>

                    <!-- Media Preview -->
                    <div class="media-preview" id="mediaPreview" style="display: none;">
                        <div class="preview-content">
                            <img id="previewImage" style="display: none;">
                            <video id="previewVideo" style="display: none;" controls></video>
                            <button onclick="cancelMedia()" class="cancel-media">✕</button>
                        </div>
                    </div>

                    <!-- Input Wrapper -->
                    <div class="input-wrapper">
                        <button class="attachment-btn" onclick="document.getElementById('fileInput').click()" title="Attach">
                            📎
                        </button>
                        
                        <input type="file" id="fileInput" style="display: none;" 
                               accept="image/*,video/*" onchange="handleFileSelect(event)">
                        
                        <button class="emoji-btn" id="emojiPickerBtn" title="Emoji">
                            😊
                        </button>

                        <div class="message-input-wrapper">
                            <textarea 
                                id="messageInput" 
                                placeholder="Type a message..."
                                rows="1"
                                maxlength="5000"></textarea>
                        </div>

                        <button class="send-btn" id="sendBtn" onclick="sendMessage()" title="Send">
                            ➤
                        </button>
                    </div>

                    <!-- Emoji Picker -->
                    <div class="emoji-picker" id="emojiPicker" style="display: none;">
                        <div class="emoji-grid">
                            <?php
                            $emojis = ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','✋','🤚','🖐️','🖖','👋','🤏','💪','🦾','🙏','🤝','👏','🙌','👐','🤲','🤜','🤛','✊','👊','🤌','❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❤️‍🔥','❤️‍🩹','💕','💞','💓','💗','💖','💘','💝','🔥','✨','💫','⭐','🌟','💯','🎉','🎊','🎁','🎈'];
                            foreach ($emojis as $emoji) {
                                echo "<button class='emoji-item' data-emoji='$emoji'>$emoji</button>";
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</main>

<!-- Context Menu -->
<div class="context-menu" id="contextMenu" style="display: none;">
    <button onclick="contextReplyMessage()">↩️ Reply</button>
    <button onclick="copyMessage()">📋 Copy</button>
    <button onclick="deleteMessage()" class="danger">🗑️ Delete</button>
</div>

<!-- Hidden Data -->
<input type="hidden" id="currentUserId" value="<?php echo $user['id']; ?>">
<input type="hidden" id="currentUserName" value="<?php echo htmlspecialchars($user['name']); ?>">
<input type="hidden" id="familyId" value="<?php echo $user['family_id']; ?>">

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>