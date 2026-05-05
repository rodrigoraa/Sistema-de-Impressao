<?php

class PrinterController
{
    public function options()
    {
        header('Content-Type: application/json');

        $printer = $_ENV['PRINTER_NAME'] ?? '';
        if (!$printer) {
            echo json_encode([]);
            return;
        }

        exec("lpoptions -p " . escapeshellarg($printer) . " -l", $out);

        $result = [];

        foreach ($out as $line) {
            // exemplo:
            // Duplex/Double-Sided: None *DuplexNoTumble DuplexTumble
            if (!str_contains($line, ':')) continue;

            [$left, $right] = explode(':', $line, 2);

            $keyParts = explode('/', trim($left));
            $key = trim($keyParts[0]);
            $label = $keyParts[1] ?? $key;

            $options = [];
            $default = null;

            foreach (explode(' ', trim($right)) as $opt) {
                if ($opt === '') continue;

                if (str_starts_with($opt, '*')) {
                    $opt = substr($opt, 1);
                    $default = $opt;
                }

                $options[] = $opt;
            }

            $result[$key] = [
                'label' => $label,
                'options' => $options,
                'default' => $default
            ];
        }

        echo json_encode($result);
    }
}