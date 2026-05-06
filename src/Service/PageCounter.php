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
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === 'docx') {
            return self::countDocxPages($file);
        }

        // Verifica arquivo e extensão
        if (!file_exists($file) || $ext !== 'pdf') {
            return 0;
        }

        $pdfinfo = self::findPdfInfo();
        if ($pdfinfo !== null) {
            exec(escapeshellarg($pdfinfo) . ' ' . escapeshellarg($file), $output, $status);
            if ($status === 0 && !empty($output)) {
                foreach ($output as $line) {
                    if (stripos($line, 'Pages:') === 0) {
                        return intval(filter_var($line, FILTER_SANITIZE_NUMBER_INT));
                    }
                }
            }
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return 0;
        }

        $treeCount = self::countFromPageTree($content);
        if ($treeCount > 0) {
            return $treeCount;
        }

        if (preg_match_all('/\/Type\s*\/Page\b/', $content, $matches)) {
            return count($matches[0]);
        }

        return 0;
    }

    public static function countDocxPages($file)
    {
        if (!is_file($file)) {
            return 0;
        }

        $appXml = self::readZipEntry($file, 'docProps/app.xml');
        if ($appXml === null) {
            return 0;
        }

        if (preg_match('/<Pages>(\d+)<\/Pages>/', $appXml, $match)) {
            return (int) $match[1];
        }

        return 0;
    }

    private static function countFromPageTree($content)
    {
        if (!preg_match('/\/Type\s*\/Catalog\b.*?\/Pages\s+(\d+)\s+\d+\s+R/s', $content, $catalog)) {
            return 0;
        }

        $pagesObject = self::findPdfObject($content, (int) $catalog[1]);
        if ($pagesObject === null) {
            return 0;
        }

        if (preg_match('/\/Type\s*\/Pages\b.*?\/Count\s+(\d+)/s', $pagesObject, $count)) {
            return (int) $count[1];
        }

        return 0;
    }

    private static function findPdfObject($content, $objectNumber)
    {
        $pattern = '/\b' . preg_quote((string) $objectNumber, '/') . '\s+0\s+obj\b(.*?)\bendobj\b/s';
        if (!preg_match($pattern, $content, $match)) {
            return null;
        }

        return $match[1];
    }

    private static function findPdfInfo()
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $candidates = [
            $_ENV['PDFINFO_PATH'] ?? null,
            'pdfinfo',
            'C:\\Program Files\\poppler\\Library\\bin\\pdfinfo.exe',
            'C:\\Program Files\\poppler\\bin\\pdfinfo.exe',
            '/usr/bin/pdfinfo',
        ];

        foreach (array_filter($candidates) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }

            if (strpos($candidate, DIRECTORY_SEPARATOR) !== false || preg_match('/^[A-Z]:\\\\/i', $candidate)) {
                continue;
            }

            $command = $isWindows ? 'where ' : 'command -v ';
            $stderr = $isWindows ? ' 2>NUL' : ' 2>/dev/null';
            exec($command . escapeshellarg($candidate) . $stderr, $output, $status);
            if ($status === 0 && !empty($output[0]) && is_file($output[0])) {
                return $output[0];
            }
        }

        return null;
    }

    private static function readZipEntry($zipFile, $entryName)
    {
        $data = @file_get_contents($zipFile);
        if ($data === false) {
            return null;
        }

        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false || $eocd + 22 > strlen($data)) {
            return null;
        }

        $dirSize = self::readUInt32($data, $eocd + 12);
        $dirOffset = self::readUInt32($data, $eocd + 16);
        $pos = $dirOffset;
        $end = $dirOffset + $dirSize;

        while ($pos + 46 <= $end && substr($data, $pos, 4) === "PK\x01\x02") {
            $method = self::readUInt16($data, $pos + 10);
            $compressedSize = self::readUInt32($data, $pos + 20);
            $filenameLength = self::readUInt16($data, $pos + 28);
            $extraLength = self::readUInt16($data, $pos + 30);
            $commentLength = self::readUInt16($data, $pos + 32);
            $localOffset = self::readUInt32($data, $pos + 42);
            $name = substr($data, $pos + 46, $filenameLength);

            if ($name === $entryName) {
                return self::readZipEntryAtOffset($data, $localOffset, $method, $compressedSize);
            }

            $pos += 46 + $filenameLength + $extraLength + $commentLength;
        }

        return null;
    }

    private static function readZipEntryAtOffset($data, $offset, $method, $compressedSize)
    {
        if ($offset + 30 > strlen($data) || substr($data, $offset, 4) !== "PK\x03\x04") {
            return null;
        }

        $filenameLength = self::readUInt16($data, $offset + 26);
        $extraLength = self::readUInt16($data, $offset + 28);
        $contentOffset = $offset + 30 + $filenameLength + $extraLength;
        $compressed = substr($data, $contentOffset, $compressedSize);

        if ($method === 0) {
            return $compressed;
        }

        if ($method === 8) {
            $content = @gzinflate($compressed);
            return $content === false ? null : $content;
        }

        return null;
    }

    private static function readUInt16($data, $offset)
    {
        $value = unpack('v', substr($data, $offset, 2));
        return $value ? $value[1] : 0;
    }

    private static function readUInt32($data, $offset)
    {
        $value = unpack('V', substr($data, $offset, 4));
        return $value ? $value[1] : 0;
    }
}
