<!DOCTYPE html>
<html>

<head>
    <title>Painel</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>

    <div class="container">

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

        <div class="footer">
            <a href="/">Voltar</a>
            <a href="/logout">Sair</a>
        </div>

    </div>

</body>

</html>