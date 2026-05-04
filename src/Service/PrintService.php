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

        if ($ext === 'docx') {
            exec("libreoffice --headless --convert-to pdf " . escapeshellarg($filePath) . " --outdir /tmp");
            $pdfPath = "/tmp/" . basename($filePath, '.docx') . ".pdf";
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $pdfPath = "/tmp/" . uniqid() . ".pdf";
            exec("convert " . escapeshellarg($filePath) . " " . escapeshellarg($pdfPath));
        }

        return file_exists($pdfPath) ? $pdfPath : false;
    }

    public function print($pdfPath, $copies, $sides, $orientation, $quality)
    {
        $orientationFlag = ($orientation === 'landscape') ? 4 : 3;

        $cmd = "lp -d {$this->printer} "
            . "-n " . intval($copies) . " "
            . "-o sides={$sides} "
            . "-o orientation-requested={$orientationFlag} "
            . "-o print-quality={$quality} "
            . escapeshellarg($pdfPath);

        exec($cmd . " 2>&1", $out, $status);

        // DEBUG (temporário)
        file_put_contents(
            '/tmp/print_debug.log',
            date('Y-m-d H:i:s') . "\nCMD: $cmd\nSTATUS: $status\nOUTPUT:\n" . implode("\n", $out) . "\n\n",
            FILE_APPEND
        );

        return $status === 0;
    }
}