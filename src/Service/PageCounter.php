<?php

class PageCounter
{
    public static function count($file)
    {
        if (!file_exists($file)) {
            return 0;
        }
        $cmd = "/usr/bin/pdfinfo " . escapeshellarg($file) . " 2>&1";
        exec($cmd, $output, $status);
        if ($status !== 0 || empty($output)) {
            return 0;
        }
        foreach ($output as $line) {
            if (strpos($line, 'Pages:') !== false) {
                return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
            }
        }
        return 0;
    }
}
