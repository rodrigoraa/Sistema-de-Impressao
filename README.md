# Sistema de Impressão

## Requisitos
- PHP 8+ com extensão `sqlite3` habilitada
- (para imprimir) `lp` (CUPS), `libreoffice` (DOCX→PDF) e `convert` (ImageMagick)
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
```

