<?php

class PdfReportService
{
    private const PAGE_WIDTH = 595;
    private const PAGE_HEIGHT = 842;
    private const MARGIN = 36;

    public function monthlyReport($title, $filters, $rows)
    {
        $month = $this->formatMonth($filters['month'] ?? date('Y-m'));
        $cpf = trim((string) ($filters['cpf'] ?? ''));
        $includeFailures = !empty($filters['include_failures']);

        $totalJobs = 0;
        $totalPages = 0;
        $totalCopies = 0;
        $totalCharged = 0;
        $totalFailures = 0;
        foreach ($rows as $row) {
            $totalJobs += (int) ($row['jobs'] ?? 0);
            $totalPages += (int) ($row['pages'] ?? 0);
            $totalCopies += (int) ($row['copies'] ?? 0);
            $totalCharged += (int) ($row['charged_pages'] ?? 0);
            $totalFailures += (int) ($row['failed_jobs'] ?? 0);
        }

        $context = [
            'title' => $title,
            'month' => $month,
            'cpf' => $cpf !== '' ? $cpf : 'Todos os professores',
            'failures' => $includeFailures ? 'Inclui falhas e cancelamentos' : 'Somente impressões concluídas',
            'generated_at' => date('d/m/Y H:i'),
            'totals' => [
                'Trabalhos' => $totalJobs,
                'Páginas' => $totalPages,
                'Cópias solicitadas' => $totalCopies,
                'Contabilizadas' => $totalCharged,
                'Falhas' => $totalFailures,
            ],
        ];

        return $this->buildPdf($context, $rows);
    }

    private function buildPdf($context, $rows)
    {
        $pages = [];
        $pageRows = array_chunk($rows, 24);
        if (empty($pageRows)) {
            $pageRows = [[]];
        }

        $pageCount = count($pageRows);
        foreach ($pageRows as $index => $chunk) {
            $pages[] = $this->drawPage($context, $chunk, $index + 1, $pageCount);
        }

        return $this->assemblePdf($pages);
    }

    private function drawPage($context, $rows, $pageNumber, $pageCount)
    {
        $c = '';
        $c .= $this->fillColor(248, 250, 252) . $this->rect(0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, 'f');
        $c .= $this->fillColor(22, 78, 99) . $this->rect(0, self::PAGE_HEIGHT - 86, self::PAGE_WIDTH, 86, 'f');
        $c .= $this->text(36, 792, $context['title'], 20, true, 255, 255, 255);
        $c .= $this->text(36, 767, 'Relatório mensal de impressões por professor', 10, false, 219, 234, 254);
        $c .= $this->textRight(559, 792, 'Gerado em ' . $context['generated_at'], 9, false, 219, 234, 254);
        $c .= $this->textRight(559, 767, 'Página ' . $pageNumber . ' de ' . $pageCount, 9, false, 219, 234, 254);

        $c .= $this->summaryBox(36, 700, 250, 'Mês de referência', $context['month']);
        $c .= $this->summaryBox(306, 700, 253, 'Total geral de impressões no mês', $context['totals']['Contabilizadas'] . ' folhas contabilizadas');

        if (empty($rows)) {
            $c .= $this->fillColor(255, 255, 255) . $this->rect(self::MARGIN, 632, 523, 32, 'f');
            $c .= $this->strokeColor(226, 232, 240) . $this->rect(self::MARGIN, 632, 523, 32, 'S');
            $c .= $this->text(self::MARGIN + 14, 644, 'Nenhuma impressão encontrada para os filtros selecionados.', 10, false, 71, 85, 105);
        } else {
            $c .= $this->teacherTotalsTable(36, 662, $rows);
        }

        $c .= $this->strokeColor(203, 213, 225) . $this->line(36, 50, 559, 50);
        $c .= $this->text(36, 34, 'Sistema de Impressão Escolar - relatório gerado automaticamente a partir das tentativas registradas.', 8, false, 100, 116, 139);
        $c .= $this->text(36, 20, 'Falhas não entram no acumulado.', 8, false, 100, 116, 139);

        return $c;
    }

    private function summaryBox($x, $y, $w, $label, $value)
    {
        $c = $this->fillColor(255, 255, 255) . $this->rect($x, $y, $w, 48, 'f');
        $c .= $this->strokeColor(226, 232, 240) . $this->rect($x, $y, $w, 48, 'S');
        $c .= $this->text($x + 12, $y + 30, $label, 8, true, 100, 116, 139);
        $c .= $this->text($x + 12, $y + 13, $this->fitText($value, $w - 24, 10, true), 10, true, 15, 23, 42);

        return $c;
    }

    private function metricBox($x, $y, $w, $label, $value)
    {
        $c = $this->fillColor(255, 255, 255) . $this->rect($x, $y, $w, 44, 'f');
        $c .= $this->strokeColor(226, 232, 240) . $this->rect($x, $y, $w, 44, 'S');
        $c .= $this->text($x + 12, $y + 27, $label, 8, false, 100, 116, 139);
        $c .= $this->text($x + 12, $y + 8, $value, 16, true, 15, 118, 110);

        return $c;
    }

    private function teacherTotalsTable($x, $y, $rows)
    {
        $c = '';
        $groupWidth = 523;
        $nameWidth = 438;
        $totalWidth = 85;
        $rowHeight = 22;
        $rowsPerPage = 24;

        $c .= $this->teacherTotalsHeader($x, $y, $nameWidth, $totalWidth);

        for ($i = 0; $i < $rowsPerPage; $i++) {
            $rowY = $y - 24 - ($i * $rowHeight);
            $row = $rows[$i] ?? null;

            if ($row !== null) {
                $c .= $this->teacherTotalsRow($x, $rowY, $groupWidth, $nameWidth, $totalWidth, $row, $i % 2 === 1);
            }
        }

        return $c;
    }

    private function teacherTotalsHeader($x, $y, $nameWidth, $totalWidth)
    {
        $c = $this->fillColor(15, 23, 42) . $this->rect($x, $y, $nameWidth + $totalWidth, 22, 'f');
        $c .= $this->text($x + 8, $y + 7, 'Professor', 8.5, true, 255, 255, 255);
        $c .= $this->textRight($x + $nameWidth + $totalWidth - 8, $y + 7, 'Total', 8.5, true, 255, 255, 255);

        return $c;
    }

    private function teacherTotalsRow($x, $y, $groupWidth, $nameWidth, $totalWidth, $row, $alt)
    {
        $c = $this->fillColor($alt ? 241 : 255, $alt ? 245 : 255, $alt ? 249 : 255) . $this->rect($x, $y - 4, $groupWidth, 22, 'f');
        $c .= $this->strokeColor(226, 232, 240) . $this->line($x, $y - 4, $x + $groupWidth, $y - 4);
        $name = $this->fitText((string) ($row['name'] ?? ''), $nameWidth - 16, 8.5, false);
        $total = (string) ((int) ($row['charged_pages'] ?? 0));
        $c .= $this->text($x + 8, $y + 4, $name, 8.5, false, 30, 41, 59);
        $c .= $this->textRight($x + $nameWidth + $totalWidth - 8, $y + 4, $total, 8.5, true, 15, 118, 110);

        return $c;
    }

    private function tableHeader($x, $y, $columns)
    {
        $c = $this->fillColor(15, 23, 42) . $this->rect($x, $y, 770, 24, 'f');
        $cursor = $x;
        foreach ($columns as $column) {
            $c .= $this->text($cursor + 8, $y + 8, $this->fitText($column['label'], $column['w'] - 16, 8.5, true), 8.5, true, 255, 255, 255);
            $cursor += $column['w'];
        }

        return $c;
    }

    private function tableRow($x, $y, $columns, $values, $alt)
    {
        $c = $this->fillColor($alt ? 241 : 255, $alt ? 245 : 255, $alt ? 249 : 255) . $this->rect($x, $y - 4, 770, 28, 'f');
        $c .= $this->strokeColor(226, 232, 240) . $this->line($x, $y - 4, $x + 770, $y - 4);
        $cursor = $x;
        foreach ($columns as $i => $column) {
            $value = $this->fitText((string) ($values[$i] ?? ''), $column['w'] - 16, 8.5, false);
            $textX = $cursor + 8;
            if (($column['align'] ?? 'left') === 'right') {
                $textX = $cursor + $column['w'] - 8 - $this->textWidth($value, 8.5, false);
            }
            $c .= $this->text($textX, $y + 6, $value, 8.5, false, 30, 41, 59);
            $cursor += $column['w'];
        }

        return $c;
    }

    private function assemblePdf($pages)
    {
        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $kids = [];
        foreach ($pages as $i => $content) {
            $pageObj = 5 + ($i * 2);
            $contentObj = $pageObj + 1;
            $kids[] = $pageObj . ' 0 R';
            $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . "] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentObj} 0 R >>";
            $objects[$contentObj] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    private function text($x, $y, $text, $size = 10, $bold = false, $r = 0, $g = 0, $b = 0)
    {
        $font = $bold ? 'F2' : 'F1';
        return sprintf(
            "BT\n/%s %.2F Tf\n%.3F %.3F %.3F rg\n1 0 0 1 %.2F %.2F Tm\n(%s) Tj\nET\n",
            $font,
            $size,
            $r / 255,
            $g / 255,
            $b / 255,
            $x,
            $y,
            $this->escape($this->pdfText($text))
        );
    }

    private function textRight($rightX, $y, $text, $size = 10, $bold = false, $r = 0, $g = 0, $b = 0)
    {
        return $this->text($rightX - $this->textWidth($text, $size, $bold), $y, $text, $size, $bold, $r, $g, $b);
    }

    private function rect($x, $y, $w, $h, $mode)
    {
        return sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, $y, $w, $h, $mode);
    }

    private function line($x1, $y1, $x2, $y2)
    {
        return sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
    }

    private function fillColor($r, $g, $b)
    {
        return sprintf("%.3F %.3F %.3F rg\n", $r / 255, $g / 255, $b / 255);
    }

    private function strokeColor($r, $g, $b)
    {
        return sprintf("%.3F %.3F %.3F RG\n", $r / 255, $g / 255, $b / 255);
    }

    private function statusText($statuses, $failedJobs)
    {
        $labels = [];
        foreach (explode(',', (string) $statuses) as $status) {
            $status = trim($status);
            if ($status === '') {
                continue;
            }
            $labels[] = $this->translateStatus($status);
        }
        if ((int) $failedJobs > 0 && !in_array('Com falhas', $labels, true)) {
            $labels[] = 'Com falhas';
        }

        return empty($labels) ? 'Sem registro' : implode(', ', array_unique($labels));
    }

    private function normalizeStatusList($value)
    {
        $parts = [];
        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $parts[] = $this->translateStatus($part);
            }
        }

        return implode(', ', array_unique($parts));
    }

    private function translateStatus($status)
    {
        $map = [
            'queued' => 'Na fila',
            'processing' => 'Enviando',
            'completed' => 'Concluído',
            'failed' => 'Falhou',
            'usage_legacy' => 'Histórico antigo',
            'accepted' => 'Aceito pelo CUPS',
            'accepted_unverified' => 'Aceito, sem confirmação',
            'accepted_unidentified' => 'Aceito, ID não identificado',
            'failed_or_canceled' => 'Falhou ou foi cancelado',
            'printer_fault' => 'Falha detectada na impressora',
            'left_queue' => 'Saiu da fila',
            'timeout' => 'Tempo esgotado',
            'preflight_failed' => 'Falha antes do envio',
            'lp_failed' => 'Falha no comando de impressão',
            'falha_pre_validacao' => 'Falha antes do envio',
        ];

        return $map[$status] ?? $status;
    }

    private function formatMonth($month)
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $month, $match)) {
            return (string) $month;
        }

        $names = [
            '01' => 'Janeiro',
            '02' => 'Fevereiro',
            '03' => 'Março',
            '04' => 'Abril',
            '05' => 'Maio',
            '06' => 'Junho',
            '07' => 'Julho',
            '08' => 'Agosto',
            '09' => 'Setembro',
            '10' => 'Outubro',
            '11' => 'Novembro',
            '12' => 'Dezembro',
        ];

        return ($names[$match[2]] ?? $match[2]) . ' de ' . $match[1];
    }

    private function shortText($text, $limit)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        if (strlen($this->pdfText($text)) <= $limit) {
            return $text;
        }

        return rtrim(substr($text, 0, max(1, $limit - 3))) . '...';
    }

    private function fitText($text, $maxWidth, $size, $bold = false)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        if ($this->textWidth($text, $size, $bold) <= $maxWidth) {
            return $text;
        }

        $ellipsis = '...';
        $available = max(1, $maxWidth - $this->textWidth($ellipsis, $size, $bold));
        $out = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $char) {
            if ($this->textWidth($out . $char, $size, $bold) > $available) {
                break;
            }
            $out .= $char;
        }

        return rtrim($out) . $ellipsis;
    }

    private function textWidth($text, $size, $bold = false)
    {
        $width = 0;
        $bytes = str_split($this->pdfText($text));
        foreach ($bytes as $char) {
            $ord = ord($char);
            if ($char === ' ') {
                $width += 0.28;
            } elseif ($ord >= 48 && $ord <= 57) {
                $width += 0.56;
            } elseif ($ord >= 65 && $ord <= 90) {
                $width += 0.66;
            } elseif ($ord >= 97 && $ord <= 122) {
                $width += 0.50;
            } elseif (str_contains('.,:;!-_/\\()[]', $char)) {
                $width += 0.30;
            } else {
                $width += 0.54;
            }
        }

        return $width * $size * ($bold ? 1.05 : 1);
    }

    private function pdfText($text)
    {
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', (string) $text);
        return $text === false ? '' : $text;
    }

    private function escape($text)
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $text);
    }
}
