'use strict';

(() => {
    const menu = document.getElementById('mobileSidebar');
    const toggle = document.getElementById('hamburgerMenuBtn');
    const close = () => { if (menu?.open) menu.close(); };
    const open = () => {
        if (!menu || menu.open) return;
        menu.showModal();
        toggle.setAttribute('aria-expanded', 'true');
    };
    if (menu && toggle) {
        toggle.addEventListener('click', open);
        document.getElementById('closeSidebarBtn').addEventListener('click', close);
        menu.addEventListener('click', event => {
            if (event.target !== menu) return;
            const bounds = menu.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right) close();
        });
        menu.addEventListener('close', () => {
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus({ preventScroll: true });
        });
        menu.querySelectorAll('a').forEach(link => link.addEventListener('click', close));
    }
    document.getElementById('siteBack')?.addEventListener('click', () => {
        if (history.length > 1) history.back();
        else location.assign('/home/');
    });
    const profilePhoto = document.querySelector('.site-account-photo');
    if (profilePhoto) {
        const showInitial = () => { profilePhoto.hidden = true; };
        profilePhoto.addEventListener('error', showInitial, { once: true });
        if (profilePhoto.complete && !profilePhoto.naturalWidth) showInitial();
    }
    document.getElementById('appLoader')?.classList.add('hidden');

    const bell = document.getElementById('notificationBell');
    const badge = document.getElementById('notificationBadge');
    function updateNotificationBadge(count) {
        if (!bell || !badge) return;
        count = Math.max(0, Number(count) || 0);
        badge.hidden = !count;
        badge.textContent = count > 99 ? '99+' : count;
        bell.setAttribute('aria-label', count ? `Notifications, ${count} unread` : 'Notifications');
        window.unreadNotificationCount = count;
    }

    let pending = false;
    async function refreshNotifications() {
        if (!bell || pending || document.hidden) return;
        pending = true;
        try {
            const response = await fetch('/notifications/api/count.php');
            if (response.ok) updateNotificationBadge((await response.json()).count);
        } catch {
            // Keep the last known count while offline.
        } finally {
            pending = false;
        }
    }

    window.HeaderMenu = {
        toggle: () => menu?.open ? close() : open(),
        close, open, updateNotificationBadge, refreshNotifications
    };
    if (bell) {
        updateNotificationBadge(window.unreadNotificationCount);
        refreshNotifications();
        window.addEventListener('pageshow', refreshNotifications);
        document.addEventListener('visibilitychange', refreshNotifications);
        if (!navigator.userAgent.includes('RelativesAndroid')) setInterval(refreshNotifications, 60000);
    }
})();
