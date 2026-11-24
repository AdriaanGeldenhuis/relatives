const helpGuides = {
    shopping: {
        icon: '🛒',
        title: 'Add a shopping item',
        summary: 'Lists, quantities and prices',
        keywords: 'groceries checklist cost',
        steps: [
            'Open Shopping and select the list you want to use.',
            'Select Add item and enter the item name. Add a quantity, price or category if you need one.',
            'Submit the form to add the item to your list.'
        ],
        href: '/shopping/',
        action: 'Open Shopping'
    },
    location: {
        icon: '📍',
        title: 'Share your location',
        summary: 'Set up live family tracking',
        keywords: 'find map gps permissions enable',
        steps: [
            'Open Family Tracking and select Enable live location.',
            'Allow location access when your device or browser asks.',
            'Use Stop sharing in Family Tracking when you want to turn sharing off.'
        ],
        href: '/tracking/app/',
        action: 'Open Family Tracking'
    },
    voice: {
        icon: '🎙️',
        title: 'Use voice commands',
        summary: 'Talk to Suzi hands-free',
        keywords: 'microphone audio commands permission',
        steps: [
            'Open Suzi and tap the microphone. Allow microphone access if prompted.',
            'Wait for Listening, then try “Open the shopping list” or ask a question.',
            'Tap the circle to stop listening or speaking. You can also tap a sample command to skip the microphone.'
        ],
        action: 'Open Suzi'
    },
    weather: {
        icon: '🌤️',
        title: 'Weather not showing',
        summary: 'Find or refresh a forecast',
        keywords: 'rain temperature city town location',
        steps: [
            'Open Weather and search for a city or town, or choose Use my location.',
            'If location access is blocked, search for your town to get its forecast.',
            'If a forecast is already on screen, select Refresh to reload it.'
        ],
        href: '/weather/',
        action: 'Open Weather'
    }
};

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

                    <div class="help-tabs" role="tablist" aria-label="Help options">
                        <button type="button" id="helpGuidesTab" role="tab" aria-controls="helpGuides" aria-selected="true">Quick guides</button>
                        <button type="button" id="helpChatTab" role="tab" aria-controls="helpChat" aria-selected="false" tabindex="-1">Ask Suzi</button>
                    </div>

                    <section class="assist-scroll help-guides" id="helpGuides" role="tabpanel" aria-labelledby="helpGuidesTab">
                        <div class="help-guide-overview">
                            <label class="help-search">
                                <svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                    <circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>
                                </svg>
                                <input type="search" id="helpSearch" aria-label="Search help guides" placeholder="Find a guide…" autocomplete="off">
                            </label>
                            <div class="assist-prompts">
                                ${Object.entries(helpGuides).map(([id, guide]) => `
                                    <button type="button" class="assist-prompt help-guide-tile" data-guide="${id}">
                                        <span class="assist-tile-icon" aria-hidden="true">${guide.icon}</span>
                                        <span><strong>${guide.title}</strong><small>${guide.summary}</small></span>
                                    </button>
                                `).join('')}
                            </div>
                            <p class="help-guide-empty" role="status" hidden>No matching guides. Try a different search or ask Suzi.</p>
                        </div>

                        <article class="help-guide-detail" hidden>
                            <button type="button" class="assist-secondary help-guide-back">← All guides</button>
                            <h3 class="help-guide-title" tabindex="-1"></h3>
                            <ol class="help-guide-steps"></ol>
                            <div class="help-guide-actions">
                                <a class="assist-primary help-guide-link" hidden></a>
                                <button type="button" class="assist-primary help-guide-voice" hidden>Open Suzi</button>
                                <button type="button" class="assist-secondary help-guide-ask">Ask about this</button>
                            </div>
                        </article>
                    </section>

                    <section class="help-chat-view" id="helpChat" role="tabpanel" aria-labelledby="helpChatTab" hidden>
                        <div class="assist-scroll help-chat">
                            <p class="help-chat-intro">Ask Suzi how to do something in Relatives.</p>
                            <div class="help-thread" role="log" aria-label="Help conversation"></div>
                            <p class="help-loading" role="status" hidden><span aria-hidden="true"></span>Suzi is looking up an answer…</p>
                            <p class="help-error" role="alert" hidden></p>
                        </div>
                        <form class="help-input-wrapper">
                            <textarea id="helpInput" class="help-input" aria-label="Your question"
                                      placeholder="Ask about Relatives…" rows="1" maxlength="500"></textarea>
                            <button type="submit" class="assist-primary help-send-btn">Send</button>
                        </form>
                    </section>
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
        this.tabs = Array.from(this.panel.querySelectorAll('[role="tab"]'));
        this.guideOverview = this.panel.querySelector('.help-guide-overview');
        this.guideDetail = this.panel.querySelector('.help-guide-detail');
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
        this.tabs.forEach(tab => {
            tab.addEventListener('click', () => this.selectTab(tab));
            tab.addEventListener('keydown', event => {
                const index = this.tabs.indexOf(tab);
                const nextIndex = { ArrowLeft: 1 - index, ArrowRight: 1 - index, Home: 0, End: 1 }[event.key];
                if (nextIndex === undefined) return;
                event.preventDefault();
                this.selectTab(this.tabs[nextIndex]);
                this.tabs[nextIndex].focus();
            });
        });

        const search = this.panel.querySelector('#helpSearch');
        const guideTiles = this.guideOverview.querySelectorAll('[data-guide]');
        search.addEventListener('input', () => {
            const words = search.value.toLowerCase().trim().split(/\s+/);
            let hasResults = false;
            guideTiles.forEach(tile => {
                const { title, summary, keywords } = helpGuides[tile.dataset.guide];
                const searchable = [title, summary, keywords].join(' ').toLowerCase();
                tile.hidden = !words.every(word => searchable.includes(word));
                hasResults ||= !tile.hidden;
            });
            this.guideOverview.querySelector('.help-guide-empty').hidden = hasResults;
        });
        guideTiles.forEach(tile => tile.addEventListener('click', () => this.showGuide(tile)));
        this.guideDetail.querySelector('.help-guide-back').addEventListener('click', () => {
            this.guideDetail.hidden = true;
            this.guideOverview.hidden = false;
            this.activeGuideTile.focus();
        });
        this.guideDetail.querySelector('.help-guide-voice').addEventListener('click', () => {
            this.close();
            window.SuziVoice.open();
        });
        this.guideDetail.querySelector('.help-guide-ask').addEventListener('click', () => {
            this.selectTab(this.tabs[1]);
            this.tabs[1].focus();
            this.sendMessage('Can you tell me more about this Relatives guide? ' + helpGuides[this.activeGuideTile.dataset.guide].title);
        });
    }

    selectTab(selectedTab) {
        this.tabs.forEach(tab => {
            const selected = tab === selectedTab;
            tab.tabIndex = selected ? 0 : -1;
            tab.setAttribute('aria-selected', String(selected));
            document.getElementById(tab.getAttribute('aria-controls')).hidden = !selected;
        });
        if (selectedTab === this.tabs[1]) {
            this.updateComposer();
            this.scrollToBottom();
        }
    }

    showGuide(tile) {
        const guide = helpGuides[tile.dataset.guide];
        const title = this.guideDetail.querySelector('.help-guide-title');
        const link = this.guideDetail.querySelector('.help-guide-link');
        const voice = this.guideDetail.querySelector('.help-guide-voice');
        title.textContent = guide.title;
        link.hidden = !guide.href;
        voice.hidden = Boolean(guide.href);
        if (guide.href) {
            link.href = guide.href;
            link.textContent = guide.action;
        }

        const steps = guide.steps.map(text => {
            const step = document.createElement('li');
            step.textContent = text;
            return step;
        });
        this.guideDetail.querySelector('.help-guide-steps').replaceChildren(...steps);
        this.activeGuideTile = tile;
        this.guideOverview.hidden = true;
        this.guideDetail.hidden = false;
        title.focus();
    }

    open() {
        if (!this.panel || this.panel.open) return;
        window.closePlusMenu?.();
        this.panel.showModal();
        this.trigger.setAttribute('aria-expanded', 'true');
        if (this.tabs[1].getAttribute('aria-selected') === 'true') this.selectTab(this.tabs[1]);
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
