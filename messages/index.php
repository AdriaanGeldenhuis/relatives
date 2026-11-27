<?php
/**
 * ============================================
 * MESSAGES/CHAT - Main Entry Point
 * Family messaging interface
 * ============================================
 */

require_once __DIR__ . '/../core/session_boot.php';

// Authentication check
$messageLink = filter_input(INPUT_GET, 'message', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$chatReturn = '/messages/' . ($messageLink ? '?message=' . $messageLink : '');
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . rawurlencode($chatReturn));
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CSRF.php';

// Get current user
try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        header('Location: /login.php?redirect=' . rawurlencode($chatReturn));
        exit;
    }
} catch (Exception $e) {
    error_log("Auth error: " . $e->getMessage());
    header('Location: /login.php?redirect=' . rawurlencode($chatReturn));
    exit;
}

// Load family members
$familyMembers = [];
try {
    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.avatar_color, u.has_avatar,
            CASE WHEN COALESCE(p.show_last_seen, 1) THEN u.last_login END AS last_login,
            COALESCE(p.show_online_status, 1) AS show_online,
            CASE WHEN u.last_login >= NOW() - INTERVAL 5 MINUTE THEN 1 ELSE 0 END AS online_now
        FROM users u LEFT JOIN user_privacy_settings p ON p.user_id = u.id
        WHERE u.family_id = ? AND u.status = 'active' AND u.id != ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$user['family_id'], $user['id']]);
    $familyMembers = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading family members: " . $e->getMessage());
}

$chatCsrf = CSRF::generate();

// Page configuration
$pageTitle = 'Family Chat';
$activePage = 'messages';

$messagesStylesheets = [
    __DIR__ . '/css/messages.css',
    __DIR__ . '/css/messages-enhanced.css',
];

$pageJS = [
    '/messages/js/messages.js',
    '/messages/js/messages-enhanced.js'
];
$pageJSVersion = max(filemtime(__DIR__ . '/js/messages.js'), filemtime(__DIR__ . '/js/messages-enhanced.js'));

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content family-main">
    <div class="hub">
        <header class="messages-toolbar app-hero photo-hero photo-hero-compact">
            <div class="messages-hero-content">
                <h1 id="messages-title">Messages</h1>
                <div id="messagesActions" role="group" aria-label="Conversation tools"></div>
            </div>
            <?php renderFamilyScene('family'); ?>
        </header>
        <div class="messages-container">
            
            <!-- Sidebar -->
            <aside class="messages-sidebar">
                <details class="member-details" id="familyMembersDetails">
                    <summary class="sidebar-header">
                        <h2>Family members</h2>
                        <span class="member-total"><?php echo count($familyMembers) + 1; ?></span>
                    </summary>

                    <div class="members-list">
                        <!-- Current User -->
                        <div class="member-item current-user">
                            <?php $myAvatarExists = is_file(__DIR__ . "/../saves/{$user['id']}/avatar/avatar.webp"); ?>
                            <div class="member-avatar" style="background: <?php echo $myAvatarExists ? 'transparent' : htmlspecialchars($user['avatar_color'] ?: '#667eea'); ?>">
                                <?php if ($myAvatarExists): ?>
                                    <img src="<?php echo avatarUrl($user['id']); ?>" alt="You">
                                <?php else: ?>
                                    <?php echo htmlspecialchars(mb_strtoupper(mb_substr($user['name'], 0, 2))); ?>
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
                            $isOnline = !empty($member['show_online']) && !empty($member['online_now']);
                            $memberAvatarPath = __DIR__ . "/../saves/{$member['id']}/avatar/avatar.webp";
                            $memberHasAvatar = file_exists($memberAvatarPath);
                        ?>
                            <div class="member-item" data-user-id="<?php echo $member['id']; ?>">
                                <div class="member-avatar" style="background: <?php echo $memberHasAvatar ? 'transparent' : htmlspecialchars($member['avatar_color']); ?>">
                                    <?php if ($memberHasAvatar): ?>
                                        <img src="<?php echo avatarUrl($member['id']); ?>" alt="<?php echo htmlspecialchars($member['full_name']); ?>">
                                    <?php else: ?>
                                        <?php echo htmlspecialchars(mb_strtoupper(mb_substr($member['full_name'], 0, 2))); ?>
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
                </details>
            </aside>

            <!-- Chat Main Area -->
            <section class="chat-main" id="chatMain" aria-labelledby="messages-title">
                <div class="connection-status" id="connectionStatus" role="status" style="display: none;">
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
                        <div class="preview-content" id="previewContent">
                            <button onclick="cancelMedia()" class="cancel-media">✕</button>
                        </div>
                    </div>

                    <!-- Input Wrapper -->
                    <div class="input-wrapper">
                        <button class="attachment-btn" onclick="document.getElementById('fileInput').click()" title="Attach" aria-label="Attach files">
                            📎
                        </button>

                        <input type="file" id="fileInput" style="display: none;" multiple
                               accept="image/*,video/*,audio/*,application/pdf,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf"
                               onchange="handleFileSelect(event)">
                        
                        <button class="emoji-btn" id="emojiPickerBtn" title="Emoji" aria-label="Emoji">
                            😊
                        </button>

                        <div class="message-input-wrapper">
                            <textarea 
                                id="messageInput" 
                                aria-label="Message"
                                placeholder="Write a message…"
                                rows="1"
                                maxlength="5000"></textarea>
                        </div>

                        <button class="send-btn" id="sendBtn" data-csrf="<?= htmlspecialchars($chatCsrf) ?>" onclick="sendMessage()" title="Send" aria-label="Send">
                            <span class="send-label" aria-hidden="true">Send</span><span aria-hidden="true">➤</span>
                        </button>
                    </div>

                    <!-- Emoji Picker -->
                    <div class="emoji-picker" id="emojiPicker">
                        <div class="emoji-categories">
                            <button class="emoji-cat-btn active" data-category="smileys" title="Smileys">😀</button>
                            <button class="emoji-cat-btn" data-category="people" title="People">👋</button>
                            <button class="emoji-cat-btn" data-category="animals" title="Animals & Nature">🐱</button>
                            <button class="emoji-cat-btn" data-category="food" title="Food & Drink">🍔</button>
                            <button class="emoji-cat-btn" data-category="travel" title="Travel">🚗</button>
                            <button class="emoji-cat-btn" data-category="activities" title="Activities">⚽</button>
                            <button class="emoji-cat-btn" data-category="objects" title="Objects">💡</button>
                            <button class="emoji-cat-btn" data-category="symbols" title="Symbols">❤️</button>
                            <button class="emoji-cat-btn" data-category="flags" title="Flags">🏳️</button>
                        </div>
                        <div class="emoji-search-wrap">
                            <input type="text" class="emoji-search" id="emojiSearch" placeholder="Search emoji...">
                            <button class="emoji-search-btn" type="button">Search</button>
                        </div>
                        <div class="emoji-scroll-area" id="emojiScrollArea">
                            <?php
                            $emojiCategories = [
                                'smileys' => ['name' => 'Smileys & Emotion', 'emojis' => ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','☺️','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😶‍🌫️','😏','😒','🙄','😬','😮‍💨','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','🥴','😵','😵‍💫','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽','👾','🤖','😺','😸','😹','😻','😼','😽','🙀','😿','😾','🙈','🙉','🙊']],
                                'people' => ['name' => 'People & Body', 'emojis' => ['👋','🤚','🖐️','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','✍️','💅','🤳','💪','🦾','🦿','🦵','🦶','👂','🦻','👃','🧠','🫀','🫁','🦷','🦴','👀','👁️','👅','👄','👶','🧒','👦','👧','🧑','👱','👨','🧔','👩','🧓','👴','👵','🙍','🙎','🙅','🙆','💁','🙋','🧏','🙇','🤦','🤷','👮','🕵️','💂','🥷','👷','🤴','👸','👳','👲','🧕','🤵','👰','🤰','🤱','👼','🎅','🤶','🦸','🦹','🧙','🧚','🧛','🧜','🧝','🧞','🧟','💆','💇','🚶','🧍','🧎','🏃','💃','🕺','🕴️','👯','🧖','🧗','🤸','🏌️','🏇','⛷️','🏂','🏋️','🤼','🤽','🤾','🤺','⛹️','🚣','🧘','🛀','🛌','👭','👫','👬','💏','💑','👪','👨‍👩‍👦','👨‍👩‍👧','👨‍👩‍👧‍👦','👨‍👩‍👦‍👦','👨‍👩‍👧‍👧','👨‍👦','👨‍👦‍👦','👨‍👧','👨‍👧‍👦','👨‍👧‍👧','👩‍👦','👩‍👦‍👦','👩‍👧','👩‍👧‍👦','👩‍👧‍👧','🗣️','👤','👥','🫂']],
                                'animals' => ['name' => 'Animals & Nature', 'emojis' => ['🐶','🐕','🦮','🐕‍🦺','🐩','🐺','🦊','🦝','🐱','🐈','🐈‍⬛','🦁','🐯','🐅','🐆','🐴','🐎','🦄','🦓','🦌','🦬','🐮','🐂','🐃','🐄','🐷','🐖','🐗','🐽','🐏','🐑','🐐','🐪','🐫','🦙','🦒','🐘','🦣','🦏','🦛','🐭','🐁','🐀','🐹','🐰','🐇','🐿️','🦫','🦔','🦇','🐻','🐻‍❄️','🐨','🐼','🦥','🦦','🦨','🦘','🦡','🐾','🦃','🐔','🐓','🐣','🐤','🐥','🐦','🐧','🕊️','🦅','🦆','🦢','🦉','🦤','🪶','🦩','🦚','🦜','🐸','🐊','🐢','🦎','🐍','🐲','🐉','🦕','🦖','🐳','🐋','🐬','🦭','🐟','🐠','🐡','🦈','🐙','🐚','🐌','🦋','🐛','🐜','🐝','🪲','🐞','🦗','🪳','🕷️','🕸️','🦂','🦟','🪰','🪱','🦠','💐','🌸','💮','🏵️','🌹','🥀','🌺','🌻','🌼','🌷','🌱','🪴','🌲','🌳','🌴','🌵','🌾','🌿','☘️','🍀','🍁','🍂','🍃','🍄','🌰','🦀','🦞','🦐','🦑','🌍','🌎','🌏','🌐','🪨','🌑','🌒','🌓','🌔','🌕','🌖','🌗','🌘','🌙','🌚','🌛','🌜','☀️','🌝','🌞','⭐','🌟','🌠','☁️','⛅','⛈️','🌤️','🌥️','🌦️','🌧️','🌨️','🌩️','🌪️','🌫️','🌬️','🌈','☂️','☔','⚡','❄️','☃️','⛄','☄️','🔥','💧','🌊']],
                                'food' => ['name' => 'Food & Drink', 'emojis' => ['🍇','🍈','🍉','🍊','🍋','🍌','🍍','🥭','🍎','🍏','🍐','🍑','🍒','🍓','🫐','🥝','🍅','🫒','🥥','🥑','🍆','🥔','🥕','🌽','🌶️','🫑','🥒','🥬','🥦','🧄','🧅','🍄','🥜','🌰','🍞','🥐','🥖','🫓','🥨','🥯','🥞','🧇','🧀','🍖','🍗','🥩','🥓','🍔','🍟','🍕','🌭','🥪','🌮','🌯','🫔','🥙','🧆','🥚','🍳','🥘','🍲','🫕','🥣','🥗','🍿','🧈','🧂','🥫','🍱','🍘','🍙','🍚','🍛','🍜','🍝','🍠','🍢','🍣','🍤','🍥','🥮','🍡','🥟','🥠','🥡','🦀','🦞','🦐','🦑','🦪','🍦','🍧','🍨','🍩','🍪','🎂','🍰','🧁','🥧','🍫','🍬','🍭','🍮','🍯','🍼','🥛','☕','🫖','🍵','🍶','🍾','🍷','🍸','🍹','🍺','🍻','🥂','🥃','🥤','🧋','🧃','🧉','🧊','🥢','🍽️','🍴','🥄','🔪','🏺']],
                                'travel' => ['name' => 'Travel & Places', 'emojis' => ['🚗','🚕','🚙','🚌','🚎','🏎️','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🦯','🦽','🦼','🛴','🚲','🛵','🏍️','🛺','🚨','🚔','🚍','🚘','🚖','🚡','🚠','🚟','🚃','🚋','🚞','🚝','🚄','🚅','🚈','🚂','🚆','🚇','🚊','🚉','✈️','🛫','🛬','🛩️','💺','🛰️','🚀','🛸','🚁','🛶','⛵','🚤','🛥️','🛳️','⛴️','🚢','⚓','🪝','⛽','🚧','🚦','🚥','🚏','🗺️','🗿','🗽','🗼','🏰','🏯','🏟️','🎡','🎢','🎠','⛲','⛱️','🏖️','🏝️','🏜️','🌋','⛰️','🏔️','🗻','🏕️','⛺','🛖','🏠','🏡','🏘️','🏚️','🏗️','🏭','🏢','🏬','🏣','🏤','🏥','🏦','🏨','🏪','🏫','🏩','💒','🏛️','⛪','🕌','🕍','🛕','🕋','⛩️','🛤️','🛣️','🗾','🎑','🏞️','🌅','🌄','🌠','🎇','🎆','🌇','🌆','🏙️','🌃','🌌','🌉','🌁']],
                                'activities' => ['name' => 'Activities', 'emojis' => ['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🪀','🏓','🏸','🏒','🏑','🥍','🏏','🪃','🥅','⛳','🪁','🏹','🎣','🤿','🥊','🥋','🎽','🛹','🛼','🛷','⛸️','🥌','🎿','⛷️','🏂','🪂','🏋️','🤼','🤸','🤺','⛹️','🤾','🏌️','🏇','⛷️','🏂','🏄','🚣','🏊','🧗','🚴','🚵','🎖️','🏆','🥇','🥈','🥉','🏅','🎪','🤹','🎭','🩰','🎨','🎬','🎤','🎧','🎼','🎹','🥁','🪘','🎷','🎺','🪗','🎸','🪕','🎻','🎲','♟️','🎯','🎳','🎮','🎰','🧩','🃏','🀄','🎴','🎭','🖼️','🎨','🧵','🪡','🧶','🪢']],
                                'objects' => ['name' => 'Objects', 'emojis' => ['⌚','📱','📲','💻','⌨️','🖥️','🖨️','🖱️','🖲️','🕹️','🗜️','💽','💾','💿','📀','📼','📷','📸','📹','🎥','📽️','🎞️','📞','☎️','📟','📠','📺','📻','🎙️','🎚️','🎛️','🧭','⏱️','⏲️','⏰','🕰️','⌛','⏳','📡','🔋','🔌','💡','🔦','🕯️','🪔','🧯','🛢️','💸','💵','💴','💶','💷','🪙','💰','💳','💎','⚖️','🪜','🧰','🪛','🔧','🔨','⚒️','🛠️','⛏️','🪚','🔩','⚙️','🪤','🧱','⛓️','🧲','🔫','💣','🧨','🪓','🔪','🗡️','⚔️','🛡️','🚬','⚰️','🪦','⚱️','🏺','🔮','📿','🧿','💈','⚗️','🔭','🔬','🕳️','🩹','🩺','💊','💉','🩸','🧬','🦠','🧫','🧪','🌡️','🧹','🪠','🧺','🧻','🚽','🚰','🚿','🛁','🛀','🧼','🪥','🪒','🧽','🪣','🧴','🛎️','🔑','🗝️','🚪','🪑','🛋️','🛏️','🛌','🧸','🪆','🖼️','🪞','🪟','🛒','🎁','🎈','🎏','🎀','🪄','🪅','🎊','🎉','🎎','🏮','🎐','🧧','✉️','📩','📨','📧','💌','📥','📤','📦','🏷️','🪧','📪','📫','📬','📭','📮','📯','📜','📃','📄','📑','🧾','📊','📈','📉','🗒️','🗓️','📆','📅','🗑️','📇','🗃️','🗳️','🗄️','📋','📁','📂','🗂️','🗞️','📰','📓','📔','📒','📕','📗','📘','📙','📚','📖','🔖','🧷','🔗','📎','🖇️','📐','📏','🧮','📌','📍','✂️','🖊️','🖋️','✒️','🖌️','🖍️','📝','✏️','🔍','🔎','🔏','🔐','🔒','🔓']],
                                'symbols' => ['name' => 'Symbols', 'emojis' => ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❤️‍🔥','❤️‍🩹','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☪️','🕉️','☸️','✡️','🔯','🕎','☯️','☦️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🉑','☢️','☣️','📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹','🈲','🅰️','🅱️','🆎','🆑','🅾️','🆘','❌','⭕','🛑','⛔','📛','🚫','💯','💢','♨️','🚷','🚯','🚳','🚱','🔞','📵','🚭','❗','❕','❓','❔','‼️','⁉️','🔅','🔆','〽️','⚠️','🚸','🔱','⚜️','🔰','♻️','✅','🈯','💹','❇️','✳️','❎','🌐','💠','Ⓜ️','🌀','💤','🏧','🚾','♿','🅿️','🛗','🈳','🈂️','🛂','🛃','🛄','🛅','🚹','🚺','🚼','⚧️','🚻','🚮','🎦','📶','🈁','🔣','ℹ️','🔤','🔡','🔠','🆖','🆗','🆙','🆒','🆕','🆓','0️⃣','1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟','🔢','#️⃣','*️⃣','⏏️','▶️','⏸️','⏯️','⏹️','⏺️','⏭️','⏮️','⏩','⏪','⏫','⏬','◀️','🔼','🔽','➡️','⬅️','⬆️','⬇️','↗️','↘️','↙️','↖️','↕️','↔️','↪️','↩️','⤴️','⤵️','🔀','🔁','🔂','🔄','🔃','🎵','🎶','➕','➖','➗','✖️','🟰','♾️','💲','💱','™️','©️','®️','👁️‍🗨️','🔚','🔙','🔛','🔝','🔜','〰️','➰','➿','✔️','☑️','🔘','🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','🟤','🔺','🔻','🔸','🔹','🔶','🔷','🔳','🔲','▪️','▫️','◾','◽','◼️','◻️','🟥','🟧','🟨','🟩','🟦','🟪','⬛','⬜','🟫','🔈','🔇','🔉','🔊','🔔','🔕','📣','📢','💬','💭','🗯️','♠️','♣️','♥️','♦️','🃏','🎴','🀄','🕐','🕑','🕒','🕓','🕔','🕕','🕖','🕗','🕘','🕙','🕚','🕛','🕜','🕝','🕞','🕟','🕠','🕡','🕢','🕣','🕤','🕥','🕦','🕧']],
                                'flags' => ['name' => 'Flags', 'emojis' => ['🏁','🚩','🎌','🏴','🏳️','🏳️‍🌈','🏳️‍⚧️','🏴‍☠️','🇦🇨','🇦🇩','🇦🇪','🇦🇫','🇦🇬','🇦🇮','🇦🇱','🇦🇲','🇦🇴','🇦🇶','🇦🇷','🇦🇸','🇦🇹','🇦🇺','🇦🇼','🇦🇽','🇦🇿','🇧🇦','🇧🇧','🇧🇩','🇧🇪','🇧🇫','🇧🇬','🇧🇭','🇧🇮','🇧🇯','🇧🇱','🇧🇲','🇧🇳','🇧🇴','🇧🇶','🇧🇷','🇧🇸','🇧🇹','🇧🇻','🇧🇼','🇧🇾','🇧🇿','🇨🇦','🇨🇨','🇨🇩','🇨🇫','🇨🇬','🇨🇭','🇨🇮','🇨🇰','🇨🇱','🇨🇲','🇨🇳','🇨🇴','🇨🇵','🇨🇷','🇨🇺','🇨🇻','🇨🇼','🇨🇽','🇨🇾','🇨🇿','🇩🇪','🇩🇬','🇩🇯','🇩🇰','🇩🇲','🇩🇴','🇩🇿','🇪🇦','🇪🇨','🇪🇪','🇪🇬','🇪🇭','🇪🇷','🇪🇸','🇪🇹','🇪🇺','🇫🇮','🇫🇯','🇫🇰','🇫🇲','🇫🇴','🇫🇷','🇬🇦','🇬🇧','🇬🇩','🇬🇪','🇬🇫','🇬🇬','🇬🇭','🇬🇮','🇬🇱','🇬🇲','🇬🇳','🇬🇵','🇬🇶','🇬🇷','🇬🇸','🇬🇹','🇬🇺','🇬🇼','🇬🇾','🇭🇰','🇭🇲','🇭🇳','🇭🇷','🇭🇹','🇭🇺','🇮🇨','🇮🇩','🇮🇪','🇮🇱','🇮🇲','🇮🇳','🇮🇴','🇮🇶','🇮🇷','🇮🇸','🇮🇹','🇯🇪','🇯🇲','🇯🇴','🇯🇵','🇰🇪','🇰🇬','🇰🇭','🇰🇮','🇰🇲','🇰🇳','🇰🇵','🇰🇷','🇰🇼','🇰🇾','🇰🇿','🇱🇦','🇱🇧','🇱🇨','🇱🇮','🇱🇰','🇱🇷','🇱🇸','🇱🇹','🇱🇺','🇱🇻','🇱🇾','🇲🇦','🇲🇨','🇲🇩','🇲🇪','🇲🇫','🇲🇬','🇲🇭','🇲🇰','🇲🇱','🇲🇲','🇲🇳','🇲🇴','🇲🇵','🇲🇶','🇲🇷','🇲🇸','🇲🇹','🇲🇺','🇲🇻','🇲🇼','🇲🇽','🇲🇾','🇲🇿','🇳🇦','🇳🇨','🇳🇪','🇳🇫','🇳🇬','🇳🇮','🇳🇱','🇳🇴','🇳🇵','🇳🇷','🇳🇺','🇳🇿','🇴🇲','🇵🇦','🇵🇪','🇵🇫','🇵🇬','🇵🇭','🇵🇰','🇵🇱','🇵🇲','🇵🇳','🇵🇷','🇵🇸','🇵🇹','🇵🇼','🇵🇾','🇶🇦','🇷🇪','🇷🇴','🇷🇸','🇷🇺','🇷🇼','🇸🇦','🇸🇧','🇸🇨','🇸🇩','🇸🇪','🇸🇬','🇸🇭','🇸🇮','🇸🇯','🇸🇰','🇸🇱','🇸🇲','🇸🇳','🇸🇴','🇸🇷','🇸🇸','🇸🇹','🇸🇻','🇸🇽','🇸🇾','🇸🇿','🇹🇦','🇹🇨','🇹🇩','🇹🇫','🇹🇬','🇹🇭','🇹🇯','🇹🇰','🇹🇱','🇹🇲','🇹🇳','🇹🇴','🇹🇷','🇹🇹','🇹🇻','🇹🇼','🇹🇿','🇺🇦','🇺🇬','🇺🇲','🇺🇳','🇺🇸','🇺🇾','🇺🇿','🇻🇦','🇻🇨','🇻🇪','🇻🇬','🇻🇮','🇻🇳','🇻🇺','🇼🇫','🇼🇸','🇽🇰','🇾🇪','🇾🇹','🇿🇦','🇿🇲','🇿🇼','🏴󠁧󠁢󠁥󠁮󠁧󠁿','🏴󠁧󠁢󠁳󠁣󠁴󠁿','🏴󠁧󠁢󠁷󠁬󠁳󠁿']]
                            ];
                            foreach ($emojiCategories as $catKey => $catData) {
                                echo "<div class='emoji-section' id='emoji-section-$catKey'>";
                                echo "<div class='emoji-section-title'>{$catData['name']}</div>";
                                echo "<div class='emoji-grid'>";
                                foreach ($catData['emojis'] as $emoji) {
                                    echo "<button class='emoji-item' data-emoji='$emoji'>$emoji</button>";
                                }
                                echo "</div>";
                                echo "</div>";
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</main>

<!-- Context Menu -->
<div class="context-menu" id="contextMenu">
    <button onclick="contextReplyMessage()">↩️ Reply</button>
    <button onclick="copyMessage()">📋 Copy</button>
    <button onclick="deleteMessage()" class="danger">🗑️ Delete</button>
</div>

<!-- Hidden Data -->
<input type="hidden" id="currentUserId" value="<?php echo $user['id']; ?>">
<input type="hidden" id="currentUserName" value="<?php echo htmlspecialchars($user['name']); ?>">
<input type="hidden" id="familyId" value="<?php echo $user['family_id']; ?>">

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
