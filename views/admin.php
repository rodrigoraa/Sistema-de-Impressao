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
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Painel de Impressão</title>
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
                    <strong>Painel Administrativo</strong>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="user"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['name']) ?></span>
                    <a href="/logout" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Sair</a>
                </div>
            </div>
        </header>

        <main class="container py-4">
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
                    <h2 class="panel-title">Situação atual da impressora</h2>
                    <a href="/admin?month=<?= htmlspecialchars($month) ?>&cpf=<?= htmlspecialchars($cpf ?? '') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-clockwise"></i> Atualizar</a>
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="metric"><span>Impressora</span><strong><?= htmlspecialchars($printerStatus['printer'] ?: 'não configurada') ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric"><span>Impressora ativada</span><strong><?= $printerStatus['enabled'] === null ? 'N/D' : ($printerStatus['enabled'] ? 'Sim' : 'Não') ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric"><span>Aceitando impressões</span><strong><?= $printerStatus['accepting'] === null ? 'N/D' : ($printerStatus['accepting'] ? 'Sim' : 'Não') ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric"><span>Fila</span><strong><?= (int) $printerStatus['pending_jobs'] ?></strong></div>
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
                                <th class="text-end">Páginas</th>
                                <th class="text-end">Cópias</th>
                                <th class="text-end">Total contabilizado</th>
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
                                        <td class="text-end"><span class="badge text-bg-primary"><?= (int) $row['charged_pages'] ?> contabilizadas</span></td>
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
