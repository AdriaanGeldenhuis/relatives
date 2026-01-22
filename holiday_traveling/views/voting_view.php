<?php
/**
 * Holiday Traveling - Voting/Polls View
 * Full implementation - Phase 7
 */
?>

<div class="ht-voting-page">
    <!-- Page Header -->
    <div class="ht-page-header">
        <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-back-link">← Back to Trip</a>
        <div class="ht-page-header-content">
            <h1 class="ht-page-title">
                <span class="ht-title-icon">🗳️</span>
                Group Voting
            </h1>
            <p class="ht-page-subtitle"><?php echo htmlspecialchars($trip['destination']); ?></p>
        </div>
        <?php if ($canEdit): ?>
        <button id="createPollBtn" class="ht-btn ht-btn-primary">
            <span class="ht-btn-icon">+</span>
            Create Poll
        </button>
        <?php endif; ?>
    </div>

    <!-- Voting Info -->
    <div class="ht-voting-info">
        <div class="ht-info-card">
            <span class="ht-info-icon">❤️</span>
            <span class="ht-info-text"><strong>Love</strong> = +2 points</span>
        </div>
        <div class="ht-info-card">
            <span class="ht-info-icon">😐</span>
            <span class="ht-info-text"><strong>Meh</strong> = +1 point</span>
        </div>
        <div class="ht-info-card">
            <span class="ht-info-icon">👎</span>
            <span class="ht-info-text"><strong>No</strong> = -1 point</span>
        </div>
    </div>

    <!-- Polls Container -->
    <div id="pollsContainer" class="ht-polls-container">
        <div class="ht-loading-state">
            <div class="ht-spinner"></div>
            <p>Loading polls...</p>
        </div>
    </div>
</div>

<!-- Create Poll Modal -->
<div id="pollModal" class="ht-modal" style="display: none;">
    <div class="ht-modal-backdrop"></div>
    <div class="ht-modal-content">
        <h3 class="ht-modal-title">Create Poll</h3>
        <form id="pollForm">
            <div class="ht-form-group">
                <label class="ht-label">Question *</label>
                <input type="text" name="title" class="ht-input" placeholder="e.g., Which hotel should we book?" required maxlength="255">
            </div>
            <div class="ht-form-group">
                <label class="ht-label">Description (optional)</label>
                <textarea name="description" class="ht-textarea" rows="2" placeholder="Add more context for voters..." maxlength="1000"></textarea>
            </div>
            <div class="ht-form-group">
                <label class="ht-label">Options *</label>
                <div id="optionsContainer" class="ht-options-container">
                    <div class="ht-option-input">
                        <input type="text" name="options[]" class="ht-input" placeholder="Option 1" required>
                        <button type="button" class="ht-remove-option-btn" disabled>×</button>
                    </div>
                    <div class="ht-option-input">
                        <input type="text" name="options[]" class="ht-input" placeholder="Option 2" required>
                        <button type="button" class="ht-remove-option-btn" disabled>×</button>
                    </div>
                </div>
                <button type="button" id="addOptionBtn" class="ht-btn ht-btn-outline ht-btn-sm">
                    + Add Option
                </button>
            </div>
            <div class="ht-form-group">
                <label class="ht-label">Close poll on (optional)</label>
                <input type="datetime-local" name="closes_at" class="ht-input">
                <p class="ht-form-hint">Leave empty for no deadline</p>
            </div>
            <div class="ht-modal-actions">
                <button type="button" class="ht-btn ht-btn-outline" data-action="cancel">Cancel</button>
                <button type="submit" class="ht-btn ht-btn-primary">Create Poll</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    'use strict';

    const tripId = <?php echo $trip['id']; ?>;

    // Initialize Voting UI when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => HT.VotingUI.init(tripId));
    } else {
        HT.VotingUI.init(tripId);
    }
})();
</script>

<style>
.ht-voting-info {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.ht-info-card {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-md);
    font-size: 13px;
}

.ht-info-icon { font-size: 18px; }
.ht-info-text { color: var(--ht-text-secondary); }

.ht-polls-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.ht-poll-card {
    background: var(--ht-glass-medium);
    border: 1px solid var(--ht-glass-border);
    border-radius: var(--ht-radius-lg);
    overflow: hidden;
}

.ht-poll-header {
    padding: 20px;
    border-bottom: 1px solid var(--ht-glass-border);
}

.ht-poll-title-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}

.ht-poll-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--ht-text-primary);
    margin: 0;
}

.ht-poll-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.ht-poll-badge.open {
    background: rgba(56, 239, 125, 0.2);
    color: #38ef7d;
}

.ht-poll-badge.closed {
    background: rgba(150, 150, 150, 0.2);
    color: #999;
}

.ht-poll-badge.expired {
    background: rgba(255, 68, 68, 0.2);
    color: #ff6666;
}

.ht-poll-description {
    font-size: 14px;
    color: var(--ht-text-secondary);
    margin: 0 0 12px 0;
}

.ht-poll-meta {
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: var(--ht-text-muted);
}

.ht-poll-options {
    padding: 16px 20px;
}

.ht-poll-option {
    padding: 16px;
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-md);
    margin-bottom: 12px;
    border: 2px solid transparent;
    transition: var(--ht-transition);
}

.ht-poll-option:last-child { margin-bottom: 0; }

.ht-poll-option.winner {
    border-color: #38ef7d;
    background: rgba(56, 239, 125, 0.1);
}

.ht-option-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}

.ht-option-label {
    font-size: 15px;
    font-weight: 600;
    color: var(--ht-text-primary);
}

.ht-winner-badge {
    padding: 2px 8px;
    background: #38ef7d;
    color: #000;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

.ht-option-description {
    font-size: 13px;
    color: var(--ht-text-muted);
    margin: 0 0 12px 0;
}

.ht-option-votes {
    display: flex;
    gap: 16px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.ht-vote-count {
    font-size: 13px;
    color: var(--ht-text-secondary);
}

.ht-vote-score {
    font-size: 13px;
    font-weight: 600;
    color: var(--ht-text-primary);
    margin-left: auto;
}

.ht-vote-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.ht-vote-btn {
    padding: 8px 16px;
    border: 1px solid var(--ht-glass-border);
    background: var(--ht-glass-medium);
    border-radius: var(--ht-radius-sm);
    font-size: 13px;
    color: var(--ht-text-secondary);
    cursor: pointer;
    transition: var(--ht-transition);
}

.ht-vote-btn:hover {
    background: var(--ht-glass-heavy);
}

.ht-vote-btn.active {
    background: var(--ht-primary);
    color: #fff;
    border-color: var(--ht-primary);
}

.ht-poll-actions {
    display: flex;
    gap: 8px;
    padding: 16px 20px;
    border-top: 1px solid var(--ht-glass-border);
    justify-content: flex-end;
}

.ht-poll-actions .danger {
    color: #ff4444;
    border-color: rgba(255, 68, 68, 0.3);
}

.ht-poll-actions .danger:hover {
    background: rgba(255, 68, 68, 0.1);
}

/* Options input */
.ht-options-container {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 12px;
}

.ht-option-input {
    display: flex;
    gap: 8px;
}

.ht-option-input .ht-input {
    flex: 1;
}

.ht-remove-option-btn {
    width: 36px;
    height: 36px;
    padding: 0;
    border: 1px solid var(--ht-glass-border);
    background: var(--ht-glass-light);
    border-radius: var(--ht-radius-sm);
    color: var(--ht-text-muted);
    font-size: 18px;
    cursor: pointer;
    transition: var(--ht-transition);
}

.ht-remove-option-btn:hover:not(:disabled) {
    background: rgba(255, 68, 68, 0.1);
    color: #ff4444;
    border-color: rgba(255, 68, 68, 0.3);
}

.ht-remove-option-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.ht-form-hint {
    font-size: 12px;
    color: var(--ht-text-muted);
    margin: 4px 0 0 0;
}

.ht-btn-sm {
    padding: 6px 12px;
    font-size: 13px;
}

/* Loading state */
.ht-loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 40px;
    color: var(--ht-text-muted);
}

.ht-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid var(--ht-glass-border);
    border-top-color: var(--ht-primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 12px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Empty state adjustments */
.ht-empty-state {
    padding: 48px 24px;
    text-align: center;
}

.ht-error-state {
    padding: 24px;
    text-align: center;
    color: #ff6666;
}

@media (max-width: 480px) {
    .ht-voting-info {
        flex-direction: column;
    }

    .ht-vote-buttons {
        flex-direction: column;
    }

    .ht-vote-btn {
        width: 100%;
        justify-content: center;
    }

    .ht-poll-actions {
        flex-direction: column;
    }

    .ht-poll-actions button {
        width: 100%;
    }
}
</style>
