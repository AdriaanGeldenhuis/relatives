/**
 * Holiday Traveling - Voting/Polls JavaScript
 * Full implementation - Phase 7
 */
(function() {
    'use strict';

    window.HT = window.HT || {};

    /**
     * Voting UI Manager
     */
    HT.VotingUI = {
        tripId: null,
        polls: [],

        /**
         * Initialize the voting UI
         */
        init(tripId) {
            this.tripId = tripId;
            this.setupEventListeners();
            this.loadPolls();
        },

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            const createBtn = document.getElementById('createPollBtn');
            const modal = document.getElementById('pollModal');
            const form = document.getElementById('pollForm');

            // Open create modal
            createBtn?.addEventListener('click', () => {
                this.resetForm();
                document.querySelector('#pollModal .ht-modal-title').textContent = 'Create Poll';
                modal.style.display = 'flex';
            });

            // Close modal
            modal?.querySelector('.ht-modal-backdrop')?.addEventListener('click', () => {
                modal.style.display = 'none';
            });
            modal?.querySelector('[data-action="cancel"]')?.addEventListener('click', () => {
                modal.style.display = 'none';
            });

            // Form submission
            form?.addEventListener('submit', (e) => this.handleFormSubmit(e));

            // Add option button
            document.getElementById('addOptionBtn')?.addEventListener('click', () => {
                this.addOptionField();
            });
        },

        /**
         * Load polls from API
         */
        async loadPolls() {
            const container = document.getElementById('pollsContainer');
            if (!container) return;

            try {
                const response = await fetch(`/holiday_traveling/api/votes_list.php?trip_id=${this.tripId}`);
                const result = await response.json();

                if (result.success) {
                    this.polls = result.data.polls;
                    this.renderPolls();
                }
            } catch (error) {
                console.error('Failed to load polls:', error);
                container.innerHTML = `
                    <div class="ht-error-state">
                        <p>Failed to load polls. Please refresh the page.</p>
                    </div>
                `;
            }
        },

        /**
         * Render polls list
         */
        renderPolls() {
            const container = document.getElementById('pollsContainer');
            if (!container) return;

            if (this.polls.length === 0) {
                container.innerHTML = `
                    <div class="ht-empty-state">
                        <div class="ht-empty-icon">🗳️</div>
                        <h3 class="ht-empty-title">No polls yet</h3>
                        <p class="ht-empty-description">
                            Create a poll to let your travel group vote on destinations, activities, or dates.
                        </p>
                    </div>
                `;
                return;
            }

            container.innerHTML = this.polls.map(poll => this.renderPollCard(poll)).join('');

            // Setup vote buttons
            container.querySelectorAll('.ht-vote-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const { pollId, optionId, value } = btn.dataset;
                    this.submitVote(pollId, optionId, value);
                });
            });

            // Setup close poll buttons
            container.querySelectorAll('.ht-close-poll-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.closePoll(btn.dataset.pollId);
                });
            });

            // Setup delete poll buttons
            container.querySelectorAll('.ht-delete-poll-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.deletePoll(btn.dataset.pollId);
                });
            });
        },

        /**
         * Render a single poll card
         */
        renderPollCard(poll) {
            const isClosed = poll.status === 'closed';
            const isExpired = poll.is_expired;

            let statusBadge = '';
            if (isClosed) {
                statusBadge = '<span class="ht-poll-badge closed">Closed</span>';
            } else if (isExpired) {
                statusBadge = '<span class="ht-poll-badge expired">Expired</span>';
            } else {
                statusBadge = '<span class="ht-poll-badge open">Open</span>';
            }

            const optionsHtml = poll.options.map(option => {
                const userVote = poll.user_votes[option.id];
                const isWinner = poll.winning_option_id === option.id;

                return `
                    <div class="ht-poll-option ${isWinner ? 'winner' : ''}" data-option-id="${option.id}">
                        <div class="ht-option-header">
                            <span class="ht-option-label">${this.escapeHtml(option.label)}</span>
                            ${isWinner ? '<span class="ht-winner-badge">Winner</span>' : ''}
                        </div>
                        ${option.description ? `<p class="ht-option-description">${this.escapeHtml(option.description)}</p>` : ''}

                        <div class="ht-option-votes">
                            <span class="ht-vote-count love" title="Love it">❤️ ${option.votes?.love || 0}</span>
                            <span class="ht-vote-count meh" title="It's okay">😐 ${option.votes?.meh || 0}</span>
                            <span class="ht-vote-count no" title="Not for me">👎 ${option.votes?.no || 0}</span>
                            <span class="ht-vote-score" title="Score">Score: ${option.score || 0}</span>
                        </div>

                        ${!isClosed && !isExpired ? `
                            <div class="ht-vote-buttons">
                                <button class="ht-vote-btn ${userVote?.vote === 'love' ? 'active' : ''}"
                                        data-poll-id="${poll.id}" data-option-id="${option.id}" data-value="love">
                                    ❤️ Love
                                </button>
                                <button class="ht-vote-btn ${userVote?.vote === 'meh' ? 'active' : ''}"
                                        data-poll-id="${poll.id}" data-option-id="${option.id}" data-value="meh">
                                    😐 Meh
                                </button>
                                <button class="ht-vote-btn ${userVote?.vote === 'no' ? 'active' : ''}"
                                        data-poll-id="${poll.id}" data-option-id="${option.id}" data-value="no">
                                    👎 No
                                </button>
                            </div>
                        ` : ''}
                    </div>
                `;
            }).join('');

            return `
                <div class="ht-poll-card" data-poll-id="${poll.id}">
                    <div class="ht-poll-header">
                        <div class="ht-poll-title-row">
                            <h3 class="ht-poll-title">${this.escapeHtml(poll.title)}</h3>
                            ${statusBadge}
                        </div>
                        ${poll.description ? `<p class="ht-poll-description">${this.escapeHtml(poll.description)}</p>` : ''}
                        <div class="ht-poll-meta">
                            <span>Created by ${this.escapeHtml(poll.creator_name)}</span>
                            ${poll.closes_at ? `<span>Closes: ${this.formatDate(poll.closes_at)}</span>` : ''}
                        </div>
                    </div>

                    <div class="ht-poll-options">
                        ${optionsHtml}
                    </div>

                    <div class="ht-poll-actions">
                        ${!isClosed && !isExpired ? `
                            <button class="ht-btn ht-btn-outline ht-close-poll-btn" data-poll-id="${poll.id}">
                                Close Poll
                            </button>
                        ` : ''}
                        <button class="ht-btn ht-btn-outline danger ht-delete-poll-btn" data-poll-id="${poll.id}">
                            Delete
                        </button>
                    </div>
                </div>
            `;
        },

        /**
         * Reset the poll creation form
         */
        resetForm() {
            const form = document.getElementById('pollForm');
            if (form) {
                form.reset();
                // Reset to 2 option fields
                const optionsContainer = document.getElementById('optionsContainer');
                if (optionsContainer) {
                    optionsContainer.innerHTML = `
                        <div class="ht-option-input">
                            <input type="text" name="options[]" class="ht-input" placeholder="Option 1" required>
                            <button type="button" class="ht-remove-option-btn" disabled>×</button>
                        </div>
                        <div class="ht-option-input">
                            <input type="text" name="options[]" class="ht-input" placeholder="Option 2" required>
                            <button type="button" class="ht-remove-option-btn" disabled>×</button>
                        </div>
                    `;
                    this.updateRemoveButtons();
                }
            }
        },

        /**
         * Add a new option input field
         */
        addOptionField() {
            const container = document.getElementById('optionsContainer');
            if (!container) return;

            const count = container.querySelectorAll('.ht-option-input').length;
            if (count >= 10) {
                HT.Toast.warning('Maximum 10 options allowed');
                return;
            }

            const div = document.createElement('div');
            div.className = 'ht-option-input';
            div.innerHTML = `
                <input type="text" name="options[]" class="ht-input" placeholder="Option ${count + 1}" required>
                <button type="button" class="ht-remove-option-btn">×</button>
            `;

            div.querySelector('.ht-remove-option-btn').addEventListener('click', () => {
                div.remove();
                this.updateRemoveButtons();
            });

            container.appendChild(div);
            this.updateRemoveButtons();
            div.querySelector('input').focus();
        },

        /**
         * Update remove buttons state (disable if only 2 options)
         */
        updateRemoveButtons() {
            const container = document.getElementById('optionsContainer');
            if (!container) return;

            const buttons = container.querySelectorAll('.ht-remove-option-btn');
            const canRemove = buttons.length > 2;

            buttons.forEach(btn => {
                btn.disabled = !canRemove;
                if (canRemove && !btn.onclick) {
                    btn.addEventListener('click', () => {
                        btn.closest('.ht-option-input').remove();
                        this.updateRemoveButtons();
                    });
                }
            });
        },

        /**
         * Handle form submission
         */
        async handleFormSubmit(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');

            const options = formData.getAll('options[]').filter(o => o.trim());

            if (options.length < 2) {
                HT.Toast.error('At least 2 options are required');
                return;
            }

            const data = {
                trip_id: this.tripId,
                title: formData.get('title'),
                description: formData.get('description') || null,
                options: options,
                closes_at: formData.get('closes_at') || null
            };

            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating...';

            try {
                const response = await fetch('/holiday_traveling/api/votes_create.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    HT.Toast.success('Poll created!');
                    document.getElementById('pollModal').style.display = 'none';
                    this.loadPolls(); // Reload polls
                } else {
                    HT.Toast.error(result.error || 'Failed to create poll');
                }
            } catch (error) {
                console.error('Error creating poll:', error);
                HT.Toast.error('Network error. Please try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Poll';
            }
        },

        /**
         * Submit a vote
         */
        async submitVote(pollId, optionId, value) {
            try {
                const response = await fetch('/holiday_traveling/api/votes_respond.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify({
                        vote_id: parseInt(pollId),
                        option_id: optionId,
                        vote_value: value
                    })
                });

                const result = await response.json();

                if (result.success) {
                    HT.Toast.success('Vote recorded!');
                    this.loadPolls(); // Reload to show updated counts
                } else {
                    HT.Toast.error(result.error || 'Failed to submit vote');
                }
            } catch (error) {
                console.error('Error submitting vote:', error);
                HT.Toast.error('Network error. Please try again.');
            }
        },

        /**
         * Close a poll
         */
        async closePoll(pollId) {
            if (!confirm('Are you sure you want to close this poll? This will determine the winner.')) {
                return;
            }

            try {
                const response = await fetch('/holiday_traveling/api/votes_close.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify({ vote_id: parseInt(pollId) })
                });

                const result = await response.json();

                if (result.success) {
                    HT.Toast.success('Poll closed! Winner: ' + (result.data.winning_option?.label || 'No winner'));
                    this.loadPolls();
                } else {
                    HT.Toast.error(result.error || 'Failed to close poll');
                }
            } catch (error) {
                console.error('Error closing poll:', error);
                HT.Toast.error('Network error. Please try again.');
            }
        },

        /**
         * Delete a poll
         */
        async deletePoll(pollId) {
            if (!confirm('Are you sure you want to delete this poll? This cannot be undone.')) {
                return;
            }

            try {
                const response = await fetch('/holiday_traveling/api/votes_delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': HT.getCSRFToken()
                    },
                    body: JSON.stringify({ vote_id: parseInt(pollId) })
                });

                const result = await response.json();

                if (result.success) {
                    HT.Toast.success('Poll deleted!');
                    this.loadPolls();
                } else {
                    HT.Toast.error(result.error || 'Failed to delete poll');
                }
            } catch (error) {
                console.error('Error deleting poll:', error);
                HT.Toast.error('Network error. Please try again.');
            }
        },

        /**
         * Format date for display
         */
        formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

})();
