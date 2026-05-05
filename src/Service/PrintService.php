<?php

class PrintService
{
    private $printerName;
    private $isWindows;

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
        $preparedExt = strtolower(pathinfo($preparedFile, PATHINFO_EXTENSION));
        $this->log('Arquivo preparado para impressao: origem=' . $filePath . ' ext=' . $sourceExt . ' mime=' . ($this->detectMime($filePath) ?: '-') . ' preparado=' . $preparedFile . ' ext_preparado=' . $preparedExt . ' tamanho=' . (@filesize($preparedFile) ?: 0));

        if ($this->isWindows) {
            $this->printWindows($preparedFile, $copies, $sides, $orientation, $numberUp, $extraOptions);
        } else {
            $this->printCups($preparedFile, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions, $sourceExt);
        }

        return $preparedFile;
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
        $cmd .= ' -n ' . max(1, intval($copies));
        $cmd .= ' -o orientation-requested=' . ($orientation === 'landscape' ? 4 : 3);
        $cmd .= ' -o print-quality=' . intval($quality);
        $cmd .= ' -o sides=' . $this->cupsSides($sides);

        if ($numberUp > 1) {
            $cmd .= ' -o number-up=' . intval($numberUp);
            $cmd .= ' -o number-up-layout=lrtb';
        }

        if (!in_array($sourceExt, ['jpg', 'jpeg', 'png'], true)) {
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

        $cmd = sprintf(
            '%s --headless --convert-to pdf --outdir %s %s 2>&1',
            escapeshellarg($office),
            escapeshellarg($outDir),
            escapeshellarg($filePath)
        );
        exec($cmd, $output, $status);

        $expected = $outDir . DIRECTORY_SEPARATOR . pathinfo($filePath, PATHINFO_FILENAME) . '.pdf';
        if ($status === 0 && is_file($expected)) {
            $this->log('LibreOffice: ' . $cmd . ' | OK');
            return $expected;
        }

        $converted = glob($outDir . DIRECTORY_SEPARATOR . '*.pdf');
        if ($status === 0 && !empty($converted) && is_file($converted[0])) {
            $this->log('LibreOffice: ' . $cmd . ' | OK: ' . $converted[0]);
            return $converted[0];
        }

        $this->log('LibreOffice falhou: ' . $cmd . ' | status=' . $status . ' | ' . implode(' | ', $output));
        throw new RuntimeException('Falha ao converter DOC/DOCX para PDF');
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
            $this->assertCanConvertPngInMemory($info[0], $info[1], filesize($filePath));
            $pdfFile = tempnam(sys_get_temp_dir(), 'imgpdf_') . '.pdf';
            $this->writePngPdf($filePath, $pdfFile, $orientation, $paper);
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
            escapeshellarg($jpegFile),
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
        $allowed = ['media', 'scaling', 'fit-to-page'];
        $result = [];

        foreach ($extraOptions as $key => $val) {
            if ($val === '' || $val === null || !in_array($key, $allowed, true)) {
                continue;
            }

            $result[$key] = $val;
        }

        return $result;
    }

    private function detectMime($filePath)
    {
        if (function_exists('mime_content_type')) {
            return @mime_content_type($filePath) ?: null;
        }

        return null;
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
            'convert',
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
