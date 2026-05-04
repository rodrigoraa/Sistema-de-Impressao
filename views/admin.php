<!DOCTYPE html>
<html>

<head>
    <title>Painel</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>

    <div class="container">
        <h3>Total do mês: <?= $totalMonth ?> páginas</h3>
        <h2>Painel de Impressão</h2>

        <p class="user">Usuário:
            <?= $_SESSION['user'] ?>
        </p>

        <table class="table">
            <tr>
                <th>Usuário</th>
                <th>Páginas</th>
            </tr>

            <?php foreach ($data as $row): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($row['user']) ?>
                    </td>
                    <td>
                        <?= $row['total'] ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h3>Últimas impressões</h3>

        <table class="table">
            <tr>
                <th>Usuário</th>
                <th>Páginas</th>
                <th>Arquivo</th>
                <th>Data</th>
            </tr>

            <?php while ($row = $history->fetchArray(SQLITE3_ASSOC)): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($row['user']) ?>
                    </td>
                    <td>
                        <?= $row['pages'] ?>
                    </td>
                    <td>
                        <?= isset($row['file']) && $row['file'] ? basename($row['file']) : '-' ?>
                    </td>
                    <td>
                        <?= $row['created_at'] ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>

        <div class="footer">
            <a href="/">Voltar</a>
            <a href="/logout">Sair</a>
        </div>

    </div>

</body>

</html>