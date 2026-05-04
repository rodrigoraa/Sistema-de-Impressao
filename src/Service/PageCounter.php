<?php

class PageCounter
{
    public static function count($file)
    {
        // ✔ verifica se arquivo existe
        if (!file_exists($file)) {
            file_put_contents('/tmp/pagecounter.log', "Arquivo não existe: $file\n", FILE_APPEND);
            return 0;
        }

        // ✔ caminho absoluto evita problema de PATH
        $cmd = "/usr/bin/pdfinfo " . escapeshellarg($file) . " 2>&1";

        exec($cmd, $output, $status);

        // ✔ se deu erro, loga
        if ($status !== 0) {
            file_put_contents(
                '/tmp/pagecounter.log',
                "Erro pdfinfo:\nCMD: $cmd\nOUTPUT:\n" . implode("\n", $output) . "\n\n",
                FILE_APPEND
            );
            return 0;
        }

        // ✔ extrai páginas
        foreach ($output as $line) {
            if (strpos($line, 'Pages:') !== false) {
                return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
            }
        }

        return 0;
    }
}