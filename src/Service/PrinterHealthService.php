<?php

/** Physical transport and IPP state; queue readiness alone does not prove printer readiness. */
class PrinterHealthService
{
    public function probe($uri)
    {
        $parts = parse_url((string) $uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? '';
        $ports = ['socket'=>9100, 'lpd'=>515, 'ipp'=>631, 'ipps'=>631, 'http'=>80, 'https'=>443];
        if ($host === '' || !isset($ports[$scheme])) return ['reachable'=>null, 'status_uri'=>''];
        $port = $parts['port'] ?? $ports[$scheme];
        $timeout = max(1, min(5, (int) ($_ENV['PRINTER_CONNECT_TIMEOUT_SECONDS'] ?? 2)));
        $connection = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, $timeout);
        if ($connection !== false) fclose($connection);
        $statusUri = in_array($scheme, ['ipp','ipps','http','https'], true)
            ? (string) $uri : 'ipp://' . $host . ':631/ipp/print';
        return ['reachable'=>$connection !== false, 'status_uri'=>$statusUri];
    }

    public function interpret($response)
    {
        $unknown = ['verified'=>false, 'can_print'=>false, 'reason'=>'estado não confirmado'];
        if (!is_array($response) || (int) ($response['return_code'] ?? 1) !== 0) return $unknown;
        $text = (string) ($response['stdout'] ?? '');
        $state = strtolower($this->attribute($text, 'printer-state'));
        if (!in_array($state, ['idle','processing','stopped','3','4','5'], true)) return $unknown;
        $reasons = strtolower($this->attribute($text, 'printer-state-reasons'));
        $message = strtolower($this->attribute($text, 'printer-state-message'));
        $map = [
            'falta de papel'=>['media-empty','media-needed','paper-empty','out of paper','sem papel','falta de papel'],
            'atolamento de papel'=>['media-jam','paper-jam','jammed','papel atolado','atolamento'],
            'toner ou suprimento'=>['toner-empty','marker-supply-empty','ink-empty','marker-waste-full','sem toner'],
            'tampa aberta'=>['door-open','cover-open','tampa aberta','porta aberta'],
            'offline'=>['offline','connecting-to-device','not connected','unreachable'],
        ];
        foreach ($map as $reason=>$tokens) {
            foreach ($tokens as $token) {
                if (str_contains($reasons . "\n" . $message, $token)) return ['verified'=>true,'can_print'=>false,'reason'=>$reason];
            }
        }
        if (in_array($state, ['stopped','5'], true)) return ['verified'=>true,'can_print'=>false,'reason'=>'impressora parada'];
        if (strtolower($this->attribute($text, 'printer-is-accepting-jobs')) === 'false') {
            return ['verified'=>true,'can_print'=>false,'reason'=>'impressora não aceita impressões'];
        }
        if (preg_match('/\b[\w-]+-error\b/', $reasons) || preg_match('/\b(service-request|other|shutdown|spool-area-full|fuser-over-temp|fuser-under-temp|interlock-open|output-area-full)\b/', $reasons)) {
            return ['verified'=>true,'can_print'=>false,'reason'=>'falha no equipamento'];
        }
        if ($reasons === '') return $unknown;
        // IPP reasons without a severity suffix have error severity by default.
        foreach (preg_split('/[,\s]+/', $reasons, -1, PREG_SPLIT_NO_EMPTY) as $reason) {
            if ($reason !== 'none' && !preg_match('/-(warning|report)$/D', $reason)) {
                return ['verified'=>true,'can_print'=>false,'reason'=>'falha no equipamento'];
            }
        }
        if (preg_match('/toner-low|marker-supply-low|ink-low/', $reasons)) {
            return ['verified'=>true,'can_print'=>true,'reason'=>'suprimento baixo'];
        }
        if ($reasons !== 'none') return ['verified'=>true,'can_print'=>true,'reason'=>'aviso do equipamento'];
        return ['verified'=>true,'can_print'=>true,'reason'=>''];
    }

    private function attribute($text, $name)
    {
        return preg_match('/^\s*' . preg_quote($name, '/') . '\s*(?:\([^\r\n]*?\))?\s*[=:]\s*([^\r\n]*)/mi', $text, $match)
            ? trim($match[1], " \t\"'") : '';
    }
}
