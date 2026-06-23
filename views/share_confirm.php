<?php
$sessionRole = $_SESSION['role'] ?? 'user';
$sessionName = $_SESSION['name'] ?? ($_SESSION['user'] ?? '');
$canPrint = ($printerStatus['can_print'] ?? true) !== false;
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
    <title>Confirmar impressão compartilhada</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="/css/base.css?v=20260623">
    <link rel="stylesheet" href="/css/print.css?v=20260623">
</head>

<body>
    <div class="app-shell">
        <header class="app-header">
            <div class="container d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <img src="/image/logo_escola.png" class="logo" alt="">
                    <strong>Sistema de Impressão</strong>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <span class="user">
                        <i class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($sessionName) ?>
                    </span>

                    <a href="/logout" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-box-arrow-right"></i> Sair
                    </a>
                </div>
            </div>
        </header>

        <main class="container py-4 share-confirm-page">
            <div class="page-title">
                <div>
                    <h1>Confirmar impressão</h1>
                    <p>Revise o arquivo recebido e escolha as opções antes de enviar.</p>
                </div>
                <a href="/" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-house"></i> Início
                </a>
            </div>

            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert alert-<?= ($_SESSION['flash_type'] ?? '') === 'error' ? 'danger' : 'success' ?>">
                    <?= htmlspecialchars($_SESSION['flash']);
                    unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($printerStatus['notice'])): ?>
                <div class="alert alert-<?= htmlspecialchars($printerStatus['notice_type'] ?? 'warning') ?> printer-status-alert">
                    <i class="bi bi-printer"></i>
                    <span><?= htmlspecialchars($printerStatus['notice']) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="/share-target.php" class="share-confirm-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="share_token" value="<?= htmlspecialchars($shareToken) ?>">

                <section class="card shadow-sm border-0 share-file-card">
                    <div class="card-body">
                        <div class="card-title-line">
                            <h4><i class="bi bi-file-earmark-check"></i> Arquivo recebido</h4>
                        </div>

                        <dl class="share-file-details">
                            <div>
                                <dt>Nome</dt>
                                <dd><?= htmlspecialchars($sharedFile['original_name']) ?></dd>
                            </div>
                            <div>
                                <dt>Tipo</dt>
                                <dd><?= htmlspecialchars(strtoupper($sharedFile['extension'])) ?> · <?= htmlspecialchars($sharedFile['mime_type'] ?: 'tipo não informado') ?></dd>
                            </div>
                            <div>
                                <dt>Tamanho</dt>
                                <dd><?= htmlspecialchars($sharedFile['size_label']) ?></dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="card-title-line">
                            <h4><i class="bi bi-sliders"></i> Opções de impressão</h4>
                        </div>

                        <div class="option-grid mb-3">
                            <div>
                                <label class="form-label">Cópias</label>
                                <input type="number" class="form-control form-control-lg" name="copies" value="1" min="1">
                            </div>

                            <div>
                                <label class="form-label">Modo</label>
                                <select class="form-select form-select-lg" name="sides">
                                    <option value="one-sided">Simples</option>
                                    <option value="two-sided-long-edge">Frente e verso - borda maior</option>
                                    <option value="two-sided-short-edge">Frente e verso - borda menor</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Orientação</label>
                                <select class="form-select form-select-lg" name="orientation">
                                    <option value="auto" selected>Automática</option>
                                    <option value="portrait">Retrato</option>
                                    <option value="landscape">Paisagem</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Qualidade</label>
                                <select class="form-select form-select-lg" name="quality">
                                    <option value="3">Normal</option>
                                    <option value="5">Alta</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Páginas por folha</label>
                                <select class="form-select form-select-lg" name="number_up">
                                    <option value="1">1 por folha</option>
                                    <option value="2">2 por folha</option>
                                    <option value="4">4 por folha</option>
                                    <option value="8">8 por folha</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Tamanho do papel</label>
                                <select class="form-select form-select-lg" name="paper">
                                    <option value="A4">A4</option>
                                    <option value="Letter">Carta</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Escala</label>
                                <select class="form-select form-select-lg" name="scale" id="shareScale">
                                    <option value="fit">Ajustar à página</option>
                                    <option value="100">100%</option>
                                    <option value="95">95%</option>
                                    <option value="90">90%</option>
                                    <option value="80">80%</option>
                                    <option value="custom">Personalizada</option>
                                </select>
                            </div>

                            <div class="d-none" id="shareScaleCustomBox">
                                <label class="form-label">Escala personalizada (%)</label>
                                <input type="number" class="form-control form-control-lg" name="scale_percent" value="100" min="10" max="400">
                            </div>

                            <div class="wide">
                                <label class="form-label">Páginas</label>
                                <input type="text" class="form-control form-control-lg" name="page_ranges" placeholder="Ex.: 1,3-5">
                                <small class="text-muted">Deixe em branco para imprimir todas</small>
                            </div>
                        </div>

                        <?php if ($sessionRole === 'admin'): ?>
                            <div class="mb-3 admin-box">
                                <label class="form-label">
                                    <i class="bi bi-person-gear"></i> Imprimir para
                                </label>

                                <input list="shareUsers" name="target_user_search" id="targetUserSearch" class="form-control form-control-lg" autocomplete="off">
                                <input type="hidden" name="target_user" id="targetUserCpf">

                                <datalist id="shareUsers">
                                    <?php foreach ($userList as $u): ?>
                                        <option value="<?= htmlspecialchars($u['name'] . ' - ' . $u['cpf']) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>

                                <small class="text-muted">
                                    Selecione o professor pelo nome para contabilizar no CPF correto
                                </small>
                            </div>
                        <?php endif; ?>

                        <div class="share-actions">
                            <button class="btn btn-primary btn-lg" name="share_action" value="print" <?= $canPrint ? '' : 'disabled' ?>>
                                <i class="bi bi-printer"></i> Confirmar impressão
                            </button>
                            <button class="btn btn-outline-danger btn-lg" name="share_action" value="cancel" formnovalidate>
                                <i class="bi bi-x-circle"></i> Cancelar
                            </button>
                        </div>
                    </div>
                </section>
            </form>
        </main>
    </div>

    <script>
        const scaleSelect = document.getElementById('shareScale');
        const scaleCustomBox = document.getElementById('shareScaleCustomBox');
        if (scaleSelect && scaleCustomBox) {
            scaleSelect.addEventListener('change', () => {
                scaleCustomBox.classList.toggle('d-none', scaleSelect.value !== 'custom');
            });
        }

        const adminUsers = <?= json_encode($userList ?? [], JSON_UNESCAPED_UNICODE) ?>;
        const targetUserSearch = document.getElementById('targetUserSearch');
        const targetUserCpf = document.getElementById('targetUserCpf');

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
            document.querySelector('.share-confirm-form').addEventListener('submit', (event) => {
                const submitter = event.submitter;
                if (submitter && submitter.value === 'cancel') return;
                if (!syncTargetUserCpf()) {
                    event.preventDefault();
                    event.currentTarget.reportValidity();
                }
            });
        }
    </script>
    <script src="/js/pwa.js?v=20260623"></script>
</body>

</html>
