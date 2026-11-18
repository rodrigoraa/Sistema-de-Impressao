<?php
require_once __DIR__ . '/../src/Service/CupsService.php';

function healthAssert($ok, $message) { if (!$ok) throw new RuntimeException($message); }
if (!class_exists('PrinterHealthService')) throw new RuntimeException('Falta consulta física da impressora: CUPS idle não comprova disponibilidade.');
$health = new PrinterHealthService();
$ipp = static fn($reasons, $state='idle', $extra='') => ['return_code'=>0, 'stdout'=>"printer-state (enum) = $state\nprinter-state-reasons (1setOf keyword) = $reasons\n$extra", 'stderr'=>''];
foreach (['media-empty-error','media-jam-error','toner-empty-error','marker-supply-empty-error','door-open-error','cover-open-error','offline-report','fuser-over-temp-error','service-request-error','developer-empty','opc-life-over','paused','unknown'] as $reason) {
    $result = $health->interpret($ipp($reason, 'idle', 'media-ready (keyword) = iso_a4_210x297mm'));
    healthAssert($result['verified'] && !$result['can_print'] && $result['reason'] !== '', "Falha física foi liberada: $reason");
}
$trayReport = $health->interpret($ipp('input-tray-missing-report'));
healthAssert($trayReport['can_print'], 'Um relatório de bandeja opcional bloqueou uma impressora pronta.');
$ready=$health->interpret($ipp('none','idle',"marker-names (nameWithoutLanguage) = Black Toner\nmarker-levels (integer) = 80\nprinter-state-reasons-supported (keyword) = media-empty-error,toner-empty-error"));
healthAssert($ready['verified'] && $ready['can_print'] && $ready['reason']==='', 'Nomes/capacidades foram tratados como falha.');
healthAssert(!$health->interpret($ipp('none','stopped'))['can_print'], 'Equipamento parado foi liberado.');
$low=$health->interpret($ipp('toner-low-warning'));
healthAssert($low['can_print'] && $low['reason']!=='', 'Suprimento baixo deve avisar sem bloquear impressão possível.');
healthAssert(!$health->interpret(['return_code'=>1,'stdout'=>'','stderr'=>'Connection refused'])['verified'], 'Erro de IPP foi confirmado como saudável.');
healthAssert(!$health->interpret(['return_code'=>0,'stdout'=>'HTTP OK','stderr'=>''])['verified'], 'Resposta sem estado foi confirmada como saudável.');

$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
healthAssert($server!==false,'Socket de teste indisponível.');
$address=stream_socket_get_name($server,false);
healthAssert($health->probe('socket://'.$address)['reachable']===true,'Impressora acessível foi tratada como desligada.');
fclose($server);
healthAssert($health->probe('socket://'.$address)['reachable']===false,'Impressora desligada não foi detectada.');

class PreflightHealthFixture extends CupsService {
    public $fixture;
    public function diagnostics($attemptAutoEnable=false) {return $this->fixture;}
}
$file=tempnam(sys_get_temp_dir(),'preflight-');file_put_contents($file,'fixture');
try {
    $cups=new PreflightHealthFixture('fixture');
    foreach (['offline','falta de papel','atolamento de papel','toner ou suprimento','tampa aberta','estado não confirmado','erro de comunicação'] as $reason) {
        $cups->fixture=['can_print'=>false,'reason'=>$reason,'notice'=>'Bloqueado: '.$reason,'cups_active'=>true,'printer_exists'=>true,'enabled'=>true,'accepting'=>true];
        healthAssert(!$cups->preflight($file)['ok'], 'Servidor aceitou envio bloqueado: '.$reason);
    }
    $cups->fixture=['can_print'=>true,'reason'=>'','cups_active'=>true,'printer_exists'=>true,'enabled'=>true,'accepting'=>true];
    healthAssert($cups->preflight($file)['ok'],'Servidor bloqueou impressora saudável.');
} finally {unlink($file);}
// Exercise actual diagnostics assembly while substituting only external command responses.
class DiagnosticsHealthFixture extends CupsService {
    public $uri;
    public $attributes;
    public $enabled = true;
    public $acceptTimeout = false;
    public $printerTimeout = false;
    public $printing = false;
    public $queueMessage = '';
    public $enableCalls = 0;
    public $faultAfterEnable = false;
    public function run($command, $timeoutSeconds = 10) {
        if (str_contains($command, 'ipptool')) return $this->attributes;
        $stdout = '';
        if (str_contains($command, ' -v ')) $stdout = 'device for fixture: ' . $this->uri;
        elseif (str_contains($command, ' -a ') && $this->acceptTimeout) return ['return_code'=>124,'stdout'=>'','stderr'=>'timed out'];
        elseif (str_contains($command, ' -a ')) $stdout = 'fixture accepting requests since Mon';
        elseif (str_contains($command, ' -p ') && $this->printerTimeout) return ['return_code'=>124,'stdout'=>'','stderr'=>'timed out'];
        elseif (str_contains($command, ' -p ') && $this->printing) $stdout = 'printer fixture now printing fixture-42.  enabled since Mon';
        elseif (str_contains($command, ' -p ')) $stdout = 'printer fixture is ' . ($this->enabled ? 'idle. enabled' : 'disabled') . " since Mon
" . $this->queueMessage;
        return ['return_code'=>0,'stdout'=>$stdout,'stderr'=>''];
    }
    public function enablePrinter($source='manual') {
        $this->enableCalls++;
        $this->enabled = true;
        if ($this->faultAfterEnable) $this->attributes = ['return_code'=>0,'stdout'=>"printer-state (enum) = stopped\nprinter-state-reasons (keyword) = media-jam-error",'stderr'=>''];
        return ['success'=>true];
    }
}
$socketContext = stream_context_create(['socket' => ['backlog' => 128]]);
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $socketContext);
$logDir=sys_get_temp_dir().'/health-log-'.bin2hex(random_bytes(6));mkdir($logDir,0700);
$_ENV['LOG_PATH']=$logDir.'/app.log';$_ENV['CUPS_AUTO_ENABLE']='1';
$diag=new DiagnosticsHealthFixture('fixture');
$diag->uri='socket://'.stream_socket_get_name($server,false);
$diag->attributes=$ipp('none','idle','marker-names (nameWithoutLanguage) = Black Toner');
$healthy=$diag->diagnostics();
healthAssert($healthy['can_print']===true,'Diagnóstico saudável foi bloqueado.');
$diag->attributes = $ipp('none', 'processing');
$diag->printing = true;
$busy = $diag->diagnostics();
healthAssert($busy['queue_verified'] && $busy['can_print'] && $busy['printer_state'] === 'printing',
    'Fila CUPS ativa durante uma impressão foi tratada como indisponível: ' . $busy['notice']);
$diag->attributes = $ipp('media-jam-error', 'processing');
$busyWithFault = $diag->diagnostics();
healthAssert(!$busyWithFault['can_print'] && !$busyWithFault['availability_unknown'] && $busyWithFault['reason'] === 'atolamento de papel',
    'Fila ocupada mascarou um atolamento confirmado pela impressora.');
$diag->printing = false;
$diag->attributes = $ipp('none');
$diag->acceptTimeout=true;
$unknownQueue = $diag->diagnostics();
healthAssert(!$unknownQueue['can_print'] && $unknownQueue['availability_unknown'] && $unknownQueue['notice_type'] === 'info',
    'Falha de consulta deve pausar novos envios e informar que o estado ainda é desconhecido.');
$diag->acceptTimeout=false;
$diag->printerTimeout = true;
$initialTimeout = $diag->diagnostics();
healthAssert(!$initialTimeout['can_print'] && $initialTimeout['availability_unknown']
    && $initialTimeout['cups_active'] === null && $initialTimeout['notice_type'] === 'info',
    'Timeout de lpstat não comprova que o CUPS parou.');
$diag->printerTimeout = false;
foreach (['media-empty-error','media-jam-error','toner-empty-error','door-open-error'] as $reason) {
    $diag->attributes=$ipp($reason,'idle','media-ready (keyword) = iso_a4_210x297mm');
    $status=$diag->diagnostics(true);
    healthAssert(!$status['can_print'],'Fila idle mascarou falha IPP: '.$reason);
}
$traySignals = [
    $ipp('input-tray-missing-error', 'stopped'),
    $ipp('media-tray-open-error', 'stopped'),
    $ipp('other-error', 'stopped', 'printer-state-message (textWithoutLanguage) = Cassette 1 is open'),
    $ipp('other-error', 'stopped', 'printer-alert (octetString) = code=inputMediaTrayMissing;severity=critical'),
    $ipp('other-error', 'stopped', 'printer-alert-description (textWithoutLanguage) = Feche a bandeja de papel'),
];
foreach ($traySignals as $attributes) {
    $diag->attributes = $attributes;
    $status = $diag->diagnostics();
    healthAssert(!$status['can_print'] && $status['reason'] === 'bandeja de papel aberta'
        && str_contains($status['notice'], 'Feche e encaixe'), 'Diagnóstico da bandeja não chegou ao aviso: ' . $attributes['stdout']);
}
$diag->attributes = $ipp('other-error', 'stopped');
$diag->queueMessage = 'printer-state-message=Paper tray 2 open';
healthAssert($diag->diagnostics()['reason'] === 'bandeja de papel aberta', 'Estado genérico do IPP mascarou o detalhe disponível no CUPS.');
$diag->queueMessage = '';
$notices = [
    ['output-area-full-error', 'stopped', false, 'bandeja de saída está cheia'],
    ['output-area-almost-full-warning', 'idle', true, 'bandeja de saída está quase cheia'],
    ['output-tray-missing-error', 'stopped', false, 'bandeja de saída está ausente'],
    ['media-low-warning', 'idle', true, 'nível de papel está baixo'],
    ['marker-waste-full-error', 'stopped', false, 'coletor de resíduos está cheio'],
    ['fuser-over-temp-error', 'stopped', false, 'fusor está acima da temperatura'],
    ['fuser-under-temp-error', 'stopped', false, 'fusor está abaixo da temperatura'],
    ['interlock-open-error', 'stopped', false, 'trava de segurança está aberta'],
    ['spool-area-full-error', 'stopped', false, 'memória da fila da impressora está cheia'],
    ['service-request-error', 'stopped', false, 'solicitou manutenção'],
    ['shutdown-error', 'stopped', false, 'está desligando'],
    ['developer-empty-error', 'stopped', false, 'unidade reveladora está vazia'],
    ['opc-life-over-error', 'stopped', false, 'unidade de imagem atingiu o fim'],
    ['paused', 'stopped', false, 'impressão foi pausada no equipamento'],
    ['processing-to-stop-point', 'processing', false, 'está interrompendo o trabalho atual'],
    ['moving-to-paused', 'processing', false, 'está entrando em pausa'],
    ['stopped-partly-error', 'stopped', false, 'Parte do equipamento está parada'],
    ['interpreter-resource-unavailable-error', 'stopped', false, 'precisa de um recurso para processar'],
    ['connecting-to-device', 'processing', false, 'tentando conectar-se ao equipamento'],
    ['output-area-full-report,vendor-feed-sensor-error', 'stopped', false, 'vendor-feed-sensor-error'],
    ['vendor-feed-sensor-warning', 'idle', true, 'vendor-feed-sensor-warning'],
];
foreach ($notices as [$code, $state, $canPrint, $copy]) {
    $diag->attributes = $ipp($code, $state);
    $status = $diag->diagnostics();
    healthAssert($status['can_print'] === $canPrint && str_contains($status['notice'], $copy), 'Aviso incorreto para ' . $code . ': ' . $status['notice']);
}
$diag->attributes = $ipp('other-error', 'stopped');
$diag->queueMessage = 'printer-state-reasons=output-area-full-error';
healthAssert(str_contains($diag->diagnostics()['notice'], 'bandeja de saída está cheia'), 'A causa informada pela fila foi mascarada por uma parada genérica.');
$diag->queueMessage = '';
$diag->enabled = false;
$diag->attributes = $ipp('other-error', 'stopped', 'printer-state-message (textWithoutLanguage) = Erro E150: verifique a unidade de imagem');
healthAssert(str_contains($diag->diagnostics()['notice'], 'Erro E150'), 'A desativação da fila mascarou a falha informada pela impressora.');
$diag->enabled = true;
$diag->attributes = $ipp('other-error', 'stopped', 'printer-state-message (textWithoutLanguage) = Erro E042: manutenção necessária');
healthAssert(str_contains($diag->diagnostics()['notice'], 'Erro E042: manutenção necessária'), 'Mensagem específica do equipamento foi perdida.');
$diag->attributes = $ipp('other-error', 'stopped', "printer-state-message (textWithoutLanguage) = Stopped
printer-alert-description (textWithoutLanguage) = Erro E049: confira a unidade de imagem");
healthAssert(str_contains($diag->diagnostics()['notice'], 'Erro E049'), 'Mensagem genérica mascarou a descrição específica do alerta.');
$diag->attributes = $ipp('other-error', 'stopped');
healthAssert(str_contains($diag->diagnostics()['notice'], 'sem detalhar a causa'), 'Aviso deve indicar que o equipamento não detalhou a falha.');
$diag->attributes=$ipp('none','idle','printer-is-accepting-jobs (boolean) = false');
healthAssert(!$diag->diagnostics()['can_print'],'Recusa do equipamento foi liberada.');
$diag->enabled=false;$diag->attributes=$ipp('media-jam-error');
healthAssert(!$diag->diagnostics(true)['can_print'] && $diag->enableCalls===0,'Reativação indevida durante falha física.');
$diag->attributes=$ipp('none');$diag->faultAfterEnable=true;
try {
    healthAssert(!$diag->diagnostics(true)['can_print'] && $diag->enableCalls===1,'Reativação da fila apagou falha física posterior.');
    $diag->attributes=['return_code'=>1,'stdout'=>'','stderr'=>'timed out'];
    healthAssert(!$diag->diagnostics()['can_print'],'Falha na consulta física foi liberada.');
    if (PHP_OS_FAMILY !== 'Windows') {
        $cups = new CupsService('fixture');
        $start = microtime(true);
        $timeoutResult = $cups->run('sleep 10', 1);
        healthAssert($timeoutResult['return_code'] === 124 && microtime(true)-$start < 3, 'Consulta externa não respeitou timeout.');
    }
} finally {foreach(glob($logDir.'/*') as $f)unlink($f);rmdir($logDir);fclose($server);}
echo "Estado físico: IPP, avisos, transporte ligado/desligado e bloqueio no servidor: OK\n";
