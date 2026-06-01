<?php

require_once __DIR__ . '/Database.php';

class PrintJobService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public function create($user, $originalName, $storedFile, $sourceExt, $copies, $numberUp, $sides, $orientation, $paper, $meta = [])
    {
        $now = date('Y-m-d H:i:s');
        $monthRef = date('Y-m', strtotime($now));
        $professorName = $meta['nome_professor'] ?? $this->findProfessorName($user);
        $stmt = $this->db->prepare("
            INSERT INTO print_jobs (
                user, nome_professor, original_name, stored_file, source_ext, mime_type, file_size,
                copies, number_up, sides, orientation, paper, status, printer, month_ref,
                observations, created_at, updated_at
            ) VALUES (
                :user, :nome_professor, :original_name, :stored_file, :source_ext, :mime_type, :file_size,
                :copies, :number_up, :sides, :orientation, :paper, 'queued', :printer, :month_ref,
                :observations, :created_at, :updated_at
            )
        ");

        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':nome_professor', $professorName, SQLITE3_TEXT);
        $stmt->bindValue(':original_name', $originalName, SQLITE3_TEXT);
        $stmt->bindValue(':stored_file', $storedFile, SQLITE3_TEXT);
        $stmt->bindValue(':source_ext', $sourceExt, SQLITE3_TEXT);
        $stmt->bindValue(':mime_type', $meta['mime_type'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':file_size', (int) ($meta['file_size'] ?? 0), SQLITE3_INTEGER);
        $stmt->bindValue(':copies', (int) $copies, SQLITE3_INTEGER);
        $stmt->bindValue(':number_up', (int) $numberUp, SQLITE3_INTEGER);
        $stmt->bindValue(':sides', $sides, SQLITE3_TEXT);
        $stmt->bindValue(':orientation', $orientation, SQLITE3_TEXT);
        $stmt->bindValue(':paper', $paper, SQLITE3_TEXT);
        $stmt->bindValue(':printer', $meta['printer'] ?? ($_ENV['PRINTER_NAME'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':month_ref', $meta['month_ref'] ?? $monthRef, SQLITE3_TEXT);
        $stmt->bindValue(':observations', $meta['observations'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $now, SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', $now, SQLITE3_TEXT);
        $stmt->execute();

        return (int) $this->db->lastInsertRowID();
    }

    public function markProcessing($id, $preparedFile = null, $pages = 0, $chargedPages = 0)
    {
        $this->updateStatus($id, 'processing', $preparedFile, $pages, $chargedPages, null, false);
    }

    public function updateSelection($id, $selectedPagesLabel, $selectedPagesCount)
    {
        $stmt = $this->db->prepare("
            UPDATE print_jobs
            SET selected_pages_label = :selected_pages_label,
                selected_pages_count = :selected_pages_count,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':selected_pages_label', substr((string) $selectedPagesLabel, 0, 120), SQLITE3_TEXT);
        $stmt->bindValue(':selected_pages_count', max(0, (int) $selectedPagesCount), SQLITE3_INTEGER);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function markCompleted($id, $preparedFile, $pages, $chargedPages)
    {
        $this->updateStatus($id, 'completed', $preparedFile, $pages, $chargedPages, null, true);
    }

    public function markFailed($id, $preparedFile, $pages, $chargedPages, $errorMessage)
    {
        $this->updateStatus($id, 'failed', $preparedFile, $pages, $chargedPages, $errorMessage, true);
    }

    public function markPreValidationFailed($id, $errorMessage, $cupsResult = [])
    {
        $this->updateCupsResult($id, $cupsResult);
        $this->updateStatus($id, 'failed', null, 0, 0, $errorMessage, true, false);
    }

    public function updateCupsResult($id, $cupsResult)
    {
        $diagnostics = $cupsResult['diagnostics'] ?? [];
        $stmt = $this->db->prepare("
            UPDATE print_jobs
            SET cups_job_id = COALESCE(:cups_job_id, cups_job_id),
                status_cups = COALESCE(:status_cups, status_cups),
                cups_stdout = COALESCE(:cups_stdout, cups_stdout),
                cups_stderr = COALESCE(:cups_stderr, cups_stderr),
                return_code = :return_code,
                printer = COALESCE(:printer, printer),
                printer_enabled = :printer_enabled,
                printer_accepting = :printer_accepting,
                printer_state = COALESCE(:printer_state, printer_state),
                printer_state_message = COALESCE(:printer_state_message, printer_state_message),
                error_category = COALESCE(:error_category, error_category),
                updated_at = :updated_at
            WHERE id = :id
        ");

        $this->bindNullableText($stmt, ':cups_job_id', $cupsResult['job_id'] ?? null);
        $this->bindNullableText($stmt, ':status_cups', $cupsResult['status_cups'] ?? null);
        $this->bindNullableText($stmt, ':cups_stdout', $cupsResult['stdout'] ?? null);
        $this->bindNullableText($stmt, ':cups_stderr', $cupsResult['stderr'] ?? null);
        if (array_key_exists('return_code', $cupsResult) && $cupsResult['return_code'] !== null) {
            $stmt->bindValue(':return_code', (int) $cupsResult['return_code'], SQLITE3_INTEGER);
        } else {
            $stmt->bindValue(':return_code', null, SQLITE3_NULL);
        }
        $this->bindNullableText($stmt, ':printer', $diagnostics['printer'] ?? ($_ENV['PRINTER_NAME'] ?? null));
        $this->bindNullableBool($stmt, ':printer_enabled', $diagnostics['enabled'] ?? null);
        $this->bindNullableBool($stmt, ':printer_accepting', $diagnostics['accepting'] ?? null);
        $this->bindNullableText($stmt, ':printer_state', $diagnostics['printer_state'] ?? null);
        $this->bindNullableText($stmt, ':printer_state_message', $diagnostics['printer_state_message'] ?? null);
        $this->bindNullableText($stmt, ':error_category', $cupsResult['error_category'] ?? ($diagnostics['reason'] ?? null));
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function updateOrientation($id, $orientation)
    {
        if (!in_array($orientation, ['portrait', 'landscape'], true)) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE print_jobs
            SET orientation = :orientation,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':orientation', $orientation, SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function listForUser($user, $isAdmin = false, $status = '', $limit = 100, $filters = [])
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

        if (!empty($filters['cpf'])) {
            $where[] = 'pj.user LIKE :cpf';
            $params[':cpf'] = ['%' . preg_replace('/\D/', '', (string) $filters['cpf']) . '%', SQLITE3_TEXT];
        }
        if (!empty($filters['month'])) {
            $where[] = "COALESCE(NULLIF(pj.month_ref, ''), strftime('%Y-%m', pj.created_at)) = :month";
            $params[':month'] = [(string) $filters['month'], SQLITE3_TEXT];
        }
        if (!empty($filters['error'])) {
            $where[] = '(pj.error_category LIKE :error OR pj.error_message LIKE :error)';
            $params[':error'] = ['%' . (string) $filters['error'] . '%', SQLITE3_TEXT];
        }

        $sql = "
            SELECT pj.*, u.name AS user_name
            FROM print_jobs pj
            LEFT JOIN users u ON u.cpf = pj.user
        ";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY pj.id DESC LIMIT :query_limit';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => [$value, $type]) {
            $stmt->bindValue($key, $value, $type);
        }
        $limit = max(1, min(500, (int) $limit));
        $stmt->bindValue(':query_limit', $limit, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $jobs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $jobs[] = $row;
        }

        if ($status === '' || $status === 'completed') {
            $jobs = array_merge($jobs, $this->legacyUsageJobs($user, $isAdmin, $filters));
        }

        $this->sortJobsNewestFirst($jobs);

        return array_slice($jobs, 0, $limit);
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

    public function adjustAccounting($id, $chargedPages, $reason, $adminCpf)
    {
        $id = (int) $id;
        $chargedPages = max(0, (int) $chargedPages);
        $reason = trim((string) $reason);
        if ($id < 1) {
            throw new RuntimeException('Impressão inválida');
        }
        if ($reason === '') {
            throw new RuntimeException('Informe o motivo da correção');
        }

        $job = $this->findVisible($id, '', true);
        if ($job === null || !empty($job['legacy_usage'])) {
            throw new RuntimeException('Impressão não encontrada');
        }
        if (($job['status'] ?? '') !== 'completed') {
            throw new RuntimeException('Só é possível corrigir contabilização de impressões concluídas');
        }

        $previous = (int) ($job['charged_pages'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $entry = sprintf(
            '[%s] Contabilização corrigida por %s: %d -> %d. Motivo: %s',
            $now,
            $adminCpf,
            $previous,
            $chargedPages,
            str_replace(["\r", "\n"], ' ', $reason)
        );
        $observations = trim((string) ($job['observations'] ?? ''));
        $observations = trim($observations . ($observations !== '' ? "\n" : '') . $entry);

        $stmt = $this->db->prepare("
            UPDATE print_jobs
            SET charged_pages = :charged_pages,
                entered_accumulator = :entered_accumulator,
                observations = :observations,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':charged_pages', $chargedPages, SQLITE3_INTEGER);
        $stmt->bindValue(':entered_accumulator', $chargedPages > 0 ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':observations', substr($observations, -4000), SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', $now, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        return [
            'previous' => $previous,
            'current' => $chargedPages,
        ];
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
        $legacyWhere = [];
        if (!$isAdmin) {
            $legacyWhere[] = 'us.user = :user';
        }
        $legacyWhere[] = "NOT EXISTS (
            SELECT 1
            FROM print_jobs pj
            WHERE pj.user = us.user
              AND pj.stored_file = us.file
        )";
        $legacySqlWhere = 'WHERE ' . implode(' AND ', $legacyWhere);
        $legacyStmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(pages), 0) AS charged
            FROM usage us
            {$legacySqlWhere}
        ");
        if (!$isAdmin) {
            $legacyStmt->bindValue(':user', $user, SQLITE3_TEXT);
        }
        $legacyResult = $legacyStmt->execute();
        $legacy = $legacyResult ? $legacyResult->fetchArray(SQLITE3_ASSOC) : [];

        return [
            'total' => (int) ($row['total'] ?? 0) + (int) ($legacy['total'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0) + (int) ($legacy['total'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'charged' => (int) ($row['charged'] ?? 0) + (int) ($legacy['charged'] ?? 0),
        ];
    }

    public function monthlySummary($month, $cpf = '', $includeFailures = false)
    {
        $where = ["COALESCE(NULLIF(pj.month_ref, ''), strftime('%Y-%m', pj.created_at)) = :month"];
        $params = [':month' => [$month, SQLITE3_TEXT]];
        if ($cpf !== '') {
            $where[] = 'pj.user = :cpf';
            $params[':cpf'] = [preg_replace('/\D/', '', $cpf), SQLITE3_TEXT];
        }
        if (!$includeFailures) {
            $where[] = "pj.status = 'completed'";
        }

        $stmt = $this->db->prepare("
            SELECT
                pj.user AS cpf,
                COALESCE(u.name, pj.nome_professor, pj.user) AS name,
                COUNT(*) AS jobs,
                COALESCE(SUM(CASE WHEN pj.status = 'completed' THEN pj.pages ELSE 0 END), 0) AS pages,
                COALESCE(SUM(CASE WHEN pj.status = 'completed' THEN pj.copies ELSE 0 END), 0) AS copies,
                COALESCE(SUM(CASE WHEN pj.status = 'completed' AND pj.entered_accumulator = 1 THEN pj.charged_pages ELSE 0 END), 0) AS charged_pages,
                SUM(CASE WHEN pj.status = 'failed' THEN 1 ELSE 0 END) AS failed_jobs,
                GROUP_CONCAT(DISTINCT pj.status) AS statuses,
                GROUP_CONCAT(DISTINCT COALESCE(pj.error_category, pj.error_message)) AS errors
            FROM print_jobs pj
            LEFT JOIN users u ON u.cpf = pj.user
            WHERE " . implode(' AND ', $where) . "
            GROUP BY pj.user
            ORDER BY name
        ");
        foreach ($params as $key => [$value, $type]) {
            $stmt->bindValue($key, $value, $type);
        }

        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[$row['cpf']] = $row;
        }

        foreach ($this->monthlyUsageSummary($month, $cpf) as $legacy) {
            $key = $legacy['cpf'];
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'cpf' => $legacy['cpf'],
                    'name' => $legacy['name'],
                    'jobs' => (int) $legacy['jobs'],
                    'pages' => (int) $legacy['charged_pages'],
                    'copies' => 0,
                    'charged_pages' => (int) $legacy['charged_pages'],
                    'failed_jobs' => 0,
                    'statuses' => 'usage_legacy',
                    'errors' => '',
                ];
                continue;
            }

            if ((int) $legacy['jobs'] > 0) {
                $rows[$key]['jobs'] = (int) $rows[$key]['jobs'] + (int) $legacy['jobs'];
                $rows[$key]['charged_pages'] = (int) $rows[$key]['charged_pages'] + (int) $legacy['charged_pages'];
                $rows[$key]['pages'] = (int) $rows[$key]['pages'] + (int) $legacy['charged_pages'];
                $rows[$key]['statuses'] = trim(($rows[$key]['statuses'] ?? '') . ',usage_legacy', ',');
            }
        }

        usort($rows, fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        return array_values($rows);
    }

    private function monthlyUsageSummary($month, $cpf = '')
    {
        $where = ["strftime('%Y-%m', us.created_at) = :month"];
        $params = [':month' => [$month, SQLITE3_TEXT]];
        if ($cpf !== '') {
            $where[] = 'us.user = :cpf';
            $params[':cpf'] = [preg_replace('/\D/', '', $cpf), SQLITE3_TEXT];
        }
        $where[] = "NOT EXISTS (
            SELECT 1
            FROM print_jobs pj
            WHERE pj.user = us.user
              AND pj.stored_file = us.file
        )";

        $stmt = $this->db->prepare("
            SELECT
                us.user AS cpf,
                COALESCE(u.name, us.user) AS name,
                COUNT(*) AS jobs,
                COALESCE(SUM(us.pages), 0) AS charged_pages
            FROM usage us
            LEFT JOIN users u ON u.cpf = us.user
            WHERE " . implode(' AND ', $where) . "
            GROUP BY us.user
        ");
        foreach ($params as $key => [$value, $type]) {
            $stmt->bindValue($key, $value, $type);
        }

        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function legacyUsageJobs($user, $isAdmin, $filters = [])
    {
        $where = [];
        $params = [];

        if (!$isAdmin) {
            $where[] = 'us.user = :user';
            $params[':user'] = [$user, SQLITE3_TEXT];
        }

        if (!empty($filters['cpf'])) {
            $where[] = 'us.user LIKE :cpf';
            $params[':cpf'] = ['%' . preg_replace('/\D/', '', (string) $filters['cpf']) . '%', SQLITE3_TEXT];
        }

        if (!empty($filters['month'])) {
            $where[] = "strftime('%Y-%m', us.created_at) = :month";
            $params[':month'] = [(string) $filters['month'], SQLITE3_TEXT];
        }

        $where[] = "NOT EXISTS (
            SELECT 1
            FROM print_jobs pj
            WHERE pj.user = us.user
              AND pj.stored_file = us.file
        )";

        $sql = "
            SELECT
                us.id,
                us.user,
                u.name AS user_name,
                us.file,
                us.pages,
                us.created_at
            FROM usage us
            LEFT JOIN users u ON u.cpf = us.user
        ";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY us.id DESC LIMIT 500';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => [$value, $type]) {
            $stmt->bindValue($key, $value, $type);
        }

        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $file = (string) ($row['file'] ?? '');
            $rows[] = [
                'id' => 'u' . (int) ($row['id'] ?? 0),
                'user' => $row['user'] ?? '',
                'user_name' => $row['user_name'] ?? '',
                'nome_professor' => $row['user_name'] ?? '',
                'original_name' => $file !== '' ? basename($file) : 'Registro antigo',
                'stored_file' => $file,
                'prepared_file' => null,
                'source_ext' => $file !== '' ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : '',
                'mime_type' => '',
                'file_size' => ($file !== '' && is_file($file)) ? (int) filesize($file) : 0,
                'pages' => (int) ($row['pages'] ?? 0),
                'confirmed_pages' => (int) ($row['pages'] ?? 0),
                'copies' => 1,
                'number_up' => 1,
                'charged_pages' => (int) ($row['pages'] ?? 0),
                'selected_pages_label' => '',
                'selected_pages_count' => 0,
                'sides' => '',
                'orientation' => '',
                'paper' => '',
                'status' => 'completed',
                'cups_job_id' => '',
                'status_cups' => 'usage_legacy',
                'error_message' => '',
                'error_category' => '',
                'return_code' => null,
                'created_at' => $row['created_at'] ?? '',
                'updated_at' => $row['created_at'] ?? '',
                'completed_at' => $row['created_at'] ?? '',
                'legacy_usage' => 1,
            ];
        }

        return $rows;
    }

    private function sortJobsNewestFirst(array &$jobs)
    {
        usort($jobs, function ($a, $b) {
            $aIsLegacy = !empty($a['legacy_usage']);
            $bIsLegacy = !empty($b['legacy_usage']);

            if ($aIsLegacy === $bIsLegacy) {
                $idCompare = $this->jobNumericId($b) <=> $this->jobNumericId($a);
                if ($idCompare !== 0) {
                    return $idCompare;
                }
            }

            $dateCompare = $this->jobTimestamp($b) <=> $this->jobTimestamp($a);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return $this->jobNumericId($b) <=> $this->jobNumericId($a);
        });
    }

    private function jobTimestamp($job)
    {
        foreach (['created_at', 'completed_at', 'updated_at'] as $field) {
            $value = trim((string) ($job[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return 0;
    }

    private function jobNumericId($job)
    {
        return (int) preg_replace('/\D+/', '', (string) ($job['id'] ?? '0'));
    }

    private function updateStatus($id, $status, $preparedFile, $pages, $chargedPages, $errorMessage, $finished, $enteredAccumulator = null)
    {
        $now = date('Y-m-d H:i:s');
        if ($enteredAccumulator === null) {
            $enteredAccumulator = $status === 'completed' && (int) $chargedPages > 0;
        }
        $stmt = $this->db->prepare("
            UPDATE print_jobs
            SET status = :status,
                prepared_file = COALESCE(:prepared_file, prepared_file),
                pages = :pages,
                confirmed_pages = :confirmed_pages,
                charged_pages = :charged_pages,
                error_message = :error_message,
                entered_accumulator = :entered_accumulator,
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
        $stmt->bindValue(':confirmed_pages', $status === 'completed' ? max(0, (int) $pages) : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':charged_pages', $status === 'completed' ? max(0, (int) $chargedPages) : 0, SQLITE3_INTEGER);
        if ($errorMessage === null || $errorMessage === '') {
            $stmt->bindValue(':error_message', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':error_message', substr((string) $errorMessage, 0, 500), SQLITE3_TEXT);
        }
        $stmt->bindValue(':entered_accumulator', $enteredAccumulator ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':updated_at', $now, SQLITE3_TEXT);
        if ($finished) {
            $stmt->bindValue(':completed_at', $now, SQLITE3_TEXT);
        } else {
            $stmt->bindValue(':completed_at', null, SQLITE3_NULL);
        }
        $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function findProfessorName($cpf)
    {
        $stmt = $this->db->prepare("SELECT name FROM users WHERE cpf = :cpf LIMIT 1");
        $stmt->bindValue(':cpf', $cpf, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

        return $row['name'] ?? '';
    }

    private function bindNullableText(SQLite3Stmt $stmt, $key, $value)
    {
        if ($value === null || $value === '') {
            $stmt->bindValue($key, null, SQLITE3_NULL);
        } else {
            $stmt->bindValue($key, substr((string) $value, 0, 4000), SQLITE3_TEXT);
        }
    }

    private function bindNullableBool(SQLite3Stmt $stmt, $key, $value)
    {
        if ($value === null) {
            $stmt->bindValue($key, null, SQLITE3_NULL);
        } else {
            $stmt->bindValue($key, $value ? 1 : 0, SQLITE3_INTEGER);
        }
    }
}
