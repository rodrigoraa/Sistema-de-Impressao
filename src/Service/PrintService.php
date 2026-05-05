<?php

class PrintService
{
    private $printer;

    public function __construct()
    {
        $this->printer = $_ENV['PRINTER_NAME'] ?? '';
    }

    public function prepareFile($filePath)
    {
        if (!file_exists($filePath)) {
            $this->log("Arquivo não existe: $filePath");
            return false;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            return $filePath;
        }

        $outputPdf = "/tmp/" . uniqid('print_', true) . ".pdf";

        if ($ext === 'docx') {

            if (!shell_exec("which libreoffice")) {
                $this->log("LibreOffice não encontrado");
                return false;
            }

            $cmd = "libreoffice --headless --convert-to pdf "
                . escapeshellarg($filePath)
                . " --outdir /tmp 2>&1";

            exec($cmd, $out, $status);

            $generated = glob("/tmp/" . pathinfo($filePath, PATHINFO_FILENAME) . "*.pdf");

            if ($status !== 0 || empty($generated)) {
                $this->log("Erro DOCX\nCMD: $cmd\n" . implode("\n", $out));
                return false;
            }

            rename($generated[0], $outputPdf);

            return $outputPdf;
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {

            if (!shell_exec("which convert")) {
                $this->log("ImageMagick não encontrado");
                return false;
            }

            $cmd = "convert "
                . escapeshellarg($filePath)
                . " -density 150 -quality 90 "
                . escapeshellarg($outputPdf) . " 2>&1";

            exec($cmd, $out, $status);

            if ($status !== 0 || !file_exists($outputPdf)) {
                $this->log("Erro IMG\nCMD: $cmd\n" . implode("\n", $out));
                return false;
            }

            return $outputPdf;
        }

        return false;
    }

    public function print($pdfPath, $copies, $sides, $orientation, $quality)
    {
        if (!file_exists($pdfPath)) {
            $this->log("PDF não encontrado: $pdfPath");
            return false;
        }

        if (!$this->printer) {
            $this->log("PRINTER_NAME não definido");
            return false;
        }

        $orientationFlag = ($orientation === 'landscape') ? 4 : 3;

        $cmd = "/usr/bin/lp "
            . "-d " . escapeshellarg($this->printer) . " "
            . "-n " . intval($copies) . " "
            . "-o sides=" . escapeshellarg($sides) . " "
            . "-o orientation-requested=" . intval($orientationFlag) . " "
            . "-o print-quality=" . intval($quality) . " "
            . escapeshellarg($pdfPath);

        exec("timeout 30 " . $cmd . " 2>&1", $out, $status);

        $this->log(
            "CMD: $cmd\nSTATUS: $status\nOUTPUT:\n" . implode("\n", $out)
        );

        return $status === 0;
    }

    private function log($msg)
    {
        $logPath = $_ENV['LOG_PATH'] ?? '/tmp/print_debug.log';

        file_put_contents(
            $logPath,
            date('Y-m-d H:i:s') . "\n" . $msg . "\n\n",
            FILE_APPEND
        );
    }
}