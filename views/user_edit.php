<!DOCTYPE html>
<html>

<head>
    <title>Editar usuário</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>

    <div class="container">

        <h2>Editar usuário</h2>

        <form method="post" action="/admin/users/update">

            <input type="hidden" name="id" value="<?= $user['id'] ?>">

            <label>Nome</label>
            <input name="name" value="<?= htmlspecialchars($user['name']) ?>" required>

            <label>CPF</label>
            <input name="cpf" value="<?= $user['cpf'] ?>" required>

            <label>Nova senha (opcional)</label>
            <input type="password" name="password">

            <label>Tipo</label>
            <select name="role">
                <option value="user" <?= $user['role'] == 'user' ? 'selected' : '' ?>>Professor</option>
                <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Administrador</option>
            </select>

            <button>Salvar</button>

        </form>

        <div class="footer">
            <a href="/admin/users">Voltar</a>
        </div>

    </div>

</body>

</html>