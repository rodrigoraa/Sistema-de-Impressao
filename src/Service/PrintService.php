<?php

class PrintService
{
    private $printer;

    public function __construct($printer)
    {
        $this->printer = $printer;
    }

    public function printFile($filePath)
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $pdfPath = $filePath;

        // DOCX → PDF
        if ($ext === 'docx') {
            $outputDir = '/tmp';
            exec("libreoffice --headless --convert-to pdf " . escapeshellarg($filePath) . " --outdir " . $outputDir);
            $pdfPath = $outputDir . '/' . basename($filePath, '.docx') . '.pdf';
        }

        // IMAGEM → PDF
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $pdfPath = '/tmp/' . basename($filePath) . '.pdf';
            exec("convert " . escapeshellarg($filePath) . " " . escapeshellarg($pdfPath));
        }

        // Verifica se PDF existe
        if (!file_exists($pdfPath)) {
            return false;
        }

        // Imprime
        $cmd = "lp -d {$this->printer} " . escapeshellarg($pdfPath);
        exec($cmd, $output, $status);

        return $status === 0;
    }
}