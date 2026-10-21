<?php

require_once __DIR__ . '/../src/Service/PrintService.php';

$service = new PrintService();
$guard = new ReflectionMethod(PrintService::class, 'assertCanConvertPngInMemory');
$originalLimit = ini_get('memory_limit');
$files = [];

try {
    ini_set('memory_limit', '256M');
    // Both used to fail even though GD has enough memory to convert them.
    $guard->invoke($service, 1024, 1024, 3200000);
    $guard->invoke($service, 3000, 3000, 50000);

    foreach (['256M', '-1'] as $limit) {
        ini_set('memory_limit', $limit);
        try {
            $guard->invoke($service, 20000, 20000, 1000);
            throw new LogicException('Imagem excessiva foi aceita: ' . $limit);
        } catch (RuntimeException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'memoria')) {
                throw $e;
            }
        }
    }

    ini_set('memory_limit', '64M');
    $guard->invoke($service, 2000, 1500, 1000);
    $occupiedMemory = str_repeat('x', 24 * 1024 * 1024);
    try {
        $guard->invoke($service, 2000, 1500, 1000);
        throw new LogicException('Memoria ja ocupada nao foi considerada.');
    } catch (RuntimeException $e) {
        if (!str_contains(strtolower($e->getMessage()), 'memoria')) {
            throw $e;
        }
    }
    unset($occupiedMemory);
    ini_set('memory_limit', '256M');

    if (!function_exists('imagecreatefrompng')) {
        echo "Protecoes de memoria PNG: OK; conversao GD nao executada (extensao ausente).\n";
    } else {
        $writer = new ReflectionMethod(PrintService::class, 'writePngPdfWithGd');
        foreach ([[1024, 1024, true], [3000, 3000, false]] as [$width, $height, $noise]) {
            $png = tempnam(sys_get_temp_dir(), 'pngtest_');
            $pdf = tempnam(sys_get_temp_dir(), 'pdftest_');
            $files[] = $png;
            $files[] = $pdf;
            $image = imagecreatetruecolor($width, $height);
            if ($noise) {
                // Uncompressed pixels produce a valid PNG above the old 3 MB cap.
                imagepng($image, $png, 0);
                if (filesize($png) <= 3000000) {
                    throw new LogicException('Fixture PNG nao excedeu 3 MB.');
                }
            } else {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
                imagepng($image, $png);
            }
            imagedestroy($image);
            unset($image);
            $guard->invoke($service, $width, $height, filesize($png));
            if (!$writer->invoke($service, $png, $pdf, 'portrait', 'A4')) {
                throw new RuntimeException('GD falhou ao converter PNG grande.');
            }
            $content = file_get_contents($pdf);
            if (!str_starts_with($content, '%PDF-1.4')
                || !str_contains($content, "/Width {$width} /Height {$height}")
                || !str_contains($content, '/MediaBox [0 0 595.28 841.89]')) {
                throw new RuntimeException('PDF perdeu dimensoes ou papel A4.');
            }
            preg_match('/\/DCTDecode \/Length (\d+) >>\nstream\n/', $content, $match, PREG_OFFSET_CAPTURE);
            $jpeg = substr($content, $match[0][1] + strlen($match[0][0]), (int) $match[1][0]);
            $decoded = imagecreatefromstring($jpeg);
            $expected = $noise ? 0x000000 : 0xffffff;
            if (!$decoded || imagecolorat($decoded, 10, 10) !== $expected) {
                throw new RuntimeException('Conversao alterou cor ou transparencia sobre branco.');
            }
            imagedestroy($decoded);
            unset($decoded, $content, $jpeg);
        }
        echo "Regressoes PNG grande com GD: OK\n";
    }
} finally {
    ini_set('memory_limit', $originalLimit);
    foreach ($files as $file) {
        @unlink($file);
    }
}
