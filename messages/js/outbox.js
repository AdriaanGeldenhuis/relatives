'use strict';

class ChatOutbox {
    constructor(account, csrf, onChange, onSent) {
        this.account = account;
        this.csrf = csrf;
        this.onChange = onChange;
        this.onSent = onSent;
        this.items = new Map();
        this.sending = null;
        this.request = null;
        this.retryTimer = null;
        this.retryDelay = 2000;
        this.paused = false;
        this.storage = this.openStorage().catch(() => null);
        this.ready = this.restore();
    }

    openStorage() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open('relatives-chat', 1);
            request.onupgradeneeded = () => {
                request.result.createObjectStore('outbox', { keyPath: 'id' }).createIndex('account', 'account');
            };
            request.onsuccess = () => {
                request.result.onversionchange = () => request.result.close();
                resolve(request.result);
            };
            request.onerror = request.onblocked = () => reject(request.error);
        });
    }

    async restore() {
        const db = await this.storage;
        if (!db) return;
        const items = await new Promise(resolve => {
            const request = db.transaction('outbox').objectStore('outbox').index('account').getAll(this.account);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => resolve([]);
        }).catch(() => []);
        items.sort((first, second) => first.createdAt - second.createdAt);
        for (const item of items) this.items.set(item.id, item);
        this.onChange();
    }

    async write(action, value) {
        const db = await this.storage;
        if (!db) return false;
        return new Promise(resolve => {
            const transaction = db.transaction('outbox', 'readwrite');
            transaction.oncomplete = () => resolve(true);
            transaction.onabort = () => resolve(false);
            transaction.objectStore('outbox')[action](value);
        }).catch(() => false);
    }

    async add(content, reply, media) {
        await this.ready;
        const item = {
            id: crypto.randomUUID(), account: this.account, createdAt: Date.now(),
            content, reply, media, error: ''
        };
        const saved = await this.write('put', item);
        this.items.set(item.id, item);
        this.onChange();
        void this.flush();
        return saved;
    }

    async remove(id) {
        if (this.sending === id) return;
        this.items.delete(id);
        this.onChange();
        await this.write('delete', id);
    }

    async retry(id) {
        const item = this.items.get(id);
        if (!item) return;
        item.error = '';
        await this.write('put', item);
        this.resume();
    }

    resume() {
        clearTimeout(this.retryTimer);
        this.retryTimer = null;
        void this.flush();
    }

    pause() {
        this.paused = true;
        clearTimeout(this.retryTimer);
        this.request?.abort();
    }

    pendingIds() {
        return [...this.items.keys()].slice(0, 20);
    }

    confirm(ids) {
        let changed = false;
        for (const id of ids) {
            if (!this.items.delete(id)) continue;
            changed = true;
            if (id === this.sending) this.request?.abort();
            void this.write('delete', id);
        }
        if (changed) this.onChange();
    }

    async flush() {
        await this.ready;
        if (this.sending || this.paused || this.retryTimer || document.hidden || !navigator.onLine) return;
        const item = [...this.items.values()].find(draft => !draft.error);
        if (!item) return;

        this.sending = item.id;
        this.onChange();
        this.request = new AbortController();
        const timeout = setTimeout(() => this.request?.abort(), 45000);
        let retry = false;
        let retryAfter = 0;
        try {
            const form = new FormData();
            form.set('client_message_id', item.id);
            form.set('family_id', this.account.split(':')[0]);
            form.set('content', item.content);
            if (item.reply) form.set('reply_to_message_id', item.reply);
            for (const file of item.media) form.append('media[]', file, file.name);
            const response = await fetch('/messages/api/send.php', {
                method: 'POST', body: form, credentials: 'same-origin',
                headers: { 'X-CSRF-Token': this.csrf }, signal: this.request.signal
            });
            if (!this.items.has(item.id)) return;
            if (response.status >= 500 || [408, 409, 429].includes(response.status)) {
                retry = true;
                const header = response.headers.get('Retry-After');
                if (header) retryAfter = /^\d+$/.test(header) ? Number(header) * 1000 : Math.max(0, Date.parse(header) - Date.now());
            } else {
                const data = await response.json().catch(() => null);
                if (!this.items.has(item.id)) return;
                if (response.ok && data === null) {
                    retry = true;
                } else if (response.ok && data.success) {
                    this.confirm([item.id]);
                    this.onSent(data.sent_message);
                } else {
                    item.error = data?.message || data?.error
                        || (response.status === 413 ? 'These attachments are too large to send together.' : 'Could not send this message.');
                    await this.write('put', item);
                }
            }
        } catch {
            retry = this.items.has(item.id);
        } finally {
            clearTimeout(timeout);
            this.request = null;
            this.sending = null;
            this.onChange();
            if (!this.paused) {
                if (retry) {
                    const delay = Math.min(Math.max(this.retryDelay, retryAfter || 0), 300000);
                    this.retryTimer = setTimeout(() => this.resume(), delay);
                    this.retryDelay = Math.min(this.retryDelay * 2, 30000);
                } else {
                    this.retryDelay = 2000;
                    void this.flush();
                }
            }
        }
    }
}
