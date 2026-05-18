<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/assets/image/logo_escola.png">
    <title>Configuração inicial</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/login.css?v=20260511">
</head>

<body>

    <main class="login-shell">

        <section class="login-hero">
            <span class="hero-badge">Sistema de Impressão</span>
            <h1>Configuração</h1>
            <p>Crie o primeiro usuário administrador.</p>
        </section>

        <section class="login-panel">

            <div class="panel-copy">
                <h2>Administrador inicial</h2>
                <p>Esse passo é necessário na primeira execução.</p>
            </div>

            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert alert-danger">
                    <?= $_SESSION['flash'];
                    unset($_SESSION['flash']); ?>
                </div>
            <?php endif; ?>

            <form method="post" class="login-form">

                <div class="mb-3">
                    <label class="form-label">Nome</label>
                    <input type="text" name="name" class="form-control" required autocomplete="name">
                </div>

                <div class="mb-3">
                    <label class="form-label">CPF</label>
                    <input type="text" name="cpf" class="form-control" required autocomplete="username">
                </div>

                <div class="mb-3">
                    <label class="form-label">Senha</label>
                    <input type="password" name="password" class="form-control" required autocomplete="new-password">
                </div>

                <button class="btn btn-primary w-100">Criar administrador</button>

            </form>

        </section>

    </main>

</body>

</html>

