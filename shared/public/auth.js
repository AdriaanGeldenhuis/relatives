'use strict';

document.querySelectorAll('[data-password]').forEach((button) => {
    const field = document.getElementById(button.dataset.password);
    const label = field.labels[0].textContent.toLowerCase();
    function updateToggle() {
        const action = field.type === 'text' ? 'Hide' : 'Show';
        button.textContent = action;
        button.setAttribute('aria-label', `${action} ${label}`);
    }

    updateToggle();
    button.hidden = false;
    button.addEventListener('click', () => {
        field.type = field.type === 'password' ? 'text' : 'password';
        updateToggle();
    });
});

const password = document.getElementById('password');
const confirmation = document.getElementById('confirm_password');
const strength = document.getElementById('password-strength');

if (strength) {
    password.addEventListener('input', () => {
        const value = password.value;
        strength.hidden = value.length === 0;
        strength.value = value.length < 12 ? 0 : 1 + [
            value.length >= 16,
            /[a-z]/.test(value) && /[A-Z]/.test(value),
            /[0-9]/.test(value),
            /[^a-zA-Z0-9]/.test(value),
        ].filter(Boolean).length;
    });
}

if (confirmation) {
    const matchStatus = document.getElementById('password-match');
    function checkMatch() {
        const mismatch = confirmation.value !== '' && password.value !== confirmation.value;
        const message = mismatch ? 'Passwords do not match.' : '';
        confirmation.setCustomValidity(message);
        matchStatus.textContent = message;
    }

    password.addEventListener('input', checkMatch);
    confirmation.addEventListener('input', checkMatch);
}

const invite = document.getElementById('invite_code');
invite?.addEventListener('input', () => {
    const start = invite.selectionStart;
    const end = invite.selectionEnd;
    invite.value = invite.value.toUpperCase();
    invite.setSelectionRange(start, end);
});

const copyInvite = document.getElementById('copy-invite');
if (copyInvite && navigator.clipboard) {
    copyInvite.hidden = false;
    copyInvite.addEventListener('click', async () => {
        const status = document.getElementById('copy-status');
        try {
            await navigator.clipboard.writeText(document.getElementById('inviteCode').textContent);
            status.textContent = 'Invite code copied.';
        } catch {
            status.textContent = 'Select the invite code to copy it manually.';
        }
    });
}

const authForm = document.querySelector('.auth-form');
if (authForm) {
    const submit = authForm.querySelector('button[type="submit"]');
    authForm.addEventListener('submit', () => { submit.disabled = true; });
    window.addEventListener('pageshow', () => { submit.disabled = false; });
}
