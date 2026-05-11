<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Fila de Impressão</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="/css/base.css">
    <link rel="stylesheet" href="/css/admin.css">
</head>

<body>
    <?php
    $statusLabels = [
        'queued' => ['label' => 'Na fila', 'class' => 'text-bg-secondary', 'icon' => 'bi-hourglass-split'],
        'processing' => ['label' => 'Enviando', 'class' => 'text-bg-info', 'icon' => 'bi-arrow-repeat'],
        'completed' => ['label' => 'Impresso', 'class' => 'text-bg-success', 'icon' => 'bi-check-circle'],
        'failed' => ['label' => 'Erro', 'class' => 'text-bg-danger', 'icon' => 'bi-exclamation-triangle'],
    ];
    $currentStatus = $_GET['status'] ?? '';
    ?>

    <div class="app-shell">
        <header class="app-header">
            <div class="container d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <img src="/image/logo_escola.png" class="logo">
                    <strong>Fila de Impressão</strong>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <span class="user">
                        <i class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($_SESSION['name'] ?? $_SESSION['user']) ?>
                    </span>

                    <a href="/logout" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-box-arrow-right"></i> Sair
                    </a>
                </div>
            </div>
        </header>

        <main class="container py-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <h4 class="mb-0"><i class="bi bi-list-task"></i> <?= $isAdmin ? 'Todas as impressões' : 'Minhas impressões' ?></h4>

                <div class="d-flex gap-2">
                    <?php if ($isAdmin): ?>
                        <a href="/admin" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-bar-chart"></i> Relatório
                        </a>
                    <?php endif; ?>
                    <a href="/" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-printer"></i> Nova impressão
                    </a>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">Todos</option>
                                <?php foreach ($statusLabels as $value => $meta): ?>
                                    <option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>>
                                        <?= $meta['label'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <button class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> Filtrar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Arquivo</th>
                                    <?php if ($isAdmin): ?>
                                        <th>Professor</th>
                                    <?php endif; ?>
                                    <th>Status</th>
                                    <th>Páginas</th>
                                    <th>Cópias</th>
                                    <th>Por folha</th>
                                    <th>Contabilizado</th>
                                    <th>Enviado em</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($jobs)): ?>
                                    <tr>
                                        <td colspan="<?= $isAdmin ? 8 : 7 ?>" class="text-center text-muted">
                                            Nenhuma impressão encontrada
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($jobs as $job): ?>
                                        <?php $meta = $statusLabels[$job['status']] ?? $statusLabels['queued']; ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= htmlspecialchars($job['original_name']) ?></div>
                                                <?php if (!empty($job['error_message'])): ?>
                                                    <small class="text-danger"><?= htmlspecialchars($job['error_message']) ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted"><?= strtoupper(htmlspecialchars($job['source_ext'] ?? '')) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($isAdmin): ?>
                                                <td>
                                                    <?= htmlspecialchars($job['user_name'] ?: $job['user']) ?><br>
                                                    <small class="text-muted"><?= htmlspecialchars($job['user']) ?></small>
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="badge <?= $meta['class'] ?>">
                                                    <i class="bi <?= $meta['icon'] ?>"></i> <?= $meta['label'] ?>
                                                </span>
                                            </td>
                                            <td><?= (int) $job['pages'] ?></td>
                                            <td><?= (int) $job['copies'] ?></td>
                                            <td><?= (int) $job['number_up'] ?></td>
                                            <td>
                                                <span class="badge text-bg-primary">
                                                    <?= (int) $job['charged_pages'] ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($job['created_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>

</html>
