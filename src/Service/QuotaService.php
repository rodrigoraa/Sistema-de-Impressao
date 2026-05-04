<?php

class QuotaService
{
    private $db;
    private $limit = 50;

    public function __construct()
    {
        $this->db = new SQLite3(__DIR__ . '/../../storage/usage.db');
    }

    public function canPrint($user, $pages)
    {
        return ($this->getUsage($user) + $pages) <= $this->limit;
    }

    public function register($user, $pages)
    {
        $stmt = $this->db->prepare("INSERT INTO usage (user, pages) VALUES (:u, :p)");
        $stmt->bindValue(':u', $user);
        $stmt->bindValue(':p', $pages);
        $stmt->execute();
    }

    public function getUsage($user)
    {
        $stmt = $this->db->prepare("SELECT SUM(pages) as total FROM usage WHERE user = :u");
        $stmt->bindValue(':u', $user);
        $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        return (int) ($res['total'] ?? 0);
    }
}