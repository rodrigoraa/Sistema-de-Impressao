<?php

class PageCounter
{
    public static function count($file)
    {
        $output = [];
        exec("pdfinfo " . escapeshellarg($file), $output);

        foreach ($output as $line) {
            if (strpos($line, 'Pages:') !== false) {
                return (int) trim(str_replace('Pages:', '', $line));
            }
        }

        return 0;
    }
}