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
        if (!file_exists($filePath)) {
            $this->log("Arquivo não existe: $filePath");
            return false;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // ✔ PDF direto
        if ($ext === 'pdf') {
            return $filePath;
        }

        // ✔ nome único seguro
        $outputPdf = "/tmp/" . uniqid('print_', true) . ".pdf";

        // ✔ DOCX → PDF (robusto)
        if ($ext === 'docx') {

            $cmd = "libreoffice --headless --convert-to pdf "
                . escapeshellarg($filePath)
                . " --outdir /tmp 2>&1";

            exec($cmd, $out, $status);

            // tenta localizar arquivo gerado com base no nome
            $expected = "/tmp/" . pathinfo($filePath, PATHINFO_FILENAME) . ".pdf";

            if ($status !== 0 || !file_exists($expected)) {
                $this->log("Erro DOCX\nCMD: $cmd\n" . implode("\n", $out));
                return false;
            }

            // renomeia para garantir isolamento
            rename($expected, $outputPdf);

            return $outputPdf;
        }

        // ✔ IMAGEM → PDF
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {

            $cmd = "convert "
                . escapeshellarg($filePath) . " "
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

        $orientationFlag = ($orientation === 'landscape') ? 4 : 3;

        // ✔ caminho absoluto evita erro no Apache
        $cmd = "/usr/bin/lp -d {$this->printer} "
            . "-n " . intval($copies) . " "
            . "-o sides={$sides} "
            . "-o orientation-requested={$orientationFlag} "
            . "-o print-quality={$quality} "
            . escapeshellarg($pdfPath);

        exec($cmd . " 2>&1", $out, $status);

        $this->log(
            "CMD: $cmd\nSTATUS: $status\nOUTPUT:\n" . implode("\n", $out)
        );

        return $status === 0;
    }

    private function log($msg)
    {
        file_put_contents(
            '/tmp/print_debug.log',
            date('Y-m-d H:i:s') . "\n" . $msg . "\n\n",
            FILE_APPEND
        );
    }
}