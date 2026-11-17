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
  const context=await browser.newContext({serviceWorkers:'block'});
  const page=await context.newPage();
  const errors=[];page.on('pageerror',e=>{errors.push(e.message);console.error('Browser:',e.message);});

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
  assert.equal(await page.locator('#btnPrint').isDisabled(),true);
  // Generate a genuine 4-page PDF with Chromium, then exercise actual PHP upload/count/preview.
  const document=await context.newPage();
  await document.setContent('<style>@page{size:A4;margin:20mm}section{break-after:page}</style>'+[1,2,3,4].map(n=>`<section>Atividade - página ${n}</section>`).join(''));
  const pdf=await document.pdf();await document.close();
  await page.locator('#fileInput').setInputFiles({name:'Atividade.pdf',mimeType:'application/pdf',buffer:pdf});
  await page.waitForFunction(()=>document.querySelector('#uploadTokenInput').value.length===64);
  await page.locator('#advancedOptions summary').click();
  await page.locator('#number_up').selectOption('1');
  await page.locator('#advancedOptions summary').click();
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
  await page.locator('#page_ranges').fill('');
  await page.locator('#advancedOptions summary').click();
  await page.locator('#preview iframe').waitFor();
  await page.setViewportSize({width:390,height:844});
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
  assert.ok(!(await page.locator('#estimateDetails').textContent()).includes('Removido.pdf'));
  await page.unroute('**/print/upload',removedHandler);
  assert.deepEqual(errors,[]);
  console.log('Professor: upload real, estimativa, favoritos, validação, responsividade, envio único, busca e autorização de reaproveitamento: OK');
  console.log('Artefatos: '+root);
 } finally {if(browser)await browser.close();server.kill();fs.closeSync(serverLog);}
})().catch(error=>{console.error(error);process.exitCode=1;});
