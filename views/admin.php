<!DOCTYPE html>
<html>

<head>
    <title>Painel</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>

    <div class="container">

        <h2>Relatório de Impressão</h2>

        <p class="user">Usuário: <?= $_SESSION['name'] ?></p>

        <!-- 🔹 Seletor de mês -->
        <form method="get">
            <label>Escolher mês</label>
            <input type="month" name="month" value="<?= $_GET['month'] ?? date('Y-m') ?>">
            <button type="submit">Ver</button>
        </form>

        <h3>Mês selecionado: <?= $_GET['month'] ?? date('Y-m') ?></h3>

        <!-- 🔹 Resultado -->
        <table class="table">
            <tr>
                <th>Professor</th>
                <th>Total de páginas</th>
            </tr>

            <?php if (empty($data)): ?>
                <tr>
                    <td colspan="2">Nenhuma impressão neste mês</td>
                </tr>
            <?php else: ?>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['user']) ?></td>
                        <td><?= $row['total'] ?> páginas</td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <div class="footer">
            <a href="/">Voltar</a>
            <a href="/logout">Sair</a>
            <a href="/admin/users">Gerenciar usuários</a>
        </div>

    </div>

</body>

</html>