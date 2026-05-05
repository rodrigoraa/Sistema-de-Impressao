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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

                                <div class="mb-3 upload-wrapper">

                                    <label class="form-label">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Arquivo
                                    </label>

                                    <div class="upload-box" id="drop-area">
                                        <i class="bi bi-cloud-upload fs-1"></i>
                                        <p id="file-label">Clique ou arraste o arquivo</p>
                                        <input type="file" name="arquivo" id="fileInput" hidden required>
                                    </div>

                                    <div id="file-info" class="mt-2 small text-muted"></div>

                                    <!-- PREVIEW -->
                                    <div id="preview" class="mt-3"></div>

                                    <!-- PROGRESS -->
                                    <div class="progress mt-3 d-none" id="progressBar">
                                        <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                    </div>

                                    <small class="text-muted">
                                        PDF, DOC, DOCX ou imagem
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

                                <div class="mb-3">
                                    <label class="form-label">Páginas por folha</label>
                                    <select class="form-select" name="number_up">
                                        <option value="1">1 por folha</option>
                                        <option value="2">2 por folha</option>
                                        <option value="4">4 por folha</option>
                                        <option value="8">8 por folha</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Tamanho do papel</label>
                                    <select class="form-select" name="paper">
                                        <option value="A4">A4</option>
                                        <option value="Letter">Carta</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Escala</label>
                                    <select class="form-select" name="scale">
                                        <option value="100">100%</option>
                                        <option value="90">90%</option>
                                        <option value="fit">Ajustar à página</option>
                                    </select>
                                </div>

                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <div class="mb-3 admin-box">

                                        <label class="form-label">
                                            <i class="bi bi-person-gear"></i> Imprimir para
                                        </label>

                                        <input list="users" name="target_user" class="form-control">

                                        <datalist id="users">
                                            <?php foreach ($userList as $u): ?>
                                                <option value="<?= $u['cpf'] ?>">
                                                    <?= $u['name'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </datalist>

                                        <small class="text-muted">
                                            Você está imprimindo em nome de outro usuário
                                        </small>

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
                                <li><i class="bi bi-check-circle"></i> Envie arquivos PDF, DOC, DOCX ou imagem</li>
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

    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Visualização do arquivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center">
                    <div id="modalContent"></div>
                </div>

            </div>
        </div>
    </div>

    <script>
        const dropArea = document.getElementById('drop-area');
        const input = document.getElementById('fileInput');
        const label = document.getElementById('file-label');
        const info = document.getElementById('file-info');
        const preview = document.getElementById('preview');
        const progress = document.getElementById('progressBar');
        const bar = progress.querySelector('.progress-bar');

        // clique
        dropArea.onclick = () => input.click();

        // tipos permitidos
        const allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

        // tamanho
        function formatSize(bytes) {
            return (bytes / 1024 / 1024).toFixed(2) + ' MB';
        }

        // 🔥 abre modal
        function openModal(url, ext) {
            const modalContent = document.getElementById('modalContent');

            if (['jpg', 'jpeg', 'png'].includes(ext)) {
                modalContent.innerHTML = `<img src="${url}" style="max-width:100%;">`;
            }

            if (ext === 'pdf') {
                modalContent.innerHTML = `<iframe src="${url}" style="width:100%; height:80vh;"></iframe>`;
            }

            const modal = new bootstrap.Modal(document.getElementById('previewModal'));
            modal.show();
        }

        // validação + preview
        input.addEventListener('change', () => {
            const file = input.files[0];
            if (!file) return;

            const ext = file.name.split('.').pop().toLowerCase();

            preview.innerHTML = '';
            dropArea.classList.remove('error', 'success');

            // valida tipo
            if (!allowed.includes(ext)) {
                label.textContent = "Tipo não permitido";
                dropArea.classList.add('error');
                input.value = '';
                return;
            }

            const maxUploadMb = 2;

            // valida tamanho
            if (file.size > maxUploadMb * 1024 * 1024) {
                label.textContent = `Arquivo muito grande (máx ${maxUploadMb}MB)`;
                dropArea.classList.add('error');
                input.value = '';
                return;
            }

            // sucesso
            label.textContent = file.name;
            info.textContent = formatSize(file.size);
            dropArea.classList.add('success');

            const url = URL.createObjectURL(file);

            // preview pequeno + clique
            if (['jpg', 'jpeg', 'png'].includes(ext)) {
                preview.innerHTML = `<img src="${url}" style="cursor:pointer;">`;
                preview.onclick = () => openModal(url, ext);
            }

            if (ext === 'pdf') {
                preview.innerHTML = `<iframe src="${url}" style="cursor:pointer;"></iframe>`;
                preview.onclick = () => openModal(url, ext);
            }

            if (['doc', 'docx'].includes(ext)) {
                preview.innerHTML = `<p class="text-muted">Pré-visualização disponível após envio</p>`;
            }
        });

        // progresso fake (UX)
        document.querySelector('form').addEventListener('submit', () => {
            progress.classList.remove('d-none');

            let percent = 0;
            const interval = setInterval(() => {
                percent += 10;
                bar.style.width = percent + '%';

                if (percent >= 100) clearInterval(interval);
            }, 100);
        });

        fetch('/printer/options')
            .then(r => r.json())
            .then(data => {

                const container = document.getElementById('advanced-options');
                if (!container) return;

                for (const key in data) {
                    const item = data[key];

                    const col = document.createElement('div');
                    col.className = 'col-md-6';

                    const label = document.createElement('label');
                    label.className = 'form-label';
                    label.textContent = item.label;

                    const select = document.createElement('select');
                    select.className = 'form-select';
                    select.name = 'opt_' + key;

                    item.options.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt;
                        option.textContent = opt;

                        if (opt === item.default) {
                            option.selected = true;
                        }

                        select.appendChild(option);
                    });

                    col.appendChild(label);
                    col.appendChild(select);
                    container.appendChild(col);
                }
            });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
