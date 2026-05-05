<?php

class PrintService
{
    private $printerName;

    public function __construct()
    {
        $this->printerName = $_ENV['PRINTER_NAME'] ?? '';
    }

    /**
     * Realiza a impressão do arquivo, convertendo-o se necessário.
     *
     * @param string $filePath Caminho absoluto do arquivo de origem.
     * @param int $copies Número de cópias.
     * @param string $sides "one-sided" ou "two-sided-short-edge"/"long-edge".
     * @param string $orientation "portrait" ou "landscape".
     * @param int $quality Qualidade de impressão (ex: 3 = normal).
     * @param int $numberUp Páginas por folha.
     * @param array $extraOptions Outras opções (por exemplo, color, fit-to-page).
     */
    public function print($filePath, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions = [])
    {
        if (empty($this->printerName)) {
            throw new RuntimeException("PRINTER_NAME não está configurada");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $escapedPath = escapeshellarg($filePath);

        // Base do comando lp
        $cmd = '/usr/bin/lp';
        $cmd .= ' -d ' . escapeshellarg($this->printerName);
        $cmd .= ' -n ' . intval($copies);
        $cmd .= ' -o orientation-requested=' . ($orientation === 'landscape' ? 4 : 3); // 3=portrait,4=landscape【32†L1-L8】
        $cmd .= ' -o print-quality=' . intval($quality);
        // Frente/verso
        $sidesOption = ($sides === 'two-sided-short-edge' ? 'two-sided-short-edge'
            : ($sides === 'two-sided-long-edge' ? 'two-sided-long-edge'
                : 'one-sided'));
        $cmd .= ' -o sides=' . $sidesOption;
        // Número de páginas por folha
        if ($numberUp > 1) {
            $cmd .= ' -o number-up=' . intval($numberUp);
        }
        // Opções extras
        foreach ($extraOptions as $key => $val) {
            $cmd .= ' -o ' . escapeshellarg("$key=$val");
        }

        // Monta o comando final conforme o tipo de arquivo
        if (in_array($ext, ['doc', 'docx'])) {
            // Converte DOC/DOCX para PDF usando LibreOffice
            $pdfName = tempnam(sys_get_temp_dir(), 'pdffile_') . '.pdf';
            $libreoffice = '/usr/bin/libreoffice';
            $convertCmd = sprintf(
                '/usr/bin/timeout 60 %s --headless --convert-to pdf:writer_pdf_Export %s --outdir %s',
                escapeshellarg($libreoffice),
                $escapedPath,
                escapeshellarg(sys_get_temp_dir())
            );
            // Envia o PDF convertido para impressão
            $lpCmd = $cmd . ' ' . escapeshellarg($pdfName);
            $shell = sprintf('%s && %s > /dev/null 2>&1 &', $convertCmd, $lpCmd);
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            // Converte imagem para PDF usando ImageMagick
            $pdfName = tempnam(sys_get_temp_dir(), 'imgpdf_') . '.pdf';
            $convertTool = '/usr/bin/convert';
            $convertCmd = sprintf(
                '/usr/bin/timeout 30 %s %s -density 150 -quality 90 %s',
                escapeshellarg($convertTool),
                $escapedPath,
                escapeshellarg($pdfName)
            );
            $lpCmd = $cmd . ' ' . escapeshellarg($pdfName);
            $shell = sprintf('%s && %s > /dev/null 2>&1 &', $convertCmd, $lpCmd);
        } else {
            // Já é PDF (ou formato suportado diretamente)
            $shell = $cmd . ' ' . $escapedPath . ' > /dev/null 2>&1 &';
        }

        // Executa o comando em background
        exec($shell);

        // (Opcional) log do comando
        $this->log("Executado: $shell");
    }

    /**
     * (Auxiliar) Converte arquivo para PDF de forma síncrona.
     * Usado para depuração ou cenário bloqueante.
     *
     * @param string $filePath
     * @return string|false Caminho do PDF gerado ou false em falha.
     */
    public function prepareFile($filePath)
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $outDir = sys_get_temp_dir();

        if (in_array($ext, ['doc', 'docx'])) {
            $pdfFile = tempnam($outDir, 'pdffile_') . '.pdf';
            $cmd = sprintf(
                '/usr/bin/timeout 60 /usr/bin/libreoffice --headless --convert-to pdf:writer_pdf_Export %s --outdir %s',
                escapeshellarg($filePath),
                escapeshellarg($outDir)
            );
            exec($cmd, $output, $status);
            if ($status === 0 && file_exists($pdfFile)) {
                return $pdfFile;
            }
            $this->log("Falha ao converter $filePath");
            return false;
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $pdfFile = tempnam($outDir, 'imgpdf_') . '.pdf';
            $cmd = sprintf(
                '/usr/bin/timeout 30 /usr/bin/convert %s %s',
                escapeshellarg($filePath),
                escapeshellarg($pdfFile)
            );
            exec($cmd, $output, $status);
            if ($status === 0 && file_exists($pdfFile)) {
                return $pdfFile;
            }
            $this->log("Falha ao converter $filePath");
            return false;
        }

        // Se já for PDF, retorna o mesmo
        return $filePath;
    }

    // Registra mensagem no log de debug (LOG_PATH)
    public function log($msg)
    {
        $logPath = $_ENV['LOG_PATH'] ?? '/tmp/print_debug.log';
        file_put_contents(
            $logPath,
            date('Y-m-d H:i:s') . " " . $msg . "\n",
            FILE_APPEND
        );
    }
}
