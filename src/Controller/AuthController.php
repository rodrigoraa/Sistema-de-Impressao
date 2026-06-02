<?php

require_once __DIR__ . '/../Service/Database.php';

class AuthController
{
    private function validateCsrfOrFail()
    {
        $token = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            http_response_code(419);
            exit('Token CSRF inválido');
        }
    }

    private function db()
    {
        return Database::connect();
    }

    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['user'])) {
            header('Location: /');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCsrfOrFail();

            $db = $this->db();

            $cpf = preg_replace('/\D/', '', $_POST['matricula'] ?? '');
            $senha = $_POST['senha'] ?? '';

            $stmt = $db->prepare("SELECT * FROM users WHERE cpf = :cpf");
            $stmt->bindValue(':cpf', $cpf, SQLITE3_TEXT);
            $result = $stmt->execute();

            $user = $result->fetchArray(SQLITE3_ASSOC);

            if (!$user) {
                $_SESSION['flash'] = "CPF não encontrado";
                header('Location: /login');
                exit;
            }

            if ($user['role'] === 'admin') {
                if (!$senha || !password_verify($senha, $user['password'])) {
                    $_SESSION['flash'] = "Senha inválida";
                    header('Location: /login');
                    exit;
                }
            }

            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['user'] = $user['cpf'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];

            header('Location: /');
            exit;
        }

        // lista admins (para exibir campo de senha no front)
        $db = $this->db();
        $res = $db->query("SELECT cpf FROM users WHERE role = 'admin'");

        $admin_matriculas = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $admin_matriculas[] = $row['cpf'];
        }

        require __DIR__ . '/../../views/login.php';
    }

    public function logout()
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            $cookieOptions = [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ];

            if (!empty($params['domain'])) {
                $cookieOptions['domain'] = $params['domain'];
            }

            setcookie(session_name(), '', $cookieOptions);
        }

        session_destroy();
        header('Location: /login');
        exit;
    }
}
