<?php

class QuotaService
{
    private $db;

    public function __construct()
    {
        $dbPath = __DIR__ . '/../../storage/usage.db';

        if (!file_exists($dbPath)) {
            throw new Exception("Banco não encontrado: $dbPath");
        }

        $this->db = new SQLite3($dbPath);
    }

    // ✔ registra uso
    public function register($user, $pages)
    {
        $stmt = $this->db->prepare(
            "INSERT INTO usage (user, pages) VALUES (:u, :p)"
        );

        $stmt->bindValue(':u', $user, SQLITE3_TEXT);
        $stmt->bindValue(':p', $pages, SQLITE3_INTEGER);

        $result = $stmt->execute();

        if (!$result) {
            $this->log("Erro ao registrar uso para $user");
        }
    }

    // ✔ total de páginas por usuário
    public function getUsage($user)
    {
        $stmt = $this->db->prepare(
            "SELECT SUM(pages) as total FROM usage WHERE user = :u"
        );

        $stmt->bindValue(':u', $user, SQLITE3_TEXT);

        $res = $stmt->execute();

        if (!$res) {
            $this->log("Erro ao buscar uso para $user");
            return 0;
        }

        $row = $res->fetchArray(SQLITE3_ASSOC);

        return (int) ($row['total'] ?? 0);
    }

    // ✔ total geral (novo)
    public function getTotal()
    {
        $res = $this->db->query("SELECT SUM(pages) as total FROM usage");

        if (!$res) {
            return 0;
        }

        $row = $res->fetchArray(SQLITE3_ASSOC);

        return (int) ($row['total'] ?? 0);
    }

    // ✔ ranking (novo)
    public function getRanking()
    {
        $res = $this->db->query("
            SELECT user, SUM(pages) as total
            FROM usage
            GROUP BY user
            ORDER BY total DESC
        ");

        $data = [];

        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    private function log($msg)
    {
        file_put_contents(
            '/tmp/quota.log',
            date('Y-m-d H:i:s') . " | $msg\n",
            FILE_APPEND
        );
    }
}