<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Usuários</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="/css/base.css?v=20260511">
    <link rel="stylesheet" href="/css/users.css?v=20260511">
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

            <div class="user-toolbar">
                <div>
                    <p class="section-kicker mb-1">Administração</p>
                    <h1><i class="bi bi-people"></i> Usuários</h1>
                    <p>Gerencie professores e administradores autorizados a imprimir.</p>
                </div>

                <div class="mt-4 text-center">
                    <a href="/admin" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar ao painel
                    </a>
                    <a href="/admin/users/create" class="btn btn-primary btn-sm">
                        <i class="bi bi-person-plus"></i> Novo usuário
                    </a>
                </div>

            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>CPF</th>
                                    <th>Tipo</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="4" class="empty-state">
                                            <i class="bi bi-people"></i>
                                            Nenhum usuário cadastrado
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($u['name']) ?></td>

                                            <td><?= htmlspecialchars($u['cpf']) ?></td>

                                            <td>
                                                <?php if ($u['role'] === 'admin'): ?>
                                                    <span class="badge role-badge-admin">Administrador</span>
                                                <?php else: ?>
                                                    <span class="badge role-badge-user">Professor</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-end">

                                                <a href="/admin/users/edit?id=<?= $u['id'] ?>"
                                                    class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>

                                                <form method="post" action="/admin/users/delete" class="d-inline">
                                                    <input type="hidden" name="csrf_token"
                                                        value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                                    <button class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Tem certeza que deseja excluir este usuário?')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>

                                            </td>
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
