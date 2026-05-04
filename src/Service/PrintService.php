<?php

class PrintService
{
    private $printer;

    public function __construct($printer)
    {
        $this->printer = $printer;
    }

    public function printFile($filePath)
    {
        $cmd = "lp -d {$this->printer} " . escapeshellarg($filePath);
        exec($cmd, $output, $status);

        return $status === 0;
    }
}