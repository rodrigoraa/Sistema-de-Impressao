<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Novo usuário</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="/css/base.css">
    <link rel="stylesheet" href="/css/users.css">
</head>

<body>

    <div class="app-shell">

        <!-- HEADER -->
        <header class="app-header">
            <div class="container d-flex justify-content-between align-items-center">

                <div class="d-flex align-items-center gap-2">
                    <img src="/image/logo_escola.png" class="logo">
                    <strong>Gestão de Usuários</strong>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <span class="user">
                        <i class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($_SESSION['name']) ?>
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
                <h4><i class="bi bi-person-plus"></i> Novo usuário</h4>

                <a href="/admin/users" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">

                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                        <div class="col-md-6">
                            <label class="form-label">Nome</label>
                            <input name="name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">CPF</label>
                            <input name="cpf" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tipo</label>
                            <select name="role" class="form-select" id="role">
                                <option value="user">Professor</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>

                        <div class="col-md-6" id="password-wrap" style="display:none;">
                            <label class="form-label">Senha (somente admin)</label>
                            <input type="password" name="password" class="form-control">
                        </div>

                        <div class="col-12">
                            <button class="btn btn-primary">
                                <i class="bi bi-save"></i> Salvar
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </main>

    </div>

    <script>
        const role = document.getElementById('role');
        const passwordWrap = document.getElementById('password-wrap');

        role.addEventListener('change', () => {
            passwordWrap.style.display = role.value === 'admin' ? 'block' : 'none';
        });
    </script>

</body>

</html>
