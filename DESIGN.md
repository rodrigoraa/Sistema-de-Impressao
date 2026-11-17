# Interface institucional de impressão

Professores precisam enviar atividades com poucas decisões e conferir o resultado antes de imprimir. Manter a identidade atual, sem nova marca ou framework.

Bootstrap 5.3.0 e Bootstrap Icons 1.11.1 são mantidos localmente em `public/css/vendor` e `public/js/vendor`, com licenças MIT incluídas. Todas as telas usam essas mesmas cópias para evitar dependência da CDN. Não modificar os arquivos minificados manualmente.

## Tokens e fonte de verdade
`public/css/base.css`: fundo --app-bg #f3f6fa, superfície --app-surface #fff, texto --app-ink #172033, marca --app-brand #155e75, cabeçalho --app-brand-dark #0f3f4f, sucesso --app-success #15803d e erro --app-danger #b42318. Tipografia existente Inter/Segoe UI/Roboto/Arial; títulos 1.6rem/750, corpo 1rem, ajuda .875rem. Cantos 8px, espaçamento 8/12/16/20/24px. `public/css/print.css` possui somente composição específica da impressão. Nenhuma fonte nova.

## Composição e comportamento
Arquivo e escolhas à esquerda; revisão e prévia à direita no desktop, após as escolhas no celular. A assinatura funcional é o resumo de folhas físicas com cópias explícitas. Qualidade, escala, orientação e papel em disclosure nativo. Botão de envio fica junto do resumo, sem cobrir conteúdo em telas estreitas. Preservar rolagem natural.

Botões com texto e foco visível, alvos de 44px. Selects e mês nativos: aceitamos a interface do sistema operacional. Feedback não depende somente de cor. Movimento reduzido respeitado. Prévia é amostra, não documento completo nem confirmação física.

## Segurança das escolhas
Favorito salva somente configurações não sensíveis, mediante ação explícita; cópias sempre começam em 1. Reaproveitar arquivo abre revisão com 1 cópia e sem imprimir. Contabilização legada permanece descrita como acumulado; estimativa de papel não altera regras existentes.
