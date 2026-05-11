<?php

require_once __DIR__ . '/../Service/AuthService.php';
require_once __DIR__ . '/../Service/PrintJobService.php';

class PrintJobController
{
    public function index()
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        $status = $_GET['status'] ?? '';
        $isAdmin = AuthService::isAdmin();
        $service = new PrintJobService();
        $jobs = $service->listForUser($_SESSION['user'], $isAdmin, $status, 150);

        require __DIR__ . '/../../views/print_jobs.php';
    }
}
