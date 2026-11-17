# Prévia com folha inteira no celular

Goal: substituir o PDF nativo em iframe que aparece ampliado/cortado no Safari por uma renderização controlada, ajustada à largura e sem alterar o PDF da impressão.

- [x] Reproduzir o uso de iframe em teste e exigir folha inteira, sem rolagem horizontal.
- [x] Adicionar PDF.js legacy 6.5.438 como assets locais, com licença e SHA-512 verificado contra npm; servir módulos e WASM com MIME correto.
- [x] Implementar public/js/print-preview.js: uma página em canvas por vez, proporção original, resolução limitada, anterior/próxima e link compacto para ampliar no navegador. Redimensionar sem novo pedido ao servidor.
- [x] Integrar ao ciclo atual: cancelamento, arquivos trocados, amostra preservada durante completa, limpeza de workers/canvases e fallback para abrir PDF em nova aba.
- [x] Validar conteúdo rasterizado, navegação, layout portrait/landscape em 320/390/844px, falha do renderer, regressões PHP/Node/Chromium e auditoria.

Review focus: prévias obsoletas após mudança de arquivo; workers/documentos antigos liberados; proporção inteira e limite de pixels; link original protegido por blob local; completa com falha mantém amostra. Não modificar geração, seleção, N-up, impressão ou contabilização.

WebKit automatizado não está instalado. O download oficial do Playwright recebeu 403 Domain forbidden pela rede do ambiente; não usar contorno de rede nem declarar validação em Safari/iPhone real.

## Evidências finais
- Regressão reproduziu iframe nativo (RED) e passou após usar canvas com conteúdo real, navegação e ampliação.
- Chromium com densidade 3x: 320/390px em retrato e 844px em paisagem; proporção integral, pixels do documento, ausência de transbordamento e botão de impressão junto da prévia.
- Revisão independente encontrou diferença de API no PDF.js 6: o documento é liberado pelo loading task. A regressão contou 2 workers após substituição (RED); passou após reter/destruir o loading task ativo (GREEN), inclusive removendo o arquivo.
- Falhas HTTP e de rasterização da completa preservam a amostra; bloqueio do módulo oferece abertura nativa em nova aba. Navegação não reenvia o arquivo.
- Suítes PHP e Node, fluxo completo Chromium, sintaxe PHP/JS e auditoria estática estrita passaram. Não houve impressão física ou validação em Safari real.
