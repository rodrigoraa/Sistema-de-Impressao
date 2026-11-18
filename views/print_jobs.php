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
    'canceled' => ['label' => 'Cancelada', 'class' => 'text-bg-secondary', 'icon' => 'bi-x-circle'],
    'interrupted' => ['label' => 'Envio interrompido', 'class' => 'text-bg-warning', 'icon' => 'bi-exclamation-triangle'],
    'failed' => ['label' => 'Erro', 'class' => 'text-bg-danger', 'icon' => 'bi-exclamation-triangle'],
];
$cupsLabels = [
    'accepted' => ['label' => 'Aceito pelo servidor', 'class' => 'text-bg-warning'],
    'accepted_unverified' => ['label' => 'Aceito, sem confirmação final', 'class' => 'text-bg-warning'],
    'accepted_unidentified' => ['label' => 'Aceito, sem número na fila', 'class' => 'text-bg-warning'],
    'completed' => ['label' => 'Conclusão confirmada', 'class' => 'text-bg-success'],
    'failed_or_canceled' => ['label' => 'Falhou ou foi cancelado', 'class' => 'text-bg-danger'],
    'cancel_failed' => ['label' => 'Falha ao cancelar', 'class' => 'text-bg-danger'],
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
        'one-sided' => 'Frente apenas',
        'two-sided-long-edge' => 'Frente e verso — como um livro',
        'two-sided-short-edge' => 'Frente e verso — como um bloco',
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
    'active' => 'Fila',
    'queued' => 'Na fila',
    'processing' => 'Enviando',
    'completed' => 'Concluídas',
    'canceled' => 'Canceladas',
    'interrupted' => 'Interrompidas',
    'failed' => 'Erros',
];
$currentStatus = $_GET['status'] ?? '';
$pageHeading = $isAdmin ? 'Histórico completo de impressão' : 'Meus envios';
$pageDescription = $isAdmin ? 'Todas as impressões de todos os professores' : 'Acompanhe seus envios e use um arquivo novamente quando precisar.';
if ($currentStatus === 'active') {
    $pageHeading = $isAdmin ? 'Fila de impressão' : 'Meus envios';
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
    <link href="/css/vendor/bootstrap.min.css" rel="stylesheet">
    <link href="/css/vendor/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/base.css?v=20261117">
    <link rel="stylesheet" href="/css/admin.css?v=20260602">
</head>

<body>
    <div class="app-shell">
        <header class="app-header">
            <div class="container d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <img src="/image/logo_escola.png" class="logo" alt="Emblema da escola">
                    <strong><?= htmlspecialchars($pageHeading) ?></strong>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="user"><i aria-hidden="true" class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['name'] ?? $_SESSION['user']) ?></span>
                    <a href="/logout" class="btn btn-outline-danger btn-sm"><i aria-hidden="true" class="bi bi-box-arrow-right"></i> Sair</a>
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
                        <a href="/admin" class="btn btn-outline-secondary"><i aria-hidden="true" class="bi bi-speedometer2"></i> Painel</a>
                    <?php endif; ?>
                    <a href="/" class="btn btn-primary"><i aria-hidden="true" class="bi bi-printer"></i> Nova impressão</a>
                </div>
            </section>

            <section class="metric-grid">
                <div class="metric"><span>Total</span><strong><?= (int) $stats['total'] ?></strong></div>
                <div class="metric"><span>Impressas</span><strong><?= (int) $stats['completed'] ?></strong></div>
                <div class="metric"><span>Na fila</span><strong><?= (int) $stats['active'] ?></strong></div>
                <div class="metric"><span>Erros</span><strong><?= (int) $stats['failed'] ?></strong></div>
                <div class="metric"><span>Acumulado contabilizado</span><strong><?= (int) $stats['charged'] ?></strong></div>
            </section>

            <?php if ($isAdmin && $currentStatus === 'active' && is_array($cupsQueue)): ?>
                <section class="panel history-table-panel">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-3">
                        <div>
                            <h2 class="panel-title">Fila real do CUPS</h2>
                            <p class="text-muted mb-0">Trabalhos que ainda estão pendentes no servidor de impressão.</p>
                        </div>
                        <a href="/prints?status=active" class="btn btn-outline-primary btn-sm"><i aria-hidden="true" class="bi bi-arrow-clockwise"></i> Atualizar fila</a>
                    </div>
                    <?php if (empty($cupsQueue['success'])): ?>
                        <div class="alert alert-warning mb-0">
                            Não foi possível consultar a fila do CUPS: <?= htmlspecialchars($cupsQueue['error_message'] ?? 'erro não informado') ?>
                        </div>
                    <?php elseif (empty($cupsQueue['jobs'])): ?>
                        <div class="text-center text-muted py-3">Nenhum trabalho ativo no CUPS agora.</div>
                    <?php else: ?>
                        <div class="table-responsive history-table-wrap">
                            <table class="table table-hover align-middle data-table history-table">
                                <thead>
                                    <tr>
                                        <th>Arquivo</th>
                                        <th>Professor</th>
                                        <th>Situação</th>
                                        <th>Resumo da impressão</th>
                                        <th>Acumulado</th>
                                        <th>Data</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($cupsQueue['jobs'] ?? []) as $queueJob): ?>
                                        <?php
                                            $cupsJobId = (string) ($queueJob['job_id'] ?? '');
                                            $localJob = $cupsQueueJobsById[$cupsJobId] ?? null;
                                            $title = trim((string) ($queueJob['title'] ?? ''));
                                            $fileName = $localJob['original_name'] ?? ($title !== '' ? $title : $cupsJobId);
                                            $queueState = trim((string) ($queueJob['state'] ?? ''));
                                            $selected = is_array($localJob) ? $selectedCount($localJob) : 0;
                                            $sheetsPerCopy = is_array($localJob)
                                                ? max(1, (int) ceil(max(1, $selected) / max(1, (int) ($localJob['number_up'] ?? 1))))
                                                : 0;
                                        ?>
                                        <tr>
                                            <td data-label="Arquivo">
                                                <div class="fw-semibold text-truncate file-name"><?= htmlspecialchars($fileName) ?></div>
                                                <small class="d-block text-muted">CUPS: <?= htmlspecialchars($cupsJobId) ?> · <?= (int) ($queueJob['size'] ?? 0) ?> bytes</small>
                                                <?php if (!is_array($localJob)): ?>
                                                    <small class="d-block text-muted">Sem vínculo encontrado no histórico local</small>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Professor">
                                                <?php if (is_array($localJob)): ?>
                                                    <?= htmlspecialchars($localJob['user_name'] ?: $localJob['user']) ?><br><small class="text-muted"><?= htmlspecialchars($localJob['user']) ?></small>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($queueJob['owner'] ?? 'não informado') ?>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Situação">
                                                <span class="badge text-bg-info"><i aria-hidden="true" class="bi bi-list-task"></i> No CUPS</span>
                                                <?php if ($queueState !== ''): ?><small class="d-block text-muted"><?= htmlspecialchars($traduzDiagnostico($queueState)) ?></small><?php endif; ?>
                                            </td>
                                            <td data-label="Resumo">
                                                <?php if (is_array($localJob)): ?>
                                                    <div class="history-summary">
                                                        <span><strong>Documento:</strong> <?= (int) $localJob['pages'] ?> pág.</span>
                                                        <span><strong>Seleção:</strong> <?= htmlspecialchars($selectionLabel($localJob)) ?><?= $selected > 0 ? ' · ' . (int) $selected . ' pág. usadas' : '' ?></span>
                                                        <span><strong>Cópias:</strong> <?= (int) $localJob['copies'] ?> · <strong>Por lado:</strong> <?= (int) $localJob['number_up'] ?></span>
                                                        <span><strong>Modo:</strong> <?= htmlspecialchars($modoImpressao($localJob['sides'] ?? '')) ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">Dados detalhados indisponíveis no registro local.</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Acumulado">
                                                <?php if (is_array($localJob)): ?>
                                                    <span class="badge text-bg-primary"><?= (int) $localJob['charged_pages'] ?></span>
                                                    <small class="d-block text-muted"><?= (int) $sheetsPerCopy ?> lado(s) por cópia</small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Data"><small><?= htmlspecialchars($queueJob['submitted_at'] ?? '') ?></small></td>
                                            <td data-label="Ações" class="text-end">
                                                <form novalidate method="post" action="/prints/cancel" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="cups_job_id" value="<?= htmlspecialchars($cupsJobId) ?>">
                                                    <button type="button" class="btn btn-outline-danger btn-sm" title="Cancelar na fila" data-confirm="Cancelar esta impressão na fila do CUPS?"><i aria-hidden="true" class="bi bi-x-circle"></i> Cancelar</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="panel">
                <form novalidate method="get" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" for="historySearch">Buscar arquivo</label>
                        <div class="input-group"><input type="search" id="historySearch" name="search" maxlength="150" class="form-control" value="<?= htmlspecialchars(is_string($_GET['search'] ?? '') ? ($_GET['search'] ?? '') : '') ?>" placeholder="Nome da atividade ou documento"><button type="button" id="clearHistorySearch" class="btn btn-outline-secondary" aria-label="Limpar busca">Limpar</button></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="filter-status">Situação</label>
                        <select id="filter-status" name="status" class="form-select">
                            <?php foreach ($statusFilters as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($isAdmin): ?>
                        <div class="col-md-3">
                            <label class="form-label" for="filter-cpf">CPF</label>
                            <input id="filter-cpf" name="cpf" class="form-control" value="<?= htmlspecialchars($_GET['cpf'] ?? '') ?>" placeholder="Todos">
                        </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label class="form-label" for="filter-month">Mês</label>
                        <input type="month" id="filter-month" name="month" class="form-control" value="<?= htmlspecialchars($_GET['month'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="filter-error">Erro</label>
                        <input id="filter-error" name="error" class="form-control" value="<?= htmlspecialchars($_GET['error'] ?? '') ?>" placeholder="papel, toner, tempo esgotado...">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100"><i aria-hidden="true" class="bi bi-search"></i> Filtrar</button>
                    </div>
                </form>
            </section>

            <section class="panel history-table-panel">
                <p class="small text-muted">Mostrando até 150 registros mais recentes. Use a busca e os filtros para encontrar envios anteriores. O acumulado segue a contabilização da escola.</p>
                <div class="table-responsive history-table-wrap">
                    <table class="table table-hover align-middle data-table history-table">
                        <thead>
                            <tr>
                                <th>Arquivo</th>
                                <?php if ($isAdmin): ?><th>Professor</th><?php endif; ?>
                                <th>Situação</th>
                                <th>Resumo da impressão</th>
                                <th>Acumulado</th>
                                <th>Data</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($jobs)): ?>
                                <tr><td colspan="<?= $isAdmin ? 7 : 6 ?>" class="text-center text-muted py-4"><?= ($isAdmin && $currentStatus === 'active') ? 'Nenhum registro local ainda marcado como fila. A fila real do CUPS aparece acima.' : 'Nenhuma impressão encontrada' ?></td></tr>
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
                                        $managed = !empty($job['queue_payload']);
                                        $waiting = $managed && $job['status'] === 'queued';
                                        $canPrintAgain = PrintQueueService::canPrintAgain($job);
                                        $canCancel = !$managed && $isAdmin && !$isLegacyUsage && in_array($job['status'], ['queued', 'processing'], true);
                                        $hasCupsJob = trim((string) ($job['cups_job_id'] ?? '')) !== '';
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
                                                    <?php if ($managed && !empty($job['printer'])): ?>
                                                        <?= htmlspecialchars($job['printer']) ?> ·
                                                    <?php endif; ?>
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
                                        <td data-label="Situação"><span class="badge <?= $meta['class'] ?>"><i aria-hidden="true" class="bi <?= $meta['icon'] ?>"></i> <?= $meta['label'] ?></span></td>
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
                                                <span><strong>Cópias:</strong> <?= (int) $job['copies'] ?> · <strong>Por lado:</strong> <?= (int) $job['number_up'] ?></span>
                                                <span><strong>Modo:</strong> <?= htmlspecialchars($modoImpressao($job['sides'] ?? '')) ?></span>
                                                <span><strong>Papel/orientação:</strong> <?= htmlspecialchars($paperPt) ?> · <?= htmlspecialchars($orientationPt) ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Acumulado">
                                            <span class="badge text-bg-primary"><?= (int) $job['charged_pages'] ?></span>
                                            <small class="d-block text-muted"><?= (int) $sheetsPerCopy ?> lado(s) por cópia</small>
                                        </td>
                                        <td data-label="Data"><small><?= htmlspecialchars($job['created_at']) ?></small></td>
                                        <td data-label="Ações" class="text-end">
                                            <div class="job-actions">
                                                <?php if (!$isLegacyUsage): ?>
                                                    <div class="job-action-buttons">
                                                        <a class="btn btn-outline-secondary btn-sm" title="Baixar arquivo" href="/prints/download?id=<?= (int) $job['id'] ?>"><i aria-hidden="true" class="bi bi-download"></i> Baixar</a>
                                                        <?php if ($waiting || ($canCancel && $hasCupsJob)): ?>
                                                            <form novalidate method="post" action="/prints/cancel" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                                <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                                                <button type="button" class="btn btn-outline-danger btn-sm" title="Cancelar na fila" data-confirm="Cancelar esta impressão na fila?"><i aria-hidden="true" class="bi bi-x-circle"></i> Cancelar</button>
                                                            </form>
                                                        <?php elseif ($canCancel): ?>
                                                            <button class="btn btn-outline-danger btn-sm" disabled title="Aguardando número no CUPS para cancelar"><i aria-hidden="true" class="bi bi-x-circle"></i> Cancelar</button>
                                                        <?php endif; ?>
                                                        <?php if ($canPrintAgain): ?>
                                                            <form novalidate method="post" action="/prints/reuse" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                                <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                                                <button class="btn btn-outline-primary btn-sm">Usar arquivo novamente</button>
                                                            </form>
                                                            <?php if ($partialSelection): ?>
                                                                <button class="btn btn-outline-secondary btn-sm" disabled title="Faça uma nova impressão para repetir a seleção de páginas"><i aria-hidden="true" class="bi bi-arrow-repeat"></i> Reimprimir</button>
                                                            <?php else: ?>
                                                                <form novalidate method="post" action="/prints/reprint" class="d-inline">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                                    <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                                                    <button type="button" class="btn btn-outline-primary btn-sm" title="Reimprimir" data-confirm="Reimprimir com as mesmas opções e quantidade de cópias?"><i aria-hidden="true" class="bi bi-arrow-repeat"></i> Reimprimir</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($isAdmin && ($job['status'] ?? '') === 'completed'): ?>
                                                        <details class="accounting-adjust text-start">
                                                            <summary>Corrigir folhas</summary>
                                                            <form novalidate method="post" action="/prints/accounting" class="mt-2">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                                <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                                                <label class="form-label small mb-1">Acumulado contabilizado</label>
                                                                <input type="number" name="charged_pages" class="form-control form-control-sm mb-1" min="0" value="<?= (int) $job['charged_pages'] ?>" required>
                                                                <input type="text" name="reason" class="form-control form-control-sm mb-1" maxlength="180" placeholder="Motivo da correção" required>
                                                                <button type="button" class="btn btn-warning btn-sm w-100" data-confirm="Confirmar correção da contabilização?">Salvar correção</button>
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
    <div class="modal fade" id="historyConfirm" tabindex="-1" aria-labelledby="historyConfirmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h2 id="historyConfirmTitle" class="modal-title h5">Confirme a ação</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar confirmação"></button></div>
            <div class="modal-body"><p id="historyConfirmMessage"></p><p id="historyConfirmFile" class="fw-semibold text-break"></p><p id="historyConfirmOptions" class="small text-muted"></p></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="historyKeep">Voltar</button><button type="button" class="btn btn-primary" id="historyProceed">Confirmar</button></div>
        </div></div>
    </div>
    <script src="/js/vendor/bootstrap.bundle.min.js"></script>
    <script src="/js/print-history.js?v=20261117"></script>
</body>

</html>
