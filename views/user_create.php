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

            <label>Nome</label>
            <input name="name" required>

            <label>CPF</label>
            <input name="cpf" required>

            <label>Senha (somente admin)</label>
            <input type="password" name="password">

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