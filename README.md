# Sistema de Impressão

## Requisitos
- PHP 8+ com extensão `sqlite3` habilitada
- Linux: `lp`/CUPS, `libreoffice`, fontes compatíveis com Microsoft Office e ImageMagick recomendado para imagens grandes
- Windows: SumatraPDF para enviar PDFs à impressora e LibreOffice para DOC/DOCX
- Permissão de escrita em `storage/`

## Deploy (Apache)
1. Aponte o DocumentRoot para `public/` (recomendado) **ou** mantenha o DocumentRoot na raiz e use o `.htaccess` da raiz.
2. Garanta que `mod_rewrite` esteja habilitado.

## Primeira execução
- Acesse `/setup` para criar o primeiro usuário `admin`.
- Depois faça login em `/login` e crie os demais usuários em `/admin/users`.

## Configuração
Você pode configurar via `config/config.php` ou criando um arquivo `.env` na raiz do projeto:

```env
PRINTER_NAME=kyocera-escola
UPLOAD_PATH=/var/www/impressao/storage/uploads
LOG_PATH=/var/www/impressao/storage/logs/app.log
LIBREOFFICE_PATH=C:\Program Files\LibreOffice\program\soffice.exe
SUMATRA_PATH=C:\Program Files\SumatraPDF\SumatraPDF.exe
IMAGEMAGICK_PATH=/usr/bin/convert
```

## Diagnóstico de DOC/DOCX no Linux
- DOC/DOCX são convertidos para PDF pelo LibreOffice antes de ir para o CUPS.
- O sistema salva uma cópia do PDF convertido em `storage/print-debug/` para comparar se o problema aconteceu na conversão ou só na impressão.
- Se texto, tabelas ou paginação mudarem, confira o log: ele registra fontes solicitadas no DOCX e a fonte que o Linux resolveu via `fc-match`. Fontes ausentes são a causa mais comum de quebra de formatação.
- Na conversão, o sistema cria aliases temporários para fontes comuns do Word, como Calibri→Carlito e Cambria→Caladea. Mesmo assim, essas fontes precisam estar instaladas no servidor para o resultado ficar mais fiel.
- Em Debian/Ubuntu, normalmente ajuda instalar pacotes como `fonts-liberation`, `fonts-crosextra-carlito`, `fonts-crosextra-caladea` e, quando licenciado/disponível, fontes Microsoft compatíveis.

