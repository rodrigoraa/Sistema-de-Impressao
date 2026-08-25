<?php
$sessionRole = $_SESSION['role'] ?? 'user';
$sessionName = $_SESSION['name'] ?? ($_SESSION['user'] ?? '');
$sharedMode = !empty($sharedMode);
$shareToken = (string) ($shareToken ?? '');
$sharedFile = is_array($sharedFile ?? null) ? $sharedFile : [];
$printFormAction = $sharedMode ? '/share-target.php' : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/favicon.ico?v=2">
    <link rel="apple-touch-icon" href="/image/pwa-icon-180.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f3f4f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Sistema de Impressão">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>Impressão</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="/css/base.css?v=20260511">
    <link rel="stylesheet" href="/css/print.css?v=20260825">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body>

    <div class="app-shell">

        <!-- HEADER -->
        <header class="app-header">
            <div class="container d-flex justify-content-between align-items-center">

                <div class="d-flex align-items-center gap-2">
                    <img src="/image/logo_escola.png" class="logo">
                    <strong>Sistema de Impressão</strong>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <span class="user">
                        <i class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($sessionName) ?>
                    </span>

                    <?php if ($sessionRole === 'admin'): ?>
                        <a href="/prints?status=active" class="btn btn-outline-light btn-sm">
                            <i class="bi bi-list-task"></i> Fila
                        </a>
                    <?php endif; ?>

                    <a href="/logout" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-box-arrow-right"></i> Sair
                    </a>
                </div>

            </div>
        </header>

        <!-- MAIN -->
        <main class="container py-4">
            <div class="page-title">
                <div>
                    <h1>Nova impressão</h1>
                    <p>Envie o arquivo, revise as opções e acompanhe pela fila quando precisar.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="/prints" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-clock-history"></i> Histórico
                    </a>
                    <?php if ($sessionRole === 'admin'): ?>
                        <a href="/admin" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-speedometer2"></i> Painel
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ALERT -->
            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert alert-<?= ($_SESSION['flash_type'] ?? '') === 'error' ? 'danger' : 'success' ?>">
                    <?= htmlspecialchars($_SESSION['flash']);
                    unset($_SESSION['flash']); ?>
                </div>
            <?php endif; ?>
            <div id="printResult" class="alert d-none"></div>
            <div id="printerStatusBox" class="alert d-none" role="status"></div>

            <div class="row g-4">

                <!-- FORM -->
                <div class="col-lg-6">

                    <div class="card shadow-sm border-0">
                        <div class="card-body">

                            <div class="card-title-line">
                                <h4><i class="bi bi-printer"></i> Configuração</h4>
                            </div>

                            <form id="printForm" method="post" enctype="multipart/form-data" <?= $printFormAction !== '' ? 'action="' . htmlspecialchars($printFormAction) . '"' : '' ?>>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="upload_token" id="uploadTokenInput" value="<?= htmlspecialchars((string) ($sharedFile['upload_token'] ?? '')) ?>">
                                <?php if ($sharedMode): ?>
                                    <input type="hidden" name="share_token" value="<?= htmlspecialchars($shareToken) ?>">
                                    <input type="hidden" name="share_action" value="print">
                                <?php endif; ?>

                                <div class="mb-3 upload-wrapper">

                                    <label class="form-label">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Arquivo
                                    </label>

                                    <?php if ($sharedMode): ?>
                                        <div class="upload-box success shared-upload-box" id="drop-area">
                                            <i class="bi bi-file-earmark-check fs-1"></i>
                                            <p id="file-label"><?= htmlspecialchars($sharedFile['original_name'] ?? 'Arquivo recebido') ?></p>
                                        </div>

                                        <div id="file-info" class="mt-2 small text-muted">
                                            <?= htmlspecialchars(($sharedFile['size_label'] ?? '') . (!empty($sharedFile['page_label']) ? ' · ' . $sharedFile['page_label'] : '')) ?>
                                        </div>

                                        <?php if (!empty($sharedFile['page_warning'])): ?>
                                            <div class="print-advice" id="pageAdvice">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                <span><?= htmlspecialchars($sharedFile['page_warning']) ?></span>
                                            </div>
                                        <?php elseif (!empty($sharedFile['page_advice'])): ?>
                                            <div class="print-advice" id="pageAdvice">
                                                <i class="bi bi-lightbulb"></i>
                                                <span><?= htmlspecialchars($sharedFile['page_advice']) ?></span>
                                            </div>
                                        <?php elseif (!empty($sharedFile['page_error'])): ?>
                                            <div class="text-muted small mt-2" id="pageAdvice">
                                                <?= htmlspecialchars($sharedFile['page_error']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="upload-box" id="drop-area">
                                            <i class="bi bi-cloud-upload fs-1"></i>
                                            <p id="file-label">Clique ou arraste o arquivo</p>
                                            <input type="file" name="arquivo" id="fileInput" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp,.txt,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg,image/webp,text/plain" hidden required>
                                        </div>

                                        <div id="file-info" class="mt-2 small text-muted"></div>
                                    <?php endif; ?>

                                    <!-- PREVIEW -->
                                    <div id="previewSummary" class="preview-summary mt-3 d-none">
                                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                            <strong>Pré-visualização da impressão</strong>
                                            <span id="previewConfiguration" class="text-muted small"></span>
                                        </div>
                                        <div id="previewCoverage" class="small text-muted mt-1"></div>
                                    </div>
                                    <div id="preview" class="mt-3"></div>

                                    <!-- PROGRESS -->
                                    <div class="progress mt-3 d-none" id="progressBar">
                                        <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                    </div>

                                    <?php if (!$sharedMode): ?>
                                        <small class="text-muted">
                                            PDF, DOC, DOCX, TXT ou imagem (máx <?= (int) ($maxUploadMb ?? 20) ?>MB)
                                        </small>
                                    <?php endif; ?>

                                </div>

                                <div class="option-grid mb-3">
                                    <div>
                                        <label class="form-label">Cópias</label>
                                        <input type="number" class="form-control" name="copies" value="1" min="1">
                                    </div>

                                    <div>
                                        <label class="form-label">Modo</label>
                                        <select class="form-select" name="sides">
                                            <option value="one-sided">Simples</option>
                                            <option value="two-sided-long-edge">Frente e verso - borda maior</option>
                                            <option value="two-sided-short-edge">Frente e verso - borda menor</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label">Orientação</label>
                                        <select class="form-select" name="orientation">
                                            <option value="auto" selected>Automática</option>
                                            <option value="portrait">Retrato</option>
                                            <option value="landscape">Paisagem</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label">Qualidade</label>
                                        <select class="form-select" name="quality">
                                            <option value="3">Normal</option>
                                            <option value="5">Alta</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label">Páginas por folha</label>
                                        <select class="form-select" name="number_up">
                                            <option value="1">1 por folha</option>
                                            <option value="2">2 por folha</option>
                                            <option value="4">4 por folha</option>
                                            <option value="8">8 por folha</option>
                                        </select>
                                        <input type="hidden" name="large_document_layout" value="auto">
                                    </div>

                                    <div>
                                        <label class="form-label">Tamanho do papel</label>
                                        <select class="form-select" name="paper">
                                            <option value="A4">A4</option>
                                            <option value="Letter">Carta</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label">Escala</label>
                                        <select class="form-select" name="scale">
                                            <option value="fit">Ajustar à página</option>
                                            <option value="100">100%</option>
                                            <option value="95">95%</option>
                                            <option value="90">90%</option>
                                            <option value="80">80%</option>
                                            <option value="custom">Personalizada</option>
                                        </select>
                                    </div>

                                    <div class="d-none" id="scaleCustomBox">
                                        <label class="form-label">Escala personalizada (%)</label>
                                        <input type="number" class="form-control" name="scale_percent" value="100" min="10" max="400">
                                    </div>

                                    <div class="wide">
                                        <label class="form-label">Páginas</label>
                                        <input type="text" class="form-control" name="page_ranges" placeholder="Ex.: 1,3-5">
                                        <small class="text-muted">Deixe em branco para imprimir todas</small>
                                    </div>

                                </div>

                                <?php if ($sessionRole === 'admin'): ?>
                                    <div class="mb-3 admin-box">

                                        <label class="form-label">
                                            <i class="bi bi-person-gear"></i> Imprimir para
                                        </label>

                                        <input list="users" name="target_user_search" id="targetUserSearch" class="form-control" autocomplete="off">
                                        <input type="hidden" name="target_user" id="targetUserCpf">

                                        <datalist id="users">
                                            <?php foreach ($userList as $u): ?>
                                                <option value="<?= htmlspecialchars($u['name'] . ' - ' . $u['cpf']) ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>

                                        <small class="text-muted">
                                            Selecione o professor pelo nome para contabilizar no CPF correto
                                        </small>

                                    </div>
                                <?php endif; ?>

                                <div class="<?= $sharedMode ? 'print-actions' : '' ?>">
                                    <button id="btnPrint" class="btn btn-primary w-100" <?= (($printerStatus['can_print'] ?? true) === false) ? 'disabled' : '' ?>>
                                        <span class="text"><?= $sharedMode ? 'Confirmar impressão' : 'Imprimir' ?></span>
                                        <span class="spinner-border spinner-border-sm d-none"></span>
                                    </button>

                                    <?php if ($sharedMode): ?>
                                        <button type="submit" form="shareCancelForm" class="btn btn-outline-danger w-100">
                                            <i class="bi bi-x-circle"></i> Cancelar
                                        </button>
                                    <?php endif; ?>
                                </div>

                            </form>

                            <?php if ($sharedMode): ?>
                                <form id="shareCancelForm" method="post" action="/share-target.php" class="d-none">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="share_token" value="<?= htmlspecialchars($shareToken) ?>">
                                    <input type="hidden" name="share_action" value="cancel">
                                </form>
                            <?php endif; ?>

                        </div>
                    </div>

                </div>

                <!-- INFO -->
                <div class="col-lg-6">

                    <div class="card shadow-sm border-0 info-panel">
                        <div class="card-body">

                            <div class="card-title-line">
                                <h5><i class="bi bi-info-circle"></i> Atalhos</h5>
                            </div>

                            <ul class="list-unstyled info-list">
                                <li><i class="bi bi-check-circle"></i> Envie arquivos PDF, DOC, DOCX, TXT ou imagem</li>
                                <li><i class="bi bi-check-circle"></i> Escolha frente e verso se necessário</li>
                                <li><i class="bi bi-check-circle"></i> Consulte arquivos impressos para baixar ou reimprimir</li>
                                <li><i class="bi bi-check-circle"></i> Administradores podem imprimir para outros
                                    usuários</li>
                            </ul>

                            <div class="quick-actions mt-3">
                                <a href="<?= $sessionRole === 'admin' ? '/prints?status=active' : '/prints' ?>">
                                    <span><i class="bi <?= $sessionRole === 'admin' ? 'bi-list-task' : 'bi-clock-history' ?>"></i> <?= $sessionRole === 'admin' ? 'Ver fila de impressão' : 'Ver meu histórico' ?></span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                                <?php if ($sessionRole === 'admin'): ?>
                                    <a href="/admin">
                                        <span><i class="bi bi-speedometer2"></i> Abrir painel administrativo</span>
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>

                </div>

            </div>

        </main>

    </div>

    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Visualização do arquivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center">
                    <div id="modalContent"></div>
                </div>

            </div>
        </div>
    </div>

    <script>
        const dropArea = document.getElementById('drop-area');
        const input = document.getElementById('fileInput');
        const label = document.getElementById('file-label');
        const info = document.getElementById('file-info');
        const preview = document.getElementById('preview');
        const previewSummary = document.getElementById('previewSummary');
        const previewConfiguration = document.getElementById('previewConfiguration');
        const previewCoverage = document.getElementById('previewCoverage');
        const uploadTokenInput = document.getElementById('uploadTokenInput');
        const progress = document.getElementById('progressBar');
        const bar = progress.querySelector('.progress-bar');
        const printForm = document.getElementById('printForm');
        const btnPrint = document.getElementById('btnPrint');
        const resultBox = document.getElementById('printResult');
        const csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
        const sharedMode = <?= json_encode($sharedMode) ?>;
        const sharedFile = <?= json_encode($sharedFile, JSON_UNESCAPED_UNICODE) ?>;
        const shareToken = <?= json_encode($shareToken) ?>;
        const adminUsers = <?= json_encode($userList ?? [], JSON_UNESCAPED_UNICODE) ?>;
        let printerStatus = <?= json_encode($printerStatus ?? [], JSON_UNESCAPED_UNICODE) ?>;
        let pageCountToken = 0;
        let previewToken = 0;
        let previewObjectUrl = '';
        let uploadToken = uploadTokenInput.value || '';
        let uploadController = null;
        let pageCountController = null;
        let previewController = null;
        let previewDebounceTimer = null;
        let currentPageData = null;
        let currentFileMeta = sharedMode ? sharedFile : null;
        let currentPageAdvice = '';
        let printingActive = false;
        let sharedPrintCompleted = false;
        let postPrintWatchTimer = null;
        let postPrintWatchUntil = 0;
        const scaleSelect = document.querySelector('[name="scale"]');
        const scaleCustomBox = document.getElementById('scaleCustomBox');
        const targetUserSearch = document.getElementById('targetUserSearch');
        const targetUserCpf = document.getElementById('targetUserCpf');
        const printerStatusBox = document.getElementById('printerStatusBox');
        const numberUpSelect = document.querySelector('[name="number_up"]');
        const sidesSelect = document.querySelector('[name="sides"]');
        const largeDocumentLayout = document.querySelector('[name="large_document_layout"]');
        let layoutChangedByUser = false;

        scaleSelect.addEventListener('change', () => {
            scaleCustomBox.classList.toggle('d-none', scaleSelect.value !== 'custom');
        });

        function normalizePersonSearch(value) {
            return String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/\s+/g, ' ')
                .trim()
                .toLowerCase();
        }

        function selectedAdminUser(value) {
            const text = String(value || '').trim();
            const digits = text.replace(/\D/g, '');
            const normalized = normalizePersonSearch(text);

            return adminUsers.find(user => {
                const name = String(user.name || '');
                const cpf = String(user.cpf || '');
                const label = `${name} - ${cpf}`;

                return text === cpf
                    || digits === cpf
                    || normalizePersonSearch(name) === normalized
                    || normalizePersonSearch(label) === normalized;
            }) || null;
        }

        function syncTargetUserCpf() {
            if (!targetUserSearch || !targetUserCpf) return true;
            const value = targetUserSearch.value.trim();
            if (value === '') {
                targetUserCpf.value = '';
                targetUserSearch.setCustomValidity('');
                return true;
            }

            const user = selectedAdminUser(value);
            if (user) {
                targetUserCpf.value = user.cpf;
                targetUserSearch.setCustomValidity('');
                return true;
            }

            targetUserCpf.value = '';
            targetUserSearch.setCustomValidity('Selecione um professor da lista.');
            return false;
        }

        if (targetUserSearch) {
            targetUserSearch.addEventListener('input', syncTargetUserCpf);
            targetUserSearch.addEventListener('change', syncTargetUserCpf);
        }

        [numberUpSelect, sidesSelect].forEach(select => {
            if (!select) return;
            select.addEventListener('change', () => {
                layoutChangedByUser = true;
                if (largeDocumentLayout) {
                    largeDocumentLayout.value = 'manual';
                }
            });
        });

        // clique
        if (!sharedMode && dropArea && input) {
            dropArea.onclick = () => input.click();
        }

        // tipos permitidos
        const allowed = <?= json_encode(array_values($allowedExtensions ?? ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp', 'txt'])) ?>;
        const maxUploadMb = <?= (int) ($maxUploadMb ?? 20) ?>;

        // tamanho
        function formatSize(bytes) {
            return (bytes / 1024 / 1024).toFixed(2) + ' MB';
        }

        // 🔥 abre modal
        function openModal(url, ext) {
            const modalContent = document.getElementById('modalContent');

            if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) {
                modalContent.innerHTML = `<img src="${url}" style="max-width:100%;">`;
            }

            if (ext === 'pdf') {
                modalContent.innerHTML = `<iframe src="${url}" style="width:100%; height:80vh;"></iframe>`;
            }

            const modal = new bootstrap.Modal(document.getElementById('previewModal'));
            modal.show();
        }

        function appendPreviewOptions(formData) {
            ['paper', 'orientation', 'number_up', 'scale', 'scale_percent', 'page_ranges', 'page_set'].forEach(name => {
                const element = document.querySelector(`[name="${name}"]`);
                if (element && element.value !== '') formData.append(name, element.value);
            });
            ['top', 'right', 'bottom', 'left'].forEach(side => {
                const element = document.querySelector(`[name="margin_${side}"]`);
                if (element && element.value !== '') formData.append(`margin_${side}`, element.value);
            });
            if (uploadToken) formData.append('upload_token', uploadToken);
            formData.append('csrf_token', csrfToken);
        }

        function resetPreviewObjectUrl() {
            if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = '';
            preview.onclick = null;
        }

        function pageInfoText(data) {
            const size = currentFileMeta && currentFileMeta.size_label
                ? currentFileMeta.size_label
                : (currentFileMeta && currentFileMeta.size ? formatSize(currentFileMeta.size) : '');
            const pages = data.pages === 1 ? '1 página' : `${data.pages} páginas`;
            const converted = data.original_pages && data.converted_pages && data.original_pages !== data.converted_pages
                ? ' após conversão'
                : '';
            return `${size}${size ? ' · ' : ''}${pages}${converted}${data.warning ? ` · atenção: ${data.warning}` : ''}`;
        }

        function renderPreviewSummary(metadata = null) {
            if (!uploadToken) {
                previewSummary.classList.add('d-none');
                return;
            }
            const numberUp = Number(numberUpSelect.value || 1);
            const paper = document.querySelector('[name="paper"]').value === 'Letter' ? 'Carta' : 'A4';
            const orientationValue = document.querySelector('[name="orientation"]').value;
            const orientation = orientationValue === 'portrait' ? 'Retrato' : (orientationValue === 'landscape' ? 'Paisagem' : 'Automática');
            const sides = sidesSelect.value === 'one-sided' ? 'Impressão simples' : 'Frente e verso';
            previewConfiguration.textContent = `${numberUp} ${numberUp === 1 ? 'página' : 'páginas'} por folha • ${paper} • ${orientation} • ${sides}`;

            if (metadata) {
                const shown = Number(metadata.previewSheets || 0);
                const total = Number(metadata.totalSheets || 0);
                const documentPages = Number(metadata.documentPages || (currentPageData && currentPageData.pages) || 0);
                const selectedPages = Number(metadata.selectedPages || documentPages);
                const pageText = selectedPages === documentPages
                    ? `${documentPages} ${documentPages === 1 ? 'página do documento' : 'páginas do documento'}`
                    : `${selectedPages} de ${documentPages} páginas selecionadas`;
                previewCoverage.textContent = `Exibindo ${shown === 1 ? 'o primeiro lado' : `os primeiros ${shown} lados`} de ${total}. ${pageText} → ${total} ${total === 1 ? 'lado impresso' : 'lados impressos'} por cópia.`;
            } else {
                previewCoverage.textContent = 'A prévia é uma amostra limitada dos primeiros lados da impressão.';
            }
            previewSummary.classList.remove('d-none');
        }

        async function uploadSelectedFile(file) {
            if (uploadController) uploadController.abort();
            if (pageCountController) pageCountController.abort();
            if (previewController) previewController.abort();
            previewToken++;
            const controller = new AbortController();
            uploadController = controller;
            uploadToken = '';
            uploadTokenInput.value = '';
            currentPageData = null;
            currentFileMeta = { original_name: file.name, extension: file.name.split('.').pop().toLowerCase(), size: file.size };
            info.textContent = `${formatSize(file.size)} · enviando e contando páginas...`;
            preview.innerHTML = '<div class="preview-loading"><span class="spinner-border spinner-border-sm"></span><span>Preparando o arquivo...</span></div>';
            previewSummary.classList.add('d-none');
            btnPrint.disabled = true;

            const formData = new FormData();
            formData.append('arquivo', file);
            appendPreviewOptions(formData);
            try {
                const response = await fetch('/print/upload', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                    signal: controller.signal
                });
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch {
                    throw new Error(friendlyServerError(text, response));
                }
                if (!response.ok || !data.success) throw new Error(data.message || 'Não foi possível enviar o arquivo.');
                uploadToken = data.upload_token;
                uploadTokenInput.value = uploadToken;
                currentPageData = data;
                currentFileMeta = { ...currentFileMeta, ...data };
                info.textContent = pageInfoText(data);
                currentPageAdvice = data.advice || '';
                applyLargeDocumentLayout(data);
                renderPageAdvice();
                renderPreviewSummary();
                schedulePreview(0);
            } catch (error) {
                if (error.name === 'AbortError') return;
                dropArea.classList.remove('success');
                dropArea.classList.add('error');
                preview.innerHTML = `<p class="text-danger">${escapeHtml(error.message)}</p>`;
                info.textContent = `${formatSize(file.size)} · falha no envio`;
            } finally {
                if (uploadController === controller) uploadController = null;
                if (!printingActive) renderPrinterStatus(printerStatus);
            }
        }

        async function loadDocumentPreview(token) {
            if (!uploadToken) return;
            if (previewController) previewController.abort();
            const controller = new AbortController();
            previewController = controller;
            preview.innerHTML = '<div class="preview-loading"><span class="spinner-border spinner-border-sm"></span><span>Gerando pré-visualização da impressão...</span></div>';
            renderPreviewSummary();
            const formData = new FormData();
            appendPreviewOptions(formData);

            try {
                const response = await fetch('/print/preview', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                    signal: controller.signal
                });
                if (!response.ok) throw new Error(await response.text() || 'Não foi possível gerar a pré-visualização.');
                const metadata = {
                    documentPages: response.headers.get('X-Preview-Document-Pages'),
                    selectedPages: response.headers.get('X-Preview-Selected-Pages'),
                    totalSheets: response.headers.get('X-Preview-Total-Sheets'),
                    previewSheets: response.headers.get('X-Preview-Sheets')
                };
                const blob = await response.blob();
                if (token !== previewToken) return;
                resetPreviewObjectUrl();
                previewObjectUrl = URL.createObjectURL(blob);
                preview.innerHTML = `<iframe src="${previewObjectUrl}" title="Pré-visualização da impressão"></iframe>`;
                preview.onclick = () => openModal(previewObjectUrl, 'pdf');
                renderPreviewSummary(metadata);
            } catch (error) {
                if (error.name === 'AbortError' || token !== previewToken) return;
                resetPreviewObjectUrl();
                preview.innerHTML = `<div class="alert alert-warning mb-0">${escapeHtml(error.message || 'Não foi possível gerar a pré-visualização, mas o arquivo pode ser enviado para impressão.')}</div>`;
            } finally {
                if (previewController === controller) previewController = null;
            }
        }

        function schedulePreview(delay = 350) {
            clearTimeout(previewDebounceTimer);
            if (previewController) previewController.abort();
            const token = ++previewToken;
            previewDebounceTimer = setTimeout(() => loadDocumentPreview(token), delay);
        }

        async function loadPageCount(token) {
            if (!uploadToken) return;
            if (pageCountController) pageCountController.abort();
            const controller = new AbortController();
            pageCountController = controller;
            const formData = new FormData();
            appendPreviewOptions(formData);
            try {
                const response = await fetch('/print/page-count', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                    signal: controller.signal
                });
                const data = await response.json();
                if (token !== pageCountToken) return;
                if (!response.ok || !data.success) throw new Error(data.message || 'não foi possível contar as páginas');
                currentPageData = data;
                info.textContent = pageInfoText(data);
                currentPageAdvice = data.advice || '';
                applyLargeDocumentLayout(data);
                renderPageAdvice();
                schedulePreview();
            } catch (error) {
                if (error.name === 'AbortError' || token !== pageCountToken) return;
                info.textContent = `${currentFileMeta && currentFileMeta.size_label ? currentFileMeta.size_label : ''} · não foi possível recalcular as páginas`;
                schedulePreview();
            } finally {
                if (pageCountController === controller) pageCountController = null;
            }
        }

        if (!sharedMode && input) {
            input.addEventListener('change', () => {
                const file = input.files[0];
                if (!file) return;
                const ext = file.name.split('.').pop().toLowerCase();
                resetPreviewObjectUrl();
                const previousLayoutWasAutomatic = largeDocumentLayout && largeDocumentLayout.value === 'auto_applied';
                currentPageAdvice = '';
                layoutChangedByUser = false;
                if (previousLayoutWasAutomatic && numberUpSelect && numberUpSelect.value === '2') {
                    numberUpSelect.value = '1';
                }
                if (largeDocumentLayout) largeDocumentLayout.value = 'auto';
                renderPageAdvice();
                dropArea.classList.remove('error', 'success');
                if (!allowed.includes(ext)) {
                    label.textContent = 'Tipo não permitido';
                    dropArea.classList.add('error');
                    input.value = '';
                    return;
                }
                if (file.size > maxUploadMb * 1024 * 1024) {
                    label.textContent = `Arquivo muito grande (máx ${maxUploadMb}MB)`;
                    dropArea.classList.add('error');
                    input.value = '';
                    return;
                }
                label.textContent = file.name;
                dropArea.classList.add('success');
                uploadSelectedFile(file);
            });
        }

        function renderPageAdvice() {
            const existing = document.getElementById('pageAdvice');
            if (existing) existing.remove();
            if (!currentPageAdvice) return;

            const box = document.createElement('div');
            box.id = 'pageAdvice';
            box.className = 'print-advice';
            box.innerHTML = `<i class="bi bi-lightbulb"></i><span>${escapeHtml(currentPageAdvice)}</span>`;
            info.insertAdjacentElement('afterend', box);
        }

        function applyLargeDocumentLayout(data) {
            if (!data || !data.large_document || !numberUpSelect || !sidesSelect || layoutChangedByUser) {
                return false;
            }

            if (numberUpSelect.value === '1' && sidesSelect.value === 'one-sided') {
                numberUpSelect.value = '2';
                if (largeDocumentLayout) {
                    largeDocumentLayout.value = 'auto_applied';
                }
                return true;
            }
            return false;
        }

        ['paper', 'orientation'].forEach(name => {
            const element = document.querySelector(`[name="${name}"]`);
            if (!element) return;
            element.addEventListener('change', () => {
                if (!uploadToken) return;
                previewToken++;
                if (previewController) previewController.abort();
                info.textContent = `${currentFileMeta && currentFileMeta.size_label ? currentFileMeta.size_label : ''} · recalculando páginas...`;
                renderPreviewSummary();
                loadPageCount(++pageCountToken);
            });
        });

        ['number_up', 'scale'].forEach(name => {
            const element = document.querySelector(`[name="${name}"]`);
            if (!element) return;
            element.addEventListener('change', () => {
                renderPreviewSummary();
                schedulePreview();
            });
        });

        if (sidesSelect) {
            sidesSelect.addEventListener('change', () => renderPreviewSummary());
        }

        ['scale_percent', 'page_ranges', 'page_set', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left'].forEach(name => {
            const element = document.querySelector(`[name="${name}"]`);
            if (!element) return;
            const eventName = element.tagName === 'SELECT' ? 'change' : 'input';
            element.addEventListener(eventName, () => schedulePreview(500));
        });

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showPrintResult(message, success) {
            resultBox.className = `alert alert-${success ? 'success' : 'danger'}`;
            resultBox.textContent = message;
            resultBox.classList.remove('d-none');
            resultBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function friendlyServerError(text, response) {
            const raw = String(text || '').trim();
            const lower = raw.toLowerCase();

            if (response && response.redirected && String(response.url || '').includes('/login')) {
                return 'Sua sessão expirou. Entre novamente no sistema e repita o envio.';
            }
            if (response && response.status === 413) {
                return 'O arquivo é maior que o limite aceito pelo servidor. Avise a equipe de TI para ajustar o limite de upload.';
            }
            if ((response && response.status === 502) || lower.includes('502 bad gateway')) {
                return 'O serviço de conversão não respondeu corretamente. Tente novamente; se continuar, avise a equipe de TI.';
            }
            if ((response && response.status === 504) || lower.includes('gateway time-out') || lower.includes('error code 504')) {
                return 'O envio demorou mais que o esperado. Verifique a fila de impressão; se o problema continuar, avise a equipe de TI.';
            }
            if (lower.includes('<!doctype html') || lower.includes('<html')) {
                return 'O servidor retornou uma página de erro. Tente novamente; se continuar, avise a equipe de TI.';
            }
            if (!raw) {
                return 'O servidor não retornou uma resposta válida. Tente novamente.';
            }

            return raw.length > 220 ? `${raw.slice(0, 220)}...` : raw;
        }

        function renderPrinterStatus(status) {
            printerStatus = status || {};
            const notice = printerStatus.notice || '';
            const awaitingUpload = !sharedMode && input && input.files.length > 0 && !uploadToken;
            if (!notice) {
                printerStatusBox.classList.add('d-none');
                if (!printingActive) {
                    btnPrint.disabled = awaitingUpload || (sharedMode && sharedPrintCompleted);
                }
                return;
            }

            const type = printerStatus.notice_type || (printerStatus.can_print === false ? 'warning' : 'success');
            printerStatusBox.className = `alert alert-${type} printer-status-alert`;
            printerStatusBox.innerHTML = `<i class="bi bi-printer"></i><span>${escapeHtml(notice)}</span>`;
            printerStatusBox.classList.remove('d-none');
            if (!printingActive) {
                btnPrint.disabled = awaitingUpload || printerStatus.can_print === false || (sharedMode && sharedPrintCompleted);
            }
        }

        function refreshPrinterStatus() {
            return fetch('/printer/status', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.status) {
                        renderPrinterStatus(data.status);
                    }
                })
                .catch(() => {});
        }

        function startPostPrintStatusWatch() {
            if (postPrintWatchTimer) {
                clearInterval(postPrintWatchTimer);
            }

            postPrintWatchUntil = Date.now() + 180000;
            refreshPrinterStatus();

            postPrintWatchTimer = setInterval(() => {
                if (Date.now() > postPrintWatchUntil) {
                    clearInterval(postPrintWatchTimer);
                    postPrintWatchTimer = null;
                    return;
                }

                refreshPrinterStatus();
            }, 5000);
        }

        function setPrintingState(active) {
            printingActive = active;
            const text = btnPrint.querySelector('.text');
            const spinner = btnPrint.querySelector('.spinner-border');
            const awaitingUpload = !sharedMode && input && input.files.length > 0 && !uploadToken;
            btnPrint.disabled = active || awaitingUpload || printerStatus.can_print === false || (sharedMode && sharedPrintCompleted);
            text.textContent = active ? 'Enviando...' : (sharedMode && sharedPrintCompleted ? 'Enviado' : (sharedMode ? 'Confirmar impressão' : 'Imprimir'));
            spinner.classList.toggle('d-none', !active);
            progress.classList.toggle('d-none', !active);
            if (!active) {
                bar.style.width = '0%';
            }
        }

        // envio AJAX para exibir erros sem deixar a pagina presa carregando
        printForm.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!sharedMode && !uploadToken) {
                showPrintResult('Aguarde o término do envio e da validação do arquivo antes de imprimir.', false);
                return;
            }
            if (!syncTargetUserCpf()) {
                printForm.reportValidity();
                return;
            }
            if (printerStatus.can_print === false) {
                showPrintResult(printerStatus.notice || 'A impressora não está disponível agora. Avise a equipe de TI.', false);
                refreshPrinterStatus();
                return;
            }
            clearTimeout(previewDebounceTimer);
            if (previewController) previewController.abort();
            if (pageCountController) pageCountController.abort();
            previewToken++;
            pageCountToken++;
            resultBox.classList.add('d-none');
            progress.classList.remove('d-none');
            setPrintingState(true);

            let percent = 0;
            const interval = setInterval(() => {
                percent = Math.min(percent + 10, 95);
                bar.style.width = percent + '%';
            }, 500);

            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 300000);

            const printData = new FormData(printForm);
            if (uploadToken) {
                printData.delete('arquivo');
                printData.set('upload_token', uploadToken);
            }

            fetch(printForm.action || window.location.href, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: printData,
                signal: controller.signal
            })
                .then(async response => {
                    const text = await response.text();
                    try {
                        return JSON.parse(text);
                    } catch {
                        throw new Error(friendlyServerError(text, response));
                    }
                })
                .then(data => {
                    showPrintResult(data.message || 'Impressão enviada', !!data.success);
                    if (data.success) {
                        if (sharedMode) {
                            sharedPrintCompleted = true;
                        } else {
                            uploadToken = '';
                            uploadTokenInput.value = '';
                        }
                        bar.style.width = '100%';
                        startPostPrintStatusWatch();
                    }
                })
                .catch(error => {
                    let message = error.name === 'AbortError'
                        ? 'O envio demorou mais que o esperado. Verifique a fila de impressão; se o problema continuar, avise a equipe de TI.'
                        : `Não foi possível enviar a impressão: ${error.message}`;
                    if (String(error.message || '').startsWith('O envio demorou')
                        || String(error.message || '').startsWith('O servidor retornou')
                        || String(error.message || '').startsWith('O arquivo é maior')) {
                        message = error.message;
                    }
                    showPrintResult(message, false);
                })
                .finally(() => {
                    clearTimeout(timeout);
                    clearInterval(interval);
                    setPrintingState(false);
                });
        });

        renderPrinterStatus(printerStatus);
        if (sharedMode && uploadToken) {
            currentPageData = sharedFile;
            currentPageAdvice = sharedFile.page_advice || '';
            if (sharedFile.large_document) applyLargeDocumentLayout(sharedFile);
            renderPreviewSummary();
            schedulePreview(0);
        }
        refreshPrinterStatus();
        setInterval(refreshPrinterStatus, 30000);

    </script>
    <script src="/js/pwa.js?v=20260623"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
