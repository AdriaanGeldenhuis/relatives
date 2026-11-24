'use strict';

(() => {
    let active = null;

    function close(selector, restoreFocus = false) {
        if (!active || (selector && !active.panel.matches(selector))) return;
        const { panel, trigger, placeholder } = active;
        active = null;
        panel.classList.remove('action-popup-open');
        trigger?.setAttribute('aria-expanded', 'false');
        panel.remove();
        placeholder?.replaceWith(panel);
        if (restoreFocus) trigger?.focus({ preventScroll: true });
    }

    function toggle(panel, event) {
        if (!panel) return;
        event.stopPropagation();
        const trigger = event.currentTarget instanceof Element ? event.currentTarget : null;
        const alreadyOpen = active?.panel === panel || (trigger && active?.trigger === trigger);
        close();
        if (alreadyOpen) return;

        const anchor = trigger?.getBoundingClientRect();
        const placeholder = panel.isConnected ? document.createComment('action popup') : null;
        if (placeholder) panel.before(placeholder);
        document.body.append(panel);
        panel.classList.add('action-popup-open');

        const viewport = window.visualViewport;
        const margin = 8;
        const leftEdge = (viewport?.offsetLeft ?? 0) + margin;
        const topEdge = (viewport?.offsetTop ?? 0) + margin;
        const rightEdge = leftEdge + (viewport?.width ?? document.documentElement.clientWidth) - 2 * margin;
        const bottomEdge = topEdge + (viewport?.height ?? innerHeight) - 2 * margin;
        panel.style.maxWidth = `${rightEdge - leftEdge}px`;
        panel.style.maxHeight = `${bottomEdge - topEdge}px`;
        const { width, height } = panel.getBoundingClientRect();
        const below = (anchor?.bottom ?? event.clientY) + 5;
        const above = (anchor?.top ?? event.clientY) - height - 5;
        const left = (anchor?.right ?? event.clientX) - width;
        panel.style.left = `${Math.max(leftEdge, Math.min(left, rightEdge - width))}px`;
        panel.style.top = `${Math.max(topEdge, Math.min(below + height <= bottomEdge ? below : above, bottomEdge - height))}px`;
        trigger?.setAttribute('aria-expanded', 'true');
        active = { panel, trigger, placeholder };
        if (event.detail === 0) panel.querySelector('button, a[href]')?.focus({ preventScroll: true });
    }

    document.addEventListener('click', event => {
        if (!active) return;
        const { panel, trigger } = active;
        if (!panel.contains(event.target) && !trigger?.contains(event.target)) close();
        else if (panel.contains(event.target) && event.target.closest('button, a[href]')) close();
    });
    document.addEventListener('keydown', event => {
        if (active && event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(null, true);
        }
    }, true);
    window.addEventListener('scroll', event => {
        if (active && !active.panel.contains(event.target)) close();
    }, { capture: true, passive: true });
    window.addEventListener('resize', () => close(), { passive: true });
    window.visualViewport?.addEventListener('resize', () => close(), { passive: true });

    window.ActionPopup = { toggle, close };
})();
