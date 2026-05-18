<?php

class PrinterController
{
    public function options()
    {
        header('Content-Type: application/json');

        if (!isset($_SESSION['user'])) {
            http_response_code(401);
            echo json_encode([]);
            return;
        }

        $printer = $_ENV['PRINTER_NAME'] ?? '';
        if (!$printer) {
            echo json_encode([]);
            return;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            echo json_encode([]);
            return;
        }

        exec("command -v lpoptions 2>/dev/null", $check, $checkStatus);
        if ($checkStatus !== 0) {
            echo json_encode([]);
            return;
        }

        $lpoptions = $check[0] ?? 'lpoptions';
        exec(escapeshellarg($lpoptions) . " -p " . escapeshellarg($printer) . " -l 2>/dev/null", $out);

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
