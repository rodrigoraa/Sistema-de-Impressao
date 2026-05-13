<?php

require_once __DIR__ . '/Database.php';

class PrintJobService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public function create($user, $originalName, $storedFile, $sourceExt, $copies, $numberUp, $sides, $orientation, $paper)
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            INSERT INTO print_jobs (
                user, original_name, stored_file, source_ext, copies, number_up,
                sides, orientation, paper, status, created_at, updated_at
            ) VALUES (
                :user, :original_name, :stored_file, :source_ext, :copies, :number_up,
                :sides, :orientation, :paper, 'queued', :created_at, :updated_at
            )
        ");

        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':original_name', $originalName, SQLITE3_TEXT);
        $stmt->bindValue(':stored_file', $storedFile, SQLITE3_TEXT);
        $stmt->bindValue(':source_ext', $sourceExt, SQLITE3_TEXT);
        $stmt->bindValue(':copies', (int) $copies, SQLITE3_INTEGER);
        $stmt->bindValue(':number_up', (int) $numberUp, SQLITE3_INTEGER);
        $stmt->bindValue(':sides', $sides, SQLITE3_TEXT);
        $stmt->bindValue(':orientation', $orientation, SQLITE3_TEXT);
        $stmt->bindValue(':paper', $paper, SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $now, SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', $now, SQLITE3_TEXT);
        $stmt->execute();

        return (int) $this->db->lastInsertRowID();
    }

    public function markProcessing($id, $preparedFile = null, $pages = 0, $chargedPages = 0)
    {
        $this->updateStatus($id, 'processing', $preparedFile, $pages, $chargedPages, null, false);
    }

    public function markCompleted($id, $preparedFile, $pages, $chargedPages)
    {
        $this->updateStatus($id, 'completed', $preparedFile, $pages, $chargedPages, null, true);
    }

    public function markFailed($id, $preparedFile, $pages, $chargedPages, $errorMessage)
    {
        $this->updateStatus($id, 'failed', $preparedFile, $pages, $chargedPages, $errorMessage, true);
    }

    public function listForUser($user, $isAdmin = false, $status = '', $limit = 100)
    {
        $where = [];
        $params = [];

        if (!$isAdmin) {
            $where[] = 'pj.user = :user';
            $params[':user'] = [$user, SQLITE3_TEXT];
        }

        if ($status === 'active') {
            $where[] = "pj.status IN ('queued', 'processing')";
        } elseif (in_array($status, ['queued', 'processing', 'completed', 'failed'], true)) {
            $where[] = 'pj.status = :status';
            $params[':status'] = [$status, SQLITE3_TEXT];
        }

        $sql = "
            SELECT pj.*, u.name AS user_name
            FROM print_jobs pj
            LEFT JOIN users u ON u.cpf = pj.user
        ";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY pj.created_at DESC, pj.id DESC LIMIT :limit';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => [$value, $type]) {
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->bindValue(':limit', max(1, min(500, (int) $limit)), SQLITE3_INTEGER);

        $result = $stmt->execute();
        $jobs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $jobs[] = $row;
        }

        return $jobs;
    }

    public function findVisible($id, $user, $isAdmin = false)
    {
        $sql = "
            SELECT pj.*, u.name AS user_name
            FROM print_jobs pj
            LEFT JOIN users u ON u.cpf = pj.user
            WHERE pj.id = :id
        ";
        if (!$isAdmin) {
            $sql .= " AND pj.user = :user";
        }
        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
        if (!$isAdmin) {
            $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        }

        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

        return $row ?: null;
    }

    public function statsForUser($user, $isAdmin = false)
    {
        $where = $isAdmin ? '' : 'WHERE user = :user';
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status IN ('queued', 'processing') THEN 1 ELSE 0 END) AS active,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN charged_pages ELSE 0 END), 0) AS charged
            FROM print_jobs
            {$where}
        ");
        if (!$isAdmin) {
            $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        }

        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'charged' => (int) ($row['charged'] ?? 0),
        ];
    }

    private function updateStatus($id, $status, $preparedFile, $pages, $chargedPages, $errorMessage, $finished)
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            UPDATE print_jobs
            SET status = :status,
                prepared_file = COALESCE(:prepared_file, prepared_file),
                pages = :pages,
                charged_pages = :charged_pages,
                error_message = :error_message,
                updated_at = :updated_at,
                completed_at = :completed_at
            WHERE id = :id
        ");

        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        if ($preparedFile === null || $preparedFile === '') {
            $stmt->bindValue(':prepared_file', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':prepared_file', $preparedFile, SQLITE3_TEXT);
        }
        $stmt->bindValue(':pages', max(0, (int) $pages), SQLITE3_INTEGER);
        $stmt->bindValue(':charged_pages', max(0, (int) $chargedPages), SQLITE3_INTEGER);
        if ($errorMessage === null || $errorMessage === '') {
            $stmt->bindValue(':error_message', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':error_message', substr((string) $errorMessage, 0, 500), SQLITE3_TEXT);
        }
        $stmt->bindValue(':updated_at', $now, SQLITE3_TEXT);
        if ($finished) {
            $stmt->bindValue(':completed_at', $now, SQLITE3_TEXT);
        } else {
            $stmt->bindValue(':completed_at', null, SQLITE3_NULL);
        }
        $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
        $stmt->execute();
    }
}
