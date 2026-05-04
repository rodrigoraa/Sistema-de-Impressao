<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Login</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/login.css">
</head>

<body>

    <main class="login-shell">

        <section class="login-hero">
            <span class="hero-badge">Sistema de Impressão</span>
            <h1>Impressão EESJ</h1>
            <p>Professores acessem com seu CPF.</p>
        </section>

        <section class="login-panel">

            <div class="panel-copy">
                <h2>Entrar no sistema</h2>
                <p>Informe seu CPF. Administradores devem informar senha.</p>
            </div>

            <!-- ALERT -->
            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert alert-danger">
                    <?= $_SESSION['flash'];
                    unset($_SESSION['flash']); ?>
                </div>
            <?php endif; ?>

            <form method="post" class="login-form">

                <div class="mb-3">
                    <label class="form-label">CPF</label>
                    <input type="text" name="matricula" id="matricula" class="form-control" required
                        autocomplete="username">
                </div>

                <div class="mb-3" id="senha-wrap" style="display:none;">
                    <label class="form-label">Senha</label>
                    <input type="password" name="senha" id="senha" class="form-control" autocomplete="current-password">
                </div>

                <button class="btn btn-primary w-100">Entrar</button>

            </form>

        </section>

    </main>

    <script>
        const admins = <?= json_encode($admin_matriculas ?? []) ?>;
        const input = document.getElementById('matricula');
        const senhaWrap = document.getElementById('senha-wrap');

        input.addEventListener('input', () => {
            const val = input.value.trim().replace(/\D/g, '');
            senhaWrap.style.display = admins.includes(val) ? 'block' : 'none';
        });
    </script>

</body>

</html>