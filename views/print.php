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

                                    <div class="upload-box" id="drop-area">
                                        <i class="bi bi-cloud-upload fs-1"></i>
                                        <p id="file-label">Clique ou arraste o arquivo</p>
                                        <input type="file" name="arquivo" id="fileInput" hidden required>
                                    </div>

                                    <small class="text-muted">
                                        PDF, DOCX ou imagem (máx 10MB)
                                    </small>
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
                                        <input list="users" name="target_user" class="form-control">

                                        <datalist id="users">
                                            <?php foreach ($userList as $u): ?>
                                                <option value="<?= $u['cpf'] ?>">
                                                    <?= $u['name'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                <?php endif; ?>

                                <button id="btnPrint" class="btn btn-primary w-100">
                                    <span class="text">Imprimir</span>
                                    <span class="spinner-border spinner-border-sm d-none"></span>
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
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="/admin" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-speedometer2"></i> Painel
                    </a>
                <?php endif; ?>
            </div>

        </main>

    </div>
    <script>
        const dropArea = document.getElementById('drop-area');
        const input = document.getElementById('fileInput');
        const label = document.getElementById('file-label');

        // clique
        dropArea.onclick = () => input.click();

        // mostrar nome do arquivo
        input.addEventListener('change', () => {
            label.textContent = input.files[0]?.name || 'Clique ou arraste o arquivo';
        });

        // loading botão
        document.querySelector('form').addEventListener('submit', () => {
            const btn = document.getElementById('btnPrint');

            btn.disabled = true;
            btn.querySelector('.text').textContent = 'Enviando...';
            btn.querySelector('.spinner-border').classList.remove('d-none');
        });
    </script>
</body>

</html>