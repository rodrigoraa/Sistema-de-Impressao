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

        // Se já for PDF
        if ($ext === 'pdf') {
            return $filePath;
        }

        // Nome único garantido
        $outputPdf = "/tmp/" . uniqid() . ".pdf";

        // DOCX → PDF
        if ($ext === 'docx') {

            exec("libreoffice --headless --convert-to pdf "
                . escapeshellarg($filePath)
                . " --outdir /tmp 2>&1", $out, $status);

            // pegar último PDF gerado
            $files = glob("/tmp/*.pdf");

            if (!$files) {
                file_put_contents('/tmp/print_debug.log', "Erro DOCX:\n" . implode("\n", $out));
                return false;
            }

            return end($files);
        }

        // IMAGEM → PDF
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {

            exec(
                "convert "
                . escapeshellarg($filePath) . " "
                . escapeshellarg($outputPdf) . " 2>&1",
                $out,
                $status
            );

            if (!file_exists($outputPdf)) {
                file_put_contents('/tmp/print_debug.log', "Erro IMG:\n" . implode("\n", $out));
                return false;
            }

            return $outputPdf;
        }

        return false;
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