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
                <div class="d-flex gap-2">
                    <a href="/prints?status=completed" class="btn btn-outline-secondary"><i class="bi bi-clock-history"></i> Histórico</a>
                    <a href="/" class="btn btn-primary"><i class="bi bi-printer"></i> Nova impressão</a>
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
                    <div class="col-md-4">
                        <label class="form-label">Selecionar mês</label>
                        <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($_GET['month'] ?? date('Y-m')) ?>">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrar</button>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="panel-title">Uso por professor</h2>
                    <span class="text-muted small">Mês: <?= htmlspecialchars($_GET['month'] ?? date('Y-m')) ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle data-table">
                        <thead>
                            <tr>
                                <th>Professor</th>
                                <th class="text-end">Total contabilizado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data)): ?>
                                <tr><td colspan="2" class="text-center text-muted py-4">Nenhuma impressão neste mês</td></tr>
                            <?php else: ?>
                                <?php foreach ($data as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['name']) ?><br><small class="text-muted"><?= htmlspecialchars($row['cpf']) ?></small></td>
                                        <td class="text-end"><span class="badge text-bg-primary"><?= (int) $row['total'] ?> contabilizadas</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="action-strip">
                <a href="/admin/users" class="btn btn-outline-secondary"><i class="bi bi-people"></i> Usuários</a>
                <a href="/prints?status=failed" class="btn btn-outline-danger"><i class="bi bi-exclamation-triangle"></i> Ver erros</a>
                <a href="/prints?status=completed" class="btn btn-outline-primary"><i class="bi bi-folder2-open"></i> Arquivos impressos</a>
            </div>
        </main>
    </div>
</body>

</html>
