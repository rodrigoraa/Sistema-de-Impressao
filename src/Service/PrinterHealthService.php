<?php

/** Physical transport and IPP state; queue readiness alone does not prove printer readiness. */
class PrinterHealthService
{
    // Motivo exibido => [palavras-chave IPP, orientação em português].
    private const CONDITIONS = [
        'saída de papel cheia' => [
            ['output-area-full', 'output-tray-full'],
            'A bandeja de saída está cheia. Retire as folhas impressas para liberar espaço.',
        ],
        'saída de papel quase cheia' => [
            ['output-area-almost-full', 'output-tray-almost-full'],
            'A bandeja de saída está quase cheia. Retire as folhas impressas antes que ela encha.',
        ],
        'bandeja de saída ausente' => [
            ['output-tray-missing', 'output-tray-open'],
            'A bandeja de saída está ausente ou desencaixada. Recoloque e encaixe a bandeja.',
        ],
        'nível de papel baixo' => [
            ['media-low', 'paper-low'],
            'O nível de papel está baixo. Reponha as folhas na bandeja.',
        ],
        'coletor de resíduos cheio' => [
            ['marker-waste-full', 'toner-waste-full'],
            'O coletor de resíduos está cheio. Avise a equipe de TI para esvaziar ou trocar o coletor.',
        ],
        'coletor de resíduos quase cheio' => [
            ['marker-waste-almost-full', 'toner-waste-almost-full'],
            'O coletor de resíduos está quase cheio. Avise a equipe de TI para programar a troca.',
        ],
        'coletor de resíduos ausente' => [
            ['marker-waste-missing', 'toner-waste-missing'],
            'O coletor de resíduos está ausente ou desencaixado. Avise a equipe de TI para verificar o coletor.',
        ],
        'cartucho ausente' => [
            ['marker-supply-missing', 'toner-missing', 'ink-missing'],
            'O cartucho ou reservatório de toner ou tinta está ausente ou desencaixado. Avise a equipe de TI.',
        ],
        'fusor superaquecido' => [
            ['fuser-over-temp'],
            'O fusor está acima da temperatura de operação. Avise a equipe de TI.',
        ],
        'fusor abaixo da temperatura' => [
            ['fuser-under-temp'],
            'O fusor está abaixo da temperatura de operação. Avise a equipe de TI.',
        ],
        'trava de segurança aberta' => [
            ['interlock-open'],
            'Uma trava de segurança está aberta. Confira se as tampas de acesso estão fechadas e avise a equipe de TI se persistir.',
        ],
        'memória da fila cheia' => [
            ['spool-area-full'],
            'A memória da fila da impressora está cheia. Aguarde os trabalhos em andamento ou avise a equipe de TI.',
        ],
        'manutenção solicitada' => [
            ['service-request', 'maintenance-required'],
            'A impressora solicitou manutenção. Confira o código no painel e informe à equipe de TI.',
        ],
        'equipamento desligando' => [
            ['shutdown'],
            'A impressora está desligando. Aguarde que ela volte a ficar pronta ou avise a equipe de TI.',
        ],
        'unidade reveladora vazia' => [
            ['developer-empty'],
            'A unidade reveladora está vazia. Avise a equipe de TI para verificar o suprimento.',
        ],
        'nível baixo na unidade reveladora' => [
            ['developer-low'],
            'O nível de suprimento da unidade reveladora está baixo. Avise a equipe de TI.',
        ],
        'fim da vida da unidade de imagem' => [
            ['opc-life-over', 'drum-life-over'],
            'A unidade de imagem atingiu o fim da vida útil. Avise a equipe de TI para providenciar a troca.',
        ],
        'unidade de imagem perto do fim da vida' => [
            ['opc-near-eol', 'drum-near-eol'],
            'A unidade de imagem está perto do fim da vida útil. Avise a equipe de TI para programar a troca.',
        ],
        'falha na unidade de imagem' => [
            ['opc-missing', 'drum-missing'],
            'A unidade de imagem está ausente ou desencaixada. Avise a equipe de TI para verificar a unidade.',
        ],
        'impressão pausada' => [
            ['paused'],
            'A impressão foi pausada no equipamento. Confira o painel ou avise a equipe de TI para retomar.',
        ],
        'equipamento entrando em pausa' => [
            ['moving-to-paused'],
            'A impressora está entrando em pausa. Confira o painel ou avise a equipe de TI para retomar.',
        ],
        'interrupção do trabalho atual' => [
            ['processing-to-stop-point', 'stopping'],
            'A impressora está interrompendo o trabalho atual. Aguarde e confira o painel se ela permanecer parada.',
        ],
        'equipamento aquecendo' => [
            ['warming-up'],
            'A impressora está aquecendo. A impressão pode demorar até que ela fique pronta.',
        ],
        'equipamento ocupado' => [
            ['timed-out'],
            'A impressora demorou para responder a uma operação. Aguarde e avise a equipe de TI se persistir.',
        ],
        'parte do equipamento indisponível' => [
            ['stopped-partly'],
            'Parte do equipamento está parada. Confira o painel da impressora e avise a equipe de TI.',
        ],
        'recurso de processamento indisponível' => [
            ['interpreter-resource-unavailable'],
            'A impressora precisa de um recurso para processar o documento. Avise a equipe de TI para verificar o painel e o formato do arquivo.',
        ],
        'equipamento conectando' => [
            ['connecting-to-device'],
            'O serviço de impressão ainda está tentando conectar-se ao equipamento. Aguarde ou avise a equipe de TI se persistir.',
        ],
        'aviso de configuração do papel' => [
            ['media-mismatch', 'media-size', 'media-type'],
            'O papel disponível não corresponde ao tamanho ou tipo solicitado. Confira o papel e as opções de impressão.',
        ],
    ];

    public static function noticeFor(string $reason): ?string
    {
        return self::CONDITIONS[$reason][1] ?? null;
    }

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

    public static function hasPaperTrayFault(string $text): bool
    {
        return preg_match('/\b(?:(?:input|media|paper)-tray-(?:open|missing)|inputMediaTray(?:Open|Missing)|media-input-tray-(?:open|missing))(?:-error)?(?![\w-])/i', $text) === 1
            || preg_match('/\b(?:close|insert|reinsert)\s+(?:the\s+)?(?:paper\s+)?(?:cassette|(?:input|paper)\s+tray)\b/i', $text) === 1
            || preg_match('/\b(?:cassette|(?:input|paper)\s+tray|bandeja(?:\s+de\s+papel)|gaveta(?:\s+de\s+papel)?)\s*(?:#?[0-9]+\s*)?(?:is\s+|está\s+|:)?\s*(?:open|missing|not (?:installed|inserted)|aberta|desencaixada)\b/iu', $text) === 1
            || preg_match('/\b(?:feche|encaixe|reencaixe)\s+a\s+(?:bandeja(?:\s+de\s+papel)?|gaveta(?:\s+de\s+papel)?)\b/iu', $text) === 1;
    }

    public function interpret($response)
    {
        $unknown = ['verified'=>false, 'can_print'=>false, 'reason'=>'estado não confirmado'];
        if (!is_array($response) || (int) ($response['return_code'] ?? 1) !== 0) return $unknown;
        $text = (string) ($response['stdout'] ?? '');
        $state = strtolower($this->attribute($text, 'printer-state'));
        if (!in_array($state, ['idle','processing','stopped','3','4','5'], true)) return $unknown;
        $reasons = strtolower($this->attribute($text, 'printer-state-reasons'));
        $message = $this->attribute($text, 'printer-state-message');
        $alert = $this->attribute($text, 'printer-alert');
        $description = $this->attribute($text, 'printer-alert-description');
        if (self::hasPaperTrayFault(implode("\n", [$reasons, $message, $alert, $description]))) {
            return ['verified'=>true, 'can_print'=>false, 'reason'=>'bandeja de papel aberta'];
        }
        $detail = '';
        foreach ([$message, $description] as $candidate) {
            if (preg_match('/^[^\p{C}]{1,240}$/uD', $candidate)
                && !preg_match('/^(?:none|unknown|no-value|stopped|paused|error|other|parad[ao]|erro)[.!]?$/iuD', $candidate)) {
                $detail = $candidate;
                break;
            }
        }
        $stopped = in_array($state, ['stopped', '5'], true);
        $accepting = strtolower($this->attribute($text, 'printer-is-accepting-jobs')) !== 'false';
        $codes = preg_split('/[,\s]+/', $reasons, -1, PREG_SPLIT_NO_EMPTY);
        $blockingCodes = array_filter($codes, static fn ($code) => $code !== 'none' && !preg_match('/-(warning|report)$/D', $code));
        $message = strtolower($message);
        $map = [
            'falta de papel'=>['media-empty','media-needed','paper-empty','out of paper','sem papel','falta de papel'],
            'atolamento de papel'=>['media-jam','paper-jam','jammed','papel atolado','atolamento'],
            'toner ou suprimento'=>['toner-empty','marker-supply-empty','ink-empty','sem toner'],
            'tampa aberta'=>['door-open','cover-open','tampa aberta','porta aberta'],
            'offline'=>['offline','not connected','unreachable'],
        ];
        foreach ($map as $reason=>$tokens) {
            foreach ($tokens as $token) {
                if (str_contains($reasons . "\n" . $message, $token)) return ['verified'=>true,'can_print'=>false,'reason'=>$reason];
            }
        }
        $condition = $this->interpretCondition($reasons);
        if ($condition !== null && !$condition['can_print']) {
            return ['verified'=>true] + $condition;
        }

        // Preserve a firmware-specific code when the equipment supplied no usable message.
        if ($detail === '') {
            $safeCodes = array_filter($blockingCodes ?: $codes, static fn ($code) =>
                preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $code) && !in_array(preg_replace('/-(error|warning|report)$/D', '', $code), ['none', 'other', 'unknown'], true));
            $detail = implode(', ', array_slice($safeCodes, 0, 3));
        }
        $fault = ['verified'=>true, 'can_print'=>false, 'detail'=>$detail];
        if ($stopped || $blockingCodes) {
            return $fault + ['reason'=>$stopped ? 'impressora parada' : 'falha no equipamento'];
        }
        if (!$accepting) {
            return $fault + ['reason'=>'impressora não aceita impressões'];
        }
        if ($reasons === '') return $unknown;
        if ($condition !== null) return ['verified'=>true, 'detail'=>$detail] + $condition;
        if (preg_match('/toner-low|marker-supply-low|ink-low/', $reasons)) {
            return ['verified'=>true,'can_print'=>true,'reason'=>'suprimento baixo'];
        }
        if ($reasons !== 'none') return ['verified'=>true,'can_print'=>true,'reason'=>'aviso do equipamento','detail'=>$detail];
        return ['verified'=>true,'can_print'=>true,'reason'=>''];
    }

    public function interpretQueueReasons(string $text): ?array
    {
        return $this->interpretCondition(strtolower($this->attribute($text, 'printer-state-reasons')));
    }

    private function interpretCondition(string $reasons): ?array
    {
        $codes = preg_split('/[,\s]+/', $reasons, -1, PREG_SPLIT_NO_EMPTY);
        $advisory = '';
        foreach (self::CONDITIONS as $reason => [$keywords]) {
            foreach ($codes as $code) {
                $keyword = preg_replace('/-(error|warning|report)$/D', '', $code);
                if (!in_array($keyword, $keywords, true)) {
                    continue;
                }
                if (!preg_match('/-(warning|report)$/D', $code)) {
                    return ['can_print'=>false, 'reason'=>$reason];
                }
                if (!str_ends_with($code, '-report')) {
                    $advisory = $advisory ?: $reason;
                }
            }
        }
        return $advisory === '' ? null : ['can_print'=>true, 'reason'=>$advisory];
    }

    private function attribute($text, $name)
    {
        return preg_match('/^\s*' . preg_quote($name, '/') . '\s*(?:\([^\r\n]*?\))?\s*[=:]\s*([^\r\n]*)/mi', $text, $match)
            ? trim($match[1], " \t\"'") : '';
    }
}
