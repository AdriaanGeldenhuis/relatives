/* The footer Help Center keeps the chat open while questions are answered. */
class HelpSystem {
    static instance = null;

    static getInstance() {
        return this.instance ??= new this();
    }

    constructor() {
        this.trigger = document.getElementById('helpBtn');
        this.isLoading = false;
        if (!this.trigger) return;

        this.injectHTML();
        this.attachListeners();
        this.updateComposer();
    }

    injectHTML() {
        document.body.insertAdjacentHTML('beforeend', `
            <dialog class="footer-assist-dialog help-panel" id="helpPanel" aria-labelledby="helpHeading">
                <div class="assist-panel">
                    <header class="assist-header">
                        <span class="assist-header-icon" aria-hidden="true">?</span>
                        <div>
                            <h2 id="helpHeading">Help Center</h2>
                            <p>App answers from Suzi</p>
                        </div>
                        <button type="button" class="assist-close" aria-label="Close help">×</button>
                    </header>

                    <div class="assist-scroll help-chat">
                        <div class="help-welcome">
                            <p>Choose a topic or ask your own question about Relatives.</p>
                            <div class="assist-prompts">
                                <button type="button" class="assist-prompt" data-question="How do I add a shopping item?">
                                    <span aria-hidden="true">🛒</span><span>Add a shopping item</span>
                                </button>
                                <button type="button" class="assist-prompt" data-question="How do I enable location tracking?">
                                    <span aria-hidden="true">📍</span><span>Enable location tracking</span>
                                </button>
                                <button type="button" class="assist-prompt" data-question="How do I use voice commands?">
                                    <span aria-hidden="true">🎤</span><span>Use voice commands</span>
                                </button>
                                <button type="button" class="assist-prompt" data-question="Why can't I see weather data?">
                                    <span aria-hidden="true">🌤️</span><span>Weather not showing</span>
                                </button>
                            </div>
                        </div>
                        <div class="help-thread" role="log" aria-label="Help conversation"></div>
                        <p class="help-loading" role="status" hidden>Suzi is looking up an answer…</p>
                        <p class="help-error" role="alert" hidden></p>
                    </div>

                    <form class="help-input-wrapper">
                        <textarea id="helpInput" class="help-input" aria-label="Your question"
                                  placeholder="Ask about Relatives…" rows="1" maxlength="500"></textarea>
                        <button type="submit" class="help-send-btn">Send</button>
                    </form>
                </div>
            </dialog>
        `);

        this.panel = document.getElementById('helpPanel');
        this.closeButton = this.panel.querySelector('.assist-close');
        this.chat = this.panel.querySelector('.help-chat');
        this.thread = this.panel.querySelector('.help-thread');
        this.loading = this.panel.querySelector('.help-loading');
        this.error = this.panel.querySelector('.help-error');
        this.input = this.panel.querySelector('.help-input');
        this.sendButton = this.panel.querySelector('.help-send-btn');
        this.prompts = this.panel.querySelectorAll('[data-question]');
    }

    attachListeners() {
        this.trigger.addEventListener('click', () => this.open());
        this.closeButton.addEventListener('click', () => this.close());
        this.panel.addEventListener('click', event => {
            if (event.target === this.panel) this.close();
        });
        this.panel.addEventListener('cancel', event => {
            event.preventDefault();
            this.close();
        });

        const viewport = window.visualViewport;
        if (viewport) {
            const fitPanel = () => {
                if (!this.panel.open || viewport.scale !== 1) return;
                const cutoff = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
                this.panel.style.setProperty('--assist-viewport-height', viewport.height + 'px');
                this.panel.style.setProperty('--assist-viewport-cutoff', cutoff + 'px');
            };
            viewport.addEventListener('resize', fitPanel);
            viewport.addEventListener('scroll', fitPanel);
        }

        this.panel.querySelector('form').addEventListener('submit', event => {
            event.preventDefault();
            this.sendMessage();
        });
        this.input.addEventListener('input', () => this.updateComposer());
        this.input.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                this.sendMessage();
            }
        });
        this.prompts.forEach(button => button.addEventListener('click', () => {
            this.sendMessage(button.dataset.question);
        }));
    }

    open() {
        if (!this.panel || this.panel.open) return;
        window.closePlusMenu?.();
        this.panel.showModal();
        this.trigger.setAttribute('aria-expanded', 'true');
        if (this.thread.childElementCount) this.scrollToBottom();
    }

    close() {
        if (!this.panel?.open) return;
        this.panel.close();
        this.panel.style.removeProperty('--assist-viewport-height');
        this.panel.style.removeProperty('--assist-viewport-cutoff');
        this.trigger.setAttribute('aria-expanded', 'false');
        this.trigger.focus({ preventScroll: true });
    }

    updateComposer() {
        this.input.style.height = 'auto';
        this.input.style.height = Math.min(this.input.scrollHeight + 2, 108) + 'px';
        this.sendButton.disabled = this.isLoading || !this.input.value.trim();
    }

    setLoading(loading) {
        this.isLoading = loading;
        this.loading.hidden = !loading;
        this.prompts.forEach(button => { button.disabled = loading; });
        this.updateComposer();
        this.scrollToBottom();
    }

    async sendMessage(question) {
        const message = (question ?? this.input.value).trim();
        if (!message || this.isLoading) return;

        if (question === undefined) this.input.value = '';
        this.error.hidden = true;
        this.addMessage('user', message);
        this.setLoading(true);

        try {
            const response = await fetch('/help/api/ask.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message })
            });
            const data = await response.json();
            if (response.ok && data.ok) {
                this.addMessage('assistant', data.answer);
            } else {
                this.showError(data.error || 'Suzi could not answer. Try again in a moment.');
            }
        } catch (error) {
            this.showError('Could not reach Suzi. Check your connection and try again.');
        } finally {
            this.setLoading(false);
        }
    }

    addMessage(type, content) {
        const message = document.createElement('div');
        message.className = 'help-message ' + type;
        const label = document.createElement('span');
        label.className = 'help-message-label';
        label.textContent = type === 'user' ? 'You' : 'Suzi';
        const bubble = document.createElement('p');
        bubble.className = 'help-message-bubble';
        bubble.textContent = content;
        message.append(label, bubble);
        this.thread.append(message);
    }

    showError(message) {
        this.error.textContent = message;
        this.error.hidden = false;
    }

    scrollToBottom() {
        this.chat.scrollTop = this.chat.scrollHeight;
    }
}

window.HelpSystem = HelpSystem;
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => HelpSystem.getInstance(), { once: true });
} else {
    HelpSystem.getInstance();
}
