<!DOCTYPE html>
<html>

<head>
    <title>Login</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>

    <div class="container">

        <h2>Login</h2>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert error">
                <?= $_SESSION['flash'];
                unset($_SESSION['flash']); ?>
            </div>
        <?php endif; ?>

        <form method="post">

            <label>Usuário</label>
            <input name="user" required>

            <label>Senha</label>
            <input type="password" name="pass" required>

            <button type="submit">Entrar</button>

        </form>

    </div>

</body>

</html>