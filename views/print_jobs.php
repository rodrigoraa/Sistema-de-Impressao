<?php
$traduzDiagnostico = function ($valor) {
    $mapa = [
        'timeout' => 'Tempo esgotado',
        'job cancelado' => 'Trabalho cancelado',
        'impressora nao aceita jobs' => 'Impressora não aceita impressões',
        'impressora nao aceita impressoes' => 'Impressora não aceita impressões',
        'lp_failed' => 'Falha no comando de impressão',
        'preflight_failed' => 'Falha antes do envio',
        'accepted_unverified' => 'Aceito, sem confirmação',
        'accepted_unidentified' => 'Aceito, ID não identificado',
        'failed_or_canceled' => 'Falhou ou foi cancelado',
        'printer_fault' => 'Falha detectada na impressora',
        'left_queue' => 'Saiu da fila',
    ];
    $valor = trim((string) $valor);
    if (isset($mapa[$valor])) {
        return $mapa[$valor];
    }

    return str_ireplace(
        ['paused', 'disabled', 'enabled', 'accepting requests', 'not accepting requests', 'idle', 'printing', 'stopped', 'filter failed', 'paper jam', 'out of paper', 'toner low', 'offline', 'timeout'],
        ['pausada', 'desativada', 'ativada', 'aceitando impressões', 'recusando impressões', 'ociosa', 'imprimindo', 'parada', 'falha no filtro', 'atolamento de papel', 'sem papel', 'toner baixo', 'offline', 'tempo esgotado'],
        $valor
    );
};
$statusLabels = [
    'queued' => ['label' => 'Na fila', 'class' => 'text-bg-secondary', 'icon' => 'bi-hourglass-split'],
    'processing' => ['label' => 'Enviando', 'class' => 'text-bg-info', 'icon' => 'bi-arrow-repeat'],
    'completed' => ['label' => 'Impresso', 'class' => 'text-bg-success', 'icon' => 'bi-check-circle'],
    'failed' => ['label' => 'Erro', 'class' => 'text-bg-danger', 'icon' => 'bi-exclamation-triangle'],
];
$cupsLabels = [
    'accepted' => ['label' => 'Aceito pelo servidor', 'class' => 'text-bg-warning'],
    'accepted_unverified' => ['label' => 'Aceito, sem confirmação final', 'class' => 'text-bg-warning'],
    'accepted_unidentified' => ['label' => 'Aceito, sem número na fila', 'class' => 'text-bg-warning'],
    'completed' => ['label' => 'Conclusão confirmada', 'class' => 'text-bg-success'],
    'failed_or_canceled' => ['label' => 'Falhou ou foi cancelado', 'class' => 'text-bg-danger'],
    'printer_fault' => ['label' => 'Falha detectada na impressora', 'class' => 'text-bg-danger'],
    'left_queue' => ['label' => 'Saiu da fila, sem confirmação final', 'class' => 'text-bg-warning'],
    'timeout' => ['label' => 'Tempo esgotado', 'class' => 'text-bg-danger'],
    'preflight_failed' => ['label' => 'Falha antes do envio', 'class' => 'text-bg-danger'],
    'lp_failed' => ['label' => 'Falha no comando de impressão', 'class' => 'text-bg-danger'],
    'falha_pre_validacao' => ['label' => 'Falha antes do envio', 'class' => 'text-bg-danger'],
    'usage_legacy' => ['label' => 'Registro antigo', 'class' => 'text-bg-secondary'],
];
$statusMetaForJob = function ($job) use ($statusLabels) {
    $status = (string) ($job['status'] ?? '');
    $cups = (string) ($job['status_cups'] ?? '');
    if ($status === 'completed' && $cups === 'completed') {
        return ['label' => 'Confirmado', 'class' => 'text-bg-success', 'icon' => 'bi-check-circle'];
    }
    if ($status === 'completed' && in_array($cups, ['accepted', 'accepted_unverified', 'accepted_unidentified', 'left_queue'], true)) {
        return ['label' => 'Aceito', 'class' => 'text-bg-warning', 'icon' => 'bi-printer'];
    }

    return $statusLabels[$status] ?? $statusLabels['queued'];
};
$simNao = function ($valor) {
    if ($valor === null || $valor === '') {
        return 'N/D';
    }

    return ((int) $valor) === 1 ? 'Sim' : 'Não';
};
$modoImpressao = function ($sides) {
    return [
        'one-sided' => 'Simples',
        'two-sided-long-edge' => 'Frente e verso - borda maior',
        'two-sided-short-edge' => 'Frente e verso - borda menor',
    ][$sides] ?? ($sides ?: 'Simples');
};
$selectionLabel = function ($job) {
    $label = trim((string) ($job['selected_pages_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    $pages = max(0, (int) ($job['pages'] ?? 0));
    if ($pages <= 0) {
        return 'Não informado';
    }

    return $pages === 1 ? 'Página 1' : "Todas as páginas ({$pages})";
};
$selectedCount = function ($job) {
    $selected = (int) ($job['selected_pages_count'] ?? 0);
    if ($selected > 0) {
        return $selected;
    }

    return max(0, (int) ($job['pages'] ?? 0));
};
$hasPartialSelection = function ($job) {
    $pages = max(0, (int) ($job['pages'] ?? 0));
    $selected = max(0, (int) ($job['selected_pages_count'] ?? 0));
    $label = trim((string) ($job['selected_pages_label'] ?? ''));

    return $pages > 0 && $selected > 0 && $selected < $pages && $label !== '';
};
$statusFilters = [
    '' => 'Todo o histórico',
    'completed' => 'Concluídas',
    'failed' => 'Erros',
];
if ($isAdmin) {
    $statusFilters = [
        '' => 'Todo o histórico',
        'active' => 'Fila',
        'queued' => 'Na fila',
        'processing' => 'Enviando',
        'completed' => 'Concluídas',
        'failed' => 'Erros',
    ];
}
$currentStatus = $_GET['status'] ?? '';
$pageHeading = $isAdmin ? 'Histórico completo de impressão' : 'Meu histórico de impressão';
$pageDescription = $isAdmin ? 'Todas as impressões de todos os professores' : 'Minhas impressões e tentativas';
if ($currentStatus === 'active') {
    $pageHeading = 'Fila de impressão';
    $pageDescription = 'Pendentes e em envio';
} elseif ($currentStatus === 'completed') {
    $pageHeading = 'Impressões concluídas';
    $pageDescription = $isAdmin ? 'Impressões concluídas de todos os professores' : 'Minhas impressões concluídas';
} elseif ($currentStatus === 'failed') {
    $pageHeading = 'Impressões com erro';
    $pageDescription = 'Trabalhos que precisam de atenção';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/favicon.ico?v=2">
    <title><?= htmlspecialchars($pageHeading) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/base.css?v=20260601">
    <link rel="stylesheet" href="/css/admin.css?v=20260602">
</head>

<body>
    <div class="app-shell">
        <header class="app-header">
            <div class="container d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <img src="/image/logo_escola.png" class="logo">
                    <strong><?= htmlspecialchars($pageHeading) ?></strong>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="user"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['name'] ?? $_SESSION['user']) ?></span>
                    <a href="/logout" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Sair</a>
                </div>
            </div>
        </header>

        <main class="container-fluid history-page py-4">
            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert alert-<?= ($_SESSION['flash_type'] ?? '') === 'error' ? 'danger' : 'success' ?>">
                    <?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
                </div>
            <?php endif; ?>

            <section class="page-title">
                <div>
                    <p class="section-kicker mb-1"><?= $isAdmin ? 'Administração' : 'Professor' ?></p>
                    <h1><?= htmlspecialchars($pageHeading) ?></h1>
                    <p class="text-muted mb-0"><?= htmlspecialchars($pageDescription) ?></p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($isAdmin): ?>
                        <a href="/admin" class="btn btn-outline-secondary"><i class="bi bi-speedometer2"></i> Painel</a>
                    <?php endif; ?>
                    <a href="/" class="btn btn-primary"><i class="bi bi-printer"></i> Nova impressão</a>
                </div>
            </section>

            <section class="metric-grid">
                <div class="metric"><span>Total</span><strong><?= (int) $stats['total'] ?></strong></div>
                <div class="metric"><span>Impressas</span><strong><?= (int) $stats['completed'] ?></strong></div>
                <div class="metric"><span>Na fila</span><strong><?= (int) $stats['active'] ?></strong></div>
                <div class="metric"><span>Erros</span><strong><?= (int) $stats['failed'] ?></strong></div>
                <div class="metric"><span>Folhas contabilizadas</span><strong><?= (int) $stats['charged'] ?></strong></div>
            </section>

            <section class="panel">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Situação</label>
                        <select name="status" class="form-select">
                            <?php foreach ($statusFilters as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($isAdmin): ?>
                        <div class="col-md-3">
                            <label class="form-label">CPF</label>
                            <input name="cpf" class="form-control" value="<?= htmlspecialchars($_GET['cpf'] ?? '') ?>" placeholder="Todos">
                        </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label class="form-label">Mês</label>
                        <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($_GET['month'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Erro</label>
                        <input name="error" class="form-control" value="<?= htmlspecialchars($_GET['error'] ?? '') ?>" placeholder="papel, toner, tempo esgotado...">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrar</button>
                    </div>
                </form>
            </section>

            <section class="panel history-table-panel">
                <div class="table-responsive history-table-wrap">
                    <table class="table table-hover align-middle data-table history-table">
                        <thead>
                            <tr>
                                <th>Arquivo</th>
                                <?php if ($isAdmin): ?><th>Professor</th><?php endif; ?>
                                <th>Situação</th>
                                <th>Resumo da impressão</th>
                                <th>Folhas</th>
                                <th>Data</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($jobs)): ?>
                                <tr><td colspan="<?= $isAdmin ? 7 : 6 ?>" class="text-center text-muted py-4">Nenhuma impressão encontrada</td></tr>
                            <?php else: ?>
                                <?php foreach ($jobs as $job): ?>
                                    <?php
                                        $meta = $statusMetaForJob($job);
                                        $isLegacyUsage = !empty($job['legacy_usage']);
                                        $cupsStatus = (string) ($job['status_cups'] ?? '');
                                        $cupsMeta = $cupsLabels[$cupsStatus] ?? ['label' => ($cupsStatus !== '' ? $cupsStatus : 'Não informado'), 'class' => 'text-bg-secondary'];
                                        $selected = $selectedCount($job);
                                        $partialSelection = $hasPartialSelection($job);
                                        $sheetsPerCopy = max(1, (int) ceil(max(1, $selected) / max(1, (int) ($job['number_up'] ?? 1))));
                                    ?>
                                    <tr>
                                        <td data-label="Arquivo">
                                            <div class="fw-semibold text-truncate file-name"><?= htmlspecialchars($job['original_name']) ?></div>
                                            <?php if (!empty($job['error_message'])): ?>
                                                <small class="text-danger"><?= htmlspecialchars($traduzDiagnostico($job['error_message'])) ?></small>
                                            <?php else: ?>
                                                <small class="text-muted"><?= strtoupper(htmlspecialchars($job['source_ext'] ?? '')) ?> · <?= (int) $job['pages'] ?> pág.</small>
                                            <?php endif; ?>
                                            <?php if ($isLegacyUsage): ?>
                                                <small class="d-block text-muted">Registro antigo do acumulado mensal</small>
                                            <?php else: ?>
                                                <small class="d-block text-muted">
                                                    Servidor: <?= htmlspecialchars($job['cups_job_id'] ?: '-') ?>
                                                    <span class="badge <?= htmlspecialchars($cupsMeta['class']) ?>"><?= htmlspecialchars($cupsMeta['label']) ?></span>
                                                </small>
                                                <?php if ($isAdmin): ?>
                                                    <small class="d-block text-muted">
                                                        No envio: ativada <?= htmlspecialchars($simNao($job['printer_enabled'] ?? null)) ?>,
                                                        aceitando <?= htmlspecialchars($simNao($job['printer_accepting'] ?? null)) ?>,
                                                        mensagem <?= htmlspecialchars($traduzDiagnostico($job['printer_state_message'] ?: ($job['error_category'] ?: 'sem erro registrado'))) ?>.
                                                    </small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($job['observations'])): ?>
                                                <small class="d-block text-muted"><?= nl2br(htmlspecialchars($job['observations'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($isAdmin): ?>
                                            <td data-label="Professor"><?= htmlspecialchars($job['user_name'] ?: $job['user']) ?><br><small class="text-muted"><?= htmlspecialchars($job['user']) ?></small></td>
                                        <?php endif; ?>
                                        <td data-label="Situação"><span class="badge <?= $meta['class'] ?>"><i class="bi <?= $meta['icon'] ?>"></i> <?= $meta['label'] ?></span></td>
                                        <?php
                                            $orientationPt = [
                                                'auto' => 'Automática',
                                                'portrait' => 'Retrato',
                                                'landscape' => 'Paisagem',
                                            ][$job['orientation']] ?? ($job['orientation'] ?: 'Retrato');
                                            $paperPt = ($job['paper'] ?? '') === 'Letter' ? 'Carta' : ($job['paper'] ?: 'A4');
                                        ?>
                                        <td data-label="Resumo">
                                            <div class="history-summary">
                                                <span><strong>Documento:</strong> <?= (int) $job['pages'] ?> pág.</span>
                                                <span><strong>Seleção:</strong> <?= htmlspecialchars($selectionLabel($job)) ?><?= $selected > 0 ? ' · ' . (int) $selected . ' pág. usadas' : '' ?></span>
                                                <span><strong>Cópias:</strong> <?= (int) $job['copies'] ?> · <strong>Por folha:</strong> <?= (int) $job['number_up'] ?></span>
                                                <span><strong>Modo:</strong> <?= htmlspecialchars($modoImpressao($job['sides'] ?? '')) ?></span>
                                                <span><strong>Papel/orientação:</strong> <?= htmlspecialchars($paperPt) ?> · <?= htmlspecialchars($orientationPt) ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Folhas">
                                            <span class="badge text-bg-primary"><?= (int) $job['charged_pages'] ?></span>
                                            <small class="d-block text-muted"><?= (int) $sheetsPerCopy ?> por cópia</small>
                                        </td>
                                        <td data-label="Data"><small><?= htmlspecialchars($job['created_at']) ?></small></td>
                                        <td data-label="Ações" class="text-end">
                                            <div class="job-actions">
                                                <?php if (!$isLegacyUsage): ?>
                                                    <div class="job-action-buttons">
                                                        <a class="btn btn-outline-secondary btn-sm" title="Baixar arquivo" href="/prints/download?id=<?= (int) $job['id'] ?>"><i class="bi bi-download"></i></a>
                                                        <?php if ($partialSelection): ?>
                                                            <button class="btn btn-outline-secondary btn-sm" disabled title="Faça uma nova impressão para repetir a seleção de páginas"><i class="bi bi-arrow-repeat"></i></button>
                                                        <?php else: ?>
                                                            <form method="post" action="/prints/reprint" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                                <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                                                <button class="btn btn-outline-primary btn-sm" title="Reimprimir" onclick="return confirm('Reimprimir este arquivo?')"><i class="bi bi-arrow-repeat"></i></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($isAdmin && ($job['status'] ?? '') === 'completed'): ?>
                                                        <details class="accounting-adjust text-start">
                                                            <summary>Corrigir folhas</summary>
                                                            <form method="post" action="/prints/accounting" class="mt-2">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                                <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                                                <label class="form-label small mb-1">Folhas contabilizadas</label>
                                                                <input type="number" name="charged_pages" class="form-control form-control-sm mb-1" min="0" value="<?= (int) $job['charged_pages'] ?>" required>
                                                                <input type="text" name="reason" class="form-control form-control-sm mb-1" maxlength="180" placeholder="Motivo da correção" required>
                                                                <button class="btn btn-warning btn-sm w-100" onclick="return confirm('Confirmar correção da contabilização?')">Salvar correção</button>
                                                            </form>
                                                        </details>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">Sem arquivo rastreado</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>

</html>
