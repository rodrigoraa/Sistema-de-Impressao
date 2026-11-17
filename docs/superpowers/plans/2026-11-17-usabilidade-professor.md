# Usabilidade do professor — Implementation Plan

**Goal:** Reduzir decisões no envio e tornar revisão, papel e acompanhamento compreensíveis.
**Architecture:** Reutilizar PHP, Bootstrap e tokens existentes; extrair cálculo de estimativa em JS puro. Preservar autorização, tokens temporários, seleção de páginas e contabilização do servidor.
**Tech Stack:** PHP 8+, SQLite, JavaScript sem dependências de produção.
**Spec:** Recomendações e autorização de implementação na conversa; DESIGN.md e UX-CONTRACT.md.

## Global Constraints
- pt-BR; identidade institucional existente; nenhum trabalho físico em testes.
- Estimativa física por cópia, arredondando frente e verso antes de multiplicar cópias; não alterar charged_pages.
- Não guardar CPF, arquivo, token ou quantidade de cópias em favoritos.
- Cada arquivo reaproveitado exige autorização e revisão antes do envio.

## Review Focus
- Páginas ímpares, seleção sobreposta/inválida e cópias múltiplas.
- Troca/remoção de arquivo durante upload e prévia; respostas antigas não podem reaparecer.
- Timeout após envio não pode habilitar repetição cega.
- Professor não pode reaproveitar arquivo de outro usuário.
- Celular, teclado e favoritos indisponíveis no armazenamento local.

### Task 1: Revisão e formulário
- [x] Testar cálculo físico e intervalos (node --test tests/print_estimate.test.js), observar falha.
- [x] Implementar public/js/print-estimate.js; reorganizar views/print.php e public/css/print.css.
- [x] Implementar upload acessível, troca/remoção, favoritos sem cópias e estados reais de envio.
- [x] Testar teclado, upload/erro, cópias/duplex e largura estreita em Chromium.

### Task 2: Histórico e reaproveitamento
- [x] Testar busca e autorização de reaproveitamento antes da implementação.
- [x] Adicionar busca por nome com parâmetros SQL; manter filtro por proprietário e limite existente.
- [x] Adicionar POST /prints/reuse que apenas cria cópia temporária para revisão; nenhuma impressão.
- [x] Tornar ações legíveis e substituir confirmação nativa por diálogo acessível Bootstrap.

### Task 3: Verificação
- [x] Executar seis regressões PHP existentes, novos testes e sintaxe PHP/JS.
- [x] Validar navegador com backend real em checkout temporário de teste e impressão interceptada.
- [x] Executar auditoria estática da skill; registrar limites e revisar diff.

## Resultado da revisão
+- Seleção de páginas: labels de exibição com 120 bytes ou contagem divergente não são reutilizados; é solicitado novo envio/seleção. Intervalos íntegros são preservados.
+- Papel/orientação alterados durante upload provocam nova contagem antes de habilitar impressão.
+- Capturar FormData antes de desabilitar controles preserva CSRF e todas as opções.
+- Dependências Bootstrap existentes passaram a cópias locais, com licenças; normalização do roteador PHP embutido corrigida.
+- Regressões: sete scripts PHP, quatro testes Node, fluxo Chromium com backend isolado e impressão interceptada. Auditoria estática estrita sem achados; ela não analisa templates PHP, cobertos por lint e testes de navegador.
+- Não houve impressão física, publicação ou alteração da regra de contabilização.
