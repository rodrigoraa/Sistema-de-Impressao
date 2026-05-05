<?php

class PageCounter
{
    /**
     * Retorna o número de páginas de um PDF.
     * Retorna 0 se o arquivo não existir ou não for PDF.
     *
     * @param string $file Caminho absoluto do arquivo.
     * @return int
     */
    public static function count($file)
    {
        // Verifica arquivo e extensão
        if (!file_exists($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'pdf') {
            return 0;
        }

        // Usa pdfinfo para obter informações do PDF
        exec('/usr/bin/pdfinfo ' . escapeshellarg($file), $output, $status);
        if ($status !== 0 || empty($output)) {
            return 0;
        }

        // Procura pela linha "Pages:" na saída
        foreach ($output as $line) {
            if (stripos($line, 'Pages:') === 0) {
                // Extrai o número de páginas
                $num = intval(filter_var($line, FILTER_SANITIZE_NUMBER_INT));
                return $num;
            }
        }
        return 0;
    }
}
