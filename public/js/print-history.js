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

(function () {
    'use strict';
    const modal = document.getElementById('historyPreview');
    const name = document.getElementById('historyPreviewName');
    const status = document.getElementById('historyPreviewStatus');
    const content = document.getElementById('historyPreviewContent');
    const viewer = window.createPrintPreview(content);
    const close = modal.querySelector('[data-bs-dismiss="modal"]');
    const unavailable = 'Não foi possível visualizar o arquivo. Você pode usar Baixar no histórico.';
    let request = null;
    let objectUrl = null;

    modal.addEventListener('show.bs.modal', ({ relatedTarget }) => {
        name.textContent = relatedTarget.closest('tr').querySelector('.file-name').textContent;
        status.textContent = 'Carregando arquivo…';
    });

    modal.addEventListener('shown.bs.modal', async ({ relatedTarget }) => {
        close.focus();
        const controller = new AbortController();
        request = controller;
        const { signal } = controller;
        const current = () => !signal.aborted;

        try {
            const response = await fetch(relatedTarget.dataset.previewUrl, { signal, redirect: 'manual' });
            if (!current()) return;
            if (response.type === 'opaqueredirect') {
                status.textContent = 'Sessão expirada. Entre novamente para visualizar o arquivo.';
                return;
            }
            if (response.status === 404) {
                status.textContent = 'Arquivo indisponível.';
                return;
            }
            if (!response.ok) throw new Error('Falha ao obter o arquivo.');

            const file = await response.blob();
            if (!current()) return;
            const type = file.type.split(';')[0];
            if (type === 'application/pdf') {
                objectUrl = URL.createObjectURL(file);
                const key = response.headers.get('X-Preview-Cache-Key');
                const token = response.headers.get('X-Preview-Token');
                const imageContext = key && token ? { key, token, csrf: modal.dataset.csrfToken } : null;
                await viewer.load(file, objectUrl, current, imageContext);
            } else if (type.startsWith('image/')) {
                objectUrl = URL.createObjectURL(file);
                const image = new Image();
                image.alt = name.textContent;
                image.className = 'img-fluid d-block mx-auto';
                image.addEventListener('load', () => {
                    if (!current()) return;
                    content.replaceChildren(image);
                    status.textContent = '';
                }, { once: true });
                image.addEventListener('error', () => {
                    if (current()) status.textContent = unavailable;
                }, { once: true });
                image.src = objectUrl;
                return;
            } else if (type === 'text/plain') {
                const data = await file.arrayBuffer();
                if (!current()) return;
                const text = document.createElement('pre');
                text.className = 'text-wrap text-break mb-0';
                try {
                    text.textContent = new TextDecoder('utf-8', { fatal: true }).decode(data);
                } catch {
                    text.textContent = new TextDecoder('windows-1252').decode(data);
                }
                content.replaceChildren(text);
            } else {
                throw new Error('Tipo incompatível com a visualização.');
            }

            if (current()) status.textContent = '';
        } catch {
            if (current()) status.textContent = unavailable;
        }
    });

    modal.addEventListener('hide.bs.modal', () => request?.abort());
    modal.addEventListener('hidden.bs.modal', () => {
        viewer.clear();
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        request = null;
        name.textContent = '';
        status.textContent = '';
    });
}());
