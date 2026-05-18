<?php

class StorageCleanupService
{
    public function stats()
    {
        $uploadDir = $this->uploadDir();
        $debugDir = $this->debugDir();

        return [
            'uploads' => $this->directoryStats($uploadDir),
            'debug' => $this->directoryStats($debugDir),
        ];
    }

    public function cleanup($areas, $olderThanDays)
    {
        $allowedAreas = [
            'uploads' => $this->uploadDir(),
            'debug' => $this->debugDir(),
        ];
        $areas = array_values(array_intersect((array) $areas, array_keys($allowedAreas)));
        $cutoff = $olderThanDays > 0 ? time() - ((int) $olderThanDays * 86400) : null;

        $summary = [
            'files' => 0,
            'bytes' => 0,
            'errors' => [],
        ];

        foreach ($areas as $area) {
            $dir = $allowedAreas[$area];
            if ($dir === null) {
                continue;
            }

            $result = $this->deleteFilesInDirectory($dir, $cutoff);
            $summary['files'] += $result['files'];
            $summary['bytes'] += $result['bytes'];
            $summary['errors'] = array_merge($summary['errors'], $result['errors']);
        }

        return $summary;
    }

    public function formatBytes($bytes)
    {
        $bytes = max(0, (int) $bytes);
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $unit === 0 ? 0 : 2, ',', '.') . ' ' . $units[$unit];
    }

    private function uploadDir()
    {
        return $_ENV['UPLOAD_PATH'] ?? dirname(__DIR__, 2) . '/storage/uploads';
    }

    private function debugDir()
    {
        return dirname(__DIR__, 2) . '/storage/print-debug';
    }

    private function directoryStats($dir)
    {
        $real = $this->safeDirectory($dir);
        if ($real === null) {
            return ['path' => (string) $dir, 'exists' => false, 'files' => 0, 'bytes' => 0];
        }

        $files = 0;
        $bytes = 0;
        foreach ($this->files($real) as $file) {
            $files++;
            $bytes += (int) $file->getSize();
        }

        return ['path' => $real, 'exists' => true, 'files' => $files, 'bytes' => $bytes];
    }

    private function deleteFilesInDirectory($dir, $cutoff)
    {
        $real = $this->safeDirectory($dir);
        $summary = ['files' => 0, 'bytes' => 0, 'errors' => []];
        if ($real === null) {
            return $summary;
        }

        $deletedDirs = [];
        foreach ($this->files($real) as $file) {
            if ($cutoff !== null && $file->getMTime() > $cutoff) {
                continue;
            }

            $path = $file->getPathname();
            $size = (int) $file->getSize();
            if (@unlink($path)) {
                $summary['files']++;
                $summary['bytes'] += $size;
                $deletedDirs[$file->getPath()] = true;
            } else {
                $summary['errors'][] = $path;
            }
        }

        $this->removeEmptyDirectories(array_keys($deletedDirs), $real);

        return $summary;
    }

    private function files($dir)
    {
        if (!is_dir($dir)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        $files = [];
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $files[] = $item;
            }
        }

        return $files;
    }

    private function safeDirectory($dir)
    {
        $dir = rtrim((string) $dir, "\\/");
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }

        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            return null;
        }

        return $real;
    }

    private function removeEmptyDirectories($dirs, $root)
    {
        usort($dirs, fn($a, $b) => strlen($b) <=> strlen($a));
        $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/') . '/';

        foreach ($dirs as $dir) {
            $real = realpath($dir);
            if ($real === false || !is_dir($real)) {
                continue;
            }

            $normalized = rtrim(str_replace('\\', '/', $real), '/') . '/';
            if ($normalized === $root || !str_starts_with($normalized, $root)) {
                continue;
            }

            @rmdir($real);
        }
    }
}
