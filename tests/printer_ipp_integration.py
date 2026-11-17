"""Local IPP equipment fixture: Get-Printer-Attributes only, never Print-Job.
Run with activated PHP/CUPS environment: python3 tests/printer_ipp_integration.py
"""
import http.server
import struct
import subprocess
import tempfile
import threading
from pathlib import Path


def attribute(tag, name, value):
    name = name.encode()
    return bytes([tag]) + struct.pack('!H', len(name)) + name + struct.pack('!H', len(value)) + value


class Equipment(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    reason = 'none'

    def log_message(self, *args):
        pass

    def do_POST(self):
        if self.headers.get('Transfer-Encoding', '').lower() == 'chunked':
            request = b''
            while True:
                size = int(self.rfile.readline().strip(), 16)
                if size == 0:
                    self.rfile.readline()
                    break
                request += self.rfile.read(size)
                self.rfile.read(2)
        else:
            request = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        assert request[2:4] == b'\x00\x0b', 'Only status requests are permitted'
        body = request[:2] + b'\x00\x00' + request[4:8] + b'\x01'
        body += attribute(0x47, 'attributes-charset', b'utf-8')
        body += attribute(0x48, 'attributes-natural-language', b'en') + b'\x04'
        body += attribute(0x23, 'printer-state', struct.pack('!I', 3))
        body += attribute(0x44, 'printer-state-reasons', self.reason.encode())
        body += attribute(0x22, 'printer-is-accepting-jobs', b'\x01') + b'\x03'
        self.send_response(200)
        self.send_header('Content-Type', 'application/ipp')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)


PHP = r'''
require 'src/Service/CupsService.php';
$_ENV['LOG_PATH']=$argv[2];
class RealIppFixture extends CupsService {
 public $uri;
 public function run($command,$timeoutSeconds=10) {
  if(str_contains($command,' -v ')) return ['return_code'=>0,'stdout'=>'device for fixture: '.$this->uri,'stderr'=>''];
  return parent::run($command,$timeoutSeconds);
 }
}
$cups=new RealIppFixture('fixture');$cups->uri=$argv[1];
$method=new ReflectionMethod(CupsService::class,'queryPrinterIppAttributes');$method->setAccessible(true);
$result=$method->invoke($cups,'/usr/bin/lpstat');
$health=(new PrinterHealthService())->interpret($result['attributes']);
if(!$health['verified'] || $health['can_print']!==($argv[3]==='none') || $health['reason']!==$argv[4]) {
 throw new RuntimeException(json_encode([$result,$health]));
}
'''

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Equipment)
threading.Thread(target=server.serve_forever, daemon=True).start()
try:
    with tempfile.TemporaryDirectory(prefix='ipp-health-') as work:
        for reason, expected in [('none', ''), ('media-jam-error', 'atolamento de papel'),
                                 ('media-empty-error', 'falta de papel'), ('toner-empty', 'toner ou suprimento')]:
            Equipment.reason = reason
            subprocess.run(['php', '-r', PHP, f'ipp://127.0.0.1:{server.server_port}/ipp/print',
                            work + '/test.log', reason, expected],
                           cwd=Path(__file__).resolve().parent.parent, check=True, timeout=15)
    print('IPP real local + ipptool + parser: pronta, atolamento, papel e toner: OK')
finally:
    server.shutdown()
    server.server_close()
