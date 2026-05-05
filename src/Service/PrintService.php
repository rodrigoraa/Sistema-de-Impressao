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

        if ($ext === 'doc' || $ext === 'docx') {

            if (!shell_exec("which libreoffice")) {
                $this->log("LibreOffice não encontrado");
                return false;
            }

            $cmd = "HOME=/tmp libreoffice --headless --invisible --norestore --nolockcheck --nodefault "
                . "--convert-to pdf:writer_pdf_Export "
                . escapeshellarg($filePath)
                . " --outdir /tmp > /dev/null 2>&1";

            exec($cmd, $out, $status);

            if (!empty($out)) {
                $this->log("LibreOffice OUTPUT:\n" . implode("\n", $out));
            }

            if ($status !== 0) {
                $this->log("Erro DOC/DOCX\nCMD: $cmd\n" . implode("\n", $out));
                return false;
            }

            $generated = "/tmp/" . pathinfo($filePath, PATHINFO_FILENAME) . ".pdf";

            if (!file_exists($generated)) {
                $this->log("PDF não gerado: $generated");
                return false;
            }

            rename($generated, $outputPdf);

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

    public function print($pdfPath, $copies, $sides, $orientation, $quality, $extraOptions = [], $numberUp = 1)
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
            . "-o sides=" . $sides . " "
            . "-o orientation-requested=" . intval($orientationFlag) . " "
            . "-o print-quality=" . intval($quality) . " ";

        // ✔ opções extras
        foreach ($extraOptions as $key => $value) {
            if (!$value)
                continue;

            $cmd .= "-o " . escapeshellarg($key . "=" . $value) . " ";
        }

        // ✔ number-up (2 por folha, etc)
        if ($numberUp > 1) {
            $cmd .= "-o number-up=" . intval($numberUp) . " ";
        }

        // ✔ ARQUIVO SEMPRE POR ÚLTIMO
        $cmd .= escapeshellarg($pdfPath);

        // ✔ execução com timeout
        exec("timeout 30 " . $cmd . " > /dev/null 2>&1 &", $out, $status);

        $this->log(
            "CMD: $cmd\nSTATUS: $status\nOUTPUT:\n" . implode("\n", $out)
        );

        return true;
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