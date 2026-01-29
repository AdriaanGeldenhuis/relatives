<?php
/**
 * RELATIVES - NOTES SYSTEM
 * Beautiful sticky notes with AI & voice
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/NotificationManager.php';
require_once __DIR__ . '/../core/NotificationTriggers.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login.php');
    exit;
}

// 🎤 VOICE PREFILL DETECTION
$voicePrefillContent = '';
if (isset($_GET['new']) && $_GET['new'] == '1' && isset($_GET['content'])) {
    $voicePrefillContent = trim($_GET['content']);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'create_note':
                $type = $_POST['type'] ?? 'text';
                $title = trim($_POST['title'] ?? '');
                $body = trim($_POST['body'] ?? '');
                $color = $_POST['color'] ?? '#ffeb3b';
                
                if ($type === 'text' && empty($body)) {
                    throw new Exception('Note content is required');
                }
                
                $stmt = $db->prepare("
                    INSERT INTO notes (family_id, user_id, type, title, body, color, pinned, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
                ");
                $stmt->execute([$user['family_id'], $user['id'], $type, $title, $body, $color]);
                
                $noteId = $db->lastInsertId();
                
                if ($type === 'voice' && isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../uploads/voice/';

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $filename = 'voice_' . time() . '_' . uniqid() . '.webm';
                    $filepath = $uploadDir . $filename;

                    if (move_uploaded_file($_FILES['audio']['tmp_name'], $filepath)) {
                        $audioPath = '/uploads/voice/' . $filename;

                        $stmt = $db->prepare("UPDATE notes SET audio_path = ? WHERE id = ?");
                        $stmt->execute([$audioPath, $noteId]);
                    }
                }

                // Send notification to family members
                try {
                    $triggers = new NotificationTriggers($db);
                    $triggers->onNoteCreated($noteId, $user['id'], $user['family_id'], $title, $type);
                } catch (Exception $e) {
                    error_log('Note notification error: ' . $e->getMessage());
                }

                // Return full note data for real-time DOM update
                $audioPath = null;
                if ($type === 'voice' && isset($_FILES['audio'])) {
                    $stmt = $db->prepare("SELECT audio_path FROM notes WHERE id = ?");
                    $stmt->execute([$noteId]);
                    $audioPath = $stmt->fetchColumn();
                }

                echo json_encode([
                    'success' => true,
                    'note_id' => $noteId,
                    'note' => [
                        'id' => $noteId,
                        'type' => $type,
                        'title' => $title,
                        'body' => $body,
                        'color' => $color,
                        'pinned' => 0,
                        'audio_path' => $audioPath,
                        'user_id' => $user['id'],
                        'user_name' => $user['full_name'],
                        'avatar_color' => $user['avatar_color'],
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                exit;
            
            case 'update_note':
                $noteId = (int)$_POST['note_id'];
                $title = trim($_POST['title'] ?? '');
                $body = trim($_POST['body'] ?? '');
                $color = $_POST['color'] ?? '#ffeb3b';
                
                if (empty($body)) {
                    throw new Exception('Note content is required');
                }
                
                $stmt = $db->prepare("
                    UPDATE notes 
                    SET title = ?, body = ?, color = ?, updated_at = NOW() 
                    WHERE id = ? AND family_id = ?
                ");
                $stmt->execute([$title, $body, $color, $noteId, $user['family_id']]);
                
                echo json_encode(['success' => true]);
                exit;
            
            case 'delete_note':
                $noteId = (int)$_POST['note_id'];
                
                $stmt = $db->prepare("SELECT audio_path FROM notes WHERE id = ? AND family_id = ?");
                $stmt->execute([$noteId, $user['family_id']]);
                $note = $stmt->fetch();
                
                if ($note && $note['audio_path']) {
                    $audioFile = __DIR__ . '/..' . $note['audio_path'];
                    if (file_exists($audioFile)) {
                        unlink($audioFile);
                    }
                }
                
                $stmt = $db->prepare("DELETE FROM notes WHERE id = ? AND family_id = ?");
                $stmt->execute([$noteId, $user['family_id']]);
                
                echo json_encode(['success' => true]);
                exit;
            
            case 'toggle_pin':
                $noteId = (int)$_POST['note_id'];
                
                $stmt = $db->prepare("SELECT pinned FROM notes WHERE id = ? AND family_id = ?");
                $stmt->execute([$noteId, $user['family_id']]);
                $currentPinned = (int)$stmt->fetchColumn();
                
                $newPinned = $currentPinned ? 0 : 1;
                
                $stmt = $db->prepare("UPDATE notes SET pinned = ? WHERE id = ?");
                $stmt->execute([$newPinned, $noteId]);
                
                echo json_encode(['success' => true, 'pinned' => $newPinned]);
                exit;
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get all notes
$stmt = $db->prepare("
    SELECT n.*, u.full_name, u.avatar_color
    FROM notes n
    LEFT JOIN users u ON n.user_id = u.id
    WHERE n.family_id = ?
    ORDER BY n.pinned DESC, n.created_at DESC
");
$stmt->execute([$user['family_id']]);
$notes = $stmt->fetchAll();

$pinnedNotes = array_filter($notes, fn($n) => $n['pinned'] == 1);
$regularNotes = array_filter($notes, fn($n) => $n['pinned'] == 0);

function timeAgo($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    
    return date('M j, Y', $time);
}

$pageTitle = 'Notes';
$cacheVersion = '10.0.0';
$pageCSS = [
    '/notes/css/notes.css?v=' . $cacheVersion,
    '/collage/css/collage.css?v=' . $cacheVersion
];
$pageJS = [
    '/notes/js/notes.js?v=' . $cacheVersion,
    '/collage/js/collage.state.js?v=' . $cacheVersion,
    '/collage/js/collage.images.js?v=' . $cacheVersion,
    '/collage/js/collage.drag.js?v=' . $cacheVersion,
    '/collage/js/collage.resize.js?v=' . $cacheVersion,
    '/collage/js/collage.rotate.js?v=' . $cacheVersion,
    '/collage/js/collage.layouts.js?v=' . $cacheVersion,
    '/collage/js/collage.background.js?v=' . $cacheVersion,
    '/collage/js/collage.cleanup.js?v=' . $cacheVersion,
    '/collage/js/collage.init.js?v=' . $cacheVersion
];
$shoppingCount = 0;

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
        
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="greeting-card">
                <div class="greeting-time"><?php echo date('l, F j, Y'); ?></div>
                <h1 class="greeting-text">
                    <span class="greeting-icon">📝</span>
                    <span class="greeting-name">Family Notes</span>
                </h1>
                <p class="greeting-subtitle">Capture ideas, reminders, and memories together</p>
                
                <div class="quick-actions">
                    <button id="btnNewNote" class="quick-action-btn" data-action="text">
                        <span class="qa-icon">📝</span>
                        <span>New Note</span>
                    </button>
                    <button id="btnVoiceNote" class="quick-action-btn" data-action="voice">
                        <span class="qa-icon">🎤</span>
                        <span>Voice Note</span>
                    </button>
                    <button id="btnSearch" class="quick-action-btn" data-action="search">
                        <span class="qa-icon">🔍</span>
                        <span>Search</span>
                    </button>
                </div>

                <!-- INLINE SCRIPT FOR NATIVE APP COMPATIBILITY -->
                <script>
                (function() {
                    // Immediately attach handlers - no waiting for DOMContentLoaded
                    function handleAction(action) {
                        if (action === 'text') {
                            document.getElementById('createTextNoteModal').classList.add('active');
                            setTimeout(function() {
                                var noteBody = document.getElementById('noteBody');
                                if (noteBody) noteBody.focus();
                            }, 100);
                        } else if (action === 'voice') {
                            document.getElementById('createVoiceNoteModal').classList.add('active');
                            if (typeof resetVoiceRecorder === 'function') resetVoiceRecorder();
                        } else if (action === 'search') {
                            document.getElementById('searchInput').focus();
                        }
                    }

                    function setupButton(btn) {
                        if (!btn) return;
                        var action = btn.getAttribute('data-action');
                        var handled = false;

                        btn.addEventListener('touchstart', function(e) {
                            btn.style.transform = 'translateY(-2px) scale(1.02)';
                            btn.style.background = 'rgba(255,255,255,0.18)';
                        }, {passive: true});

                        btn.addEventListener('touchend', function(e) {
                            btn.style.transform = '';
                            btn.style.background = '';
                            e.preventDefault();
                            handled = true;
                            handleAction(action);
                            setTimeout(function() { handled = false; }, 300);
                        }, {passive: false});

                        btn.addEventListener('touchcancel', function() {
                            btn.style.transform = '';
                            btn.style.background = '';
                        }, {passive: true});

                        btn.addEventListener('click', function(e) {
                            if (!handled) handleAction(action);
                        });
                    }

                    setupButton(document.getElementById('btnNewNote'));
                    setupButton(document.getElementById('btnVoiceNote'));
                    setupButton(document.getElementById('btnSearch'));

                    console.log('✅ Quick action buttons ready (inline)');
                })();
                </script>
            </div>
        </div>

        <!-- Search and Filter Bar -->
        <div class="search-filter-section">
            <div class="search-bar glass-card">
                <span class="search-icon">🔍</span>
                <input 
                    type="text" 
                    id="searchInput" 
                    class="search-input" 
                    placeholder="Search notes..."
                    oninput="searchNotes(this.value)">
            </div>
            
            <div class="filter-buttons">
                <button class="filter-btn active" data-filter="all" onclick="filterNotes('all')">
                    All Notes
                </button>
                <button class="filter-btn" data-filter="text" onclick="filterNotes('text')">
                    📝 Text
                </button>
                <button class="filter-btn" data-filter="voice" onclick="filterNotes('voice')">
                    🎤 Voice
                </button>
                <button class="filter-btn" data-filter="pinned" onclick="filterNotes('pinned')">
                    📌 Pinned
                </button>
            </div>
        </div>

        <!-- Stats Bar -->
        <div class="notes-stats-bar">
            <div class="stat-item">
                <span class="stat-icon">📝</span>
                <span class="stat-value" id="totalNotes"><?php echo count($notes); ?></span>
                <span class="stat-label">Total</span>
            </div>
            <div class="stat-item">
                <span class="stat-icon">📌</span>
                <span class="stat-value" id="pinnedCount"><?php echo count($pinnedNotes); ?></span>
                <span class="stat-label">Pinned</span>
            </div>
            <div class="stat-item">
                <span class="stat-icon">📄</span>
                <span class="stat-value" id="textCount"><?php echo count(array_filter($notes, fn($n) => $n['type'] === 'text')); ?></span>
                <span class="stat-label">Text</span>
            </div>
            <div class="stat-item">
                <span class="stat-icon">🎤</span>
                <span class="stat-value" id="voiceCount"><?php echo count(array_filter($notes, fn($n) => $n['type'] === 'voice')); ?></span>
                <span class="stat-label">Voice</span>
            </div>
        </div>

        <!-- Pinned Notes Section -->
        <?php if (!empty($pinnedNotes)): ?>
            <section class="notes-section">
                <h2 class="section-title">
                    <span class="title-icon">📌</span>
                    Pinned Notes
                </h2>
                <div class="notes-grid">
                    <?php foreach ($pinnedNotes as $note): ?>
                        <div class="note-card"
                             data-note-id="<?php echo $note['id']; ?>"
                             data-note-type="<?php echo $note['type']; ?>"
                             style="background: <?php echo htmlspecialchars($note['color']); ?>; cursor: pointer;"
                             onclick="openFullscreenNote(<?php echo $note['id']; ?>)">

                            <div class="note-header">
                                <button onclick="event.stopPropagation(); togglePin(<?php echo $note['id']; ?>)"
                                        class="note-pin active"
                                        title="Unpin">
                                    📌
                                </button>
                                <div class="note-actions">
                                    <?php if ($note['type'] === 'text'): ?>
                                        <button onclick="event.stopPropagation(); editNote(<?php echo $note['id']; ?>)"
                                                class="note-action"
                                                title="Edit">
                                            ✏️
                                        </button>
                                    <?php endif; ?>
                                    <button onclick="event.stopPropagation(); duplicateNote(<?php echo $note['id']; ?>)"
                                            class="note-action"
                                            title="Duplicate">
                                        📋
                                    </button>
                                    <button onclick="event.stopPropagation(); shareNote(<?php echo $note['id']; ?>)"
                                            class="note-action"
                                            title="Share">
                                        📤
                                    </button>
                                    <button onclick="event.stopPropagation(); deleteNote(<?php echo $note['id']; ?>)"
                                            class="note-action"
                                            title="Delete">
                                        🗑️
                                    </button>
                                </div>
                            </div>

                            <?php if (!empty($note['title'])): ?>
                                <div class="note-title"><?php echo htmlspecialchars($note['title']); ?></div>
                            <?php endif; ?>

                            <?php if ($note['type'] === 'text'): ?>
                                <div class="note-body"><?php echo nl2br(htmlspecialchars($note['body'])); ?></div>
                            <?php else: ?>
                                <div class="note-voice" onclick="event.stopPropagation();">
                                    <div class="voice-icon">🎤</div>
                                    <div class="voice-label">Voice Note</div>
                                    <?php
                                    $audioExists = $note['audio_path'] && file_exists(__DIR__ . '/..' . $note['audio_path']);
                                    if ($audioExists): ?>
                                        <audio controls onclick="event.stopPropagation();">
                                            <source src="<?php echo htmlspecialchars($note['audio_path']); ?>" type="audio/webm">
                                            Your browser does not support audio playback.
                                        </audio>
                                    <?php elseif ($note['audio_path']): ?>
                                        <div class="voice-missing">Audio file not available</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="note-footer">
                                <div class="note-author">
                                    <div class="author-avatar-mini"
                                         style="background: <?php echo htmlspecialchars($note['avatar_color']); ?>">
                                        <img src="/saves/<?php echo (int)$note['user_id']; ?>/avatar/avatar.webp"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                             style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                                        <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;"><?php echo strtoupper(substr($note['full_name'], 0, 1)); ?></span>
                                    </div>
                                    <span><?php echo htmlspecialchars($note['full_name']); ?></span>
                                </div>
                                <div class="note-date" title="<?php echo date('M j, Y \a\t g:i A', strtotime($note['created_at'])); ?>">
                                    <?php echo timeAgo($note['created_at']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Regular Notes Section -->
        <section class="notes-section">
            <?php if (!empty($pinnedNotes)): ?>
                <h2 class="section-title">
                    <span class="title-icon">📋</span>
                    Other Notes
                </h2>
            <?php endif; ?>
            
            <?php if (empty($notes)): ?>
                <div class="empty-state glass-card">
                    <div class="empty-icon">📝</div>
                    <h2>No notes yet</h2>
                    <p>Start capturing your family's ideas and reminders</p>
                    <div class="empty-actions">
                        <button onclick="showCreateNoteModal('text')" class="btn btn-primary btn-lg">
                            <span class="btn-icon">📝</span>
                            <span>Create First Note</span>
                        </button>
                        <button onclick="showCreateNoteModal('voice')" class="btn btn-voice btn-lg">
                            <span class="btn-icon">🎤</span>
                            <span>Record Voice Note</span>
                        </button>
                    </div>
                </div>
                
            <?php else: ?>
                <div class="notes-grid">
                    <?php foreach ($regularNotes as $note): ?>
                        <div class="note-card"
                             data-note-id="<?php echo $note['id']; ?>"
                             data-note-type="<?php echo $note['type']; ?>"
                             style="background: <?php echo htmlspecialchars($note['color']); ?>; cursor: pointer;"
                             onclick="openFullscreenNote(<?php echo $note['id']; ?>)">

                            <div class="note-header">
                                <button onclick="event.stopPropagation(); togglePin(<?php echo $note['id']; ?>)"
                                        class="note-pin"
                                        title="Pin">
                                    📌
                                </button>
                                <div class="note-actions">
                                    <?php if ($note['type'] === 'text'): ?>
                                        <button onclick="event.stopPropagation(); editNote(<?php echo $note['id']; ?>)"
                                                class="note-action"
                                                title="Edit">
                                            ✏️
                                        </button>
                                    <?php endif; ?>
                                    <button onclick="event.stopPropagation(); duplicateNote(<?php echo $note['id']; ?>)"
                                            class="note-action"
                                            title="Duplicate">
                                        📋
                                    </button>
                                    <button onclick="event.stopPropagation(); shareNote(<?php echo $note['id']; ?>)"
                                            class="note-action"
                                            title="Share">
                                        📤
                                    </button>
                                    <button onclick="event.stopPropagation(); deleteNote(<?php echo $note['id']; ?>)"
                                            class="note-action"
                                            title="Delete">
                                        🗑️
                                    </button>
                                </div>
                            </div>

                            <?php if (!empty($note['title'])): ?>
                                <div class="note-title"><?php echo htmlspecialchars($note['title']); ?></div>
                            <?php endif; ?>

                            <?php if ($note['type'] === 'text'): ?>
                                <div class="note-body"><?php echo nl2br(htmlspecialchars($note['body'])); ?></div>
                            <?php else: ?>
                                <div class="note-voice" onclick="event.stopPropagation();">
                                    <div class="voice-icon">🎤</div>
                                    <div class="voice-label">Voice Note</div>
                                    <?php
                                    $audioExists = $note['audio_path'] && file_exists(__DIR__ . '/..' . $note['audio_path']);
                                    if ($audioExists): ?>
                                        <audio controls onclick="event.stopPropagation();">
                                            <source src="<?php echo htmlspecialchars($note['audio_path']); ?>" type="audio/webm">
                                            Your browser does not support audio playback.
                                        </audio>
                                    <?php elseif ($note['audio_path']): ?>
                                        <div class="voice-missing">Audio file not available</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="note-footer">
                                <div class="note-author">
                                    <div class="author-avatar-mini"
                                         style="background: <?php echo htmlspecialchars($note['avatar_color']); ?>">
                                        <img src="/saves/<?php echo (int)$note['user_id']; ?>/avatar/avatar.webp"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                             style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                                        <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;"><?php echo strtoupper(substr($note['full_name'], 0, 1)); ?></span>
                                    </div>
                                    <span><?php echo htmlspecialchars($note['full_name']); ?></span>
                                </div>
                                <div class="note-date" title="<?php echo date('M j, Y \a\t g:i A', strtotime($note['created_at'])); ?>">
                                    <?php echo timeAgo($note['created_at']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<!-- Create Text Note Modal -->
<div id="createTextNoteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>📝 Create Note</h2>
            <button onclick="closeModal('createTextNoteModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form onsubmit="createNote(event, 'text')">
                <div class="form-group">
                    <label>Title (optional)</label>
                    <input type="text" id="noteTitle" class="form-control" placeholder="Note title...">
                </div>

                <div class="form-group">
                    <label>Content</label>
                    <textarea id="noteBody" 
                              class="form-control note-textarea" 
                              placeholder="Write your note here..." 
                              required 
                              rows="8"><?php echo htmlspecialchars($voicePrefillContent); ?></textarea>
                </div>

                <button type="button" id="btnAddCollage" class="btn btn-collage btn-add-collage">
                    <span>🖼️</span> Add a collage or photo
                </button>

                <div class="form-group">
                    <label>Color</label>
                    <div class="color-picker">
                        <input type="radio" name="noteColor" value="#ffeb3b" id="color1" checked>
                        <label for="color1" class="color-option" style="background: #ffeb3b;"></label>

                        <input type="radio" name="noteColor" value="#ff9800" id="color2">
                        <label for="color2" class="color-option" style="background: #ff9800;"></label>

                        <input type="radio" name="noteColor" value="#e91e63" id="color3">
                        <label for="color3" class="color-option" style="background: #e91e63;"></label>

                        <input type="radio" name="noteColor" value="#9c27b0" id="color4">
                        <label for="color4" class="color-option" style="background: #9c27b0;"></label>

                        <input type="radio" name="noteColor" value="#2196f3" id="color5">
                        <label for="color5" class="color-option" style="background: #2196f3;"></label>

                        <input type="radio" name="noteColor" value="#00bcd4" id="color6">
                        <label for="color6" class="color-option" style="background: #00bcd4;"></label>

                        <input type="radio" name="noteColor" value="#4caf50" id="color7">
                        <label for="color7" class="color-option" style="background: #4caf50;"></label>

                        <input type="radio" name="noteColor" value="#8bc34a" id="color8">
                        <label for="color8" class="color-option" style="background: #8bc34a;"></label>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Create Note</button>
                    <button type="button" onclick="closeModal('createTextNoteModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Voice Note Modal -->
<div id="createVoiceNoteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>🎤 Voice Note</h2>
            <button onclick="closeModal('createVoiceNoteModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="voice-recorder">
                <div class="recording-status" id="recordingStatus">
                    <div class="recording-icon">🎤</div>
                    <div class="recording-text">Press record to start</div>
                    <div class="recording-timer" id="recordingTimer">00:00</div>
                </div>

                <div class="recording-visualizer" id="visualizer">
                    <canvas id="visualizerCanvas"></canvas>
                </div>

                <div class="recording-controls">
                    <button type="button" onclick="startRecording()" id="startRecordBtn" class="btn btn-primary btn-lg">
                        <span class="btn-icon">⏺️</span>
                        <span class="btn-text">Start Recording</span>
                    </button>
                    <button type="button" onclick="stopRecording()" id="stopRecordBtn" class="btn btn-danger btn-lg" style="display: none;">
                        <span class="btn-icon">⏹️</span>
                        <span class="btn-text">Stop Recording</span>
                    </button>
                    <button type="button" onclick="playRecording()" id="playRecordBtn" class="btn btn-success btn-lg" style="display: none;">
                        <span class="btn-icon">▶️</span>
                        <span class="btn-text">Play</span>
                    </button>
                </div>

                <audio id="recordedAudio" controls style="display: none; width: 100%; margin: 20px 0;"></audio>

                <form onsubmit="createNote(event, 'voice')" id="voiceNoteForm" style="display: none;">
                    <div class="form-group">
                        <label>Title (optional)</label>
                        <input type="text" id="voiceNoteTitle" class="form-control" placeholder="Voice note title...">
                    </div>

                    <div class="form-group">
                        <label>Color</label>
                        <div class="color-picker">
                            <input type="radio" name="voiceNoteColor" value="#ffeb3b" id="vcolor1" checked>
                            <label for="vcolor1" class="color-option" style="background: #ffeb3b;"></label>

                            <input type="radio" name="voiceNoteColor" value="#ff9800" id="vcolor2">
                            <label for="vcolor2" class="color-option" style="background: #ff9800;"></label>

                            <input type="radio" name="voiceNoteColor" value="#e91e63" id="vcolor3">
                            <label for="vcolor3" class="color-option" style="background: #e91e63;"></label>

                            <input type="radio" name="voiceNoteColor" value="#9c27b0" id="vcolor4">
                            <label for="vcolor4" class="color-option" style="background: #9c27b0;"></label>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Save Voice Note</button>
                        <button type="button" onclick="closeModal('createVoiceNoteModal')" class="btn btn-secondary">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Note Modal -->
<div id="editNoteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>✏️ Edit Note</h2>
            <button onclick="closeModal('editNoteModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form onsubmit="updateNote(event)">
                <input type="hidden" id="editNoteId">
                
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" id="editNoteTitle" class="form-control" placeholder="Note title...">
                </div>

                <div class="form-group">
                    <label>Content</label>
                    <textarea id="editNoteBody" 
                              class="form-control note-textarea" 
                              required 
                              rows="8"></textarea>
                </div>

                <div class="form-group">
                    <label>Color</label>
                    <div class="color-picker">
                        <input type="radio" name="editNoteColor" value="#ffeb3b" id="ecolor1">
                        <label for="ecolor1" class="color-option" style="background: #ffeb3b;"></label>

                        <input type="radio" name="editNoteColor" value="#ff9800" id="ecolor2">
                        <label for="ecolor2" class="color-option" style="background: #ff9800;"></label>

                        <input type="radio" name="editNoteColor" value="#e91e63" id="ecolor3">
                        <label for="ecolor3" class="color-option" style="background: #e91e63;"></label>

                        <input type="radio" name="editNoteColor" value="#9c27b0" id="ecolor4">
                        <label for="ecolor4" class="color-option" style="background: #9c27b0;"></label>

                        <input type="radio" name="editNoteColor" value="#2196f3" id="ecolor5">
                        <label for="ecolor5" class="color-option" style="background: #2196f3;"></label>

                        <input type="radio" name="editNoteColor" value="#00bcd4" id="ecolor6">
                        <label for="ecolor6" class="color-option" style="background: #00bcd4;"></label>

                        <input type="radio" name="editNoteColor" value="#4caf50" id="ecolor7">
                        <label for="ecolor7" class="color-option" style="background: #4caf50;"></label>

                        <input type="radio" name="editNoteColor" value="#8bc34a" id="ecolor8">
                        <label for="ecolor8" class="color-option" style="background: #8bc34a;"></label>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" onclick="closeModal('editNoteModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Share Note Modal -->
<div id="shareNoteModal" class="modal">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h2>📤 Share Note</h2>
            <button onclick="closeModal('shareNoteModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="share-options">
                <button onclick="copyNoteText()" class="share-option-btn">
                    <span class="share-icon">📋</span>
                    <span>Copy Text</span>
                </button>
                <button onclick="downloadNoteAsText()" class="share-option-btn">
                    <span class="share-icon">💾</span>
                    <span>Download as TXT</span>
                </button>
                <button onclick="printNote()" class="share-option-btn">
                    <span class="share-icon">🖨️</span>
                    <span>Print</span>
                </button>
                <button onclick="emailNote()" class="share-option-btn">
                    <span class="share-icon">📧</span>
                    <span>Email</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Collage Builder Modal -->
<div id="collageModal" class="modal">
    <div class="modal-content modal-fullscreen">
        <div class="modal-header">
            <h2>🖼️ Create Collage</h2>
            <button onclick="closeModal('collageModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body collage-modal-body">
            <div class="collage-toolbar">
                <button id="btnChooseImages">Choose images</button>
                <button id="btnChooseLayout">Choose layout</button>
                <button id="btnChooseBackground">Choose background</button>
                <button id="btnDone">Done</button>
                <button id="btnDelete">Delete</button>
            </div>
            <div id="collageCanvasWrapper">
                <div id="collageCanvas"></div>
            </div>
        </div>
    </div>
</div>

<!-- Fullscreen Note View -->
<div id="fullscreenNoteModal" class="fullscreen-note-overlay">
    <div class="fullscreen-note-container">
        <button onclick="closeFullscreenNote()" class="fullscreen-close-btn" title="Sluit">&times;</button>

        <div class="fullscreen-note-content" id="fullscreenNoteContent">
            <!-- Note content will be injected here -->
        </div>

        <div class="fullscreen-note-actions" id="fullscreenNoteActions">
            <!-- Action buttons will be injected here -->
        </div>
    </div>
</div>

<?php if ($voicePrefillContent): ?>
<script>
// 🎤 AUTO-OPEN MODAL WITH PREFILLED CONTENT
document.addEventListener('DOMContentLoaded', function() {
    showCreateNoteModal('text');
    
    const noteBody = document.getElementById('noteBody');
    if (noteBody) {
        noteBody.focus();
        noteBody.setSelectionRange(noteBody.value.length, noteBody.value.length);
        
        // Show toast notification
        if (typeof showToast === 'function') {
            showToast('🎤 Voice command prefilled!', 'success');
        }
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>