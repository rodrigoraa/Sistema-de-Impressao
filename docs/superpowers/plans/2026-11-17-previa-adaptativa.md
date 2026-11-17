# Prévia adaptativa

Goal: Exibir toda a impressão em arquivos comuns, mantendo a revisão compacta e permitindo carregar documentos grandes sob demanda.

## Contrato
- Completa automaticamente quando a seleção gera até 20 páginas do PDF de impressão e o PDF preparado tem até 10 MiB. Limiares configuráveis.
- Demais documentos começam na amostra existente (PRINT_PREVIEW_MAX_SHEETS, padrão 3). Mostrar uma linha com a cobertura e o botão Ver prévia completa somente quando faltarem páginas.
- Completa explícita respeita seleção, N-up, papel, orientação, escala e timeout existente; não há limite oculto de 10 páginas.
- Limitar transferência do PDF gerado a 50 MiB configuráveis. Timeout/tamanho não invalidam uma amostra que já está visível.
- Mudanças de opções que alteram a prévia retornam à política automática. Respostas antigas e remoção de arquivo não podem restaurar prévias obsoletas.
- Cache distingue cobertura da amostra e completa. Tokens, sessão/usuário e CSRF existentes continuam obrigatórios. Prévia nunca envia trabalho à impressora nem altera contabilização.

## Execução
- [x] Testar seleção/limites/tamanho/modo explícito em política PHP, observar falha e implementar no serviço.
- [x] Integrar política/cache/cabeçalhos/limite de transferência ao endpoint e testar PDF real.
- [x] Adicionar botão condicional e preservar amostra em falha; testes Chromium com documento de 60 páginas e mudança de opções.
- [x] Executar regressões PHP, Node, Chromium e auditoria; revisar diff e enviar à main conforme autorização da conversa.

Review focus: cache da amostra após completa; N-up e seleções pequenas em documento grande; falha da completa sem apagar amostra; troca de configuração durante pedido completo; autorização/CSRF e tamanho da resposta.

## Evidências
- Política PHP cobre limites inclusivos, PDF pesado, N-up e seleção pequena em documento grande.
- Chromium: PDF real com 60 páginas → amostra de 3 e completa de 30 em 2-up; conteúdo do blob verificado com PageCounter; cache auto/full separado; seleção parcial gera 2 páginas.
- Falha da completa preserva amostra, CSRF inválido recebe 419, tamanho excessivo retorna 413 tanto em cache quanto após geração. Nenhum trabalho é criado.
- Revisão independente identificou favoritos durante completa pendente. Teste com respostas retidas reproduziu o erro (RED) e passou após invalidar imediatamente a requisição antiga (GREEN).
- Suíte PHP completa, quatro testes Node, fluxo Chromium, lint PHP e auditoria estática estrita passaram. Não houve teste de impressão física ou benchmark no servidor de produção.
