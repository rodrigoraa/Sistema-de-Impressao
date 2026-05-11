<?php

class PrintService
{
    private $printerName;
    private $isWindows;
    private $lastPrintCompleted = null;

    public function __construct()
    {
        $this->printerName = $_ENV['PRINTER_NAME'] ?? '';
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Prepara o arquivo, converte quando necessario, e envia para impressao.
     *
     * @return string Caminho do arquivo efetivamente enviado para a impressora.
     */
    public function print($filePath, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions = [])
    {
        if (empty($this->printerName)) {
            throw new RuntimeException('PRINTER_NAME nao esta configurada');
        }

        $preparedFile = $this->prepareFile(
            $filePath,
            $orientation,
            $extraOptions['media'] ?? $extraOptions['paper'] ?? 'A4'
        );
        $sourceExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $this->printPrepared($preparedFile, $sourceExt, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions);

        return $preparedFile;
    }

    public function printPrepared($preparedFile, $sourceExt, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions = [])
    {
        if (empty($this->printerName)) {
            throw new RuntimeException('PRINTER_NAME nao esta configurada');
        }

        if (!is_file($preparedFile)) {
            throw new RuntimeException('Arquivo preparado nao encontrado para impressao');
        }

        $this->lastPrintCompleted = null;
        $preparedExt = strtolower(pathinfo($preparedFile, PATHINFO_EXTENSION));
        $this->log('Arquivo preparado para impressao: ext_origem=' . $sourceExt . ' preparado=' . $preparedFile . ' ext_preparado=' . $preparedExt . ' tamanho=' . (@filesize($preparedFile) ?: 0));
        if (in_array($sourceExt, ['doc', 'docx', 'png'], true)) {
            $debugCopy = $this->copyDebugFile($preparedFile, $sourceExt . '-preparado');
            if ($debugCopy !== null) {
                $this->log(strtoupper($sourceExt) . ' diagnostico: copia do arquivo preparado=' . $debugCopy);
            }
        }

        if ($this->isWindows) {
            $this->lastPrintCompleted = $this->printWindows($preparedFile, $copies, $sides, $orientation, $numberUp, $extraOptions);
        } else {
            $this->lastPrintCompleted = $this->printCups($preparedFile, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions, $sourceExt);
        }

        return $this->lastPrintCompleted;
    }

    public function lastPrintCompleted()
    {
        return $this->lastPrintCompleted;
    }

    public function prepareFile($filePath, $orientation = 'portrait', $paper = 'A4')
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('Arquivo nao encontrado para impressao');
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return $filePath;
        }

        if (in_array($ext, ['doc', 'docx'], true)) {
            return $this->convertOfficeToPdf($filePath);
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return $this->convertImageToPdf($filePath, $orientation, $paper);
        }

        throw new RuntimeException('Tipo de arquivo nao suportado para impressao');
    }

    private function printCups($filePath, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions, $sourceExt = '')
    {
        $lp = $this->findExecutable(['/usr/bin/lp', '/bin/lp', 'lp']);
        if ($lp === null) {
            throw new RuntimeException('Comando lp/CUPS nao encontrado no servidor');
        }

        $cmd = escapeshellarg($lp);
        $cmd .= ' -d ' . escapeshellarg($this->printerName);
        if (intval($copies) > 1) {
            $cmd .= ' -n ' . intval($copies);
        }

        if (in_array($sourceExt, ['jpg', 'jpeg', 'png'], true)) {
            $this->log('CUPS imagem: usando envio simples equivalente ao lp manual');
        } else {
            $cmd .= ' -o orientation-requested=' . ($orientation === 'landscape' ? 4 : 3);
            $cmd .= ' -o print-quality=' . intval($quality);
            $cmd .= ' -o sides=' . $this->cupsSides($sides);

            if ($numberUp > 1) {
                $cmd .= ' -o number-up=' . intval($numberUp);
                $cmd .= ' -o number-up-layout=lrtb';
            }

            foreach ($this->cupsExtraOptions($extraOptions) as $key => $val) {
                $cmd .= ' -o ' . escapeshellarg($key . '=' . $val);
            }
        }

        $cmd .= ' ' . escapeshellarg($filePath) . ' 2>&1';
        exec($cmd, $output, $status);
        $this->log('CUPS: ' . $cmd . ' | status=' . $status . ' | ' . implode(' | ', $output));

        if ($status !== 0) {
            throw new RuntimeException('Falha ao enviar arquivo para a impressora');
        }

        $jobId = $this->extractCupsJobId(implode(' ', $output));
        if ($jobId === null) {
            $this->log('CUPS: nao foi possivel identificar job; contabilizacao permitida pelo aceite do lp');
            return true;
        }

        return $this->waitForCupsJob($jobId);
    }

    private function extractCupsJobId($output)
    {
        if (!preg_match('/\b([A-Za-z0-9_.:@-]+-\d+)\b/', $output, $match)) {
            return null;
        }

        return $match[1];
    }

    private function waitForCupsJob($jobId)
    {
        $lpstat = $this->findExecutable(['/usr/bin/lpstat', '/bin/lpstat', 'lpstat']);
        if ($lpstat === null) {
            $this->log('CUPS: lpstat nao encontrado; contabilizacao permitida pelo aceite do lp');
            return true;
        }

        $waitSeconds = max(1, (int) ($_ENV['PRINT_JOB_WAIT_SECONDS'] ?? 120));
        $deadline = time() + $waitSeconds;
        $seen = false;

        while (time() <= $deadline) {
            if ($this->cupsJobInList($lpstat, $jobId, 'completed')) {
                $this->log('CUPS: job concluido=' . $jobId);
                return true;
            }

            if ($this->cupsJobInList($lpstat, $jobId, 'not-completed')) {
                $seen = true;
                sleep(2);
                continue;
            }

            $this->log('CUPS: job nao esta mais na fila; considerando concluido pelo aceite do lp=' . $jobId . ' visto=' . ($seen ? 'sim' : 'nao'));
            return true;
        }

        $this->log('CUPS: tempo esgotado aguardando conclusao do job=' . $jobId);
        return false;
    }

    private function cupsJobInList($lpstat, $jobId, $which)
    {
        $cmd = escapeshellarg($lpstat) . ' -W ' . escapeshellarg($which) . ' -o ' . escapeshellarg($jobId) . ' 2>&1';
        exec($cmd, $output, $status);
        $text = implode("\n", $output);

        return $status === 0 && preg_match('/(^|\s)' . preg_quote($jobId, '/') . '\s/', $text) === 1;
    }

    private function printWindows($filePath, $copies, $sides, $orientation, $numberUp, $extraOptions)
    {
        $sumatra = $this->findSumatra();
        if ($sumatra === null) {
            throw new RuntimeException('SumatraPDF nao encontrado. Configure SUMATRA_PATH no .env para imprimir no Windows.');
        }

        $settings = [];
        $settings[] = max(1, intval($copies)) . 'x';
        $settings[] = $orientation === 'landscape' ? 'landscape' : 'portrait';
        $settings[] = $this->windowsSides($sides);

        $scale = $extraOptions['fit-to-page'] ?? $extraOptions['scale'] ?? 'fit';
        if ($scale === 'fit') {
            $settings[] = 'fit';
        } elseif ($scale === '90' || $scale === '100') {
            $settings[] = 'noscale';
        }

        $paper = $extraOptions['paper'] ?? $extraOptions['media'] ?? '';
        if ($paper !== '') {
            $settings[] = 'paper=' . $paper;
        }

        if ($numberUp > 1) {
            $this->log('Aviso: number-up=' . intval($numberUp) . ' solicitado no Windows. O SumatraPDF pode ignorar esta opcao se o driver nao oferecer suporte.');
            $settings[] = 'number-up=' . intval($numberUp);
        }

        $cmd = sprintf(
            '%s -silent -print-to %s -print-settings %s %s',
            escapeshellarg($sumatra),
            escapeshellarg($this->printerName),
            escapeshellarg(implode(',', array_filter($settings))),
            escapeshellarg($filePath)
        );

        exec($cmd, $output, $status);
        $this->log('SumatraPDF: ' . $cmd . ' | status=' . $status . ' | ' . implode(' | ', $output));

        if ($status !== 0) {
            throw new RuntimeException('Falha ao enviar arquivo para a impressora no Windows');
        }

        return $this->waitForWindowsPrintJob($filePath);
    }

    private function waitForWindowsPrintJob($filePath)
    {
        $powershell = $this->findExecutable(['powershell', 'powershell.exe', 'pwsh', 'pwsh.exe']);
        if ($powershell === null) {
            $this->log('Windows: PowerShell nao encontrado; contabilizacao permitida pelo envio ao spooler');
            return true;
        }

        $document = basename($filePath);
        $waitSeconds = max(1, (int) ($_ENV['PRINT_JOB_WAIT_SECONDS'] ?? 60));
        $deadline = time() + $waitSeconds;
        $seen = false;

        while (time() <= $deadline) {
            $status = strtolower($this->windowsPrintJobStatus($powershell, $document));
            if ($status !== '') {
                $seen = true;
                if (str_contains($status, 'delet') || str_contains($status, 'cancel') || str_contains($status, 'error')) {
                    $this->log('Windows: job cancelado/falhou no spooler=' . $document . ' status=' . $status);
                    return false;
                }

                if (str_contains($status, 'complete') || str_contains($status, 'printed')) {
                    $this->log('Windows: job concluido=' . $document . ' status=' . $status);
                    return true;
                }

                sleep(1);
                continue;
            }

            if ($seen) {
                $this->log('Windows: job saiu da fila apos ser visto=' . $document);
                return true;
            }

            sleep(1);
        }

        $this->log('Windows: job nao localizado na fila; contabilizacao permitida pelo envio ao spooler=' . $document);
        return true;
    }

    private function windowsPrintJobStatus($powershell, $document)
    {
        $printer = $this->powershellSingleQuoted($this->printerName);
        $doc = $this->powershellSingleQuoted('*' . $document . '*');
        $script = '$job = Get-PrintJob -PrinterName ' . $printer . ' -ErrorAction SilentlyContinue | Where-Object { $_.DocumentName -like ' . $doc . ' -or $_.Name -like ' . $doc . ' } | Select-Object -First 1; if ($job) { [string]$job.JobStatus }';
        $cmd = escapeshellarg($powershell) . ' -NoProfile -ExecutionPolicy Bypass -Command ' . escapeshellarg($script) . ' 2>&1';
        exec($cmd, $output, $status);

        if ($status !== 0) {
            return '';
        }

        return trim(implode(' ', $output));
    }

    private function powershellSingleQuoted($value)
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function convertOfficeToPdf($filePath)
    {
        $office = $this->findLibreOffice();
        if ($office === null) {
            throw new RuntimeException('LibreOffice nao encontrado. Configure LIBREOFFICE_PATH no .env.');
        }

        $outDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'print_' . uniqid('', true);
        if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
            throw new RuntimeException('Nao foi possivel criar pasta temporaria para conversao');
        }

        $profileDir = $outDir . DIRECTORY_SEPARATOR . 'lo-profile';
        $homeDir = $outDir . DIRECTORY_SEPARATOR . 'lo-home';
        foreach ([$profileDir, $homeDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                throw new RuntimeException('Nao foi possivel criar ambiente temporario do LibreOffice');
            }
        }
        $this->writeOfficeFontConfig($homeDir . DIRECTORY_SEPARATOR . '.config');

        $sourceExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (in_array($sourceExt, ['doc', 'docx'], true)) {
            $this->logOfficeFontDiagnostics($filePath, $sourceExt);
            $this->logOfficeLayoutDiagnostics($filePath, $sourceExt);
        }

        $envPrefix = '';
        if (!$this->isWindows) {
            $envPrefix = 'HOME=' . escapeshellarg($homeDir)
                . ' SAL_USE_VCLPLUGIN=gen ';
        }

        $declaredPages = $sourceExt === 'docx' ? $this->countDocxDeclaredPages($filePath) : 0;
        $attempts = [
            [
                'label' => 'perfil-isolado-filtro-writer',
                'build' => function ($attemptDir) use ($envPrefix, $office, $profileDir, $filePath) {
                    return sprintf(
                        '%s%s %s --headless --invisible --nodefault --norestore --nofirststartwizard --nolockcheck --convert-to %s --outdir %s %s 2>&1',
                        $envPrefix,
                        escapeshellarg($office),
                        escapeshellarg('-env:UserInstallation=' . $this->pathToFileUri($profileDir)),
                        escapeshellarg('pdf:writer_pdf_Export'),
                        escapeshellarg($attemptDir),
                        escapeshellarg($filePath)
                    );
                },
            ],
            [
                'label' => 'perfil-isolado-pdf-simples',
                'build' => function ($attemptDir) use ($envPrefix, $office, $profileDir, $filePath) {
                    return sprintf(
                        '%s%s %s --headless --convert-to pdf --outdir %s %s 2>&1',
                        $envPrefix,
                        escapeshellarg($office),
                        escapeshellarg('-env:UserInstallation=' . $this->pathToFileUri($profileDir)),
                        escapeshellarg($attemptDir),
                        escapeshellarg($filePath)
                    );
                },
            ],
            [
                'label' => 'compatibilidade-comando-antigo',
                'build' => function ($attemptDir) use ($office, $filePath) {
                    return sprintf(
                        '%s --headless --convert-to pdf --outdir %s %s 2>&1',
                        escapeshellarg($office),
                        escapeshellarg($attemptDir),
                        escapeshellarg($filePath)
                    );
                },
            ],
        ];

        $lastStatus = 1;
        $lastOutput = [];
        $bestPdf = null;
        $bestPages = PHP_INT_MAX;
        foreach ($attempts as $index => $attempt) {
            $attemptDir = $outDir . DIRECTORY_SEPARATOR . 'attempt-' . ($index + 1);
            if (!is_dir($attemptDir) && !mkdir($attemptDir, 0775, true)) {
                continue;
            }

            $cmd = $attempt['build']($attemptDir);
            $output = [];
            $status = 1;
            exec($cmd, $output, $status);
            $lastStatus = $status;
            $lastOutput = $output;

            $expected = $attemptDir . DIRECTORY_SEPARATOR . pathinfo($filePath, PATHINFO_FILENAME) . '.pdf';
            $candidate = null;
            if ($status === 0 && is_file($expected)) {
                $candidate = $expected;
            }
            if ($candidate === null) {
                $converted = glob($attemptDir . DIRECTORY_SEPARATOR . '*.pdf');
                if ($status === 0 && !empty($converted) && is_file($converted[0])) {
                    $candidate = $converted[0];
                }
            }

            if ($candidate !== null) {
                $candidatePages = $this->countPdfPages($candidate);
                $this->log('LibreOffice tentativa ' . ($index + 1) . ' ' . $attempt['label'] . ': ' . $cmd . ' | OK paginas=' . $candidatePages . ' | ' . implode(' | ', $output));

                if ($candidatePages > 0 && $candidatePages < $bestPages) {
                    $bestPdf = $candidate;
                    $bestPages = $candidatePages;
                } elseif ($bestPdf === null) {
                    $bestPdf = $candidate;
                    $bestPages = $candidatePages > 0 ? $candidatePages : PHP_INT_MAX;
                }

                if ($declaredPages > 0 && $candidatePages > 0 && $candidatePages <= $declaredPages) {
                    $this->logConvertedPdfDiagnostics($candidate, $sourceExt);
                    return $candidate;
                }

                continue;
            }

            $this->log('LibreOffice tentativa ' . ($index + 1) . ' falhou: ' . $cmd . ' | status=' . $status . ' | ' . implode(' | ', $output));
        }

        if ($bestPdf !== null) {
            if ($declaredPages > 0 && $bestPages > $declaredPages) {
                $this->log('LibreOffice: metadado DOCX declara menos paginas que o PDF convertido; usando PDF completo. declarado=' . $declaredPages . ' convertido=' . $bestPages . ' pdf=' . $bestPdf);
            }
            $this->logConvertedPdfDiagnostics($bestPdf, $sourceExt);
            return $bestPdf;
        }

        $detail = trim(implode(' | ', $lastOutput));
        throw new RuntimeException('Falha ao converter DOC/DOCX para PDF' . ($detail !== '' ? ': ' . substr($detail, 0, 180) : ''));
    }

    private function convertImageToPdf($filePath, $orientation, $paper)
    {
        $info = @getimagesize($filePath);
        if ($info === false) {
            throw new RuntimeException('Imagem invalida ou corrompida');
        }

        $converted = $this->convertImageWithTool($filePath, $orientation, $paper);
        if ($converted !== null) {
            return $converted;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            $pdfFile = tempnam(sys_get_temp_dir(), 'pngpdf_') . '.pdf';
            $this->assertCanConvertPngInMemory($info[0], $info[1], filesize($filePath));

            if ($this->writePngPdfWithGd($filePath, $pdfFile, $orientation, $paper)) {
                $this->log('PNG convertido para PDF via GD: ' . $pdfFile);
                return $pdfFile;
            }

            $this->writePngPdf($filePath, $pdfFile, $orientation, $paper);
            $this->log('PNG convertido para PDF via conversor interno: ' . $pdfFile);
            return $pdfFile;
        }

        if (!in_array($ext, ['jpg', 'jpeg'], true)) {
            throw new RuntimeException('Formato de imagem nao suportado');
        }

        $pdfFile = tempnam(sys_get_temp_dir(), 'imgpdf_') . '.pdf';
        $this->writeImagePdf(
            file_get_contents($filePath),
            $pdfFile,
            $info[0],
            $info[1],
            '/DCTDecode',
            $orientation,
            $paper
        );
        return $pdfFile;
    }

    private function convertImageWithTool($filePath, $orientation, $paper)
    {
        $tool = $this->findImageMagick();
        if ($tool === null) {
            return null;
        }

        $jpegFile = tempnam(sys_get_temp_dir(), 'img_') . '.jpg';
        $pdfFile = tempnam(sys_get_temp_dir(), 'imgpdf_') . '.pdf';
        [$pageWidth, $pageHeight] = $this->paperSize($paper);
        if ($orientation === 'landscape') {
            [$pageWidth, $pageHeight] = [$pageHeight, $pageWidth];
        }

        // 300 DPI target in pixels, bounded to the selected paper size.
        $maxPixels = max(1, (int) round($pageWidth / 72 * 300)) . 'x' . max(1, (int) round($pageHeight / 72 * 300)) . '>';

        $args = [
            escapeshellarg($tool),
            escapeshellarg($filePath),
            '-auto-orient',
            '-background white',
            '-alpha remove',
            '-alpha off',
            '-resize ' . escapeshellarg($maxPixels),
            '-quality 92',
            escapeshellarg('jpg:' . $jpegFile),
            '2>&1',
        ];

        $cmd = implode(' ', $args);
        $timeout = $this->findExecutable(['/usr/bin/timeout', 'timeout']);
        if (!$this->isWindows && $timeout !== null) {
            $cmd = escapeshellarg($timeout) . ' 60 ' . $cmd;
        }

        exec($cmd, $output, $status);
        if ($status === 0 && is_file($jpegFile) && filesize($jpegFile) > 0) {
            $jpegInfo = @getimagesize($jpegFile);
            if ($jpegInfo !== false) {
                $this->writeImagePdf(
                    file_get_contents($jpegFile),
                    $pdfFile,
                    $jpegInfo[0],
                    $jpegInfo[1],
                    '/DCTDecode',
                    $orientation,
                    $paper
                );
                if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'png') {
                    $jpegDebug = $this->copyDebugFile($jpegFile, 'png-intermediario');
                    if ($jpegDebug !== null) {
                        $this->log('PNG diagnostico: JPG intermediario=' . $jpegDebug . ' tamanho=' . (@filesize($jpegDebug) ?: 0));
                    }
                }
                @unlink($jpegFile);
                $this->log('ImageMagick JPG: ' . $cmd . ' | PDF=' . $pdfFile);
                return $pdfFile;
            }
        }

        @unlink($jpegFile);
        @unlink($pdfFile);
        $this->log('ImageMagick falhou: ' . $cmd . ' | status=' . $status . ' | ' . implode(' | ', $output));
        return null;
    }

    private function writePngPdf($imagePath, $pdfPath, $orientation, $paper)
    {
        $png = $this->decodePngToRgb($imagePath);
        $this->writeImagePdf(
            gzcompress($png['rgb']),
            $pdfPath,
            $png['width'],
            $png['height'],
            '/FlateDecode',
            $orientation,
            $paper
        );
    }

    private function writePngPdfWithGd($imagePath, $pdfPath, $orientation, $paper)
    {
        if (!function_exists('imagecreatefrompng')) {
            return false;
        }

        $image = @imagecreatefrompng($imagePath);
        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas) {
            imagedestroy($image);
            return false;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        $rgb = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($canvas, $x, $y);
                $rgb .= chr(($color >> 16) & 0xff)
                    . chr(($color >> 8) & 0xff)
                    . chr($color & 0xff);
            }
        }

        imagedestroy($canvas);
        imagedestroy($image);

        $this->writeImagePdf(
            gzcompress($rgb),
            $pdfPath,
            $width,
            $height,
            '/FlateDecode',
            $orientation,
            $paper
        );

        return true;
    }

    private function writeImagePdf($imageData, $pdfPath, $imageWidth, $imageHeight, $filter, $orientation, $paper)
    {
        [$pageWidth, $pageHeight] = $this->paperSize($paper);
        if ($orientation === 'landscape') {
            [$pageWidth, $pageHeight] = [$pageHeight, $pageWidth];
        }

        $margin = 24;
        $maxWidth = $pageWidth - ($margin * 2);
        $maxHeight = $pageHeight - ($margin * 2);
        $ratio = min($maxWidth / $imageWidth, $maxHeight / $imageHeight);
        $drawWidth = $imageWidth * $ratio;
        $drawHeight = $imageHeight * $ratio;
        $x = ($pageWidth - $drawWidth) / 2;
        $y = ($pageHeight - $drawHeight) / 2;

        if ($imageData === false) {
            throw new RuntimeException('Nao foi possivel ler imagem para PDF');
        }

        $objects = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>",
            $pageWidth,
            $pageHeight
        );
        $objects[] = "<< /Type /XObject /Subtype /Image /Width {$imageWidth} /Height {$imageHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter {$filter} /Length " . strlen($imageData) . " >>\nstream\n" . $imageData . "\nendstream";

        $content = sprintf("q\n%.4F 0 0 %.4F %.4F %.4F cm\n/Im1 Do\nQ", $drawWidth, $drawHeight, $x, $y);
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF\n";

        if (file_put_contents($pdfPath, $pdf) === false) {
            throw new RuntimeException('Nao foi possivel criar PDF da imagem');
        }
    }

    private function decodePngToRgb($imagePath)
    {
        $data = file_get_contents($imagePath);
        if ($data === false || substr($data, 0, 8) !== "\x89PNG\x0D\x0A\x1A\x0A") {
            throw new RuntimeException('PNG invalido');
        }

        $offset = 8;
        $width = 0;
        $height = 0;
        $bitDepth = 0;
        $colorType = 0;
        $idat = '';
        $palette = null;
        $transparency = null;

        while ($offset + 8 <= strlen($data)) {
            $length = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            $chunk = substr($data, $offset + 8, $length);
            $offset += 12 + $length;

            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', $chunk);
                $width = $header['width'];
                $height = $header['height'];
                $bitDepth = $header['bitDepth'];
                $colorType = $header['colorType'];
                if ($header['interlace'] !== 0) {
                    throw new RuntimeException('PNG interlacado nao suportado');
                }
            } elseif ($type === 'PLTE') {
                $palette = $chunk;
            } elseif ($type === 'tRNS') {
                $transparency = $chunk;
            } elseif ($type === 'IDAT') {
                $idat .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
        }

        if ($width < 1 || $height < 1 || $bitDepth !== 8) {
            throw new RuntimeException('PNG deve usar profundidade de 8 bits');
        }

        $channelsByType = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];
        if (!isset($channelsByType[$colorType])) {
            throw new RuntimeException('Tipo de cor PNG nao suportado');
        }

        $raw = gzuncompress($idat);
        if ($raw === false) {
            throw new RuntimeException('Nao foi possivel descompactar PNG');
        }

        $channels = $channelsByType[$colorType];
        $stride = $width * $channels;
        $pos = 0;
        $previous = str_repeat("\0", $stride);
        $rgb = '';

        for ($row = 0; $row < $height; $row++) {
            $filter = ord($raw[$pos]);
            $pos++;
            $scanline = substr($raw, $pos, $stride);
            $pos += $stride;
            $line = $this->unfilterPngScanline($scanline, $previous, $filter, $channels);
            $previous = $line;

            for ($x = 0; $x < $width; $x++) {
                $i = $x * $channels;
                if ($colorType === 0) {
                    $gray = ord($line[$i]);
                    $rgb .= chr($gray) . chr($gray) . chr($gray);
                } elseif ($colorType === 2) {
                    $rgb .= $line[$i] . $line[$i + 1] . $line[$i + 2];
                } elseif ($colorType === 3) {
                    if ($palette === null) {
                        throw new RuntimeException('PNG sem paleta');
                    }
                    $index = ord($line[$i]);
                    $p = $index * 3;
                    $alpha = ($transparency !== null && $index < strlen($transparency)) ? ord($transparency[$index]) : 255;
                    $rgb .= $this->compositeOnWhite(ord($palette[$p]), ord($palette[$p + 1]), ord($palette[$p + 2]), $alpha);
                } elseif ($colorType === 4) {
                    $gray = ord($line[$i]);
                    $alpha = ord($line[$i + 1]);
                    $rgb .= $this->compositeOnWhite($gray, $gray, $gray, $alpha);
                } elseif ($colorType === 6) {
                    $rgb .= $this->compositeOnWhite(ord($line[$i]), ord($line[$i + 1]), ord($line[$i + 2]), ord($line[$i + 3]));
                }
            }
        }

        return ['width' => $width, 'height' => $height, 'rgb' => $rgb];
    }

    private function assertCanConvertPngInMemory($width, $height, $fileSize)
    {
        if ((int) $width * (int) $height > 8000000 || (int) $fileSize > 3000000) {
            throw new RuntimeException('PNG grande demais para converter sem ImageMagick no servidor. Envie em JPG ou instale ImageMagick.');
        }

        $estimatedBytes = (int) $width * (int) $height * 12;
        $limit = $this->memoryLimitBytes();

        if ($limit > 0 && $estimatedBytes > ($limit * 0.5)) {
            throw new RuntimeException('Imagem PNG muito grande para converter sem ImageMagick no servidor');
        }
    }

    private function memoryLimitBytes()
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        if ($unit === 'g') {
            return (int) ($number * 1024 * 1024 * 1024);
        }
        if ($unit === 'm') {
            return (int) ($number * 1024 * 1024);
        }
        if ($unit === 'k') {
            return (int) ($number * 1024);
        }

        return (int) $number;
    }

    private function unfilterPngScanline($scanline, $previous, $filter, $bytesPerPixel)
    {
        $result = '';
        $length = strlen($scanline);

        for ($i = 0; $i < $length; $i++) {
            $x = ord($scanline[$i]);
            $left = $i >= $bytesPerPixel ? ord($result[$i - $bytesPerPixel]) : 0;
            $up = ord($previous[$i]);
            $upLeft = $i >= $bytesPerPixel ? ord($previous[$i - $bytesPerPixel]) : 0;

            if ($filter === 1) {
                $x += $left;
            } elseif ($filter === 2) {
                $x += $up;
            } elseif ($filter === 3) {
                $x += intdiv($left + $up, 2);
            } elseif ($filter === 4) {
                $x += $this->paeth($left, $up, $upLeft);
            } elseif ($filter !== 0) {
                throw new RuntimeException('Filtro PNG nao suportado');
            }

            $result .= chr($x & 0xff);
        }

        return $result;
    }

    private function paeth($a, $b, $c)
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        return $pb <= $pc ? $b : $c;
    }

    private function compositeOnWhite($r, $g, $b, $alpha)
    {
        if ($alpha >= 255) {
            return chr($r) . chr($g) . chr($b);
        }

        $factor = $alpha / 255;
        return chr((int) round(255 + (($r - 255) * $factor)))
            . chr((int) round(255 + (($g - 255) * $factor)))
            . chr((int) round(255 + (($b - 255) * $factor)));
    }

    private function paperSize($paper)
    {
        return strtolower((string) $paper) === 'letter'
            ? [612.0, 792.0]
            : [595.28, 841.89];
    }

    private function cupsSides($sides)
    {
        if ($sides === 'two-sided-short-edge') {
            return 'two-sided-short-edge';
        }
        if ($sides === 'two-sided-long-edge') {
            return 'two-sided-long-edge';
        }
        return 'one-sided';
    }

    private function cupsExtraOptions($extraOptions)
    {
        $allowed = [
            'media',
            'scaling',
            'fit-to-page',
            'page-ranges',
            'page-set',
            'page-top',
            'page-right',
            'page-bottom',
            'page-left',
        ];
        $result = [];

        foreach ($extraOptions as $key => $val) {
            if ($val === '' || $val === null) {
                continue;
            }

            if (!in_array($key, $allowed, true) && !$this->isSafeCupsOption($key, $val)) {
                continue;
            }

            $result[$key] = $val;
        }

        return $result;
    }

    private function isSafeCupsOption($key, $val)
    {
        $key = (string) $key;
        $val = (string) $val;

        if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $key)) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_.:,+\-\/]{1,160}$/', $val) === 1;
    }

    private function logOfficeFontDiagnostics($filePath, $sourceExt)
    {
        if ($sourceExt === 'doc') {
            $this->log('DOC diagnostico: formato binario legado; se a formatacao mudar, confira fontes instaladas no Linux e salve como DOCX/PDF para comparar.');
            return;
        }

        if ($sourceExt !== 'docx' || !class_exists('ZipArchive')) {
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return;
        }

        $fonts = [];
        foreach (['word/document.xml', 'word/styles.xml', 'word/numbering.xml'] as $entry) {
            $xml = $zip->getFromName($entry);
            if ($xml === false) {
                continue;
            }

            if (preg_match_all('/w:(?:ascii|hAnsi|eastAsia|cs)="([^"]+)"/', $xml, $matches)) {
                foreach ($matches[1] as $font) {
                    $font = trim(html_entity_decode($font, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($font !== '' && !str_starts_with($font, '+')) {
                        $fonts[$font] = true;
                    }
                }
            }
        }
        $zip->close();

        $fonts = array_slice(array_keys($fonts), 0, 20);
        if (empty($fonts)) {
            return;
        }

        $resolved = [];
        $fcMatch = $this->isWindows ? null : $this->findExecutable(['/usr/bin/fc-match', 'fc-match']);
        foreach ($fonts as $font) {
            if ($fcMatch === null) {
                $resolved[] = $font;
                continue;
            }

            $output = [];
            $status = 1;
            exec(escapeshellarg($fcMatch) . ' -f ' . escapeshellarg('%{family}\n') . ' ' . escapeshellarg($font) . ' 2>/dev/null', $output, $status);
            $match = $status === 0 && !empty($output[0]) ? trim($output[0]) : '?';
            $resolved[] = $font . '=>' . $match;
        }

        $this->log('DOCX fontes solicitadas/resolvidas: ' . implode('; ', $resolved));
    }

    private function logOfficeLayoutDiagnostics($filePath, $sourceExt)
    {
        if ($sourceExt !== 'docx') {
            return;
        }

        $documentXml = $this->readDocxEntry($filePath, 'word/document.xml');
        if ($documentXml === null) {
            return;
        }

        $sections = [];
        if (preg_match_all('/<w:sectPr\b.*?<\/w:sectPr>/s', $documentXml, $matches)) {
            foreach ($matches[0] as $sectionXml) {
                $page = $this->parseDocxPageSection($sectionXml);
                if ($page !== null) {
                    $sections[] = $page;
                }
            }
        }

        if (!empty($sections)) {
            $labels = [];
            foreach (array_slice($sections, 0, 5) as $index => $section) {
                $labels[] = sprintf(
                    '#%d %s %.1Fx%.1Fmm margens T%.1F/R%.1F/B%.1F/L%.1Fmm',
                    $index + 1,
                    $section['orient'],
                    $section['width_mm'],
                    $section['height_mm'],
                    $section['top_mm'],
                    $section['right_mm'],
                    $section['bottom_mm'],
                    $section['left_mm']
                );
            }
            $this->log('DOCX layout: ' . implode('; ', $labels));
        }

        $settingsXml = $this->readDocxEntry($filePath, 'word/settings.xml');
        $compatFlags = [];
        if ($settingsXml !== null && preg_match('/<w:compat\b.*?<\/w:compat>/s', $settingsXml, $compat)) {
            if (preg_match_all('/<w:([A-Za-z0-9_]+)\b/', $compat[0], $flags)) {
                $compatFlags = array_slice(array_unique($flags[1]), 0, 20);
            }
        }
        if (!empty($compatFlags)) {
            $this->log('DOCX compatibilidade: ' . implode(', ', $compatFlags));
        }
    }

    private function parseDocxPageSection($sectionXml)
    {
        if (!preg_match('/<w:pgSz\b([^>]*)\/?>/s', $sectionXml, $sizeMatch)) {
            return null;
        }

        $sizeAttrs = $this->parseXmlAttributes($sizeMatch[1]);
        $marginAttrs = [];
        if (preg_match('/<w:pgMar\b([^>]*)\/?>/s', $sectionXml, $marginMatch)) {
            $marginAttrs = $this->parseXmlAttributes($marginMatch[1]);
        }

        $width = (int) ($sizeAttrs['w'] ?? 0);
        $height = (int) ($sizeAttrs['h'] ?? 0);
        if ($width < 1 || $height < 1) {
            return null;
        }

        return [
            'orient' => $sizeAttrs['orient'] ?? 'portrait',
            'width_mm' => $this->twipsToMm($width),
            'height_mm' => $this->twipsToMm($height),
            'top_mm' => $this->twipsToMm((int) ($marginAttrs['top'] ?? 0)),
            'right_mm' => $this->twipsToMm((int) ($marginAttrs['right'] ?? 0)),
            'bottom_mm' => $this->twipsToMm((int) ($marginAttrs['bottom'] ?? 0)),
            'left_mm' => $this->twipsToMm((int) ($marginAttrs['left'] ?? 0)),
        ];
    }

    private function parseXmlAttributes($text)
    {
        $attrs = [];
        if (preg_match_all('/(?:\w+:)?([A-Za-z0-9_]+)="([^"]*)"/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs[$match[1]] = html_entity_decode($match[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        return $attrs;
    }

    private function twipsToMm($twips)
    {
        return round(((float) $twips) / 1440 * 25.4, 1);
    }

    private function logConvertedPdfDiagnostics($pdfFile, $sourceExt)
    {
        $debugCopy = $this->copyDebugFile($pdfFile, $sourceExt . '-convertido');
        if ($debugCopy !== null) {
            $this->log(strtoupper($sourceExt) . ' diagnostico: copia do PDF convertido=' . $debugCopy);
        }

        $pdfinfo = $this->findExecutable([$_ENV['PDFINFO_PATH'] ?? null, '/usr/bin/pdfinfo', 'pdfinfo']);
        if ($pdfinfo === null) {
            return;
        }

        $output = [];
        $status = 1;
        exec(escapeshellarg($pdfinfo) . ' ' . escapeshellarg($pdfFile) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            $this->log('PDF diagnostico: pdfinfo falhou status=' . $status . ' | ' . implode(' | ', $output));
            return;
        }

        $interesting = [];
        foreach ($output as $line) {
            if (preg_match('/^(Pages|Page size|Creator|Producer):/i', $line)) {
                $interesting[] = trim($line);
            }
        }
        if (!empty($interesting)) {
            $this->log('PDF diagnostico convertido: ' . implode(' | ', $interesting));
        }
    }

    private function readDocxEntry($filePath, $entry)
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $content = $zip->getFromName($entry);
        $zip->close();

        return $content === false ? null : $content;
    }

    private function countDocxDeclaredPages($filePath)
    {
        $appXml = $this->readDocxEntry($filePath, 'docProps/app.xml');
        if ($appXml === null) {
            return 0;
        }

        if (preg_match('/<Pages>(\d+)<\/Pages>/', $appXml, $match)) {
            return (int) $match[1];
        }

        return 0;
    }

    private function countPdfPages($pdfFile)
    {
        $pdfinfo = $this->findExecutable([$_ENV['PDFINFO_PATH'] ?? null, '/usr/bin/pdfinfo', 'pdfinfo']);
        if ($pdfinfo !== null) {
            $output = [];
            $status = 1;
            exec(escapeshellarg($pdfinfo) . ' ' . escapeshellarg($pdfFile) . ' 2>&1', $output, $status);
            if ($status === 0) {
                foreach ($output as $line) {
                    if (stripos($line, 'Pages:') === 0) {
                        return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                    }
                }
            }
        }

        $content = @file_get_contents($pdfFile);
        if ($content === false) {
            return 0;
        }

        if (preg_match('/\/Type\s*\/Catalog\b.*?\/Pages\s+(\d+)\s+\d+\s+R/s', $content, $catalog)) {
            $object = $this->findPdfObject($content, (int) $catalog[1]);
            if ($object !== null && preg_match('/\/Type\s*\/Pages\b.*?\/Count\s+(\d+)/s', $object, $count)) {
                return (int) $count[1];
            }
        }

        if (preg_match_all('/\/Type\s*\/Page\b/', $content, $matches)) {
            return count($matches[0]);
        }

        return 0;
    }

    private function findPdfObject($content, $objectNumber)
    {
        $pattern = '/\b' . preg_quote((string) $objectNumber, '/') . '\s+0\s+obj\b(.*?)\bendobj\b/s';
        if (!preg_match($pattern, $content, $match)) {
            return null;
        }

        return $match[1];
    }

    private function writeOfficeFontConfig($configDir)
    {
        if ($this->isWindows) {
            return;
        }

        $fontConfigDir = $configDir . DIRECTORY_SEPARATOR . 'fontconfig';
        if (!is_dir($fontConfigDir) && !@mkdir($fontConfigDir, 0775, true)) {
            return;
        }

        $aliases = [
            'Calibri' => ['Carlito', 'Liberation Sans', 'DejaVu Sans'],
            'Cambria' => ['Caladea', 'Liberation Serif', 'DejaVu Serif'],
            'Arial' => ['Liberation Sans', 'DejaVu Sans'],
            'Times New Roman' => ['Liberation Serif', 'DejaVu Serif'],
            'Courier New' => ['Liberation Mono', 'DejaVu Sans Mono'],
            'Verdana' => ['DejaVu Sans', 'Liberation Sans'],
            'Tahoma' => ['DejaVu Sans', 'Liberation Sans'],
        ];

        $xml = "<?xml version=\"1.0\"?>\n<!DOCTYPE fontconfig SYSTEM \"fonts.dtd\">\n<fontconfig>\n";
        foreach ($aliases as $source => $targets) {
            $xml .= "  <alias binding=\"same\">\n";
            $xml .= "    <family>" . htmlspecialchars($source, ENT_XML1) . "</family>\n";
            $xml .= "    <prefer>\n";
            foreach ($targets as $target) {
                $xml .= "      <family>" . htmlspecialchars($target, ENT_XML1) . "</family>\n";
            }
            $xml .= "    </prefer>\n";
            $xml .= "  </alias>\n";
        }
        $xml .= "</fontconfig>\n";

        if (@file_put_contents($fontConfigDir . DIRECTORY_SEPARATOR . 'fonts.conf', $xml) !== false) {
            $this->log('LibreOffice: fontconfig temporario criado para fontes Office metricamente compativeis');
        }
    }

    private function pathToFileUri($path)
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        $parts = array_map('rawurlencode', explode('/', $path));
        $uriPath = implode('/', $parts);

        if (preg_match('/^[A-Za-z]:\//', $path)) {
            return 'file:///' . $uriPath;
        }

        if (str_starts_with($path, '/')) {
            return 'file://' . $uriPath;
        }

        return 'file:///' . $uriPath;
    }

    private function detectMime($filePath)
    {
        if (function_exists('mime_content_type')) {
            return @mime_content_type($filePath) ?: null;
        }

        return null;
    }

    private function copyDebugFile($filePath, $prefix)
    {
        if (!is_file($filePath)) {
            return null;
        }

        $baseDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'print-debug';
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
            return null;
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'bin';
        $target = $baseDir . DIRECTORY_SEPARATOR . $prefix . '-' . date('Ymd-His') . '-' . substr(sha1($filePath . microtime(true)), 0, 8) . '.' . $ext;

        return @copy($filePath, $target) ? $target : null;
    }

    private function windowsSides($sides)
    {
        if ($sides === 'two-sided-short-edge') {
            return 'duplexshort';
        }
        if ($sides === 'two-sided-long-edge') {
            return 'duplexlong';
        }
        return 'simplex';
    }

    private function findLibreOffice()
    {
        $candidates = array_filter([
            $_ENV['LIBREOFFICE_PATH'] ?? null,
            'soffice',
            'libreoffice',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/libreoffice',
            '/usr/bin/soffice',
        ]);

        return $this->findExecutable($candidates);
    }

    private function findSumatra()
    {
        $candidates = array_filter([
            $_ENV['SUMATRA_PATH'] ?? null,
            'SumatraPDF',
            'SumatraPDF.exe',
            'C:\\Program Files\\SumatraPDF\\SumatraPDF.exe',
            'C:\\Program Files (x86)\\SumatraPDF\\SumatraPDF.exe',
            getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA') . '\\SumatraPDF\\SumatraPDF.exe' : null,
        ]);

        return $this->findExecutable($candidates);
    }

    private function findImageMagick()
    {
        $candidates = array_filter([
            $_ENV['IMAGEMAGICK_PATH'] ?? null,
            'magick',
            $this->isWindows ? null : 'convert',
            'C:\\Program Files\\ImageMagick-7.1.1-Q16-HDRI\\magick.exe',
            '/usr/bin/magick',
            '/usr/bin/convert',
        ]);

        return $this->findExecutable($candidates);
    }

    private function findExecutable($candidates)
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            if (is_file($candidate)) {
                return $candidate;
            }

            if (strpos($candidate, DIRECTORY_SEPARATOR) !== false || preg_match('/^[A-Z]:\\\\/i', $candidate)) {
                continue;
            }

            $command = $this->isWindows ? 'where ' : 'command -v ';
            $stderr = $this->isWindows ? ' 2>NUL' : ' 2>/dev/null';
            exec($command . escapeshellarg($candidate) . $stderr, $output, $status);
            if ($status === 0 && !empty($output[0]) && is_file($output[0])) {
                return $output[0];
            }
        }

        return null;
    }

    public function log($msg)
    {
        $logPath = $_ENV['LOG_PATH'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'print_debug.log');
        if (is_dir($logPath)) {
            $logPath = rtrim($logPath, "\\/") . DIRECTORY_SEPARATOR . 'app.log';
        }

        $dir = dirname($logPath);
        if ($dir && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            $logPath,
            date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL,
            FILE_APPEND
        );
    }
}
