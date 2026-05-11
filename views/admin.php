<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Painel de Impressão</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="/css/base.css">
    <link rel="stylesheet" href="/css/admin.css">
</head>

<body>

    <div class="app-shell">

        <!-- HEADER -->
        <header class="app-header">
            <div class="container d-flex justify-content-between align-items-center">

                <div class="d-flex align-items-center gap-2">
                    <img src="/image/logo_escola.png" class="logo">
                    <strong>Painel Administrativo</strong>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <span class="user">
                        <i class="bi bi-person-circle"></i>
                        <?= $_SESSION['name'] ?>
                    </span>

                    <a href="/logout" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-box-arrow-right"></i> Sair
                    </a>
                </div>

            </div>
        </header>

        <!-- MAIN -->
        <main class="container py-4">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4><i class="bi bi-bar-chart"></i> Relatório de Impressão</h4>

                <a href="/" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-printer"></i> Nova impressão
                </a>
            </div>

            <!-- FILTRO -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">

                    <form method="get" class="row g-3 align-items-end">

                        <div class="col-md-4">
                            <label class="form-label">Selecionar mês</label>
                            <input type="month" name="month" class="form-control"
                                value="<?= $_GET['month'] ?? date('Y-m') ?>">
                        </div>

                        <div class="col-md-2">
                            <button class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> Filtrar
                            </button>
                        </div>

                    </form>

                </div>
            </div>

            <!-- RESULTADO -->
            <div class="card shadow-sm border-0">
                <div class="card-body">

                    <h5 class="mb-3">
                        Mês: <?= $_GET['month'] ?? date('Y-m') ?>
                    </h5>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead>
                                <tr>
                                    <th>Professor</th>
                                    <th>Total contabilizado</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (empty($data)): ?>
                                    <tr>
                                        <td colspan="2" class="text-center text-muted">
                                            Nenhuma impressão neste mês
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($data as $row): ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($row['name']) ?><br>
                                                <small class="text-muted"><?= $row['cpf'] ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?= $row['total'] ?> contabilizadas
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>
            </div>

            <!-- FOOTER ACTIONS -->
            <div class="mt-4 d-flex gap-2 justify-content-center">

                <a href="/admin/users" class="btn btn-outline-secondary">
                    <i class="bi bi-people"></i> Usuários
                </a>

                <a href="/prints" class="btn btn-outline-primary">
                    <i class="bi bi-list-task"></i> Fila de impressão
                </a>

            </div>

        </main>

    </div>

</body>

</html>
