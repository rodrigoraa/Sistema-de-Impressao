const assert = require('node:assert/strict');
const { execFileSync, spawn } = require('node:child_process');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

async function run() {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'history-preview-'));
    for (const name of ['config', 'public', 'src', 'views']) {
        fs.cpSync(path.join(__dirname, '..', name), path.join(root, name), { recursive: true });
    }
    const uploads = path.join(root, 'storage', 'uploads');
    fs.mkdirSync(uploads, { recursive: true });
    fs.writeFileSync(path.join(uploads, 'atividade.png'), Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5xoAAAAASUVORK5CYII=', 'base64'));
    fs.writeFileSync(path.join(uploads, 'atividade.txt'), 'Revisão <b>terça-feira</b>');
    fs.writeFileSync(path.join(root, 'fora-da-pasta.pdf'), '%PDF-1.4');
    if (process.env.HISTORY_PREVIEW_DOCX) {
        fs.copyFileSync(process.env.HISTORY_PREVIEW_DOCX, path.join(uploads, 'atividade.docx'));
    }
    let browser, server;
    try {
        browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox'] });
        const page = await browser.newPage({ serviceWorkers: 'block' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent('<p style="break-after:page">Primeira página</p><p>Segunda página</p>');
        const pdf = await page.pdf();
        fs.writeFileSync(path.join(uploads, 'atividade.pdf'), pdf);

        const seed = `require 'src/Service/PrintJobService.php'; $db = Database::connect();
            $db->exec("INSERT INTO users(name,cpf,role) VALUES ('Admin','00000000000','admin'),('Professora','11111111111','user'),('Outro professor','22222222222','user')");
            $db->exec("UPDATE users SET password = '" . password_hash('senha-teste', PASSWORD_DEFAULT) . "' WHERE role = 'admin'");
            $service = new PrintJobService();
            $files = ['pdf' => 'storage/uploads/atividade.pdf', 'png' => 'storage/uploads/atividade.png',
                'txt' => 'storage/uploads/atividade.txt', 'missing' => 'storage/uploads/ausente.pdf', 'outside' => 'fora-da-pasta.pdf'];
            if (is_file('storage/uploads/atividade.docx')) $files['docx'] = 'storage/uploads/atividade.docx';
            foreach ($files as $label => $file) {
                $ids[$label] = $service->create('11111111111', 'Atividade ' . basename($file),
                    realpath($file) ?: getcwd() . '/' . $file, pathinfo($file, PATHINFO_EXTENSION), 1, 1, 'one-sided', 'auto', 'A4');
            }
            echo json_encode($ids);`;
        const ids = JSON.parse(execFileSync('php', ['-r', seed], { cwd: root, encoding: 'utf8' }));
        const port = await new Promise(resolve => {
            const socket = net.createServer().listen(0, '127.0.0.1', () => {
                const port = socket.address().port;
                socket.close(() => resolve(port));
            });
        });
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], { cwd: root });
        const base = `http://127.0.0.1:${port}`;
        for (let attempt = 0; attempt < 40; attempt++) {
            try { await fetch(base + '/login'); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
        }

        const previewUrl = id => `${base}/prints/preview?id=${id}`;
        assert.equal((await page.request.get(previewUrl(ids.pdf), { maxRedirects: 0 })).status(), 302);
        await login(page, base, '11111111111');
        const historyUrl = base + '/prints?search=Atividade';
        await page.goto(historyUrl);
        const previewButton = name => page.getByRole('row').filter({ hasText: name }).getByRole('button', { name: 'Visualizar', exact: true });
        const dialog = page.getByRole('dialog', { name: 'Visualizar arquivo' });
        const pdfButton = previewButton('Atividade atividade.pdf');
        await pdfButton.focus({ timeout: 3000 });
        await page.keyboard.press('Enter');
        await dialog.locator('canvas').waitFor();
        assert.equal(await dialog.locator('.pdf-preview-counter').textContent(), '1 / 2');
        const next = dialog.getByRole('button', { name: 'Próxima página' });
        await next.click();
        await dialog.getByRole('img', { name: 'Página 2 de 2 da prévia' }).waitFor();
        assert.equal(await next.evaluate(button => button === document.activeElement), true,
            'O controle de página deve manter o foco para o Escape continuar acessível.');
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        await page.waitForFunction(url => document.activeElement.dataset.previewUrl === url,
            await pdfButton.getAttribute('data-preview-url'));
        assert.equal(page.url(), historyUrl);

        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        if (ids.docx) {
            const officeResponse = page.waitForResponse(previewUrl(ids.docx));
            await previewButton('Atividade atividade.docx').click();
            const converted = await officeResponse;
            assert.equal(converted.headers()['content-type'], 'application/pdf');
            assert.match(converted.headers()['content-disposition'], /\.pdf"$/);
            await dialog.locator('canvas').waitFor();
            assert.ok(await dialog.locator('canvas').evaluate(canvas => canvas.getBoundingClientRect().right <= innerWidth));
            await page.keyboard.press('Escape');
            await dialog.waitFor({ state: 'hidden' });
        }
        await previewButton('Atividade atividade.png').click();
        await dialog.getByRole('img', { name: 'Atividade atividade.png' }).waitFor();
        assert.ok(await dialog.locator('img').evaluate(image => image.complete && image.naturalWidth > 0));
        assert.ok(await dialog.evaluate(element => element.scrollWidth <= innerWidth));
        await dialog.getByRole('button', { name: 'Fechar visualização' }).click();
        await dialog.waitFor({ state: 'hidden' });

        await previewButton('Atividade atividade.txt').click();
        await dialog.getByText('Revisão <b>terça-feira</b>', { exact: true }).waitFor();
        assert.equal(await dialog.locator('b').count(), 0);
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        assert.equal(await previewButton('ausente.pdf').count(), 0);
        assert.equal(await previewButton('fora-da-pasta.pdf').count(), 0);
        for (const id of [ids.missing, ids.outside]) {
            assert.equal((await page.request.get(previewUrl(id))).status(), 404);
        }

        const inline = await page.request.get(previewUrl(ids.pdf));
        assert.match(inline.headers()['content-disposition'], /^inline;/);
        assert.match(inline.headers()['cache-control'], /no-store/);
        assert.deepEqual(await inline.body(), pdf);
        const download = await page.request.get(`${base}/prints/download?id=${ids.pdf}`);
        assert.match(download.headers()['content-disposition'], /^attachment;/);
        assert.deepEqual(await download.body(), pdf);

        fs.unlinkSync(path.join(uploads, 'atividade.png'));
        await previewButton('Atividade atividade.png').click();
        await dialog.getByText('Arquivo indisponível.', { exact: true }).waitFor();
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        const other = await browser.newPage();
        await login(other, base, '22222222222');
        assert.equal((await other.request.get(previewUrl(ids.pdf))).status(), 404);
        const admin = await browser.newPage();
        await login(admin, base, '00000000000', 'senha-teste');
        assert.equal((await admin.request.get(previewUrl(ids.pdf))).status(), 200);
        assert.deepEqual(errors, []);
        console.log(`Visualização no histórico: PDF, imagem, TXT${ids.docx ? ', DOCX' : ''}, teclado, celular e permissões: OK`);
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) {
            const stopped = new Promise(resolve => server.once('exit', resolve));
            server.kill();
            await stopped;
        }
        fs.rmSync(root, { recursive: true, force: true });
    }
}

async function login(page, base, cpf, password) {
    await page.goto(base + '/login');
    await page.locator('#matricula').fill(cpf);
    if (password) await page.locator('#senha').fill(password);
    await Promise.all([
        page.waitForURL(url => url.pathname !== '/login'),
        page.getByRole('button', { name: 'Entrar', exact: true }).click(),
    ]);
}

run().catch(error => { console.error(error); process.exitCode = 1; });
