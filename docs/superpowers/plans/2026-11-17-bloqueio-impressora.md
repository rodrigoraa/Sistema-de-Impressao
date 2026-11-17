# Bloqueio de impressora Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans para executar esta tarefa na sessão atual.

**Goal:** impedir envio silencioso para equipamento indisponível e explicar o motivo ao professor.
**Architecture:** separar leitura física/IPP em PrinterHealthService; CupsService combina fila e equipamento e usa can_print como regra única no preflight; UI consulta sem cache, expira status e revalida ao enviar.
**Tech Stack:** PHP, CUPS/ipptool, JavaScript e Bootstrap existentes.
**Spec:** docs/superpowers/specs/2026-11-17-bloqueio-impressora-design.md

## Global Constraints
- Não imprimir fisicamente nos testes; não liberar por erro de consulta; não confundir nome de toner ou capacidades com falha.
- Manter configuração e comportamento do restante da impressão; enviar direto à main, sem PR, conforme autorização da sessão.

## Review Focus
- CUPS idle enquanto equipamento desligado: bloquear por transporte sem comunicação.
- IPP retorna media-empty e media-ready simultaneamente: falha física prevalece.
- Reativação CUPS com papel atolado/toner vazio: continuar bloqueado.
- Consulta HTTP falha, demora ou volta fora de ordem: não reaplicar status saudável vencido.
- Envio direto e falha entre consultas: servidor valida todos os motivos antes de lp.

### Task 1: Estado físico e preflight
**Files:** src/Service/PrinterHealthService.php, src/Service/CupsService.php, tests/printer_health_regression.php.
**Interfaces:** PrinterHealthService::probe(uri) e ::interpret(result); CupsService::diagnostics/preflight mantêm contrato e passam a devolver can_print=false em falhas ou estado não confirmado.
- [x] Criar e executar regressões RED: IPP sem papel/atolamento/toner/tampa/offline, metadata saudável, alerta baixo, erro desconhecido, socket local ligado/desligado, preflight para todos os motivos e reativação sem desbloqueio indevido.
- [x] Implementar consultas físicas limitadas e interpretação; unificar preflight pelo can_print; executar regressões GREEN.

### Task 2: Avisos e bloqueio no navegador
**Files:** views/print.php, src/Controller/PrinterController.php, DESIGN.md, README.md, tests/teacher_workflow.cjs.
**Interfaces:** /printer/status sem cache; renderPrinterStatus e refreshPrinterStatus sempre mantêm decisão segura e mensagem junto ao botão.
- [x] Criar e executar RED para can_print=false sem notice, falha HTTP, recuperação, revalidação antes de enviar e aviso próximo do botão.
- [x] Implementar e executar fluxo completo; lint PHP/JS, todos scripts PHP e testes Node, auditoria e revisão independente.
- [x] Atualizar documentação, commit e push main; não afirmar deploy sem evidência.

## Evidências e revisão

- RED → GREEN: serviço físico ausente; preflight liberava motivos não cobertos; recusa IPP ignorada; aviso vazio liberava botão; timeout externo ausente.
- Revisão independente: razões IPP sem sufixo de severidade liberavam erros; consulta CUPS com timeout mantinha can_print; resposta saudável atrasada apagava aviso offline. Regressões específicas falharam antes das correções e passaram após elas.
- Verificação final: todos scripts PHP, 4 testes Node do estimador, fluxo completo Chromium, integração IPP HTTP local com ipptool instalado, lint PHP/JS, auditoria estática estrita sem achados e git diff --check. Nenhuma impressão física executada.
- Artefatos do navegador: /tmp/teacher-workflow-KXvGla, incluindo printer-offline-mobile.png.
- O equipamento/servidor de produção não foi acessado. Requer ipptool e endpoint IPP do equipamento; consulta sem confirmação bloqueia. A limitação e configuração estão no README.
