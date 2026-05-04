<!DOCTYPE html>
<html>

<head>
    <title>Usuários</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>

    <div class="container">

        <h2>Usuários</h2>

        <a href="/admin/users/create">Novo usuário</a>

        <table class="table">
            <tr>
                <th>Nome</th>
                <th>CPF</th>
                <th>Role</th>
            </tr>

            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['name']) ?></td>
                    <td><?= htmlspecialchars($u['cpf']) ?></td>
                    <td><?= $u['role'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="footer">
            <a href="/admin">Voltar</a>
        </div>

    </div>

</body>

</html>