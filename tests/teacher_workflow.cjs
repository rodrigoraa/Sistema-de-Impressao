// Run after activating the PHP environment: node tests/teacher_workflow.cjs
// Uses an isolated checkout and intercepts every print submission. Never prints physically.
const { chromium } = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const net = require('node:net');
(async () => {
 const root = fs.mkdtempSync(path.join(os.tmpdir(), 'teacher-workflow-'));
 const repo = path.resolve(__dirname, '..');
 for(const name of ['public','src','views','config']) fs.cpSync(path.join(repo,name),path.join(root,name),{recursive:true});
 fs.mkdirSync(path.join(root,'storage/uploads'),{recursive:true});
 fs.writeFileSync(path.join(root,'.env'),'PRINTER_NAME=\n');
 const seed = `require 'src/Service/PrintJobService.php'; $db=Database::connect();
 $db->exec("INSERT INTO users(name,cpf,role) VALUES ('Admin','00000000000','admin'),('Professora teste','11111111111','user'),('Outro professor','22222222222','user')");
 $im=imagecreatetruecolor(10,10); imagepng($im,'storage/uploads/fixture.png');
 $service=new PrintJobService();
 $a=$service->create('11111111111','Atividade de matematica.png',realpath('storage/uploads/fixture.png'),'png',30,1,'two-sided-long-edge','auto','A4');
 $b=$service->create('22222222222','Arquivo privado.png',realpath('storage/uploads/fixture.png'),'png',1,1,'one-sided','auto','A4');
 echo json_encode([$a,$b]);`;
 const ids=JSON.parse(execFileSync('php',['-r',seed],{cwd:root,encoding:'utf8'}));
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const serverLog=fs.openSync(path.join(root,'server.log'),'a');
 const server=spawn('php',['-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],{cwd:root,stdio:['ignore',serverLog,serverLog]});
 let browser;
 try {
  const base=`http://127.0.0.1:${port}`;
  for(let i=0;i<40;i++){try{await fetch(base);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',headless:true,args:['--no-sandbox']});
  const context=await browser.newContext({serviceWorkers:'block',deviceScaleFactor:3});
  const page=await context.newPage();
  const errors=[];page.on('pageerror',e=>{errors.push(e.message);console.error('Browser:',e.message);});

  async function expectPdfWorkers(expected) {
   const deadline=Date.now()+2000;
   while(Date.now()<deadline) { if(page.workers().length===expected)return; await new Promise(resolve=>setTimeout(resolve,25)); }
   assert.equal(page.workers().length,expected,'Workers de prévias substituídas ou removidas devem ser liberados');
  }
  await page.route('**/printer/status',r=>r.fulfill({json:{success:true,status:{can_print:true}}}));
  let submissions=0; let submittedBody=''; let submissionMode='success';
  await page.route(base+'/',async r=>{
   if(r.request().method()!=='POST')return r.continue();
   submissions++;submittedBody=r.request().postData() || '';if(submissionMode==='abort') return r.abort();await r.fulfill({json:{success:true,message:'Arquivo aceito pelo servidor, sem confirmação física.'}});
  });
  await page.goto(base+'/login');
  await page.locator('#matricula').fill('11111111111');
  await Promise.all([page.waitForURL(base+'/'),page.getByRole('button',{name:'Entrar',exact:true}).click()]);
  await page.getByRole('heading',{name:'Arquivo e cópias'}).waitFor();
  assert.equal(await page.locator('.row.g-4').evaluate(e=>getComputedStyle(e).display),'flex','Bootstrap local deve estar carregado');
  assert.equal(await page.locator('#advancedOptions').getAttribute('open'),null);
  assert.equal(await page.locator('.primary-options #number_up').count(),1,'Páginas por folha deve estar junto das opções principais');
  assert.equal(await page.locator('#number_up').isVisible(),true);
  assert.equal(await page.locator('#btnPrint').isDisabled(),true);
  for (const width of [1280, 1024, 768]) {
   await page.setViewportSize({width,height:900});
   const copiesBox=await page.locator('#copies').boundingBox();
   const sidesBox=await page.locator('#sides').boundingBox();
   assert.ok(Math.abs(copiesBox.y-sidesBox.y)<=1,`Cópias e modo devem alinhar em ${width}px`);
   assert.equal(copiesBox.height,sidesBox.height);
   const layoutBox=await page.locator('#number_up').boundingBox();
   if(width!==1024) assert.ok(Math.abs(layoutBox.y-copiesBox.y)<=1,'Páginas por folha deve alinhar às outras opções quando há espaço');
   assert.equal(layoutBox.height,copiesBox.height);
  }
  await page.setViewportSize({width:1280,height:900});
  // Generate a genuine 4-page PDF with Chromium, then exercise actual PHP upload/count/preview.
  const document=await context.newPage();
  await document.setContent('<style>@page{size:A4;margin:20mm}section{break-after:page;height:240mm;box-sizing:border-box;border:2px solid #155e75;display:flex;align-items:center;justify-content:center;font-size:28px}</style>'+[1,2,3,4].map(n=>`<section>Atividade - página ${n}</section>`).join(''));
  const pdf=await document.pdf();await document.close();
  await page.locator('#fileInput').setInputFiles({name:'Atividade.pdf',mimeType:'application/pdf',buffer:pdf});
  await page.waitForFunction(()=>document.querySelector('#uploadTokenInput').value.length===64);
  assert.equal(await page.locator('#number_up').inputValue(),'2');
  await page.locator('#pageAdvice').getByText('Atenção: 2 por folha ativado automaticamente',{exact:true}).waitFor();
  const automaticNoticeBox=await page.locator('#pageAdvice').boundingBox();
  const mainOptionsBox=await page.locator('.primary-options').boundingBox();
  assert.ok(automaticNoticeBox.y>=mainOptionsBox.y+mainOptionsBox.height,'Aviso automático deve aparecer junto das opções principais');
  await page.screenshot({path:path.join(root,'automatic-layout-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.locator('#number_up').isVisible(),true);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.screenshot({path:path.join(root,'automatic-layout-mobile.png'),fullPage:true});
  await page.setViewportSize({width:1280,height:900});
  await page.locator('#number_up').selectOption('4');
  assert.equal(await page.locator('#pageAdvice').count(),0,'Escolha manual deve remover o aviso de ajuste automático');
  await page.locator('#number_up').selectOption('2');
  assert.equal(await page.locator('#pageAdvice').count(),0,'Escolha manual de 2 não deve ser anunciada como automática');
  await page.locator('#number_up').selectOption('1');
  await page.locator('#copies').fill('30');
  await page.locator('#sides').selectOption('two-sided-long-edge');
  await page.waitForFunction(()=>document.querySelector('#estimateTotal').textContent.includes('60 folhas'));
  assert.equal(await page.locator('#btnPrint').isDisabled(),false);
  await page.getByRole('button',{name:'Salvar estas opções',exact:true}).click();
  const saved=await page.evaluate(()=>localStorage.getItem('impressao.opcoes.v1'));
  assert.ok(!saved.includes('copies')&&!saved.includes('Atividade')&&!saved.includes('token'));
  await page.locator('#copies').fill('7');
  await page.getByRole('button',{name:'Usar opções salvas'}).click();
  assert.equal(await page.locator('#copies').inputValue(),'1');
  await page.waitForFunction(()=>!document.querySelector('#btnPrint').disabled);
  // Invalid interval stops submission and reveals the collapsed field.
  await page.locator('#advancedOptions summary').click();
  await page.locator('#page_ranges').fill('9');
  await page.locator('#btnPrint').click();
  assert.match(await page.locator('#formError').textContent(),/intervalo/);assert.equal(submissions,0);
  const previousPreviewUrl=await page.evaluate(()=>previewObjectUrl);
  await page.locator('#page_ranges').fill('');
  await page.locator('#advancedOptions summary').click();
  await page.waitForFunction(previous=>previewObjectUrl && previewObjectUrl!==previous && !previewController && document.querySelector('#preview canvas'),previousPreviewUrl);
  await page.locator('#preview canvas').waitFor();
  assert.equal(await page.locator('#preview iframe').count(),0,'A prévia deve renderizar a folha inteira sem depender do PDF nativo em iframe');
  await expectPdfWorkers(1);
  const reviewPanel=page.locator('.info-panel');
  assert.equal(await reviewPanel.locator('#previewSummary').textContent(),'Prévia completa da impressão');
  const reviewText=await reviewPanel.evaluate(panel=>{
   const visibleContent=panel.cloneNode(true);
   visibleContent.querySelectorAll('.visually-hidden, .d-none').forEach(element=>element.remove());
   return visibleContent.textContent;
  });
  for(const redundant of ['Atividade.pdf','contabilização','Arquivo pronto','Exibindo','por cópia','Abrir prévia ampliada','Abrir PDF em nova aba','Como enviar pelo celular']) {
   assert.ok(!reviewText.includes(redundant),`A revisão deve ser direta, sem texto extra: ${redundant}`);
  }
  assert.deepEqual(await reviewPanel.locator('.print-confirmation').getByRole('button').allTextContents().then(labels=>labels.map(label=>label.trim())),['Enviar para impressão']);
  async function assertPreviewNearConfirmation() {
   const previewBox=await page.locator('#preview').boundingBox();
   const buttonBox=await page.locator('#btnPrint').boundingBox();
   const gap=buttonBox.y-(previewBox.y+previewBox.height);
   assert.ok(gap>=0 && gap<=24,`Confirmação deve ficar logo abaixo da prévia, distância atual ${gap}px`);
  }
  await assertPreviewNearConfirmation();
  async function assertWholePageFits() {
   await page.waitForFunction(()=>{
    const canvas=document.querySelector('#preview canvas'),area=document.querySelector('#preview .pdf-preview-page');
    if(!canvas||!area)return false;
    const style=getComputedStyle(area),available=area.clientWidth-parseFloat(style.paddingLeft)-parseFloat(style.paddingRight);
    return Math.abs(canvas.getBoundingClientRect().width-available)<2 && Math.abs(parseFloat(canvas.style.width)-available)<2 && document.querySelector('.pdf-preview').getAttribute('aria-busy')==='false';
   });
   const size=await page.locator('#preview canvas').evaluate(canvas=>{
    const box=canvas.getBoundingClientRect(),parent=canvas.parentElement.getBoundingClientRect();
    const pixels=canvas.getContext('2d').getImageData(0,0,canvas.width,canvas.height).data;
    let marks=0;for(let i=0;i<pixels.length;i+=148)if(pixels[i]<220||pixels[i+1]<220||pixels[i+2]<220)marks++;
    return {width:box.width,height:box.height,ratio:canvas.height/canvas.width,left:box.left,right:box.right,parentLeft:parent.left,parentRight:parent.right,marks,pixels:canvas.width*canvas.height};
   });
   assert.ok(Math.abs(size.height/size.width-size.ratio)<.01,'Folha deve manter a proporção inteira');
   assert.ok(size.left>=size.parentLeft && size.right<=size.parentRight,'Página não pode ultrapassar sua moldura');
   assert.ok(size.marks>10,'A prévia deve conter pixels do documento real');
   assert.ok(size.pixels<=4010000,'Uma página não deve alocar canvas sem limite');
  }
  await page.getByRole('button',{name:'Próxima página',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('.pdf-preview-counter').textContent==='2 / 4');
  await page.getByRole('button',{name:'Página anterior',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('.pdf-preview-counter').textContent==='1 / 4');
  assert.equal(await page.getByRole('button',{name:'Página anterior',exact:true}).isDisabled(),true);
  const zoom=page.getByRole('link',{name:'Ampliar prévia em nova aba',exact:true});
  assert.equal(await zoom.getAttribute('target'),'_blank');
  assert.ok((await zoom.getAttribute('href')).startsWith('blob:'));
  for(const viewport of [{width:320,height:568},{width:844,height:390},{width:390,height:844}]) {
   await page.setViewportSize(viewport);
   await assertWholePageFits();
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  }
  await assertPreviewNearConfirmation();
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.screenshot({path:path.join(root,'mobile.png'),fullPage:true});
  await page.setViewportSize({width:1280,height:900});
  await page.screenshot({path:path.join(root,'desktop.png'),fullPage:true});
  await page.locator('#btnPrint').click();
  await page.waitForFunction(()=>document.querySelector('#printResult').textContent.includes('aceito'));
  assert.equal(submissions,1);assert.equal(await page.locator('#btnPrint').isDisabled(),true);
  assert.match(submittedBody,/name="csrf_token"/,'Envio deve conter CSRF');
  assert.match(submittedBody,/name="copies"/,'Envio deve conter cópias');
  assert.match(submittedBody,/name="sides"/,'Envio deve conter frente e verso');
  // History search, ownership checks and editable reuse must not send a print.
  await page.goto(base+'/prints');
  await page.getByLabel('Buscar arquivo').fill('Atividade');
  await page.getByRole('button',{name:'Filtrar',exact:true}).click();
  assert.ok((await page.locator('tbody').textContent()).includes('Atividade de matematica'));
  assert.ok(!(await page.locator('tbody').textContent()).includes('Arquivo privado'));
  await page.getByRole('button',{name:'Reimprimir',exact:true}).first().click();
  await page.locator('#historyConfirm.show').waitFor();
  await page.waitForFunction(()=>document.activeElement.id==='historyKeep');
  await page.keyboard.press('Escape');
  await page.locator('#historyConfirm.show').waitFor({state:'hidden'});
  const csrf=await page.locator('input[name=csrf_token]').first().inputValue();
  const forbidden=await page.request.post(base+'/prints/reuse',{form:{id:String(ids[1]),csrf_token:csrf}});
  assert.equal(forbidden.status(),404);
  const invalidCsrf=await page.request.post(base+'/prints/reuse',{form:{id:String(ids[0]),csrf_token:'wrong'}});
  assert.equal(invalidCsrf.status(),419);
  await page.getByRole('button',{name:'Usar arquivo novamente',exact:true}).first().click();
  await page.getByRole('heading',{name:'Arquivo e cópias'}).waitFor();
  assert.equal(await page.locator('#copies').inputValue(),'1');
  assert.equal(await page.locator('#uploadTokenInput').inputValue().then(t=>t.length),64);
  assert.equal(submissions,1);
  await page.getByRole('button',{name:'Remover arquivo',exact:true}).click();
  await expectPdfWorkers(0);
  assert.equal(await page.locator('#uploadTokenInput').inputValue(),'');
  assert.equal(await page.locator('#btnPrint').isDisabled(),true);
  // Drag/drop uses the same actual upload path and rejects invalid replacements.
  const transfer=await page.evaluateHandle(()=>new DataTransfer());
  await page.evaluate(({transfer,bytes})=>{transfer.items.add(new File([new Uint8Array(bytes)],'Arrastado.pdf',{type:'application/pdf'}));},{transfer,bytes:Array.from(pdf)});
  await page.locator('#drop-area').dispatchEvent('drop',{dataTransfer:transfer});
  await page.waitForFunction(()=>document.querySelector('#uploadTokenInput').value.length===64);
  await page.locator('#fileInput').setInputFiles({name:'nao-permitido.exe',mimeType:'application/octet-stream',buffer:Buffer.from('invalid')});
  assert.equal(await page.locator('#uploadTokenInput').inputValue(),'');
  assert.equal(await page.locator('#btnPrint').isDisabled(),true);
  // Favorite storage denial is recoverable.
  await page.evaluate(()=>Object.defineProperty(window,'localStorage',{get(){throw new Error('blocked');}}));
  await page.getByRole('button',{name:'Salvar estas opções',exact:true}).click();
  assert.match(await page.locator('#favoriteFeedback').textContent(),/não permitiu/);
  // Test the shared-file confirmation variant without dispatching a print.
  const shared=await page.request.post(base+'/share-target.php',{multipart:{arquivo:{name:'Compartilhado.pdf',mimeType:'application/pdf',buffer:pdf}}});
  await page.goto(shared.url());
  await page.getByRole('button',{name:'Confirmar impressão',exact:true}).waitFor();
  await page.waitForFunction(()=>document.querySelector('#uploadTokenInput').value.length===64);
  assert.equal(submissions,1);
  // Reuse retains an explicit partial selection and still starts at one copy.
  fs.writeFileSync(path.join(root,'storage/uploads/partial.pdf'),pdf);
  const partial=execFileSync('php',['-r',`require 'src/Service/PrintJobService.php'; $s=new PrintJobService(); $id=$s->create('11111111111','Selecao.pdf',realpath('storage/uploads/partial.pdf'),'pdf',20,1,'two-sided-long-edge','auto','A4'); $s->updateSelection($id,'Páginas 1, 3',2); Database::connect()->exec("UPDATE print_jobs SET pages=4 WHERE id=".$id); echo $id;`],{cwd:root,encoding:'utf8'});
  await page.goto(base+'/prints');
  const partialRow=page.locator('tbody tr').filter({hasText:'Selecao.pdf'});
  await partialRow.getByRole('button',{name:'Usar arquivo novamente',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('#page_ranges').value==='1,3');
  assert.equal(await page.locator('#copies').inputValue(),'1');
  // Network failure after submitting must lock blind retry and preserve access to history.
  await page.goto(base+'/');
  await page.locator('#fileInput').setInputFiles({name:'Falha.pdf',mimeType:'application/pdf',buffer:pdf});
  await page.waitForFunction(()=>document.querySelector('#uploadTokenInput').value.length===64);
  submissionMode='abort';
  await page.locator('#btnPrint').click();
  await page.waitForFunction(()=>document.querySelector('#printResult').textContent.includes('Confira Meus envios'));
  assert.equal(await page.locator('#btnPrint').isDisabled(),true);
  await page.getByText('Atenção ao envio',{exact:true}).waitFor();
  assert.equal(submissions,2);
  const count=execFileSync('php',['-r',`require 'src/Service/Database.php'; echo Database::connect()->querySingle('SELECT count(*) FROM print_jobs');`],{cwd:root,encoding:'utf8'});
  assert.equal(count,'3','Uploads e revisões não devem gerar trabalhos de impressão');
  // Preference changes during a delayed upload require a fresh count for the final options.
  await page.goto(base+'/');
  await page.evaluate(()=>localStorage.setItem('impressao.opcoes.v1',JSON.stringify({paper:'Letter',orientation:'landscape'})));
  await page.reload();
  let releaseUpload, markUploadReady;
  const uploadGate=new Promise(resolve=>releaseUpload=resolve);
  const uploadReady=new Promise(resolve=>markUploadReady=resolve);
  let recountAfterUpload=0;
  page.on('request',request=>{if(request.url().endsWith('/print/page-count'))recountAfterUpload++;});
  const delayedUpload=async route=>{const response=await route.fetch();markUploadReady();await uploadGate;await route.fulfill({response});};
  await page.route('**/print/upload',delayedUpload);
  await page.locator('#fileInput').setInputFiles({name:'Preferencias.pdf',mimeType:'application/pdf',buffer:pdf});
  await uploadReady;
  await page.getByRole('button',{name:'Usar opções salvas',exact:true}).click();
  releaseUpload();
  await page.waitForFunction(()=>document.querySelector('#uploadTokenInput').value.length===64 && !document.querySelector('#btnPrint').disabled);
  assert.equal(recountAfterUpload,1,'Mudança de papel/orientação durante upload precisa recontar páginas');
  await page.unroute('**/print/upload',delayedUpload);
  // Removing a file before a delayed upload response arrives must never revive its token.
  await page.goto(base+'/');
  let releaseRemovedUpload, removedReady, removedDone;
  const removedGate=new Promise(resolve=>releaseRemovedUpload=resolve);
  const removedResponse=new Promise(resolve=>removedReady=resolve);
  const removedFinished=new Promise(resolve=>removedDone=resolve);
  const removedHandler=async route=>{
   const response=await route.fetch();removedReady();await removedGate;
   try { await route.fulfill({response}); } catch { /* The browser aborted this obsolete request. */ }
   finally { removedDone(); }
  };
  await page.route('**/print/upload',removedHandler);
  await page.locator('#drop-area').focus();
  await page.waitForFunction(()=>document.activeElement.id==='drop-area' && typeof document.querySelector('#drop-area').onclick==='function');
  const chooserPromise=page.waitForEvent('filechooser');
  await page.locator('#drop-area').press('Enter');
  const chooser=await chooserPromise;
  await chooser.setFiles({name:'Removido.pdf',mimeType:'application/pdf',buffer:pdf});
  await removedResponse;
  await page.getByRole('button',{name:'Remover arquivo',exact:true}).click();
  releaseRemovedUpload();await removedFinished;
  assert.equal(await page.locator('#uploadTokenInput').inputValue(),'');
  assert.equal(await page.locator('#btnPrint').isDisabled(),true);
  assert.equal(await page.locator('#estimateTotal').textContent(),'Estimativa: —');
  await page.unroute('**/print/upload',removedHandler);
  // A real large PDF starts with a sample, then loads the complete selected layout on demand.
  const longDocument=await context.newPage();
  await longDocument.setContent('<style>@page{size:A4;margin:20mm}section{break-after:page}</style>'+Array.from({length:60},(_,i)=>`<section>Atividade extensa - página ${i+1}</section>`).join(''));
  const longPdf=await longDocument.pdf();await longDocument.close();
  await page.goto(base+'/');
  await page.locator('#fileInput').setInputFiles({name:'Documento-completo.pdf',mimeType:'application/pdf',buffer:longPdf});
  await page.waitForFunction(()=>document.querySelector('#previewSummary').textContent.includes('3 de 30'));
  await page.locator('#preview canvas').waitFor();
  const sampleUrl=await page.locator('#preview .pdf-preview-open').getAttribute('href');
  const rejectFull=route=>route.request().postData()?.includes('name="preview_mode"')
    ? route.fulfill({status:500,body:'A prévia completa demorou mais que o esperado.'}) : route.continue();
  await page.route('**/print/preview',rejectFull);
  await page.getByRole('button',{name:'Ver prévia completa',exact:true}).click();
  await page.locator('#previewNotice').getByText('Não foi possível carregar tudo. A amostra continua disponível.',{exact:true}).waitFor();
  assert.equal(await page.locator('#preview .pdf-preview-open').getAttribute('href'),sampleUrl,'Falha na completa deve preservar a amostra');
  assert.equal(await page.locator('#btnPrint').isDisabled(),false);
  await page.unroute('**/print/preview',rejectFull);
  const invalidRenderedPdf=route=>route.request().postData()?.includes('name="preview_mode"')
   ? route.fulfill({status:200,contentType:'application/pdf',headers:{'X-Preview-Total-Sheets':'30','X-Preview-Sheets':'30','X-Preview-Additional-Sheets':'0'},body:'%PDF-1.7\ninvalid-document'}) : route.continue();
  await page.route('**/print/preview',invalidRenderedPdf);
  await page.getByRole('button',{name:'Ver prévia completa',exact:true}).click();
  await page.waitForFunction(()=>!previewController && document.querySelector('#previewNotice').textContent==='Não foi possível carregar tudo. A amostra continua disponível.');
  assert.equal(await page.locator('#preview .pdf-preview-open').getAttribute('href'),sampleUrl,'Falha do renderer também deve preservar a amostra');
  await expectPdfWorkers(1);
  await page.unroute('**/print/preview',invalidRenderedPdf);
  const completeResponse=page.waitForResponse(response=>response.url().endsWith('/print/preview') && response.ok() && response.headers()['x-preview-sheets']==='30');
  await page.getByRole('button',{name:'Ver prévia completa',exact:true}).click();
  const complete=await completeResponse;
  await page.waitForFunction(()=>document.querySelector('#previewSummary').textContent==='Prévia completa da impressão' && !previewController);
  const completeBytes=Buffer.from(await page.evaluate(async()=>Array.from(new Uint8Array(await (await fetch(previewObjectUrl)).arrayBuffer()))));
  assert.equal(completeBytes.length,Number(complete.headers()['content-length']),'O navegador deve receber todo o PDF gerado');
  const completePath=path.join(root,'complete-preview.pdf');fs.writeFileSync(completePath,completeBytes);
  const countPdf=file=>execFileSync('php',['-r',`require 'src/Service/PageCounter.php'; echo PageCounter::count($argv[1]);`,file],{cwd:root,encoding:'utf8'});
  assert.equal(countPdf(completePath),'30','PDF completo deve conter todos os 30 lados, sem teto antigo de 10');
  await page.waitForFunction(()=>document.querySelector('#previewSummary').textContent==='Prévia completa da impressão' && !previewController);
  assert.equal(await page.locator('#loadFullPreview').isVisible(),false);
  const previewForm={upload_token:await page.locator('#uploadTokenInput').inputValue(),csrf_token:await page.locator('input[name=csrf_token]').first().inputValue(),number_up:'2',paper:'A4',orientation:'auto',scale:'fit'};
  const cachedSample=await page.request.post(base+'/print/preview',{form:previewForm});
  assert.equal(cachedSample.headers()['x-preview-sheets'],'3');
  const samplePath=path.join(root,'sample-preview.pdf');fs.writeFileSync(samplePath,await cachedSample.body());
  assert.equal(countPdf(samplePath),'3','Cache da amostra não pode devolver a completa');
  const cachedFull=await page.request.post(base+'/print/preview',{form:{...previewForm,preview_mode:'full'}});
  assert.deepEqual(await cachedFull.body(),completeBytes,'Cache da completa deve ser separado e reutilizado');
  const selectedPreview=await page.request.post(base+'/print/preview',{form:{...previewForm,page_ranges:'1,3-5'}});
  assert.equal(selectedPreview.headers()['x-preview-sheets'],'2','Seleção pequena em documento grande deve ser completa');
  assert.equal(selectedPreview.headers()['x-preview-additional-sheets'],'0');
  assert.equal((await page.request.post(base+'/print/preview',{form:{...previewForm,preview_mode:'full',csrf_token:'invalid'}})).status(),419);
  const originalEnv=fs.readFileSync(path.join(root,'.env'),'utf8');
  try {
   fs.appendFileSync(path.join(root,'.env'),'PRINT_PREVIEW_MAX_BYTES=1\n');
   const limited=await page.request.post(base+'/print/preview',{form:{...previewForm,preview_mode:'full'}});
   assert.equal(limited.status(),413,'Limite de transferência também deve valer para cache existente');
   const uncachedLimited=await page.request.post(base+'/print/preview',{form:{...previewForm,preview_mode:'full',number_up:'4',page_ranges:'1-5'}});
   assert.equal(uncachedLimited.status(),413,'Limite deve valer para PDF recém-gerado');
  } finally {fs.writeFileSync(path.join(root,'.env'),originalEnv);}
  // Changing options returns to automatic policy rather than regenerating a large full preview.
  const changedResponse=page.waitForResponse(response=>response.url().endsWith('/print/preview') && response.ok() && response.headers()['x-preview-sheets']==='15');
  await page.locator('#number_up').selectOption('4');
  await changedResponse;
  await page.waitForFunction(()=>document.querySelector('#previewSummary').textContent==='Prévia completa da impressão' && !previewController);
  // Applying favorites during a full preview must invalidate old visual settings immediately.
  await page.locator('#number_up').selectOption('1');
  await page.waitForFunction(()=>document.querySelector('#previewSummary').textContent.includes('3 de 60') && !previewController);
  const beforeFavoritesUrl=await page.locator('#preview .pdf-preview-open').getAttribute('href');
  await page.evaluate(()=>localStorage.setItem('impressao.opcoes.v1',JSON.stringify({paper:'Letter',orientation:'landscape',number_up:'4'})));
  let fullReady, releaseOldFull, oldFullDone, recountReady, releaseRecount;
  const fullReadyGate=new Promise(resolve=>fullReady=resolve);
  const oldFullGate=new Promise(resolve=>releaseOldFull=resolve);
  const oldFullFinished=new Promise(resolve=>oldFullDone=resolve);
  const recountReadyGate=new Promise(resolve=>recountReady=resolve);
  const recountGate=new Promise(resolve=>releaseRecount=resolve);
  const heldFull=async route=>{
   if(!route.request().postData()?.includes('name="preview_mode"'))return route.continue();
   const response=await route.fetch();fullReady();await oldFullGate;
   try{await route.fulfill({response});}catch{/* Invalidated request may already be aborted. */}finally{oldFullDone();}
  };
  const heldRecount=async route=>{const response=await route.fetch();recountReady();await recountGate;await route.fulfill({response});};
  await page.route('**/print/preview',heldFull);
  await page.route('**/print/page-count',heldRecount);
  await page.getByRole('button',{name:'Ver prévia completa',exact:true}).click();
  await fullReadyGate;
  await page.getByRole('button',{name:'Usar opções salvas',exact:true}).click();
  await recountReadyGate;
  releaseOldFull();await oldFullFinished;
  await page.waitForFunction(()=>!previewController);
  assert.equal(await page.locator('#preview .pdf-preview-open').getAttribute('href'),beforeFavoritesUrl,'Prévia antiga não pode substituir amostra durante recálculo de favoritos');
  releaseRecount();
  await page.unroute('**/print/preview',heldFull);
  await page.waitForFunction(()=>document.querySelector('#previewSummary').textContent==='Prévia completa da impressão' && !previewController && !documentRecalculating);
  await page.unroute('**/print/page-count',heldRecount);
  assert.equal(submissions,2,'Gerar prévias completas não deve imprimir');
  assert.equal(execFileSync('php',['-r',`require 'src/Service/Database.php'; echo Database::connect()->querySingle('SELECT count(*) FROM print_jobs');`],{cwd:root,encoding:'utf8'}),'3','Prévia não cria trabalhos ou altera o histórico');
  // Conversion warnings remain prominent when the automatic layout is changed manually.
  await page.goto(base+'/');
  const conversionWarning='Confira a paginação após a conversão do documento.';
  const warningUpload=async route=>{
   const response=await route.fetch();const data=await response.json();
   await route.fulfill({response,json:{...data,warning:conversionWarning}});
  };
  await page.route('**/print/upload',warningUpload);
  await page.locator('#fileInput').setInputFiles({name:'Conversao.pdf',mimeType:'application/pdf',buffer:pdf});
  await page.locator('#pageAdviceHost').getByText(conversionWarning,{exact:true}).waitFor();
  await page.locator('#number_up').selectOption('4');
  assert.equal(await page.locator('#pageAdvice').count(),0);
  await page.locator('#pageAdviceHost').getByText(conversionWarning,{exact:true}).waitFor();
  assert.equal(await page.locator('#pageAdviceHost .print-notice').evaluate(e=>getComputedStyle(e).borderLeftWidth),'6px');
  await page.unroute('**/print/upload',warningUpload);
  // Persistent printer warnings are not re-announced by replacing their DOM on every poll.
  await page.route('**/printer/status',route=>route.fulfill({json:{success:true,status:{can_print:false,notice_type:'warning',notice:'Sem papel. Reponha o papel e confira a impressora.'}}}));
  await page.reload();
  await page.locator('#printerStatusBox').getByText('Atenção: impressora indisponível',{exact:true}).waitFor();
  assert.equal(await page.locator('#btnPrint').isDisabled(),true);
  await page.evaluate(()=>{window.firstPrinterNotice=document.querySelector('#printerStatusBox').firstElementChild;});
  await page.evaluate(()=>refreshPrinterStatus());
  assert.equal(await page.evaluate(()=>window.firstPrinterNotice===document.querySelector('#printerStatusBox').firstElementChild),true);
  // If the module cannot load, offer the PDF directly rather than a broken/cropped iframe.
  await page.route('**/printer/status',route=>route.fulfill({json:{success:true,status:{can_print:true}}}));
  const blockedRenderer=route=>route.abort();
  await page.route('**/pdfjs-6.5.438/pdf.mjs',blockedRenderer);
  await page.goto(base+'/');
  await page.locator('#fileInput').setInputFiles({name:'Fallback.pdf',mimeType:'application/pdf',buffer:pdf});
  const fallback=page.getByRole('link',{name:'Abrir prévia no navegador',exact:true});
  await fallback.waitFor();
  assert.ok((await fallback.getAttribute('href')).startsWith('blob:'));
  assert.equal(await fallback.getAttribute('target'),'_blank');
  assert.equal(await page.locator('#preview iframe').count(),0);
  assert.equal(await page.locator('#btnPrint').isDisabled(),false);
  await page.unroute('**/pdfjs-6.5.438/pdf.mjs',blockedRenderer);
  assert.deepEqual(errors,[]);
  console.log('Professor: upload real, estimativa, favoritos, validação, responsividade, envio único, busca e autorização de reaproveitamento: OK');
  console.log('Artefatos: '+root);
 } finally {if(browser)await browser.close();server.kill();fs.closeSync(serverLog);}
})().catch(error=>{console.error(error);process.exitCode=1;});
