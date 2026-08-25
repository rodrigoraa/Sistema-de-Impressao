# Sistema de Impressão

## Requisitos
- PHP 8+ com extensão `sqlite3` habilitada
- Linux: `lp`/CUPS com `cupsfilter`, `libreoffice`, fontes compatíveis com Microsoft Office e ImageMagick recomendado para imagens/PNG
- Windows: SumatraPDF para enviar PDFs à impressora e LibreOffice para DOC/DOCX
- Permissão de escrita em `storage/`

## Deploy (Apache)
1. Aponte o DocumentRoot para `public/` (recomendado) **ou** mantenha o DocumentRoot na raiz e use o `.htaccess` da raiz.
2. Garanta que `mod_rewrite` esteja habilitado.

## Primeira execução
- Acesse `/setup` para criar o primeiro usuário administrador.
- Depois faça login em `/login` e crie os demais usuários em `/admin/users`.

## Configuração
Você pode configurar via `config/config.php` ou criando um arquivo `.env` na raiz do projeto:

```env
PRINTER_NAME=kyocera-escola
UPLOAD_PATH=/var/www/impressao/storage/uploads
SHARE_TARGET_PATH=/var/www/impressao/storage/share-target
PRINT_TEMP_PATH=/var/www/impressao/storage/print-temp
MAX_UPLOAD_BYTES=104857600
PRINT_PREVIEW_MAX_SHEETS=3
PRINT_PREVIEW_TTL_SECONDS=1800
PRINT_PREVIEW_TIMEOUT_SECONDS=30
CUPSFILTER_PATH=/usr/sbin/cupsfilter
LOG_PATH=/var/www/impressao/storage/logs/app.log
APP_TIMEZONE=America/Cuiaba
LIBREOFFICE_PATH=C:\Program Files\LibreOffice\program\soffice.exe
SUMATRA_PATH=C:\Program Files\SumatraPDF\SumatraPDF.exe
IMAGEMAGICK_PATH=/usr/bin/convert
PRINT_JOB_WAIT_FOR_COMPLETION=0
PRINT_JOB_WAIT_SECONDS=120
PRINT_JOB_AFTER_QUEUE_MONITOR_SECONDS=45
PRINT_JOB_IDLE_CONFIRM_SECONDS=6
```

## Pré-visualização fiel da impressão

O arquivo é enviado uma única vez para uma pasta temporária fora de `public/`. O navegador recebe somente um token aleatório associado ao usuário e à sessão; contagem de páginas, mudanças de configuração e prévias seguintes usam esse token, sem reenviar o arquivo completo. O token não contém caminho de arquivo e expira por padrão em 30 minutos.

A prévia é um PDF de amostra montado pela pilha de filtros do CUPS com a mesma ordem `number-up-layout=lrtb` usada pela impressão. Ela respeita 1, 2, 4 ou 8 páginas por folha e processa, por padrão, no máximo as primeiras 3 folhas. Seleções como `1,3-5` são aplicadas antes do N-up. A interface informa páginas do documento, lados impressos por cópia e quantas folhas da amostra estão visíveis.

DOC/DOCX, TXT e imagens reutilizam o PDF convertido enquanto papel, orientação e margens de conversão permanecerem iguais. Os arquivos ficam em `PRINT_TEMP_PATH`, possuem nomes imprevisíveis, são removidos após a impressão ou por TTL e também podem ser limpos no painel administrativo. Nenhum arquivo temporário fica em sessão ou em diretório público.

A prévia é assíncrona e não altera o documento da impressão física. A impressão continua enviando o PDF preparado ao CUPS e aplicando `number-up` somente no comando `lp`; portanto não há dupla aplicação de N-up. Se a prévia falhar ou atingir o timeout, o usuário recebe um aviso amigável e ainda pode imprimir depois que upload e validações obrigatórias terminarem.

Não foi introduzida biblioteca ou framework novo. `cupsfilter` pertence ao pacote `cups`, que já é requisito do servidor. Para limitar PDFs grandes às páginas da amostra, o sistema usa o primeiro mecanismo disponível entre `qpdf`, Ghostscript e `pdfseparate`/`pdfunite` do Poppler.

Verificação recomendada no Ubuntu:

```bash
command -v lp cupsfilter libreoffice pdfinfo pdfseparate pdfunite
command -v qpdf || command -v gs
sudo apt update
sudo apt install cups libreoffice poppler-utils qpdf
sudo install -d -o www-data -g www-data -m 0700 /var/www/impressao/storage/print-temp
```

`qpdf` pode ser substituído por `ghostscript`; não é necessário instalar ambos. Depois do deploy, valide uma amostra sem enviar trabalho físico:

```bash
sudo -u www-data /usr/sbin/cupsfilter -i application/pdf -m application/vnd.cups-pdf -d kyocera-escola -o number-up=2 -o number-up-layout=lrtb arquivo-teste.pdf > /tmp/preview-teste.pdf
pdfinfo /tmp/preview-teste.pdf
```

## Compartilhar arquivos direto do WhatsApp, iPhone e Android

O sistema também funciona como PWA e pode receber arquivos pelo menu "Compartilhar" de outros aplicativos, como WhatsApp, visualizador de PDF, galeria e gerenciadores de arquivo.

Para esse fluxo funcionar corretamente:

- O site precisa estar publicado com HTTPS.
- O usuário precisa instalar o sistema como PWA na tela inicial do celular.
- No iPhone, abra o site pelo Safari, toque em "Compartilhar" e depois em "Adicionar à Tela de Início".
- O compartilhamento direto só funciona corretamente quando o sistema está instalado como PWA.
- O suporte pode variar conforme a versão do iOS/Safari.
- Caso o PWA não apareça no menu de compartilhamento do iPhone, use o upload manual do sistema como alternativa.

Formatos aceitos no compartilhamento:

- PDF: `application/pdf`
- DOC: `application/msword`
- DOCX: `application/vnd.openxmlformats-officedocument.wordprocessingml.document`
- PNG: `image/png`
- JPG/JPEG: `image/jpeg`
- WEBP: `image/webp`
- TXT: `text/plain`

O arquivo compartilhado é salvo primeiro em `storage/share-target/`, fora da pasta pública. Ele abre uma tela de confirmação antes de imprimir. A impressão não é feita automaticamente ao receber o compartilhamento. Ao confirmar, o sistema move o arquivo para o fluxo normal de upload/impressão; ao cancelar, remove o arquivo temporário.

Teste manual no Android:

1. Abrir o site no Chrome.
2. Instalar o PWA.
3. Abrir um PDF ou imagem no WhatsApp.
4. Tocar em compartilhar.
5. Escolher o Sistema de Impressão.
6. Confirmar se a tela de confirmação abre com o arquivo anexado.

Teste manual no iPhone:

1. Abrir o site no Safari.
2. Tocar em compartilhar.
3. Escolher "Adicionar à Tela de Início".
4. Abrir um arquivo no WhatsApp.
5. Tocar em compartilhar.
6. Verificar se o PWA aparece como opção.
7. Caso apareça, enviar o arquivo para o sistema.
8. Caso não apareça, documentar que depende do suporte do iOS e usar upload manual.

## Auditoria e diagnóstico de impressão
- Cada tentativa fica registrada em `print_jobs`, inclusive falhas de pré-validação, envio inválido, erro no CUPS, tempo esgotado e falha no `lp`.
- Por padrão, `PRINT_JOB_WAIT_FOR_COMPLETION=0` libera a tela assim que o CUPS aceita o trabalho, sem esperar a impressão física terminar.
- Se `PRINT_JOB_WAIT_FOR_COMPLETION=1`, depois que um trabalho sai da fila do CUPS, o sistema ainda monitora a impressora por `PRINT_JOB_AFTER_QUEUE_MONITOR_SECONDS` segundos para capturar falta de papel, atolamento, tampa aberta, offline ou suprimento antes de marcar a impressão como concluída.
- O painel administrativo mostra impressora ativada/desativada, aceitando/recusando impressões, estado da impressora, mensagem atual, trabalhos pendentes/concluídos/cancelados/falhos e o último diagnóstico conhecido.
- O acumulado mensal considera somente trabalhos concluídos marcados para entrar no acumulado; falhas não entram no total.
- O relatório PDF mensal fica em `/admin/report/pdf` e aceita filtros por mês, CPF e inclusão/ocultação de falhas.

## Comandos Linux úteis
```bash
lpstat -p kyocera-escola
lpstat -a kyocera-escola
lpstat -l -p kyocera-escola
lpstat -o kyocera-escola
lpstat -W completed -o kyocera-escola
sudo cupsenable kyocera-escola
sudo cupsaccept kyocera-escola
sudo cupsdisable kyocera-escola
sudo cupsreject kyocera-escola
sudo systemctl status cups
```

## Monitoramento automático da impressora
O script `scripts/printer_health_check.php` pode rodar em segundo plano para consultar CUPS mesmo quando nenhum professor está usando a tela. Ele detecta sem papel, atolamento, impressora desativada/offline e também tenta `cupsenable`/`cupsaccept` quando a reativação automática estiver permitida.

Teste manual:

```bash
cd /var/www/impressao
php scripts/printer_health_check.php
```

Cron simples, a cada minuto:

```cron
* * * * * cd /var/www/impressao && /usr/bin/php scripts/printer_health_check.php >> storage/logs/printer-health-cron.log 2>&1
```

Se precisar verificar mais rápido que 1 minuto, use um timer systemd em vez de cron:

```ini
# /etc/systemd/system/impressao-printer-health.service
[Unit]
Description=Monitor da impressora do sistema de impressao

[Service]
Type=oneshot
WorkingDirectory=/var/www/impressao
ExecStart=/usr/bin/php /var/www/impressao/scripts/printer_health_check.php
```

```ini
# /etc/systemd/system/impressao-printer-health.timer
[Unit]
Description=Executa o monitor da impressora a cada 30 segundos

[Timer]
OnBootSec=30
OnUnitActiveSec=30
AccuracySec=1
Unit=impressao-printer-health.service

[Install]
WantedBy=timers.target
```

Ativar:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now impressao-printer-health.timer
systemctl list-timers impressao-printer-health.timer
```

## Simulações locais
```bash
php tests/print_diagnostics_simulation.php
php tests/security_regression.php
php tests/print_preview_regression.php
php tests/temporary_print_storage_regression.php
```

## Limite de envio no nginx/PHP
Se aparecer `413 Request Entity Too Large`, o nginx bloqueou o arquivo antes de chegar ao PHP. Ajuste o site do nginx:

```nginx
server {
    client_max_body_size 110M;
}
```

Depois recarregue:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

O PHP também precisa permitir tamanho maior que o limite interno do sistema. O projeto limita o envio a 100 MB em `MAX_UPLOAD_BYTES`, então deixe o PHP um pouco acima disso:

```ini
upload_max_filesize = 105M
post_max_size = 110M
max_input_time = 300
memory_limit = 256M
```

O arquivo `public/.user.ini` já traz esses valores para servidores com PHP-FPM/CGI que leem `.user.ini`. Em alguns servidores é preciso ajustar o `php.ini` ou o pool do PHP-FPM e reiniciar o serviço:

```bash
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx
```

Se o PWA abrir, mas aparecer "Arquivo não enviado" ou "o PHP descartou o arquivo", o POST chegou ao site, porém o PHP descartou o corpo antes de preencher `$_FILES`. Confira `upload_max_filesize`, `post_max_size`, `client_max_body_size` e o log configurado em `LOG_PATH`.

Se a mensagem informar um tamanho muito pequeno, por exemplo `502 B`, o limite do servidor provavelmente não é o problema. Nesse caso o Android abriu o PWA, mas o aplicativo de origem enviou apenas metadados/texto do compartilhamento, não o arquivo. No WhatsApp, abra o documento primeiro e use a opção de compartilhar/enviar o arquivo; se o anexo não vier, compartilhe o mesmo arquivo pelo app Arquivos/Downloads ou use o upload manual.

## Diagnóstico de DOC/DOCX no Linux
- DOC/DOCX são convertidos para PDF pelo LibreOffice antes de ir para o CUPS.
- O sistema salva uma cópia do PDF convertido em `storage/print-debug/` para comparar se o problema aconteceu na conversão ou só na impressão.
- Se texto, tabelas ou paginação mudarem, confira o log: ele registra fontes solicitadas no DOCX e a fonte que o Linux resolveu via `fc-match`. Fontes ausentes são a causa mais comum de quebra de formatação.
- Na conversão, o sistema cria aliases temporários para fontes comuns do Word, como Aptos→Carlito/Liberation Sans, Calibri→Carlito e Cambria→Caladea. Mesmo assim, essas fontes precisam estar instaladas no servidor para o resultado ficar mais fiel.
- Em Debian/Ubuntu, normalmente ajuda instalar pacotes como `fonts-liberation`, `fonts-crosextra-carlito`, `fonts-crosextra-caladea` e, quando licenciado/disponível, fontes Microsoft compatíveis.
- Para documentos do Word muito cheios, a paginação só fica fiel se o LibreOffice encontrar as mesmas fontes do DOCX. Você pode criar `storage/fonts/` e colocar ali arquivos `.ttf/.otf` como Aptos, Aptos Display, Times New Roman e Calibri. Também é possível apontar outras pastas com `OFFICE_FONT_PATHS=/caminho/fontes1:/caminho/fontes2` no `.env`.

## PNG no Linux
- Para imprimir PNG de forma confiável, instale ImageMagick (`imagemagick`) ou habilite a extensão PHP `gd`.
- Sem ImageMagick/GD, o sistema retorna erro claro em vez de tentar conversão lenta em PHP puro.

