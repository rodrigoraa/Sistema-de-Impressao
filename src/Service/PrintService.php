<?php

class PrintService
{
    private $printerName;

    public function __construct()
    {
        $this->printerName = $_ENV['PRINTER_NAME'] ?? '';
    }

    /**
     * Converte o arquivo para PDF se necessário e dispara a impressão.
     *
     * @param string $filePath Caminho absoluto do arquivo (upload, antes da conversão).
     * @param int $copies Número de cópias.
     * @param string $sides Ex: 'one-sided', 'two-sided-long-edge', 'two-sided-short-edge'.
     * @param string $orientation 'portrait' ou 'landscape'.
     * @param int $quality Nível de qualidade (ex: 3).
     * @param int $numberUp Número de páginas por folha.
     * @param array $extraOptions Opções adicionais de impressão.
     *
     * @return void
     */
    public function print($filePath, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions = [])
    {
        if (empty($this->printerName)) {
            throw new RuntimeException("PRINTER_NAME não configurado");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $escapedPath = escapeshellarg($filePath);

        // Base do comando lp
        $cmd = '/usr/bin/lp';
        $cmd .= ' -d ' . escapeshellarg($this->printerName);
        $cmd .= ' -n ' . intval($copies);
        $cmd .= ' -o orientation-requested=' . ($orientation === 'landscape' ? 4 : 3);
        $cmd .= ' -o print-quality=' . intval($quality);
        // Opções de frente e verso
        $cmd .= ' -o sides=' . ($sides === 'two-sided-short-edge' ? 'two-sided-short-edge' : ($sides === 'two-sided-long-edge' ? 'two-sided-long-edge' : 'one-sided'));
        // Number-up (páginas por folha)
        if ($numberUp > 1) {
            $cmd .= ' -o number-up=' . intval($numberUp);
        }
        // Opções extras
        foreach ($extraOptions as $key => $value) {
            $cmd .= ' -o ' . escapeshellarg($key . '=' . $value);
        }

        // Comando final dependendo do tipo de arquivo
        if (in_array($ext, ['doc', 'docx'])) {
            // Converte DOC/DOCX para PDF e imprime
            // Cria nome temporário para PDF
            $pdfPath = tempnam(sys_get_temp_dir(), 'pdffile_') . '.pdf';
            // Monta comando: conversão && impressão
            $libreoffice = '/usr/bin/libreoffice';
            $convertCmd = sprintf(
                'HOME=%s %s --headless --convert-to pdf:writer_pdf_Export %s --outdir %s',
                escapeshellarg(sys_get_temp_dir()),
                escapeshellarg($libreoffice),
                $escapedPath,
                escapeshellarg(sys_get_temp_dir())
            );
            $lpCmd = $cmd . ' ' . escapeshellarg($pdfPath);
            $shell = sprintf('%s && %s > /dev/null 2>&1 &', $convertCmd, $lpCmd);

        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            // Converte imagem para PDF e imprime
            $pdfPath = tempnam(sys_get_temp_dir(), 'imgpdf_') . '.pdf';
            $convertPath = '/usr/bin/convert';
            $convertCmd = sprintf(
                '%s %s -density 150 -quality 90 %s',
                escapeshellarg($convertPath),
                $escapedPath,
                escapeshellarg($pdfPath)
            );
            $lpCmd = $cmd . ' ' . escapeshellarg($pdfPath);
            $shell = sprintf('%s && %s > /dev/null 2>&1 &', $convertCmd, $lpCmd);

        } else {
            // Já é PDF ou outro suportado diretamente pelo CUPS
            $shell = $cmd . ' ' . $escapedPath . ' > /dev/null 2>&1 &';
        }

        // Executa o comando em background
        exec($shell);

        // Opcional: registrar log do comando (para depuração)
        $this->log("Executado: $shell");
    }

    /**
     * (Opcional) Converte arquivo para PDF (síncrono).
     * Não usado no fluxo atual, mas implementado conforme especificação.
     *
     * @param string $filePath
     * @return string|false Caminho do PDF ou false em erro.
     */
    public function prepareFile($filePath)
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $dir = sys_get_temp_dir();
        if (in_array($ext, ['doc', 'docx'])) {
            $pdfPath = tempnam($dir, 'pdffile_') . '.pdf';
            $cmd = sprintf(
                '/usr/bin/timeout 60 /usr/bin/libreoffice --headless --convert-to pdf:writer_pdf_Export %s --outdir %s',
                escapeshellarg($filePath),
                escapeshellarg($dir)
            );
            exec($cmd, $output, $status);
            if ($status === 0 && file_exists($pdfPath)) {
                return $pdfPath;
            }
            $this->log("Falha ao converter $filePath para PDF");
            return false;
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $pdfPath = tempnam($dir, 'imgpdf_') . '.pdf';
            $cmd = sprintf(
                '/usr/bin/timeout 60 /usr/bin/convert %s %s',
                escapeshellarg($filePath),
                escapeshellarg($pdfPath)
            );
            exec($cmd, $output, $status);
            if ($status === 0 && file_exists($pdfPath)) {
                return $pdfPath;
            }
            $this->log("Falha ao converter imagem $filePath para PDF");
            return false;
        }
        // Se já for PDF ou outro, retorna caminho original
        return $filePath;
    }

    // Registra logs em arquivo (para depuração)
    public function log($msg)
    {
        $logPath = $_ENV['LOG_PATH'] ?? '/tmp/print_debug.log';
        file_put_contents($logPath, date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
    }
}
