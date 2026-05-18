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
$statusFilters = [
    '' => 'Todo o histórico',
    'active' => 'Fila',
    'queued' => 'Na fila',
    'processing' => 'Enviando',
    'completed' => 'Concluídas',
    'failed' => 'Erros',
];
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
    <link rel="icon" type="image/png" href="/assets/image/logo_escola.png">
    <title><?= htmlspecialchars($pageHeading) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/base.css?v=20260511">
    <link rel="stylesheet" href="/css/admin.css?v=20260511">
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

        <main class="container py-4">
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

            <section class="panel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle data-table">
                        <thead>
                            <tr>
                                <th>Arquivo</th>
                                <?php if ($isAdmin): ?><th>Professor</th><?php endif; ?>
                                <th>Situação</th>
                                <th>Configuração</th>
                                <th>Contabilizado</th>
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
                                        $meta = $statusLabels[$job['status']] ?? $statusLabels['queued'];
                                        $isLegacyUsage = !empty($job['legacy_usage']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-truncate file-name"><?= htmlspecialchars($job['original_name']) ?></div>
                                            <?php if (!empty($job['error_message'])): ?>
                                                <small class="text-danger"><?= htmlspecialchars($traduzDiagnostico($job['error_message'])) ?></small>
                                            <?php else: ?>
                                                <small class="text-muted"><?= strtoupper(htmlspecialchars($job['source_ext'] ?? '')) ?> · <?= (int) $job['pages'] ?> pág.</small>
                                            <?php endif; ?>
                                            <?php
                                                $cupsPt = [
                                                    'accepted' => 'Aceito pelo CUPS',
                                                    'accepted_unverified' => 'Aceito, sem confirmação',
                                                    'accepted_unidentified' => 'Aceito, ID não identificado',
                                                    'completed' => 'Concluído',
                                                    'failed_or_canceled' => 'Falhou ou foi cancelado',
                                                    'left_queue' => 'Saiu da fila',
                                                    'timeout' => 'Tempo esgotado',
                                                    'preflight_failed' => 'Falha antes do envio',
                                                    'lp_failed' => 'Falha no comando de impressão',
                                                    'falha_pre_validacao' => 'Falha antes do envio',
                                                ][$job['status_cups']] ?? ($job['status_cups'] ?: '-');
                                            ?>
                                            <?php if ($isLegacyUsage): ?>
                                                <small class="d-block text-muted">Registro antigo do acumulado mensal</small>
                                            <?php else: ?>
                                                <small class="d-block text-muted">CUPS: <?= htmlspecialchars($job['cups_job_id'] ?: '-') ?> · <?= htmlspecialchars($cupsPt) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($isAdmin): ?>
                                            <td><?= htmlspecialchars($job['user_name'] ?: $job['user']) ?><br><small class="text-muted"><?= htmlspecialchars($job['user']) ?></small></td>
                                        <?php endif; ?>
                                        <td><span class="badge <?= $meta['class'] ?>"><i class="bi <?= $meta['icon'] ?>"></i> <?= $meta['label'] ?></span></td>
                                        <?php
                                            $orientationPt = [
                                                'portrait' => 'Retrato',
                                                'landscape' => 'Paisagem',
                                            ][$job['orientation']] ?? ($job['orientation'] ?: 'Retrato');
                                            $paperPt = ($job['paper'] ?? '') === 'Letter' ? 'Carta' : ($job['paper'] ?: 'A4');
                                        ?>
                                        <td><small><?= (int) $job['copies'] ?> cópia(s), <?= (int) $job['number_up'] ?> por folha<br><?= htmlspecialchars($paperPt) ?> · <?= htmlspecialchars($orientationPt) ?></small></td>
                                        <td><span class="badge text-bg-primary"><?= (int) $job['charged_pages'] ?></span></td>
                                        <td><small><?= htmlspecialchars($job['created_at']) ?></small></td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <?php if (!$isLegacyUsage): ?>
                                                    <a class="btn btn-outline-secondary" title="Baixar arquivo" href="/prints/download?id=<?= (int) $job['id'] ?>"><i class="bi bi-download"></i></a>
                                                    <form method="post" action="/prints/reprint" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                                        <button class="btn btn-outline-primary" title="Reimprimir" onclick="return confirm('Reimprimir este arquivo?')"><i class="bi bi-arrow-repeat"></i></button>
                                                    </form>
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
