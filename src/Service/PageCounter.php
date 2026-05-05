<?php

class PageCounter
{
    /**
     * Retorna o número de páginas de um PDF.
     * Se o arquivo não existir ou não for PDF, retorna 0.
     *
     * @param string $file Caminho absoluto do PDF.
     * @return int
     */
    public static function count($file)
    {
        // Verifica existência e extensão
        if (!file_exists($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'pdf') {
            return 0;
        }

        // Executa pdfinfo para extrair informações do PDF
        $escapedFile = escapeshellarg($file);
        exec("/usr/bin/pdfinfo $escapedFile", $output, $status);

        // Se erro no comando, retorna 0
        if ($status !== 0) {
            return 0;
        }

        // Procura pela linha que contém "Pages:"
        foreach ($output as $line) {
            if (stripos($line, 'Pages:') === 0) {
                // Extrai somente o número
                $pages = intval(filter_var($line, FILTER_SANITIZE_NUMBER_INT));
                return $pages;
            }
        }
        return 0;
    }
}
