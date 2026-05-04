<!DOCTYPE html>
<html>

<head>
    <title>Impressão</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>

    <div class="container">

        <h2>Impressão</h2>

        <p class="user">Usuário: <?= $_SESSION['name'] ?></p>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert <?= ($_SESSION['flash_type'] ?? '') === 'error' ? 'error' : '' ?>">
                <?= $_SESSION['flash'];
                unset($_SESSION['flash']); ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">

            <label>Arquivo</label>
            <input type="file" name="arquivo" required>

            <label>Cópias</label>
            <input type="number" name="copies" value="1" min="1">

            <label>Modo</label>
            <select name="sides">
                <option value="one-sided">Simples</option>
                <option value="two-sided-long-edge">Frente e verso</option>
            </select>

            <label>Orientação</label>
            <select name="orientation">
                <option value="portrait">Retrato</option>
                <option value="landscape">Paisagem</option>
            </select>

            <label>Qualidade</label>
            <select name="quality">
                <option value="3">Normal</option>
                <option value="5">Alta</option>
            </select>

            <?php if ($_SESSION['role'] === 'admin'): ?>
                <label>Imprimir para</label>
                <select name="target_user">
                    <?php foreach ($userList as $u): ?>
                        <option value="<?= $u ?>">
                            <?= $u ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button type="submit">Imprimir</button>

        </form>

        <div class="footer">
            <a href="/admin">Painel</a>
            <a href="/logout">Sair</a>
        </div>

    </div>

</body>

</html>