# Prévia automática quando o PDF.js falha

Goal: o professor continua vendo a prévia automaticamente, sem precisar abrir uma aba, mesmo se módulos/workers/renderização do PDF falharem no celular.

- [x] Reproduzir o fallback atual com módulo bloqueado e exigir canvas automático com conteúdo real.
- [x] Permitir leitura autenticada de um PDF de prévia já gerado por chave opaca; rejeitar outras sessões/usuários, caminhos e páginas fora do PDF.
- [x] Rasterizar somente a página solicitada em PNG (Poppler ou Ghostscript), com cache temporário, dimensões limitadas e timeout. Nunca alterar PDF ou impressão física.
- [x] Usar essas imagens automaticamente apenas quando PDF.js falha; manter navegação, proporção, ampliação, cancelamento e limpeza. Abertura nativa fica como último recurso se ambos falharem.
- [x] Validar módulo bloqueado, PNG real e navegação, cache, autenticação/CSRF/páginas inválidas e regressões existentes; enviar para main.

Não foi possível consultar os assets do servidor de produção: a rede do ambiente devolveu 403 Domain forbidden. O erro exato do PDF.js em Safari não foi observado; a correção fornece um caminho automático independente dele. Nenhuma afirmação de teste em Safari real.

Validação concluída: scripts PHP, estimador Node (4 casos), fluxo Chromium com PNG real e PDF.js bloqueado, falha na segunda página, navegação, larguras 320/390/844px, remoção, cache, páginas inválidas, CSRF, sessão anônima, isolamento de usuário/sessão e symlink. Rasterização Poppler e fallback Ghostscript preservam geometria; pdfinfo lento respeita timeout e usa contagem nativa. Lint PHP/JS, auditoria visual estática estrita e git diff --check passaram. Revisão independente motivou limites também na contagem de páginas e fallback durante navegação. O carregamento alternativo de página tem recurso separado do carregamento completo, para permitir cancelamento de ambos quando há sobreposição. Artefatos do fluxo: /tmp/teacher-workflow-Ob13RA (temporários do ambiente).
