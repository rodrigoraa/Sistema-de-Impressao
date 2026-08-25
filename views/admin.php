<?php
$sessionName = $_SESSION['name'] ?? ($_SESSION['user'] ?? '');
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
$cupsEnableResult = $_SESSION['cups_enable_result'] ?? null;
unset($_SESSION['cups_enable_result']);
$lastEnableAttempt = $lastEnableAttempt ?? null;
$cupsEnableResult = $cupsEnableResult ?? $lastEnableAttempt;
$simNao = function ($valor) {
    if ($valor === null || $valor === '') {
        return 'N/D';
    }

    return $valor ? 'Sim' : 'Não';
};
$healthBadge = function ($type) {
    return [
        'success' => 'text-bg-success',
        'warning' => 'text-bg-warning',
        'danger' => 'text-bg-danger',
        'info' => 'text-bg-info',
    ][$type] ?? 'text-bg-secondary';
};
$enableAttemptLabel = function ($attempt) {
    if (!is_array($attempt)) {
        return 'Não testado';
    }
    $text = strtolower(json_encode($attempt, JSON_UNESCAPED_UNICODE) ?: '');
    if (!empty($attempt['success'])) {
        return !empty($attempt['source']) && $attempt['source'] === 'auto' ? 'Automático OK' : 'Manual OK';
    }
    if (str_contains($text, 'a password is required') || str_contains($text, 'sudo: a password')) {
        return 'Sem permissão no sudo';
    }
    if (str_contains($text, 'client-error-forbidden') || str_contains($text, 'forbidden')) {
        return 'Servidor negou';
    }
    if (str_contains($text, 'nao encontrado') || str_contains($text, 'not found')) {
        return 'Comando ausente';
    }

    return 'Falhou';
};
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/favicon.ico?v=2">
    <title>Painel de Impressão</title>
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
                    <strong>Painel Administrativo</strong>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="user"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($sessionName) ?></span>
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
                    <p class="section-kicker mb-1">Visão geral</p>
                    <h1>Relatório de impressão</h1>
                </div>
                <div class="action-strip">
                    <a href="/prints" class="btn btn-outline-secondary"><i class="bi bi-clock-history"></i> Histórico</a>
                    <a href="/prints?status=active" class="btn btn-outline-primary"><i class="bi bi-list-task"></i> Fila de impressão</a>
                    <a href="/prints?status=failed" class="btn btn-outline-danger"><i class="bi bi-exclamation-triangle"></i> Impressões com erro</a>
                    <a href="/" class="btn btn-primary"><i class="bi bi-printer"></i> Nova impressão</a>
                    <a href="/admin/users" class="btn btn-outline-secondary"><i class="bi bi-people"></i> Usuários</a>
                </div>
            </section>

            <section class="panel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="panel-title">Painel de saúde da impressora</h2>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="/prints?status=active" class="btn btn-outline-primary btn-sm"><i class="bi bi-list-task"></i> Abrir fila</a>
                        <a href="/admin?month=<?= htmlspecialchars($month) ?>&cpf=<?= htmlspecialchars($cpf ?? '') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-clockwise"></i> Atualizar</a>
                        <form method="post" action="/admin/printer/enable">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-play-circle"></i> Reativar no CUPS</button>
                        </form>
                    </div>
                </div>
                <?php if (!empty($printerStatus['notice'])): ?>
                    <div class="alert alert-<?= htmlspecialchars($printerStatus['notice_type'] ?? 'info') ?> d-flex gap-2 align-items-start">
                        <i class="bi bi-printer"></i>
                        <span><?= htmlspecialchars($printerStatus['notice']) ?></span>
                    </div>
                <?php endif; ?>
                <div class="health-grid mb-3">
                    <div class="health-item">
                        <span>Pode imprimir agora?</span>
                        <strong><span class="badge <?= htmlspecialchars($healthBadge($printerStatus['notice_type'] ?? 'info')) ?>"><?= !empty($printerStatus['can_print']) ? 'Pronta' : 'Bloqueada' ?></span></strong>
                        <small><?= htmlspecialchars($traduzDiagnostico($printerStatus['reason'] ?: 'sem problema detectado')) ?></small>
                    </div>
                    <div class="health-item">
                        <span>CUPS / servidor</span>
                        <strong><?= ($printerStatus['cups_active'] ?? null) === null ? 'N/D' : (($printerStatus['cups_active'] ?? false) ? 'Ativo' : 'Inacessível') ?></strong>
                        <small><?= !empty($printerStatus['printer_exists']) ? 'Servidor encontrou a impressora' : 'Servidor não encontrou a impressora' ?></small>
                    </div>
                    <div class="health-item">
                        <span>Última reativação</span>
                        <strong><?= htmlspecialchars($enableAttemptLabel($lastEnableAttempt)) ?></strong>
                        <small><?= is_array($lastEnableAttempt) ? htmlspecialchars(($lastEnableAttempt['created_at'] ?? '') . ' · ' . (($lastEnableAttempt['source'] ?? '') === 'auto' ? 'automática' : 'manual')) : 'nenhum comando enviado ainda' ?></small>
                    </div>
                    <div class="health-item">
                        <span>Trabalhos aguardando</span>
                        <strong><?= (int) $printerStatus['pending_jobs'] ?></strong>
                        <small><?= (int) $printerStatus['completed_jobs'] ?> já concluídos · <?= (int) $printerStatus['failed_jobs'] ?> com falha</small>
                    </div>
                </div>
                <?php if (is_array($cupsEnableResult)): ?>
                    <div class="alert alert-light border">
                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                            <strong>Última reativação registrada</strong>
                            <span class="badge text-bg-<?= !empty($cupsEnableResult['success']) ? 'success' : 'warning' ?>">
                                <?= htmlspecialchars($enableAttemptLabel($cupsEnableResult)) ?>
                            </span>
                        </div>
                        <div class="small text-muted mt-1">
                            <?= (($cupsEnableResult['source'] ?? '') === 'auto') ? 'Tentativa automática do sistema.' : 'Tentativa manual pelo painel.' ?>
                            <?= !empty($cupsEnableResult['created_at']) ? 'Data: ' . htmlspecialchars($cupsEnableResult['created_at']) . '.' : '' ?>
                        </div>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm mb-2">
                                <thead>
                                    <tr>
                                        <th>Comando</th>
                                        <th>Código</th>
                                        <th>Retorno</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($cupsEnableResult['commands'] ?? []) as $commandName => $commandResult): ?>
                                        <?php
                                            $stdout = trim((string) ($commandResult['stdout'] ?? ''));
                                            $stderr = trim((string) ($commandResult['stderr'] ?? ''));
                                            $output = trim($stdout . ($stdout !== '' && $stderr !== '' ? ' | ' : '') . $stderr);
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars(!empty($commandResult['used_sudo']) ? 'sudo ' . $commandName : $commandName) ?></td>
                                            <td><?= (int) ($commandResult['return_code'] ?? 0) ?></td>
                                            <td><?= htmlspecialchars($output !== '' ? $output : 'sem saída') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php $after = $cupsEnableResult['diagnostics'] ?? []; ?>
                        <?php if (is_array($after)): ?>
                            <div class="small">
                                Depois do comando:
                                ativada <?= ($after['enabled'] ?? null) === null ? 'N/D' : (($after['enabled'] ?? false) ? 'Sim' : 'Não') ?>,
                                aceitando <?= ($after['accepting'] ?? null) === null ? 'N/D' : (($after['accepting'] ?? false) ? 'Sim' : 'Não') ?>,
                                mensagem <?= htmlspecialchars($traduzDiagnostico($after['notice'] ?? ($after['last_error'] ?? 'sem erro atual'))) ?>.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="metric"><span>Nome no CUPS</span><strong><?= htmlspecialchars($printerStatus['printer'] ?: 'não configurada') ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric"><span>Habilitada no CUPS</span><strong><?= htmlspecialchars($simNao($printerStatus['enabled'])) ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric"><span>Aceita novos trabalhos</span><strong><?= htmlspecialchars($simNao($printerStatus['accepting'])) ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric"><span>Pendentes na fila</span><strong><?= (int) $printerStatus['pending_jobs'] ?></strong></div>
                    </div>
                </div>
                <div class="small text-muted mt-2">
                    Estado: <?= htmlspecialchars($traduzDiagnostico($printerStatus['printer_state'] ?: 'não informado')) ?>.
                    Mensagem: <?= htmlspecialchars($traduzDiagnostico($printerStatus['printer_state_message'] ?: ($printerStatus['last_error'] ?: 'sem erro atual'))) ?>.
                    Concluídos: <?= (int) $printerStatus['completed_jobs'] ?>,
                    cancelados: <?= (int) $printerStatus['canceled_jobs'] ?>,
                    falhas: <?= (int) $printerStatus['failed_jobs'] ?>.
                </div>
            </section>

            <section class="metric-grid">
                <div class="metric"><span>Total de trabalhos</span><strong><?= (int) $jobStats['total'] ?></strong></div>
                <div class="metric"><span>Impressas</span><strong><?= (int) $jobStats['completed'] ?></strong></div>
                <div class="metric"><span>Com erro</span><strong><?= (int) $jobStats['failed'] ?></strong></div>
                <div class="metric"><span>Mês filtrado</span><strong><?= (int) $totalMonth ?></strong></div>
            </section>

            <section class="panel">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h2 class="panel-title">Arquivos salvos no sistema</h2>
                        <p class="text-muted mb-0">Apaga arquivos enviados e cópias de diagnóstico, mantendo usuários, histórico e relatórios no banco.</p>
                    </div>
                </div>
                <?php
                    $formatBytes = function ($bytes) {
                        $bytes = max(0, (int) $bytes);
                        $units = ['B', 'KB', 'MB', 'GB'];
                        $value = (float) $bytes;
                        $unit = 0;
                        while ($value >= 1024 && $unit < count($units) - 1) {
                            $value /= 1024;
                            $unit++;
                        }

                        return number_format($value, $unit === 0 ? 0 : 2, ',', '.') . ' ' . $units[$unit];
                    };
                    $uploads = $storageStats['uploads'] ?? ['files' => 0, 'bytes' => 0, 'path' => '', 'exists' => false];
                    $debug = $storageStats['debug'] ?? ['files' => 0, 'bytes' => 0, 'path' => '', 'exists' => false];
                    $temporary = $storageStats['temporary'] ?? ['files' => 0, 'bytes' => 0, 'path' => '', 'exists' => false];
                ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="metric">
                            <span>Uploads</span>
                            <strong><?= (int) $uploads['files'] ?> arquivo(s)</strong>
                            <small class="text-muted"><?= htmlspecialchars($formatBytes($uploads['bytes'])) ?></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric">
                            <span>Diagnósticos</span>
                            <strong><?= (int) $debug['files'] ?> arquivo(s)</strong>
                            <small class="text-muted"><?= htmlspecialchars($formatBytes($debug['bytes'])) ?></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric">
                            <span>Pré-visualizações temporárias</span>
                            <strong><?= (int) $temporary['files'] ?> arquivo(s)</strong>
                            <small class="text-muted"><?= htmlspecialchars($formatBytes($temporary['bytes'])) ?></small>
                        </div>
                    </div>
                </div>

                <form method="post" action="/admin/storage/cleanup" class="row g-3 align-items-end" onsubmit="return confirm('Apagar os arquivos selecionados? O histórico continuará no sistema, mas download/reimpressão desses arquivos pode deixar de funcionar.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="col-md-3">
                        <label class="form-label">Apagar arquivos</label>
                        <select name="older_than_days" class="form-select">
                            <option value="30">Com mais de 30 dias</option>
                            <option value="15">Com mais de 15 dias</option>
                            <option value="7">Com mais de 7 dias</option>
                            <option value="0">Todos</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Pastas</label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="areas[]" value="uploads" id="clean_uploads" checked>
                                <label class="form-check-label" for="clean_uploads">Uploads</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="areas[]" value="debug" id="clean_debug" checked>
                                <label class="form-check-label" for="clean_debug">Diagnósticos</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="areas[]" value="temporary" id="clean_temporary">
                                <label class="form-check-label" for="clean_temporary">Pré-visualizações temporárias</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-danger w-100"><i class="bi bi-trash"></i> Apagar arquivos</button>
                    </div>
                </form>
            </section>

            <section class="panel">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Selecionar mês</label>
                        <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">CPF</label>
                        <input name="cpf" class="form-control" value="<?= htmlspecialchars($cpf ?? '') ?>" placeholder="Todos">
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="include_failures" value="1" id="include_failures" <?= $includeFailures ? 'checked' : '' ?>>
                            <label class="form-check-label" for="include_failures">Incluir falhas no relatório</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrar</button>
                    </div>
                    <div class="col-md-3">
                        <a class="btn btn-outline-secondary w-100" href="/admin/report/pdf?month=<?= urlencode($month) ?>&cpf=<?= urlencode($cpf ?? '') ?>&include_failures=<?= $includeFailures ? '1' : '0' ?>"><i class="bi bi-filetype-pdf"></i> Gerar PDF</a>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="panel-title">Uso por professor</h2>
                    <span class="text-muted small">Mês: <?= htmlspecialchars($month) ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle data-table">
                        <thead>
                            <tr>
                                <th>Professor</th>
                                <th class="text-end">Trabalhos</th>
                                <th class="text-end">Páginas do documento</th>
                                <th class="text-end">Cópias solicitadas</th>
                                <th class="text-end">Folhas contabilizadas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma impressão neste mês</td></tr>
                            <?php else: ?>
                                <?php foreach ($data as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['name']) ?><br><small class="text-muted"><?= htmlspecialchars($row['cpf']) ?></small></td>
                                        <td class="text-end"><?= (int) $row['jobs'] ?></td>
                                        <td class="text-end"><?= (int) $row['pages'] ?></td>
                                        <td class="text-end"><?= (int) $row['copies'] ?></td>
                                        <td class="text-end"><span class="badge text-bg-primary"><?= (int) $row['charged_pages'] ?> folhas</span></td>
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
