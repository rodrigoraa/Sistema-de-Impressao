# Contrato de uso

Estilo e tokens: DESIGN.md.

| Capability | Canonical owner | Source of truth | Allowed variants | Verification |
| --- | --- | --- | --- | --- |
| Select/Listbox | select nativo | views/print.php | opções do sistema operacional | Chromium desktop/mobile |
| Date | input month nativo | views/print_jobs.php | seletor do sistema operacional | filtro por mês |
| Dialog | Bootstrap Modal | views/print_jobs.php | confirmar reimpressão/cancelamento | foco, Escape, retorno |
| Feedback | alert inline e role=status | views/print.php | sucesso/erro/andamento | teste navegador |
| Form | HTML + validação inline | views/print.php | servidor valida novamente | erros e envio duplicado |
| Scrollbar | regras globais | public/css/base.css | forced-colors usa padrão nativo | auditoria CSS |

- Nova impressão só envia após upload validado; não imprimir por compartilhar ou reaproveitar.
- Reimpressão imediata existente mantém suas regras; reaproveitamento revisável é ação distinta.
- Não substituir falha ambígua de envio por incentivo a repetir. Mostrar acesso ao histórico e bloquear repetição na mesma tela.
- Estados: sem arquivo, preparando, pronto, enviando, enviado, erro recuperável e resultado incerto.
- Busca por nome via URL; manter outros filtros, propriedade e limite de resultados. Mostrar limite de 150 registros.
- Sem novas permissões ou mudanças de retenção. Nada de nomes de documentos/tokens em localStorage.

Prévia PDF: o proprietário é `public/js/print-preview.js`, com PDF.js local e controles nativos. Renderizar uma folha inteira por vez, preservar proporção e ajustar a largura. Descartar workers/renders de documentos antigos; não reinstalar uma resposta obsoleta. Link de ampliar usa somente blob local com rel=noopener. Não inserir PDF nativo em iframe.
