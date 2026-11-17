<?php
require_once __DIR__ . '/../src/Service/PrintJobService.php';
$db = new SQLite3(':memory:');
Database::ensureSchema($db);
$reflection = new ReflectionClass(PrintJobService::class);
$service = $reflection->newInstanceWithoutConstructor();
$property = $reflection->getProperty('db');
$property->setValue($service, $db);
$first = $service->create('teacher-a', 'Atividade de matematica.pdf', '/tmp/a.pdf', 'pdf', 1, 1, 'one-sided', 'auto', 'A4');
$service->create('teacher-a', 'Avaliacao.pdf', '/tmp/b.pdf', 'pdf', 1, 1, 'one-sided', 'auto', 'A4');
$service->create('teacher-b', 'Atividade privada.pdf', '/tmp/c.pdf', 'pdf', 1, 1, 'one-sided', 'auto', 'A4');
$db->exec("INSERT INTO usage (user,pages,file,created_at) VALUES ('teacher-a',1,'/tmp/Atividade antiga.pdf',datetime('now'))");
$jobs = $service->listForUser('teacher-a', false, '', 150, ['search' => 'Atividade']);
if (count($jobs) !== 2 || array_filter($jobs, fn($job) => $job['user'] !== 'teacher-a')) {
    throw new RuntimeException('Busca deve filtrar nomes atuais e legados, mantendo o proprietário.');
}
if ($service->listForUser('teacher-a', false, '', 150, ['search' => "%' OR 1=1 --"]) !== []) {
    throw new RuntimeException('Busca deve tratar texto como dado, não SQL.');
}
if ($service->findVisible($first, 'teacher-b', false) !== null) {
    throw new RuntimeException('Outro professor não deve acessar o arquivo.');
}
echo "Busca no histórico e isolamento de professores: OK\n";

require_once __DIR__ . '/../src/Controller/PrintJobController.php';
if (!method_exists(PrintJobController::class, 'reusePageRange')) {
    throw new RuntimeException('Reaproveitamento precisa validar seleção persistida antes de restaurar.');
}
$rangeMethod = new ReflectionMethod(PrintJobController::class, 'reusePageRange');
$controller = new PrintJobController();
$normal = ['pages' => 4, 'selected_pages_count' => 2, 'selected_pages_label' => 'Páginas 1, 3'];
if ($rangeMethod->invoke($controller, $normal) !== '1,3') {
    throw new RuntimeException('Intervalo íntegro deve ser restaurado.');
}
$truncated = ['pages' => 200, 'selected_pages_count' => 60,
    'selected_pages_label' => substr('Páginas ' . implode(', ', range(4, 122, 2)), 0, 120)];
$mismatch = array_merge($normal, ['selected_pages_count' => 3]);
foreach ([$truncated, $mismatch, array_merge($normal, ['selected_pages_label' => 'Páginas 1-4 ímpares'])] as $unsafe) {
    try {
        $rangeMethod->invoke($controller, $unsafe);
    } catch (RuntimeException $expected) {
        continue;
    }
    throw new RuntimeException('Seleção truncada, divergente ou especial não pode ser restaurada silenciosamente.');
}
echo "Reaproveitamento com seleção íntegra; recusa de seleção truncada: OK\n";
