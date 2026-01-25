<?php
/**
 * Holiday Traveling - Share Trip View
 */
?>

<div class="ht-share-trip">
    <!-- Page Header -->
    <div class="ht-page-header">
        <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-back-link">← Back to Trip</a>
        <h1 class="ht-page-title">
            <span class="ht-title-icon">🔗</span>
            Share Trip
        </h1>
        <p class="ht-page-subtitle"><?php echo htmlspecialchars($trip['destination']); ?> - <?php echo htmlspecialchars($trip['title']); ?></p>
    </div>

    <!-- Share Link Section -->
    <div class="ht-section ht-section-highlight">
        <h3 class="ht-section-title">
            <span class="ht-section-icon">📤</span>
            Share Link
        </h3>
        <p class="ht-share-description">
            Share this link with family or friends to let them view this trip.
        </p>
        <div class="ht-share-link-box">
            <input type="text" id="shareUrlInput" class="ht-input" value="<?php echo htmlspecialchars($shareUrl); ?>" readonly>
            <button id="copyLinkBtn" class="ht-btn ht-btn-primary">
                <span class="ht-btn-icon">📋</span>
                Copy
            </button>
        </div>
        <div class="ht-share-actions">
            <a href="https://wa.me/?text=<?php echo urlencode("Join my trip to {$trip['destination']}! {$shareUrl}"); ?>" target="_blank" class="ht-share-btn ht-share-whatsapp">
                💬 WhatsApp
            </a>
            <a href="mailto:?subject=<?php echo urlencode("Join my trip to {$trip['destination']}"); ?>&body=<?php echo urlencode("I'm planning a trip to {$trip['destination']} and would love for you to join!\n\nView the trip: {$shareUrl}"); ?>" class="ht-share-btn ht-share-email">
                ✉️ Email
            </a>
            <button id="shareNativeBtn" class="ht-share-btn ht-share-native" style="display: none;">
                📤 Share
            </button>
        </div>
    </div>

    <!-- Invite by Email Section -->
    <div class="ht-section">
        <h3 class="ht-section-title">
            <span class="ht-section-icon">✉️</span>
            Invite by Email
        </h3>
        <form id="inviteForm" class="ht-invite-form">
            <?php echo HT_CSRF::field(); ?>
            <input type="hidden" name="trip_id" value="<?php echo $trip['id']; ?>">
            <div class="ht-form-row">
                <div class="ht-form-group" style="flex: 1;">
                    <input type="email" id="inviteEmail" name="email" class="ht-input" placeholder="Enter email address" required>
                </div>
                <div class="ht-form-group">
                    <select name="role" class="ht-select">
                        <option value="viewer">Viewer</option>
                        <option value="editor">Editor</option>
                    </select>
                </div>
                <button type="submit" class="ht-btn ht-btn-primary">
                    Invite
                </button>
            </div>
        </form>
    </div>

    <!-- Members List -->
    <div class="ht-section">
        <h3 class="ht-section-title">
            <span class="ht-section-icon">👥</span>
            Trip Members (<?php echo count($members); ?>)
        </h3>
        <div class="ht-members-list">
            <?php foreach ($members as $member): ?>
            <div class="ht-member-card" data-member-id="<?php echo $member['id']; ?>">
                <div class="ht-member-avatar" style="background: <?php echo $member['avatar_color'] ?? '#667eea'; ?>">
                    <?php echo strtoupper(substr($member['full_name'] ?? $member['invited_email'] ?? '?', 0, 1)); ?>
                </div>
                <div class="ht-member-info">
                    <span class="ht-member-name">
                        <?php echo htmlspecialchars($member['full_name'] ?? 'Pending'); ?>
                    </span>
                    <span class="ht-member-email">
                        <?php echo htmlspecialchars($member['email'] ?? $member['invited_email'] ?? ''); ?>
                    </span>
                </div>
                <div class="ht-member-meta">
                    <span class="ht-member-role ht-role-<?php echo $member['role']; ?>">
                        <?php echo ucfirst($member['role']); ?>
                    </span>
                    <span class="ht-member-status ht-status-<?php echo $member['status']; ?>">
                        <?php echo ucfirst($member['status']); ?>
                    </span>
                </div>
                <?php if ($member['role'] !== 'owner'): ?>
                <button class="ht-member-remove" data-member-id="<?php echo $member['id']; ?>" title="Remove member">
                    ×
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Regenerate Link -->
    <div class="ht-section">
        <h3 class="ht-section-title">
            <span class="ht-section-icon">🔄</span>
            Regenerate Share Link
        </h3>
        <p class="ht-section-description">
            If you need to invalidate the current share link, you can generate a new one. The old link will stop working.
        </p>
        <button id="regenerateLinkBtn" class="ht-btn ht-btn-outline">
            Generate New Link
        </button>
    </div>
</div>

<script>
(function() {
    'use strict';

    const tripId = <?php echo $trip['id']; ?>;

    // Copy link to clipboard
    document.getElementById('copyLinkBtn')?.addEventListener('click', async function() {
        const input = document.getElementById('shareUrlInput');
        try {
            await navigator.clipboard.writeText(input.value);
            this.innerHTML = '<span class="ht-btn-icon">✓</span> Copied!';
            setTimeout(() => {
                this.innerHTML = '<span class="ht-btn-icon">📋</span> Copy';
            }, 2000);
        } catch (e) {
            input.select();
            document.execCommand('copy');
            HT.Toast.success('Link copied!');
        }
    });

    // Native share if available
    if (navigator.share) {
        const nativeBtn = document.getElementById('shareNativeBtn');
        if (nativeBtn) {
            nativeBtn.style.display = 'inline-flex';
            nativeBtn.addEventListener('click', async function() {
                try {
                    await navigator.share({
                        title: 'Trip to <?php echo addslashes($trip['destination']); ?>',
                        text: 'Join my trip!',
                        url: document.getElementById('shareUrlInput').value
                    });
                } catch (e) {
                    // User cancelled or error
                }
            });
        }
    }

    // Invite form
    document.getElementById('inviteForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const email = document.getElementById('inviteEmail').value;
        const role = this.querySelector('[name="role"]').value;

        try {
            await HT.API.post('members_invite.php', {
                trip_id: tripId,
                email: email,
                role: role
            });
            HT.Toast.success('Invitation sent!');
            window.location.reload();
        } catch (error) {
            HT.Toast.error(error.message || 'Failed to send invitation');
        }
    });

    // Remove member
    document.querySelectorAll('.ht-member-remove').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('Remove this member from the trip?')) return;

            const memberId = this.dataset.memberId;
            try {
                await HT.API.delete(`members_remove.php?id=${memberId}&trip_id=${tripId}`);
                HT.Toast.success('Member removed');
                this.closest('.ht-member-card').remove();
            } catch (error) {
                HT.Toast.error(error.message || 'Failed to remove member');
            }
        });
    });

    // Regenerate link
    document.getElementById('regenerateLinkBtn')?.addEventListener('click', async function() {
        if (!confirm('Generate a new share link? The current link will stop working.')) return;

        try {
            const response = await HT.API.post('trips_regenerate_link.php', { trip_id: tripId });
            HT.Toast.success('New link generated');
            window.location.reload();
        } catch (error) {
            HT.Toast.error(error.message || 'Failed to regenerate link');
        }
    });
})();
</script>

<style>
.ht-share-description {
    font-size: 14px;
    color: var(--ht-text-secondary);
    margin: 0 0 16px 0;
}

.ht-share-link-box {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}

.ht-share-link-box .ht-input {
    flex: 1;
    font-size: 13px;
}

.ht-share-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.ht-share-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    border-radius: var(--ht-radius-md);
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: var(--ht-transition);
}

.ht-share-whatsapp {
    background: #25D366;
    color: white;
}

.ht-share-email {
    background: var(--ht-glass-medium);
    color: var(--ht-text-primary);
    border: 1px solid var(--ht-glass-border);
}

.ht-share-native {
    background: var(--ht-primary);
    color: white;
}

.ht-share-btn:hover {
    transform: translateY(-2px);
    filter: brightness(1.1);
}

.ht-invite-form .ht-form-row {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

.ht-members-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ht-member-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--ht-glass-light);
    border: 1px solid var(--ht-glass-border);
    border-radius: var(--ht-radius-md);
}

.ht-member-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
}

.ht-member-info {
    flex: 1;
    min-width: 0;
}

.ht-member-name {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: var(--ht-text-primary);
}

.ht-member-email {
    display: block;
    font-size: 12px;
    color: var(--ht-text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
}

.ht-member-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.ht-member-role {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 4px;
}

.ht-role-owner { background: rgba(102, 126, 234, 0.2); color: #667eea; }
.ht-role-editor { background: rgba(247, 151, 30, 0.2); color: #f7971e; }
.ht-role-viewer { background: rgba(255, 255, 255, 0.1); color: var(--ht-text-muted); }

.ht-member-status {
    font-size: 10px;
    color: var(--ht-text-muted);
}

.ht-status-joined { color: #38ef7d; }
.ht-status-invited { color: #ffd200; }
.ht-status-declined { color: #ff416c; }

.ht-member-remove {
    background: none;
    border: none;
    color: var(--ht-text-muted);
    font-size: 20px;
    cursor: pointer;
    padding: 4px 8px;
    margin-left: 8px;
    opacity: 0.5;
    transition: var(--ht-transition);
}

.ht-member-remove:hover {
    color: #ff416c;
    opacity: 1;
}

.ht-section-description {
    font-size: 14px;
    color: var(--ht-text-secondary);
    margin: 0 0 16px 0;
}

@media (max-width: 600px) {
    .ht-invite-form .ht-form-row {
        flex-direction: column;
    }

    .ht-invite-form .ht-form-group {
        width: 100%;
    }

    .ht-share-link-box {
        flex-direction: column;
    }
}
</style>
