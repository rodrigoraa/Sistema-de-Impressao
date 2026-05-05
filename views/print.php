<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Impressão</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="/css/base.css">
    <link rel="stylesheet" href="/css/print.css">
</head>

<body>

    <div class="app-shell">

        <!-- HEADER -->
        <header class="app-header">
            <div class="container d-flex justify-content-between align-items-center">

                <div class="d-flex align-items-center gap-2">
                    <img src="/image/logo_escola.png" class="logo">
                    <strong>Sistema de Impressão</strong>
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

            <!-- ALERT -->
            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert alert-<?= ($_SESSION['flash_type'] ?? '') === 'error' ? 'danger' : 'success' ?>">
                    <?= $_SESSION['flash'];
                    unset($_SESSION['flash']); ?>
                </div>
            <?php endif; ?>

            <div class="row g-4">

                <!-- FORM -->
                <div class="col-lg-6">

                    <div class="card shadow-sm border-0">
                        <div class="card-body">

                            <h4 class="mb-3">
                                <i class="bi bi-printer"></i> Nova impressão
                            </h4>

                            <form method="post" enctype="multipart/form-data">

                                <div class="mb-3">
                                    <label class="form-label">Arquivo</label>
                                    <input type="file" class="form-control" name="arquivo" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Cópias</label>
                                    <input type="number" class="form-control" name="copies" value="1" min="1">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Modo</label>
                                    <select class="form-select" name="sides">
                                        <option value="one-sided">Simples</option>
                                        <option value="two-sided-long-edge">Frente e verso</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Orientação</label>
                                    <select class="form-select" name="orientation">
                                        <option value="portrait">Retrato</option>
                                        <option value="landscape">Paisagem</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Qualidade</label>
                                    <select class="form-select" name="quality">
                                        <option value="3">Normal</option>
                                        <option value="5">Alta</option>
                                    </select>
                                </div>

                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Imprimir para</label>
                                        <select class="form-select" name="target_user">
                                            <?php foreach ($userList as $u): ?>
                                                <option value="<?= $u ?>"><?= $u ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <button class="btn btn-primary w-100">
                                    <i class="bi bi-printer"></i> Imprimir
                                </button>

                            </form>

                        </div>
                    </div>

                </div>

                <!-- INFO -->
                <div class="col-lg-6">

                    <div class="card shadow-sm border-0">
                        <div class="card-body">

                            <h5 class="mb-3">
                                <i class="bi bi-info-circle"></i> Instruções
                            </h5>

                            <ul class="list-unstyled info-list">
                                <li><i class="bi bi-check-circle"></i> Envie arquivos PDF, DOCX ou imagem</li>
                                <li><i class="bi bi-check-circle"></i> Escolha frente e verso se necessário</li>
                                <li><i class="bi bi-check-circle"></i> Administradores podem imprimir para outros
                                    usuários</li>
                            </ul>

                        </div>
                    </div>

                </div>

            </div>

            <!-- FOOTER -->
            <div class="mt-4 text-center">
                <a href="/admin" class="btn btn-outline-secondary">
                    <i class="bi bi-bar-chart"></i> Painel
                </a>
            </div>

        </main>

    </div>

</body>

</html>