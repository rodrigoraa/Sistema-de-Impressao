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

        // DOC/DOCX → PDF
        if (in_array($ext, ['doc', 'docx'])) {
            if (!shell_exec("which soffice")) {
                $this->log("LibreOffice (soffice) não encontrado");
                return false;
            }
            $cmd = "HOME=/tmp soffice --headless --invisible --norestore --nolockcheck --nodefault "
                . "--convert-to pdf:writer_pdf_Export "
                . escapeshellarg($filePath)
                . " --outdir /tmp > /dev/null 2>&1";
            exec($cmd, $out, $status);
            if ($status !== 0) {
                $this->log("Erro ao converter DOC/DOCX (status $status)");
                return false;
            }
            $generated = "/tmp/" . pathinfo($filePath, PATHINFO_FILENAME) . ".pdf";
            if (!file_exists($generated)) {
                $this->log("PDF não foi gerado a partir de DOC/DOCX");
                return false;
            }
            rename($generated, $outputPdf);
            return $outputPdf;
        }

        // Imagem → PDF
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            if (!shell_exec("which convert")) {
                $this->log("ImageMagick (convert) não encontrado");
                return false;
            }
            $cmd = "convert " . escapeshellarg($filePath)
                . " -density 150 -quality 90 "
                . escapeshellarg($outputPdf) . " 2>&1";
            exec($cmd, $out, $status);
            if ($status !== 0 || !file_exists($outputPdf)) {
                $this->log("Erro na conversão de imagem para PDF");
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
        $cmd = "/usr/bin/lp -d " . escapeshellarg($this->printer)
            . " -n " . intval($copies)
            . " -o sides=" . escapeshellarg($sides)
            . " -o orientation-requested=" . intval($orientationFlag)
            . " -o print-quality=" . intval($quality) . " ";
        foreach ($extraOptions as $key => $value) {
            if ($value) {
                $cmd .= "-o " . escapeshellarg($key . "=" . $value) . " ";
            }
        }
        if ($numberUp > 1) {
            $cmd .= "-o number-up=" . intval($numberUp) . " ";
        }
        $cmd .= escapeshellarg($pdfPath) . " > /dev/null 2>&1 &";
        exec($cmd, $out, $status);
        $this->log("CMD: $cmd\nSTATUS: $status");
        return true;
    }

    private function log($msg)
    {
        $logPath = $_ENV['LOG_PATH'] ?? '/tmp/print_debug.log';
        file_put_contents(
            $logPath,
            date('Y-m-d H:i:s') . " " . $msg . "\n",
            FILE_APPEND
        );
    }
}
