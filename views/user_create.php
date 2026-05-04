<!DOCTYPE html>
<html>

<head>
    <title>Novo usuário</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>

    <div class="container">

        <h2>Criar usuário</h2>

        <form method="post">

            <label>Usuário</label>
            <input name="username" required>

            <label>Senha</label>
            <input type="password" name="password" required>

            <label>Tipo</label>
            <select name="role">
                <option value="user">Professor</option>
                <option value="admin">Administrador</option>
            </select>

            <button type="submit">Salvar</button>

        </form>

        <div class="footer">
            <a href="/admin/users">Voltar</a>
        </div>

    </div>

</body>

</html>