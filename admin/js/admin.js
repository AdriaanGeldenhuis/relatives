'use strict';

(() => {
    const main = document.querySelector('.admin-main');
    if (!main) return;

    document.querySelectorAll('[data-open-dialog]').forEach(button => {
        button.addEventListener('click', () => {
            const dialog = document.getElementById(button.dataset.openDialog);
            if (button.dataset.details) {
                const template = document.getElementById(button.dataset.details);
                dialog.querySelector('[data-dialog-content]').replaceChildren(template.content.cloneNode(true));
            }
            dialog.showModal();
        });
    });
    document.querySelectorAll('[data-close-dialog]').forEach(button => {
        button.addEventListener('click', () => button.closest('dialog').close());
    });

    main.querySelectorAll('[data-filter]').forEach(input => {
        const id = input.dataset.filter;
        const rows = document.getElementById(id).querySelectorAll('[data-search-row]');
        const empty = main.querySelector(`[data-filter-empty="${id}"]`);
        input.closest('label').hidden = false;

        const applyFilter = () => {
            const words = input.value.trim().toLocaleLowerCase().split(/\s+/);
            let found = false;
            for (const row of rows) {
                const text = (row.dataset.searchText ?? row.textContent).toLocaleLowerCase().replace(/\s+/g, ' ');
                const matches = words.every(word => text.includes(word));
                row.hidden = !matches;
                found ||= matches;
            }
            empty.hidden = found;
        };
        input.addEventListener('input', applyFilter);
        applyFilter();
    });

    document.querySelectorAll('[data-copy]').forEach(button => {
        button.addEventListener('click', async () => {
            const source = document.getElementById(button.dataset.copy);
            const text = source.value ?? source.textContent.trim();
            const feedback = button.closest('dialog')?.querySelector('[role="status"]') ?? document.getElementById('adminCopyFeedback');
            let copied = false;
            try {
                await navigator.clipboard.writeText(text);
                copied = true;
            } catch {
                if (source instanceof HTMLInputElement) {
                    source.focus();
                    source.select();
                } else {
                    const range = document.createRange();
                    range.selectNodeContents(source);
                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
            }
            feedback.textContent = copied ? 'Copied to clipboard.' : 'Text selected. Copy it from your browser.';
            feedback.hidden = false;
        });
    });

    const flash = main.querySelector('[data-admin-flash]');
    const destination = document.getElementById(location.hash.slice(1));
    if (flash && destination && main.contains(destination)) {
        destination.prepend(flash);
    }

    const confirmedForms = document.querySelectorAll('form[data-confirm]');
    if (!confirmedForms.length) return;
    const dialog = document.createElement('dialog');
    dialog.className = 'admin-dialog';
    dialog.setAttribute('aria-labelledby', 'adminConfirmTitle');
    dialog.innerHTML = `
        <header><h2 id="adminConfirmTitle">Confirm change</h2></header>
        <form method="dialog" class="admin-dialog-body">
            <p></p>
            <div class="admin-dialog-actions">
                <button class="admin-button" value="cancel" autofocus>Cancel</button>
                <button class="admin-button admin-primary" value="confirm">Confirm</button>
            </div>
        </form>`;
    document.body.append(dialog);
    let pendingForm;
    for (const form of confirmedForms) {
        form.addEventListener('submit', event => {
            event.preventDefault();
            pendingForm = form;
            dialog.returnValue = '';
            dialog.querySelector('p').textContent = form.dataset.confirm;
            dialog.showModal();
        });
    }
    dialog.addEventListener('close', () => {
        const form = pendingForm;
        pendingForm = null;
        if (dialog.returnValue === 'confirm' && form) {
            form.submit();
        }
    });
})();
