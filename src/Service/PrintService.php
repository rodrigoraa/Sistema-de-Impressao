<?php

class PrintService
{
    private $printer;

    public function __construct()
    {
        $this->printer = $_ENV['PRINTER_NAME'];
    }

    public function prepareFile($filePath)
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $pdfPath = $filePath;

        if ($ext === 'doc') {
            exec("libreoffice --headless --convert-to docx " . escapeshellarg($filePath) . " --outdir /tmp");
            $docxPath = "/tmp/" . basename($filePath, '.doc') . ".docx";

            exec("libreoffice --headless --convert-to pdf " . escapeshellarg($docxPath) . " --outdir /tmp");
            $pdfPath = "/tmp/" . basename($filePath, '.doc') . ".pdf";
        } elseif ($ext === 'docx') {
            exec("libreoffice --headless --convert-to pdf " . escapeshellarg($filePath) . " --outdir /tmp");
            $pdfPath = "/tmp/" . basename($filePath, '.docx') . ".pdf";
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $pdfPath = "/tmp/" . uniqid() . ".pdf";
            exec("convert " . escapeshellarg($filePath) . " " . escapeshellarg($pdfPath));
        }

        return file_exists($pdfPath) ? $pdfPath : false;
    }

    public function print($pdfPath, $copies = 1, $sides = 'one-sided')
    {
        $cmd = "lp -d {$this->printer} "
            . "-n " . intval($copies) . " "
            . "-o sides=" . escapeshellarg($sides) . " "
            . escapeshellarg($pdfPath);

        exec($cmd, $out, $status);

        return $status === 0;
    }
}