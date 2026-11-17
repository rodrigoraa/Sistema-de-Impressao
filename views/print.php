<?php
$sessionRole = $_SESSION['role'] ?? 'user';
$sessionName = $_SESSION['name'] ?? ($_SESSION['user'] ?? '');
$reviewMode = !empty($reviewMode);
$sharedMode = !empty($sharedMode);
$shareToken = (string) ($shareToken ?? '');
$sharedFile = is_array($sharedFile ?? null) ? $sharedFile : [];
$printFormAction = $sharedMode ? '/share-target.php' : '/';
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

    <link href="/css/vendor/bootstrap.min.css" rel="stylesheet">
    <link href="/css/vendor/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="/css/base.css?v=20261117">
    <link rel="stylesheet" href="/css/print.css?v=20261117-4">

</head>

<body>

    <div class="app-shell">

        <!-- HEADER -->
        <header class="app-header">
            <div class="container d-flex justify-content-between align-items-center">

                <div class="d-flex align-items-center gap-2">
                    <img src="/image/logo_escola.png" class="logo" alt="Emblema da escola">
                    <strong>Sistema de Impressão</strong>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <span class="user">
                        <i aria-hidden="true" class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($sessionName) ?>
                    </span>

                    <?php if ($sessionRole === 'admin'): ?>
                        <a href="/prints?status=active" class="btn btn-outline-light btn-sm">
                            <i aria-hidden="true" class="bi bi-list-task"></i> Fila
                        </a>
                    <?php endif; ?>

                    <a href="/logout" class="btn btn-outline-danger btn-sm">
                        <i aria-hidden="true" class="bi bi-box-arrow-right"></i> Sair
                    </a>
                </div>

            </div>
        </header>

        <!-- MAIN -->
        <main class="container py-4">
            <div class="page-title">
                <div>
                    <h1>Nova impressão</h1>
                    <p>Escolha o arquivo, confira as cópias e revise antes de enviar.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="/prints" id="trackPrint" class="btn btn-outline-secondary btn-sm">
                        <i aria-hidden="true" class="bi bi-clock-history"></i> Meus envios
                    </a>
                    <?php if ($sessionRole === 'admin'): ?>
                        <a href="/admin" class="btn btn-outline-secondary btn-sm">
                            <i aria-hidden="true" class="bi bi-speedometer2"></i> Painel
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ALERT -->
            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert print-notice print-notice-<?= ($_SESSION['flash_type'] ?? '') === 'error' ? 'danger' : 'success' ?>" role="status">
                    <i aria-hidden="true" class="bi bi-info-circle-fill"></i>
                    <div><strong><?= ($_SESSION['flash_type'] ?? '') === 'error' ? 'Atenção' : 'Informação sobre seu envio' ?></strong>
                        <p><?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            <div id="printResult" class="alert d-none" role="status" aria-live="polite" tabindex="-1"></div>
            <div id="printerStatusBox" class="alert d-none" role="status"></div>

            <div class="row g-4">

                <!-- FORM -->
                <div class="col-lg-6">

                    <div class="card shadow-sm border-0">
                        <div class="card-body">

                            <div class="card-title-line">
                                <h2 class="h5"><i aria-hidden="true" class="bi bi-printer"></i> Arquivo e cópias</h2>
                            </div>

                            <form novalidate id="printForm" method="post" enctype="multipart/form-data" <?= $printFormAction !== '' ? 'action="' . htmlspecialchars($printFormAction) . '"' : '' ?>>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="upload_token" id="uploadTokenInput" value="<?= htmlspecialchars((string) ($sharedFile['upload_token'] ?? '')) ?>">
                                <?php if ($sharedMode): ?>
                                    <input type="hidden" name="share_token" value="<?= htmlspecialchars($shareToken) ?>">
                                    <input type="hidden" name="share_action" value="print">
                                <?php endif; ?>

                                <div class="mb-3 upload-wrapper">

                                    <p class="form-label"><i aria-hidden="true" class="bi bi-file-earmark-arrow-up"></i> Arquivo</p>

                                    <?php if ($sharedMode || $reviewMode): ?>
                                        <div class="upload-box success shared-upload-box" id="drop-area">
                                            <i aria-hidden="true" class="bi bi-file-earmark-check fs-1"></i>
                                            <p id="file-label"><?= htmlspecialchars($sharedFile['original_name'] ?? 'Arquivo recebido') ?></p>
                                        </div>

                                        <div id="file-info" class="mt-2 small text-muted">
                                            <?= htmlspecialchars(($sharedFile['size_label'] ?? '') . (!empty($sharedFile['page_label']) ? ' · ' . $sharedFile['page_label'] : '')) ?>
                                        </div>

                                    <?php else: ?>
                                        <button type="button" class="upload-box w-100" id="drop-area" aria-describedby="file-help">
                                            <i aria-hidden="true" class="bi bi-cloud-upload fs-1"></i>
                                            <span id="file-label" class="d-block">Escolher arquivo ou arrastar aqui</span>

                                        </button>
                                            <input type="file" name="arquivo" id="fileInput" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp,.txt,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg,image/webp,text/plain" hidden>

                                        <div id="file-info" class="mt-2 small text-muted"></div>
                                    <?php endif; ?>

                                    <?php if ($reviewMode): ?>
                                        <input type="file" name="arquivo" id="fileInput" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp,.txt" hidden>
                                    <?php endif; ?>
                                    <?php if (!$sharedMode): ?>
                                        <div class="file-actions mt-2 d-flex gap-2">
                                            <button type="button" id="replaceFile" class="btn btn-outline-secondary btn-sm">Trocar arquivo</button>
                                            <button type="button" id="removeFile" class="btn btn-outline-danger btn-sm">Remover arquivo</button>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!$sharedMode): ?>
                                        <small id="file-help" class="text-muted">
                                            PDF, DOC, DOCX, TXT ou imagem (máx <?= (int) ($maxUploadMb ?? 20) ?>MB)
                                        </small>
                                    <?php endif; ?>

                                </div>

                                <div class="option-grid primary-options mb-3">
                                    <div>
                                        <label class="form-label" for="copies">Cópias</label>
                                        <input id="copies" type="number" class="form-control" name="copies" value="1" min="1" step="1" inputmode="numeric" aria-describedby="copies-help">
                                    </div>
                                    <div>
                                        <label class="form-label" for="sides">Como imprimir</label>
                                        <select id="sides" class="form-select" name="sides">
                                            <option value="one-sided">Frente apenas</option>
                                            <option value="two-sided-long-edge">Frente e verso — livro</option>
                                            <option value="two-sided-short-edge">Frente e verso — bloco</option>
                                        </select>
                                    </div>
                                    <div class="layout-option" id="layoutOption">
                                        <label class="form-label" for="number_up">Por folha</label>
                                        <select id="number_up" class="form-select" name="number_up" aria-label="Páginas por folha" aria-describedby="layout-help">
                                            <option value="1">1 por folha</option><option value="2">2 por folha</option><option value="4">4 por folha</option><option value="8">8 por folha</option>
                                        </select>
                                        <input type="hidden" name="large_document_layout" value="auto">
                                    </div>
                                    <small id="copies-help" class="wide text-muted copies-help">Para 30 alunos, use 30 cópias.</small>
                                </div>
                                <p id="layout-help" class="small text-muted">Em frente e verso, a quantidade por folha vale para cada lado do papel.</p>
                                <div id="pageAdviceHost" aria-live="polite" aria-atomic="true"></div>
                                <details class="advanced-options mb-3" id="advancedOptions">
                                    <summary>Mais opções de impressão</summary>
                                    <div class="option-grid mt-3">
                                        <div><label class="form-label" for="orientation">Orientação</label><select id="orientation" class="form-select" name="orientation"><option value="auto">Automática</option><option value="portrait">Retrato</option><option value="landscape">Paisagem</option></select></div>
                                        <div><label class="form-label" for="quality">Qualidade</label><select id="quality" class="form-select" name="quality"><option value="3">Normal</option><option value="5">Alta</option></select></div>

                                        <div><label class="form-label" for="paper">Tamanho do papel</label><select id="paper" class="form-select" name="paper"><option value="A4">A4</option><option value="Letter">Carta</option></select></div>
                                        <div><label class="form-label" for="scale">Escala</label><select id="scale" class="form-select" name="scale"><option value="fit">Ajustar à página</option><option value="100">100%</option><option value="95">95%</option><option value="90">90%</option><option value="80">80%</option><option value="custom">Personalizada</option></select></div>
                                        <div class="d-none" id="scaleCustomBox"><label class="form-label" for="scale_percent">Escala personalizada (%)</label><input id="scale_percent" type="number" class="form-control" name="scale_percent" value="100" min="10" max="400"></div>
                                        <div class="wide"><label class="form-label" for="page_ranges">Quais páginas?</label><input id="page_ranges" type="text" class="form-control" name="page_ranges" placeholder="Ex.: 1,3-5" aria-describedby="pages-help"><small id="pages-help" class="text-muted">Em branco, imprime todas. Separe páginas por vírgula e intervalos por hífen.</small></div>
                                    </div>
                                </details>
                                <div class="favorite-actions d-flex flex-wrap gap-2 mb-3">
                                    <button type="button" id="saveFavorite" class="btn btn-outline-secondary btn-sm">Salvar estas opções</button>
                                    <button type="button" id="loadFavorite" class="btn btn-outline-secondary btn-sm" disabled>Usar opções salvas</button>
                                </div>
                                <p id="favoriteFeedback" class="small text-muted" role="status">Salva neste navegador, sem arquivo nem quantidade de cópias.</p>
                                <p id="formError" class="text-danger" role="alert"></p>
                                <?php if ($sessionRole === 'admin'): ?>
                                    <div class="mb-3 admin-box">

                                        <label class="form-label" for="targetUserSearch">
                                            <i aria-hidden="true" class="bi bi-person-gear"></i> Imprimir para
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

                            </form>

                            <?php if ($sharedMode): ?>
                                <form novalidate id="shareCancelForm" method="post" action="/share-target.php" class="d-none">
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

                            <div class="card-title-line"><h2 class="h5">Confira antes de imprimir</h2></div>
                            <div id="paperEstimate" class="paper-estimate" role="status" aria-live="polite">
                                <strong id="estimateTotal">Estimativa: —</strong>
                            </div>
                            <p id="sendState" class="visually-hidden" role="status" aria-live="polite">Nenhum arquivo pronto para envio.</p>
                            <p id="previewSummary" class="preview-heading mt-3 mb-2 d-none">Prévia de amostra de como vai ficar a impressão</p>
                            <div id="preview"></div>

                            <div class="print-confirmation mt-3">
                                <div class="<?= $sharedMode ? 'print-actions' : '' ?>">
                                    <button type="submit" form="printForm" id="btnPrint" class="btn btn-primary w-100" <?= (($printerStatus['can_print'] ?? true) === false) ? 'disabled' : '' ?>>
                                        <span class="text"><?= $sharedMode ? 'Confirmar impressão' : 'Enviar para impressão' ?></span>
                                        <span class="spinner-border spinner-border-sm d-none"></span>
                                    </button>

                                    <?php if ($sharedMode): ?>
                                        <button type="submit" form="shareCancelForm" class="btn btn-outline-danger w-100">
                                            <i aria-hidden="true" class="bi bi-x-circle"></i> Descartar arquivo
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div id="progressBar" class="d-none" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Aguardando resposta do servidor…</div>
                            </div>

                        </div>
                    </div>

                </div>

            </div>

        </main>

    </div>

    <script src="/js/print-estimate.js?v=20261117"></script>
    <script>
        const dropArea = document.getElementById('drop-area');
        const input = document.getElementById('fileInput');
        const label = document.getElementById('file-label');
        const info = document.getElementById('file-info');
        const preview = document.getElementById('preview');
        const previewSummary = document.getElementById('previewSummary');
        const uploadTokenInput = document.getElementById('uploadTokenInput');
        const progress = document.getElementById('progressBar');

        const printForm = document.getElementById('printForm');
        const btnPrint = document.getElementById('btnPrint');
        const resultBox = document.getElementById('printResult');
        const csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
        const reviewOptions = <?= json_encode($reviewOptions ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const reviewMode = <?= json_encode($reviewMode) ?>;
        const sharedMode = <?= json_encode($sharedMode) ?>;
        const sharedFile = <?= json_encode($sharedFile, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const shareToken = <?= json_encode($shareToken) ?>;
        const adminUsers = <?= json_encode($userList ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let printerStatus = <?= json_encode($printerStatus ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let pageCountToken = 0;
        let previewToken = 0;
        let previewObjectUrl = '';
        let uploadToken = uploadTokenInput.value || '';
        let uploadController = null;
        let pageCountController = null;
        let previewController = null;
        let previewDebounceTimer = null;
        let currentPageData = null;
        let currentFileMeta = (sharedMode || reviewMode) ? sharedFile : null;
        let currentPageAdvice = '';
        let currentPageWarning = (sharedMode || reviewMode) ? (sharedFile.page_warning || sharedFile.warning || sharedFile.page_error || '') : '';
        let printingActive = false;
        let submissionLocked = false;
        let documentRecalculating = false;
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

        const favoriteKey = 'impressao.opcoes.v1';
        const favoriteFields = { sides: ['one-sided', 'two-sided-long-edge', 'two-sided-short-edge'], paper: ['A4', 'Letter'], orientation: ['auto', 'portrait', 'landscape'], quality: ['3', '5'], number_up: ['1', '2', '4', '8'], scale: ['fit', '100', '95', '90', '80'] };

        function paperEstimate() {
            return estimatePrintPaper({ pages: currentPageData && currentPageData.pages, copies: printForm.elements.copies.value,
                sides: sidesSelect.value, numberUp: numberUpSelect.value, range: printForm.elements.page_ranges.value });
        }

        function updateEstimate() {
            const total = document.getElementById('estimateTotal');
            const state = document.getElementById('sendState');
            const result = paperEstimate();
            if (!uploadToken || documentRecalculating || result.sheets === null) {
                total.textContent = !uploadToken ? 'Estimativa: —' : (documentRecalculating ? 'Estimativa: calculando…' : 'Estimativa: confira as opções');
            } else {
                total.textContent = `Estimativa: ${result.sheets} ${result.sheets === 1 ? 'folha' : 'folhas'}`;

            }
            if (!printingActive && !submissionLocked) state.textContent = uploadToken ? (documentRecalculating ? 'Recalculando o documento. Aguarde.' : 'Arquivo pronto. Confira as opções antes de enviar.') : (uploadController ? 'Enviando e validando o arquivo…' : 'Nenhum arquivo pronto para envio.');
        }

        function showFieldError(element, message) {
            document.getElementById('formError').textContent = message;
            element.setAttribute('aria-invalid', 'true');
            element.setAttribute('aria-describedby', 'formError');
            const disclosure = element.closest('details');
            if (disclosure) disclosure.open = true;
            element.focus();
            return false;
        }

        function validatePrintOptions() {
            document.getElementById('formError').textContent = '';
            printForm.querySelectorAll('[aria-invalid]').forEach(element => element.removeAttribute('aria-invalid'));
            const copies = printForm.elements.copies;
            if (!Number.isSafeInteger(Number(copies.value)) || Number(copies.value) < 1) return showFieldError(copies, 'Informe uma quantidade inteira de cópias, a partir de 1.');
            if (scaleSelect.value === 'custom') {
                const scale = printForm.elements.scale_percent;
                if (!Number.isFinite(Number(scale.value)) || Number(scale.value) < 10 || Number(scale.value) > 400) return showFieldError(scale, 'Informe uma escala entre 10% e 400%.');
            }
            if (uploadToken && paperEstimate().sheets === null) return showFieldError(printForm.elements.page_ranges, 'Confira o intervalo de páginas. Use, por exemplo, 1,3-5 e não ultrapasse o total do documento.');
            return true;
        }

        function clearSelectedFile() {
            clearTimeout(previewDebounceTimer);
            [uploadController, pageCountController, previewController].forEach(controller => { if (controller) controller.abort(); });
            uploadController = pageCountController = previewController = null;
            if (submissionLocked) printForm.elements.copies.value = '1';
            pageCountToken++;
            previewToken++;
            resetPreviewObjectUrl();
            uploadToken = '';
            uploadTokenInput.value = '';
            currentPageData = currentFileMeta = null;
            documentRecalculating = false;
            submissionLocked = false;
            if (input) input.value = '';
            label.textContent = 'Escolher arquivo ou arrastar aqui';
            info.textContent = '';
            preview.innerHTML = '';
            currentPageAdvice = '';
            currentPageWarning = '';
            renderPageAdvice();
            previewSummary.classList.add('d-none');
            dropArea.classList.remove('success', 'error', 'drag-over');
            resultBox.classList.add('d-none');
            renderPrinterStatus(printerStatus);
        }

        printForm.addEventListener('input', event => {
            if (event.target.hasAttribute('aria-invalid')) {
                event.target.removeAttribute('aria-invalid');
                document.getElementById('formError').textContent = '';
            }
            updateEstimate();
        });
        printForm.addEventListener('change', updateEstimate);
        if (input) {
            document.getElementById('replaceFile').addEventListener('click', () => input.click());
            document.getElementById('removeFile').addEventListener('click', () => { clearSelectedFile(); document.getElementById('replaceFile').focus(); });
            ['dragover', 'drop'].forEach(name => dropArea.addEventListener(name, event => event.preventDefault()));
            dropArea.addEventListener('dragover', () => { if (!printingActive) dropArea.classList.add('drag-over'); });
            dropArea.addEventListener('dragleave', () => dropArea.classList.remove('drag-over'));
            dropArea.addEventListener('drop', event => {
                dropArea.classList.remove('drag-over');
                if (printingActive || !event.dataTransfer.files.length) return;
                if (event.dataTransfer.files.length !== 1) { showPrintResult('Envie um arquivo por vez. Selecione o documento que deseja imprimir.', false); return; }
                input.files = event.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            });
        }

        document.getElementById('saveFavorite').addEventListener('click', () => {
            const feedback = document.getElementById('favoriteFeedback');
            try {
                const values = {};
                Object.entries(favoriteFields).forEach(([name, allowed]) => { const value = printForm.elements[name].value; if (allowed.includes(value)) values[name] = value; });
                localStorage.setItem(favoriteKey, JSON.stringify(values));
                document.getElementById('loadFavorite').disabled = false;
                feedback.textContent = 'Opções salvas neste navegador. Arquivo, cópias e seleção de páginas não são salvos.';
            } catch { feedback.textContent = 'O navegador não permitiu salvar. Você pode continuar imprimindo normalmente.'; }
        });
        document.getElementById('loadFavorite').addEventListener('click', () => {
            try {
                const values = JSON.parse(localStorage.getItem(favoriteKey));
                if (!values || typeof values !== 'object') throw new Error();
                Object.entries(favoriteFields).forEach(([name, allowed]) => { if (allowed.includes(values[name])) printForm.elements[name].value = values[name]; });
                printForm.elements.copies.value = '1';
                printForm.elements.page_ranges.value = '';
                largeDocumentLayout.value = 'manual';
                layoutChangedByUser = true;
                currentPageAdvice = '';
                renderPageAdvice();
                scaleCustomBox.classList.toggle('d-none', scaleSelect.value !== 'custom');
                document.getElementById('favoriteFeedback').textContent = 'Opções aplicadas. Confira a quantidade: 1 cópia.';
                if (uploadToken) {
                    documentRecalculating = true;
                    loadPageCount(++pageCountToken);
                }
                renderPrinterStatus(printerStatus);
            } catch { document.getElementById('favoriteFeedback').textContent = 'Não foi possível recuperar as opções. Ajuste os campos para continuar.'; }
        });
        try { document.getElementById('loadFavorite').disabled = !localStorage.getItem(favoriteKey); } catch { /* Favorites are optional. */ }

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
                currentPageAdvice = '';
                if (largeDocumentLayout) {
                    largeDocumentLayout.value = 'manual';
                }
                renderPageAdvice();
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

        function renderPreviewSummary() {
            updateEstimate();
            previewSummary.classList.toggle('d-none', !uploadToken);
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
            preview.innerHTML = '<div class="preview-loading"><span class="spinner-border spinner-border-sm"></span><span>Preparando arquivo…</span></div>';
            previewSummary.classList.add('d-none');
            btnPrint.disabled = true;
            updateEstimate();

            const requestedPaper = printForm.elements.paper.value;
            const requestedOrientation = printForm.elements.orientation.value;
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
                if (uploadController !== controller) return;
                uploadToken = data.upload_token;
                uploadTokenInput.value = uploadToken;
                documentRecalculating = requestedPaper !== printForm.elements.paper.value || requestedOrientation !== printForm.elements.orientation.value;
                currentPageData = data;
                currentFileMeta = { ...currentFileMeta, ...data };
                info.textContent = pageInfoText(data);
                currentPageAdvice = layoutChangedByUser ? '' : (data.advice || '');
                currentPageWarning = data.warning || '';
                applyLargeDocumentLayout(data);
                renderPageAdvice();
                renderPreviewSummary();
                if (documentRecalculating) {
                    loadPageCount(++pageCountToken);
                } else {
                    schedulePreview(0);
                }
            } catch (error) {
                if (error.name === 'AbortError' || uploadController !== controller) return;
                dropArea.classList.remove('success');
                dropArea.classList.add('error');
                preview.innerHTML = `<p class="text-danger">${escapeHtml(error.message)}</p>`;
                info.textContent = `${formatSize(file.size)} · falha no envio`;
                currentPageWarning = error.message;
                renderPageAdvice();
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
            preview.innerHTML = '<div class="preview-loading"><span class="spinner-border spinner-border-sm"></span><span>Preparando prévia…</span></div>';
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
                const blob = await response.blob();
                if (token !== previewToken) return;
                resetPreviewObjectUrl();
                previewObjectUrl = URL.createObjectURL(blob);
                preview.innerHTML = `<iframe src="${previewObjectUrl}" title="Pré-visualização da impressão"></iframe>`;
                renderPreviewSummary();
            } catch (error) {
                if (error.name === 'AbortError' || token !== previewToken) return;
                resetPreviewObjectUrl();
                preview.innerHTML = `<div class="print-notice print-notice-warning mb-0"><i aria-hidden="true" class="bi bi-exclamation-triangle-fill"></i><div><strong>Prévia indisponível</strong><p>${escapeHtml(error.message || 'Não foi possível gerar a pré-visualização, mas o arquivo pode ser enviado para impressão.')}</p></div></div>`;
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
                currentPageAdvice = layoutChangedByUser ? '' : (data.advice || '');
                currentPageWarning = data.warning || '';
                applyLargeDocumentLayout(data);
                renderPageAdvice();
                schedulePreview();
            } catch (error) {
                if (error.name === 'AbortError' || token !== pageCountToken) return;
                currentPageData = null;
                currentPageWarning = 'Não foi possível recalcular as páginas. Confira a conexão e tente alterar o papel ou orientação novamente.';
                renderPageAdvice();
                info.textContent = 'Não foi possível recalcular. Confira a conexão e tente alterar o papel ou orientação novamente.';
                schedulePreview();
            } finally {
                if (pageCountController === controller) {
                    pageCountController = null;
                    documentRecalculating = false;
                    renderPrinterStatus(printerStatus);
                    updateEstimate();
                }
            }
        }

        if (!sharedMode && input) {
            input.addEventListener('change', () => {
                const file = input.files[0];
                if (!file) return;
                clearSelectedFile();
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
                    currentPageWarning = 'Este tipo de arquivo não é aceito. Escolha PDF, DOC, DOCX, TXT ou imagem.';
                    renderPageAdvice();
                    dropArea.classList.add('error');
                    input.value = '';
                    return;
                }
                if (file.size > maxUploadMb * 1024 * 1024) {
                    label.textContent = `Arquivo muito grande (máx ${maxUploadMb}MB)`;
                    currentPageWarning = `O arquivo ultrapassa o limite de ${maxUploadMb} MB. Escolha um arquivo menor.`;
                    renderPageAdvice();
                    dropArea.classList.add('error');
                    input.value = '';
                    return;
                }
                input.value = '';
                label.textContent = file.name;
                dropArea.classList.add('success');
                uploadSelectedFile(file);
            });
        }

        function renderPageAdvice() {
            const host = document.getElementById('pageAdviceHost');
            const automatic = Boolean(uploadToken) && largeDocumentLayout.value === 'auto_applied' && numberUpSelect.value === '2';
            document.getElementById('layoutOption').classList.toggle('is-automatic', automatic);
            const notices = [];
            if (automatic) {
                notices.push(`<div id="pageAdvice" class="print-notice print-notice-warning"><i aria-hidden="true" class="bi bi-exclamation-triangle-fill"></i><div><strong>Atenção: 2 por folha ativado automaticamente</strong><p>O sistema ajustou este documento para 2 páginas em cada lado do papel. Confira a prévia antes de enviar. Para mudar, use o campo “Por folha” acima.</p></div></div>`);
            } else if (currentPageAdvice && (!currentPageData?.large_document || reviewMode)) {
                notices.push(`<div id="pageAdvice" class="print-notice print-notice-info"><i aria-hidden="true" class="bi bi-info-circle-fill"></i><div><strong>Confira antes de imprimir</strong><p>${escapeHtml(currentPageAdvice)}</p></div></div>`);
            }
            if (currentPageWarning) {
                notices.push(`<div class="print-notice print-notice-warning"><i aria-hidden="true" class="bi bi-exclamation-triangle-fill"></i><div><strong>Atenção ao arquivo</strong><p>${escapeHtml(currentPageWarning)}</p></div></div>`);
            }
            const markup = notices.join('');
            if (host.innerHTML !== markup) host.innerHTML = markup;
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
                documentRecalculating = true;
                renderPreviewSummary();
                renderPrinterStatus(printerStatus);
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
            resultBox.className = `alert print-notice print-notice-${success ? 'success' : 'danger'}`;
            resultBox.setAttribute('role', success ? 'status' : 'alert');
            resultBox.setAttribute('aria-live', success ? 'polite' : 'assertive');
            resultBox.innerHTML = `<i aria-hidden="true" class="bi ${success ? 'bi-check-circle-fill' : 'bi-exclamation-octagon-fill'}"></i><div><strong>${success ? 'Resposta do envio' : 'Atenção ao envio'}</strong><p>${escapeHtml(message)}</p></div>`;
            resultBox.focus({ preventScroll: true });
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
            const awaitingUpload = !uploadToken || documentRecalculating || submissionLocked;
            updateEstimate();
            if (!notice) {
                printerStatusBox.classList.add('d-none');
                if (!printingActive) {
                    btnPrint.disabled = awaitingUpload || (sharedMode && sharedPrintCompleted);
                }
                return;
            }

            const type = ['warning', 'danger', 'info', 'success'].includes(printerStatus.notice_type)
                ? printerStatus.notice_type : (printerStatus.can_print === false ? 'warning' : 'success');
            printerStatusBox.className = `alert print-notice print-notice-${type}`;
            const title = printerStatus.can_print === false ? 'Atenção: impressora indisponível' : (['warning', 'danger'].includes(type) ? 'Atenção à impressora' : 'Situação da impressora');
            if (printerStatusBox.dataset.notice !== notice || printerStatusBox.dataset.title !== title || printerStatusBox.dataset.type !== type) {
                printerStatusBox.innerHTML = `<i aria-hidden="true" class="bi bi-printer-fill"></i><div><strong>${title}</strong><p>${escapeHtml(notice)}</p></div>`;
                printerStatusBox.dataset.notice = notice;
                printerStatusBox.dataset.title = title;
                printerStatusBox.dataset.type = type;
            }
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
            const awaitingUpload = !uploadToken || documentRecalculating || submissionLocked;
            btnPrint.disabled = active || awaitingUpload || printerStatus.can_print === false || (sharedMode && sharedPrintCompleted);
            text.textContent = active ? 'Enviando...' : (submissionLocked ? 'Confira em Meus envios' : null) || (sharedMode && sharedPrintCompleted ? 'Enviado' : (sharedMode ? 'Confirmar impressão' : 'Enviar para impressão'));
            spinner.classList.toggle('d-none', !active);
            progress.classList.toggle('d-none', !active);
            printForm.querySelectorAll('input, select, button').forEach(element => { element.disabled = active; });
            if (!active) {
                try { document.getElementById('loadFavorite').disabled = !localStorage.getItem(favoriteKey); } catch { document.getElementById('loadFavorite').disabled = true; }
            }
            document.getElementById('sendState').textContent = active ? 'Enviando à impressora. Aguarde a resposta.' : (submissionLocked ? 'Confira o resultado em Meus envios antes de uma nova impressão.' : (uploadToken ? 'Arquivo pronto. Confira as opções antes de enviar.' : 'Escolha um arquivo para preparar o envio.'));
            updateEstimate();
        }

        // envio AJAX para exibir erros sem deixar a pagina presa carregando
        printForm.addEventListener('submit', (event) => {
            event.preventDefault();
            if (printingActive || submissionLocked || documentRecalculating) return;
            if (!validatePrintOptions()) return;
            if (!uploadToken) {
                showPrintResult('Aguarde o término do envio e da validação do arquivo antes de imprimir.', false);
                return;
            }
            if (!syncTargetUserCpf()) {
                showFieldError(targetUserSearch, 'Selecione um professor da lista.');
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
            const printData = new FormData(printForm);
            setPrintingState(true);



            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 300000);

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
                    if (data.success === undefined) submissionLocked = true;
                    showPrintResult(data.message || 'Confira Meus envios para verificar o resultado.', !!data.success);
                    if (data.success) {
                        if (sharedMode) {
                            sharedPrintCompleted = true;
                        } else {
                            uploadToken = '';
                            uploadTokenInput.value = '';
                        }
                        submissionLocked = true;
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
                    submissionLocked = true;
                    showPrintResult(message + ' Confira Meus envios antes de tentar novamente.', false);
                })
                .finally(() => {
                    clearTimeout(timeout);

                    setPrintingState(false);
                });
        });

        if (reviewMode) {
            Object.entries(reviewOptions).forEach(([name, value]) => { if (printForm.elements[name]) printForm.elements[name].value = value; });
            largeDocumentLayout.value = 'manual';
            layoutChangedByUser = true;
            if (printForm.elements.page_ranges.value) document.getElementById('advancedOptions').open = true;
        }
        renderPrinterStatus(printerStatus);
        if ((sharedMode || reviewMode) && uploadToken) {
            currentPageData = sharedFile;
            currentPageAdvice = sharedFile.page_advice || '';
            if (sharedMode && sharedFile.large_document) applyLargeDocumentLayout(sharedFile);
            renderPageAdvice();
            renderPreviewSummary();
            schedulePreview(0);
        }
        refreshPrinterStatus();
        setInterval(refreshPrinterStatus, 30000);

    </script>
    <script src="/js/pwa.js?v=20260623"></script>
    <script src="/js/vendor/bootstrap.bundle.min.js"></script>
</body>

</html>
