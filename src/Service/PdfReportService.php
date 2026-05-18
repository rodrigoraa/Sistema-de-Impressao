<?php

class PdfReportService
{
    public function monthlyReport($title, $filters, $rows)
    {
        $lines = [];
        $lines[] = $title;
        $lines[] = 'Mes: ' . ($filters['month'] ?? '') . ' | Professor: ' . (($filters['cpf'] ?? '') !== '' ? $filters['cpf'] : 'Todos');
        $lines[] = 'Falhas/canceladas: ' . (!empty($filters['include_failures']) ? 'incluidas' : 'ocultas');
        $lines[] = '';
        $lines[] = 'CPF | Nome | Jobs | Paginas | Copias | Total contabilizado | Status | Erros';

        $totalJobs = 0;
        $totalPages = 0;
        $totalCharged = 0;
        foreach ($rows as $row) {
            $totalJobs += (int) ($row['jobs'] ?? 0);
            $totalPages += (int) ($row['pages'] ?? 0);
            $totalCharged += (int) ($row['charged_pages'] ?? 0);
            $lines[] = sprintf(
                '%s | %s | %d | %d | %d | %d | %s | %s',
                $row['cpf'] ?? '',
                $row['name'] ?? '',
                (int) ($row['jobs'] ?? 0),
                (int) ($row['pages'] ?? 0),
                (int) ($row['copies'] ?? 0),
                (int) ($row['charged_pages'] ?? 0),
                $row['statuses'] ?? '',
                $row['errors'] ?? ''
            );
        }
        $lines[] = '';
        $lines[] = 'Total geral: jobs=' . $totalJobs . ' paginas=' . $totalPages . ' contabilizado=' . $totalCharged;

        return $this->buildPdf($lines);
    }

    private function buildPdf($lines)
    {
        $content = "BT\n/F1 11 Tf\n50 790 Td\n14 TL\n";
        foreach ($lines as $line) {
            foreach ($this->wrap($this->normalize($line), 105) as $wrapped) {
                $content .= '(' . $this->escape($wrapped) . ") Tj\nT*\n";
            }
        }
        $content .= "ET\n";

        $objects = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    private function wrap($text, $width)
    {
        $text = (string) $text;
        if ($text === '') {
            return [''];
        }

        return explode("\n", wordwrap($text, $width, "\n", true));
    }

    private function normalize($text)
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $text);
        return $text === false ? '' : $text;
    }

    private function escape($text)
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $text);
    }
}
