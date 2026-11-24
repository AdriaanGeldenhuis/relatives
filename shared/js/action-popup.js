'use strict';

(() => {
    let active = null;
    let repositionFrame = 0;

    function close(selector, restoreFocus = false) {
        if (!active || (selector && !active.panel.matches(selector))) return;
        const { panel, trigger, placeholder } = active;
        active = null;
        if (repositionFrame) {
            cancelAnimationFrame(repositionFrame);
            repositionFrame = 0;
        }
        panel.classList.remove('action-popup-open');
        trigger?.setAttribute('aria-expanded', 'false');
        panel.remove();
        placeholder?.replaceWith(panel);
        if (restoreFocus) trigger?.focus({ preventScroll: true });
    }

    function position() {
        if (!active) return;
        const { panel, trigger, pointer } = active;
        const anchor = trigger?.getBoundingClientRect() ?? pointer;
        const viewport = window.visualViewport;
        const left = viewport?.offsetLeft ?? 0;
        const top = viewport?.offsetTop ?? 0;
        const right = left + (viewport?.width ?? document.documentElement.clientWidth);
        const bottom = top + (viewport?.height ?? innerHeight);

        if ((trigger && !trigger.isConnected) || anchor.right < left || anchor.left > right ||
            anchor.bottom < top || anchor.top > bottom) {
            close();
            return;
        }

        const margin = 8;
        panel.style.setProperty('--action-popup-width', `${Math.max(0, right - left - 2 * margin)}px`);
        panel.style.maxHeight = `${Math.max(0, bottom - top - 2 * margin)}px`;
        const { width, height } = panel.getBoundingClientRect();
        const leftBound = Math.min(right - margin - width, anchor.right - width);
        const below = anchor.bottom + 5;
        const above = anchor.top - height - 5;
        const fitsBelow = below + height <= bottom - margin;
        const fitsAbove = above >= top + margin;
        const useBelow = fitsBelow || (!fitsAbove && bottom - below >= anchor.top - top);
        const topBound = Math.min(useBelow ? below : above, bottom - margin - height);
        panel.style.left = `${Math.max(left + margin, leftBound)}px`;
        panel.style.top = `${Math.max(top + margin, topBound)}px`;
    }

    function scheduleReposition() {
        if (!active || repositionFrame) return;
        repositionFrame = requestAnimationFrame(() => {
            repositionFrame = 0;
            position();
        });
    }

    function toggle(panel, event) {
        if (!panel) return;
        event.stopPropagation();
        const trigger = event.currentTarget instanceof Element ? event.currentTarget : null;
        const alreadyOpen = active?.panel === panel || (trigger && active?.trigger === trigger);
        close();
        if (alreadyOpen) return;

        const placeholder = panel.isConnected ? document.createComment('action popup') : null;
        if (placeholder) panel.before(placeholder);
        document.body.append(panel);
        panel.classList.add('action-popup-open');
        trigger?.setAttribute('aria-expanded', 'true');
        active = {
            panel, trigger, placeholder,
            pointer: { top: event.clientY, bottom: event.clientY, left: event.clientX, right: event.clientX }
        };
        position();
        if (active && event.detail === 0) panel.querySelector('button, a[href]')?.focus({ preventScroll: true });
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

    window.addEventListener('scroll', scheduleReposition, { capture: true, passive: true });
    window.addEventListener('resize', scheduleReposition, { passive: true });
    window.visualViewport?.addEventListener('scroll', scheduleReposition, { passive: true });
    window.visualViewport?.addEventListener('resize', scheduleReposition, { passive: true });

    window.ActionPopup = { toggle, close };
})();
