# Sistema de Impressão

## Requisitos
- PHP 8+ com extensão `sqlite3` habilitada
- Linux: `lp`/CUPS, `libreoffice`, fontes compatíveis com Microsoft Office e ImageMagick recomendado para imagens/PNG
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
LOG_PATH=/var/www/impressao/storage/logs/app.log
APP_TIMEZONE=America/Cuiaba
LIBREOFFICE_PATH=C:\Program Files\LibreOffice\program\soffice.exe
SUMATRA_PATH=C:\Program Files\SumatraPDF\SumatraPDF.exe
IMAGEMAGICK_PATH=/usr/bin/convert
PRINT_JOB_WAIT_SECONDS=120
```

## Auditoria e diagnóstico de impressão
- Cada tentativa fica registrada em `print_jobs`, inclusive falhas de pré-validação, envio inválido, erro no CUPS, tempo esgotado e falha no `lp`.
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

## Simulações locais
```bash
php tests/print_diagnostics_simulation.php
```

## Limite de envio no nginx/PHP
Se aparecer `413 Request Entity Too Large`, o nginx bloqueou o arquivo antes de chegar ao PHP. Ajuste o site do nginx:

```nginx
server {
    client_max_body_size 25M;
}
```

Depois recarregue:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

O PHP também precisa permitir o mesmo tamanho ou maior:

```ini
upload_max_filesize = 25M
post_max_size = 25M
```

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

