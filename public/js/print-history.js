(function () {
    'use strict';
    const input = document.getElementById('historySearch');
    const clear = document.getElementById('clearHistorySearch');
    function syncClear() { clear.hidden = input.value === ''; }
    if (input && clear) {
        syncClear();
        input.addEventListener('input', syncClear);
        clear.addEventListener('click', () => { input.value = ''; syncClear(); input.form.requestSubmit(); });
    }
    const element = document.getElementById('historyConfirm');
    if (!element || typeof bootstrap === 'undefined') return;
    const modal = new bootstrap.Modal(element);
    let pendingButton = null;
    document.querySelectorAll('[data-confirm]').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            pendingButton = button;
            document.getElementById('historyConfirmMessage').textContent = button.dataset.confirm;
            const row = button.closest('tr');
            document.getElementById('historyConfirmFile').textContent = row ? row.querySelector('.file-name').textContent : '';
            const summary = row && row.querySelector('.history-summary');
            document.getElementById('historyConfirmOptions').textContent = summary ? summary.textContent.replace(/\s+/g, ' ').trim() : '';
            document.getElementById('historyProceed').textContent = button.textContent.trim() || 'Confirmar';
            modal.show();
        });
    });
    element.addEventListener('shown.bs.modal', () => document.getElementById('historyKeep').focus());
    element.addEventListener('hidden.bs.modal', () => { if (pendingButton) pendingButton.focus(); pendingButton = null; });
    document.getElementById('historyProceed').addEventListener('click', () => {
        if (!pendingButton) return;
        const form = pendingButton.form;
        pendingButton.disabled = true;
        document.getElementById('historyProceed').disabled = true;
        // Browser-native form submission preserves existing server validation and permissions.
        HTMLFormElement.prototype.submit.call(form);
    });
}());
