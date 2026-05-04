<?php

class AuthController
{
    private function db()
    {
        return new SQLite3(__DIR__ . '/../../storage/usage.db');
    }

    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $db = $this->db();

            $cpf = strtoupper(trim($_POST['matricula'] ?? ''));
            $senha = $_POST['senha'] ?? '';

            $stmt = $db->prepare("SELECT * FROM users WHERE username = :u");
            $stmt->bindValue(':u', $cpf);
            $result = $stmt->execute();

            $user = $result->fetchArray(SQLITE3_ASSOC);

            if (!$user) {
                $_SESSION['flash'] = "Usuário não encontrado";
                header('Location: /login');
                exit;
            }

            // 🔥 REGRA
            if ($user['role'] === 'admin') {

                if (!$senha || !password_verify($senha, $user['password'])) {
                    $_SESSION['flash'] = "Senha inválida";
                    header('Location: /login');
                    exit;
                }

            }
            // professor entra sem senha

            $_SESSION['user'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header('Location: /');
            exit;
        }

        // 🔹 buscar admins para o JS
        $db = $this->db();
        $res = $db->query("SELECT username FROM users WHERE role = 'admin'");

        $admin_matriculas = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $admin_matriculas[] = strtoupper($row['username']);
        }

        require __DIR__ . '/../../views/login.php';
    }

    public function logout()
    {
        session_destroy();
        header('Location: /login');
        exit;
    }
}