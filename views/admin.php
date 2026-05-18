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
                    <a href="/prints?status=completed" class="btn btn-outline-secondary"><i class="bi bi-clock-history"></i> Histórico</a>
                    <a href="/" class="btn btn-primary"><i class="bi bi-printer"></i> Nova impressão</a>
                    <a href="/admin/users" class="btn btn-outline-secondary"><i class="bi bi-people"></i> Usuários</a>
                    <a href="/prints?status=failed" class="btn btn-outline-danger"><i class="bi bi-exclamation-triangle"></i> Ver erros</a>
                <a href="/prints?status=active" class="btn btn-outline-primary"><i class="bi bi-list-task"></i> Fila de impressão</a>
                </div>
            </section>

            <section class="panel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="panel-title">Status atual da impressora</h2>
                    <a href="/admin?month=<?= htmlspecialchars($month) ?>&cpf=<?= htmlspecialchars($cpf ?? '') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-clockwise"></i> Atualizar</a>
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="metric"><span>Impressora</span><strong><?= htmlspecialchars($printerStatus['printer'] ?: 'não configurada') ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric"><span>Enabled</span><strong><?= $printerStatus['enabled'] === null ? 'N/D' : ($printerStatus['enabled'] ? 'Sim' : 'Não') ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric"><span>Accepting</span><strong><?= $printerStatus['accepting'] === null ? 'N/D' : ($printerStatus['accepting'] ? 'Sim' : 'Não') ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric"><span>Fila</span><strong><?= (int) $printerStatus['pending_jobs'] ?></strong></div>
                    </div>
                </div>
                <div class="small text-muted mt-2">
                    Estado: <?= htmlspecialchars($printerStatus['printer_state'] ?: 'não informado') ?>.
                    Mensagem: <?= htmlspecialchars($printerStatus['printer_state_message'] ?: ($printerStatus['last_error'] ?: 'sem erro atual')) ?>.
                    Concluídos: <?= (int) $printerStatus['completed_jobs'] ?>,
                    cancelados: <?= (int) $printerStatus['canceled_jobs'] ?>,
                    falhas: <?= (int) $printerStatus['failed_jobs'] ?>.
                </div>
            </section>

            <section class="metric-grid">
                <div class="metric"><span>Total de jobs</span><strong><?= (int) $jobStats['total'] ?></strong></div>
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
                        <label class="form-label">Erro</label>
                        <input name="error" class="form-control" value="<?= htmlspecialchars($_GET['error'] ?? '') ?>" placeholder="papel, toner, timeout...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['' => 'Todos', 'active' => 'Fila', 'completed' => 'Concluídos', 'failed' => 'Falhas'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($_GET['status'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
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
                                <th class="text-end">Jobs</th>
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

            <section class="panel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="panel-title">Histórico de tentativas</h2>
                    <span class="text-muted small">Mostrando até 50 registros</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle data-table">
                        <thead>
                            <tr>
                                <th>Arquivo</th>
                                <th>Professor</th>
                                <th>Status</th>
                                <th>CUPS</th>
                                <th>Diagnóstico</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentJobs)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma tentativa encontrada</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentJobs as $job): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-truncate file-name"><?= htmlspecialchars($job['original_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($job['mime_type'] ?: strtoupper($job['source_ext'] ?? '')) ?> · <?= (int) ($job['file_size'] ?? 0) ?> bytes</small>
                                        </td>
                                        <td><?= htmlspecialchars($job['user_name'] ?: ($job['nome_professor'] ?: $job['user'])) ?><br><small class="text-muted"><?= htmlspecialchars($job['user']) ?></small></td>
                                        <td><span class="badge <?= $job['status'] === 'completed' ? 'text-bg-success' : ($job['status'] === 'failed' ? 'text-bg-danger' : 'text-bg-secondary') ?>"><?= htmlspecialchars($job['status']) ?></span></td>
                                        <td>
                                            <small>Job: <?= htmlspecialchars($job['cups_job_id'] ?: '-') ?><br>Status: <?= htmlspecialchars($job['status_cups'] ?: '-') ?><br>Retorno: <?= htmlspecialchars((string) ($job['return_code'] ?? '-')) ?></small>
                                        </td>
                                        <td><small class="<?= !empty($job['error_message']) ? 'text-danger' : 'text-muted' ?>"><?= htmlspecialchars($job['error_category'] ?: ($job['error_message'] ?: 'sem erro registrado')) ?></small></td>
                                        <td><small><?= htmlspecialchars($job['created_at']) ?></small></td>
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
