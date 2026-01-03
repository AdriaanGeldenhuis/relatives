<?php
// This file is included in the main notes page for each note
$isVoice = $note['type'] === 'voice';
$noteColor = $note['color'] ?: '#ffeb3b';
?>
<div class="note-card" 
     data-note-id="<?php echo $note['id']; ?>"
     data-type="<?php echo $note['type']; ?>"
     data-pinned="<?php echo $note['pinned']; ?>"
     style="background: <?php echo htmlspecialchars($noteColor); ?>">
    
    <!-- Pin Button -->
    <button onclick="togglePin(<?php echo $note['id']; ?>)" 
            class="note-pin <?php echo $note['pinned'] ? 'pinned' : ''; ?>"
            title="<?php echo $note['pinned'] ? 'Unpin' : 'Pin'; ?>">
        📌
    </button>

    <!-- Note Header -->
    <?php if ($note['title']): ?>
        <div class="note-title"><?php echo htmlspecialchars($note['title']); ?></div>
    <?php endif; ?>

    <!-- Note Content -->
    <div class="note-content">
        <?php if ($isVoice): ?>
            <!-- Voice Note -->
            <div class="voice-note-player">
                <div class="voice-icon">🎤</div>
                <?php
                $audioExists = $note['audio_path'] && file_exists(__DIR__ . '/..' . $note['audio_path']);
                if ($audioExists): ?>
                    <audio controls class="audio-player">
                        <source src="<?php echo htmlspecialchars($note['audio_path']); ?>" type="audio/webm">
                        Your browser does not support audio playback.
                    </audio>
                <?php elseif ($note['audio_path']): ?>
                    <div class="voice-missing">Audio file not available</div>
                <?php else: ?>
                    <div class="voice-placeholder">No audio recorded</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Text Note -->
            <div class="note-text"><?php echo nl2br(htmlspecialchars($note['body'])); ?></div>
        <?php endif; ?>
    </div>

    <!-- Note Footer -->
    <div class="note-footer">
        <div class="note-author">
            <div class="author-avatar" style="background: <?php echo htmlspecialchars($note['avatar_color']); ?>">
                <?php echo strtoupper(substr($note['full_name'], 0, 1)); ?>
            </div>
            <div class="author-info">
                <div class="author-name"><?php echo htmlspecialchars($note['full_name']); ?></div>
                <div class="note-date"><?php echo date('M j, Y @ g:i A', strtotime($note['created_at'])); ?></div>
            </div>
        </div>

        <div class="note-actions">
            <?php if (!$isVoice): ?>
                <button onclick="editNote(<?php echo $note['id']; ?>)" class="note-action-btn" title="Edit">
                    ✏️
                </button>
            <?php endif; ?>
            <button onclick="deleteNote(<?php echo $note['id']; ?>)" class="note-action-btn" title="Delete">
                🗑️
            </button>
        </div>
    </div>
</div>