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

        if (in_array($ext, ['jpg','jpeg','png'])) {
            $pdfPath = "/tmp/" . uniqid() . ".pdf";
            exec("convert " . escapeshellarg($filePath) . " " . escapeshellarg($pdfPath));
        }

        return file_exists($pdfPath) ? $pdfPath : false;
    }

    public function print($pdfPath, $copies, $sides, $orientation, $quality)
    {
        $orientationFlag = $orientation === 'landscape' ? 4 : 3;

        $cmd = "lp -d {$this->printer} "
             . "-n " . intval($copies) . " "
             . "-o sides=" . escapeshellarg($sides) . " "
             . "-o orientation-requested={$orientationFlag} "
             . "-o print-quality={$quality} "
             . escapeshellarg($pdfPath);

        exec($cmd, $out, $status);

        return $status === 0;
    }
}