# Bloqueio de envio quando a impressora está indisponível

O professor deve ver o motivo do bloqueio e não conseguir enviar quando a impressora estiver desligada/sem comunicação, sem papel, com atolamento, tampa aberta, sem toner, parada ou com outra falha impeditiva. Falta de confirmação do estado também bloqueia. Avisos de suprimento baixo aparecem sem impedir uma impressora que ainda pode trabalhar.

Usar o CUPS já instalado, mas consultar também o equipamento: testar o transporte de rede e interpretar somente os atributos de estado IPP retornados com sucesso. Nomes de cartuchos, listas de capacidades e media-ready não comprovam saúde/papel. IPP direto usa a URI CUPS; socket/lpd usa o mesmo host em ipp://host:631/ipp/print, configurável via PRINTER_STATUS_URI. Dispositivos que não permitem confirmar saúde ficam indisponíveis com orientação para TI. ipptool é necessário para confirmar o estado físico.

Apenas UI deixaria o endpoint aberto a envios diretos; apenas estado da fila não identifica impressora desligada. A implementação usa consulta física + bloqueio no preflight do servidor + atualização da UI a cada 10 segundos, ao retomar a página e antes de cada envio. Leituras falhas ou vencidas não liberam envio. A reativação da fila nunca sobrepõe falhas físicas.

Aviso destacado no topo e mensagem curta junto ao botão desativado. Reutilizar estilo e acessibilidade existentes. Prévia continua imediatamente acima da confirmação; avisos excepcionais podem ocupar a área da confirmação. Recuperação automática quando consulta voltar saudável. Nenhum job físico durante testes.
