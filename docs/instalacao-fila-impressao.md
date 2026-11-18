# Instalação da fila persistente de impressão

Este procedimento usa Debian ou Ubuntu com systemd, PHP 8, SQLite e CUPS. Os exemplos usam a instalação em `/var/www/impressao`, com o PHP do site executado como `www-data` e o document root em `public/`. Ajuste o caminho e o usuário conforme a instalação, inclusive na unidade do systemd.

## 1. Atualizar a aplicação

Pause o uso do site durante a atualização e pare o timer e o serviço se já estiverem instalados. Faça um backup consistente de `.env` e de `storage/` com os processos de escrita parados. Se `UPLOAD_PATH` estiver fora do projeto, inclua-o no backup dos arquivos do histórico.

No checkout existente, use a conta responsável pelo deploy.

```bash
cd /var/www/impressao
git status --short
git switch main
git pull --ff-only origin main
```

## 2. Conferir as dependências e a fila do CUPS

```bash
sudo apt update
sudo apt install php-cli php-sqlite3 php-gd php-mbstring cups cups-client cups-ipp-utils cups-filters poppler-utils qpdf ghostscript libreoffice-writer
sudo systemctl enable --now cups.service
sudo -u www-data lpstat -p -a
```

No `.env` existente, ajuste `PRINTER_NAME` para o nome exato da fila exibida pelo CUPS. Mantenha o mesmo usuário do site no worker para que ambos acessem os mesmos arquivos e o mesmo banco. Use armazenamento local persistente para `storage/`, pois o banco SQLite e o bloqueio do worker precisam estar no mesmo servidor.

Crie o diretório privado de PDFs da fila e confira o acesso ao banco existente. O comando `--check` aplica a migração aditiva e verifica o acesso ao armazenamento sem enviar trabalhos.

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/www/impressao/storage/print-queue
sudo -u www-data /usr/bin/php /var/www/impressao/scripts/print_queue_worker.php --check
```

## 3. Instalar e ativar o processamento automático

```bash
cd /var/www/impressao
sudo install -m 0644 docs/systemd/impressao-fila.service /etc/systemd/system/impressao-fila.service
sudo install -m 0644 docs/systemd/impressao-fila.timer /etc/systemd/system/impressao-fila.timer
sudo systemctl daemon-reload
sudo systemctl enable --now impressao-fila.timer
sudo systemctl start impressao-fila.service
systemctl list-timers --all impressao-fila.timer
sudo journalctl -u impressao-fila.service -n 30 --no-pager
```

O timer inicia após o boot e volta a chamar o worker cerca de 15 segundos após cada execução terminar. Um único worker por instalação pode enviar trabalhos. Ao fazer manutenção, pare o timer e acompanhe o serviço até ele terminar antes de pará-lo.

## 4. Validar no servidor

Retome o uso do site após ativar o timer.

1. Em **Enviar impressão**, selecione um PDF de teste e clique em **Deixar na fila**. Abra **Meus envios** pelo link de confirmação.
2. Para testar o cancelamento com tempo, pare o timer e aguarde qualquer leitura em andamento. Enfileire outro PDF e use **Cancelar** em **Meus envios** antes de reativar o timer. O histórico deve mostrar **Cancelada**, com zero folhas contabilizadas.
3. Com a impressora pronta e o timer ativo, enfileire uma página cuja impressão esteja autorizada. Confirme a situação em **Meus envios**, a saída física e a contabilização. A indicação **Aceito** significa que o CUPS aceitou o trabalho. O comportamento de acompanhamento físico continua respeitando `PRINT_JOB_WAIT_FOR_COMPLETION`.
4. Para validar o boot em uma janela de manutenção, deixe um trabalho de teste pendente, com a fila do CUPS indisponível, e reinicie o servidor. Confirme que o timer voltou e que o trabalho é processado quando a impressora estiver pronta.

## Trabalhos interrompidos

Enquanto a verificação do CUPS indica indisponibilidade, o PDF permanece na fila. Quando a submissão começa, o worker registra o estado **Enviando** antes de chamar o CUPS e grava o identificador do CUPS assim que ele aparece. Se o envio ficar ambíguo ou o servidor reiniciar nessa etapa, o histórico passa a mostrar **Envio interrompido**. O worker segue com os outros pendentes e o trabalho interrompido fica sem contabilização automática.

Confira a saída física e o histórico do CUPS antes de decidir se ainda faltam páginas. Use a impressora e o identificador exibidos no histórico, quando disponível.

```bash
lpstat -W not-completed -o NOME_DA_FILA
lpstat -W completed -o NOME_DA_FILA
```

Se for necessário imprimir as páginas restantes, faça um novo envio com o intervalo conferido. Conclua ou cancele os trabalhos pendentes antes de aposentar ou renomear uma fila CUPS. Cada trabalho mantém a fila de destino original, e um trabalho pendente para uma fila desativada pode segurar os seguintes.

Os PDFs preparados da fila ficam em `storage/print-queue/` até o fim do trabalho. Inclua esse diretório junto com o SQLite ao mover ou restaurar a aplicação. Preserve o caminho absoluto da instalação em uma restauração com trabalhos pendentes.
