(function () {
    'use strict';
    const assetRoot = '/js/vendor/pdfjs-6.5.438/';
    let libraryPromise;

    function library() {
        if (!libraryPromise) {
            libraryPromise = import(assetRoot + 'pdf.mjs').then(pdfjs => {
                pdfjs.GlobalWorkerOptions.workerSrc = assetRoot + 'pdf.worker.mjs';
                return pdfjs;
            }).catch(error => { libraryPromise = null; throw error; });
        }
        return libraryPromise;
    }

    function destroy(resource) {
        if (!resource) return;
        try { Promise.resolve(resource.destroy()).catch(() => {}); } catch { /* Already disposed. */ }
    }

    // A compatible source for browsers that cannot start PDF.js/modules/workers.
    // It reads images from an already generated, authenticated PDF preview.
    class ServerPreviewDocument {
        constructor(context) {
            if (!context || !/^[a-f0-9]{64}$/.test(context.key) || !Number.isSafeInteger(context.pages) || context.pages < 1) {
                throw new Error('Prévia de imagem indisponível.');
            }
            this.context = context;
            this.numPages = context.pages;
            this.pending = null;
            this.frames = new Map();
            this.closed = false;
        }

        destroy() {
            this.closed = true;
            if (this.pending) this.pending.abort();
            this.frames.forEach((url, image) => this.release(image));
        }

        release(image) {
            const url = this.frames.get(image);
            if (!url) return;
            this.frames.delete(image);
            image.removeAttribute('src');
            URL.revokeObjectURL(url);
        }

        async getPage(number) {
            if (this.closed) throw new Error('Prévia encerrada.');
            if (this.pending) this.pending.abort();
            const controller = new AbortController();
            this.pending = controller;
            const timeout = setTimeout(() => controller.abort(), 45000);
            let image;
            try {
                const body = new FormData();
                body.append('upload_token', this.context.token);
                body.append('preview_key', this.context.key);
                body.append('preview_page', number);
                body.append('csrf_token', this.context.csrf);
                const response = await fetch('/print/preview-image', { method: 'POST', body,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
                if (!response.ok) throw new Error('Não foi possível carregar a imagem da prévia.');
                const blob = await response.blob();
                if (this.closed || controller.signal.aborted) throw new Error('Prévia cancelada.');

                const url = URL.createObjectURL(blob);
                image = new Image();
                image.className = 'pdf-preview-image';
                image.alt = `Página ${number} de ${this.numPages} da prévia`;
                this.frames.set(image, url);
                await new Promise((resolve, reject) => {
                    const finish = (error) => {
                        image.onload = image.onerror = null;
                        controller.signal.removeEventListener('abort', cancel);
                        if (error) reject(error);
                        else resolve();
                    };
                    const cancel = () => finish(new Error('Prévia cancelada.'));
                    image.onload = () => finish();
                    image.onerror = () => finish(new Error('Não foi possível abrir a imagem da prévia.'));
                    controller.signal.addEventListener('abort', cancel, { once: true });
                    image.src = url;
                });
                if (this.closed || controller.signal.aborted) throw new Error('Prévia cancelada.');
                return image;
            } catch (error) {
                if (image) this.release(image);
                throw error;
            } finally {
                clearTimeout(timeout);
                if (this.pending === controller) this.pending = null;
            }
        }
    }

    class PrintPreview {
        constructor(host) {
            this.host = host;
            this.version = 0;
            this.pageVersion = 0;
            this.document = null;
            this.activeLoadingTask = null;
            this.loadingTask = null;
            this.pageLoadingTask = null;
            this.initialRender = null;
            this.pageRender = null;
            this.observer = null;
            this.resizeFrame = null;
            this.root = null;
            this.currentPage = 1;
        }

        cancelPending() {
            this.version++;
            if (this.initialRender) this.initialRender.cancel();
            this.initialRender = null;
            destroy(this.loadingTask);
            this.loadingTask = null;
            destroy(this.pageLoadingTask);
            this.pageLoadingTask = null;
        }

        clear() {
            this.cancelPending();
            this.pageVersion++;
            if (this.pageRender) this.pageRender.cancel();
            this.pageRender = null;
            if (this.observer) this.observer.disconnect();
            if (this.resizeFrame) cancelAnimationFrame(this.resizeFrame);
            this.resizeFrame = null;
            destroy(this.activeLoadingTask);
            this.activeLoadingTask = null;
            this.document = null;
            this.imageContext = null;
            this.url = null;
            this.root = null;
            this.host.replaceChildren();
        }

        width() {
            // The page area has an 8px inset on each side and a 1px outer border.
            return Math.max(100, this.host.clientWidth - 18);
        }

        async renderPage(pdf, number, width, current, registerTask) {
            if (pdf instanceof ServerPreviewDocument) {
                const image = await pdf.getPage(number);
                if (!current()) {
                    pdf.release(image);
                    throw new Error('Prévia substituída.');
                }
                return image;
            }
            const page = await pdf.getPage(number);
            try {
                if (!current()) throw new Error('Prévia substituída.');
                const natural = page.getViewport({ scale: 1 });
                const viewport = page.getViewport({ scale: width / natural.width });
                // Render one page at a time, at most 4 megapixels / 4096px per edge.
                const density = Math.min(window.devicePixelRatio || 1, 2,
                    Math.sqrt(4000000 / (viewport.width * viewport.height)),
                    4096 / viewport.width, 4096 / viewport.height);
                const canvas = document.createElement('canvas');
                canvas.className = 'pdf-preview-canvas';
                canvas.width = Math.ceil(viewport.width * density);
                canvas.height = Math.ceil(viewport.height * density);
                canvas.style.width = viewport.width + 'px';
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', `Página ${number} de ${pdf.numPages} da prévia`);
                const context = canvas.getContext('2d', { alpha: false });
                if (!context) throw new Error('Canvas indisponível.');
                const task = page.render({ canvas, canvasContext: context, viewport,
                    transform: [density, 0, 0, density, 0, 0], background: 'rgb(255,255,255)' });
                registerTask(task);
                await task.promise;
                return canvas;
            } finally { page.cleanup(); }
        }

        async load(blob, url, isCurrent, imageContext = null) {
            this.cancelPending();
            const version = this.version;
            const current = () => version === this.version && isCurrent();
            let loading;
            try {
                const pdfjs = await library();
                if (!current()) return false;
                const data = new Uint8Array(await blob.arrayBuffer());
                if (!current()) return false;
                loading = pdfjs.getDocument({ data, isEvalSupported: false, enableXfa: false,
                    cMapUrl: assetRoot + 'cmaps/', cMapPacked: true,
                    standardFontDataUrl: assetRoot + 'standard_fonts/',
                    wasmUrl: assetRoot + 'wasm/', iccUrl: assetRoot + 'iccs/' });
                this.loadingTask = loading;
                const pdf = await loading.promise;
                if (!current()) { destroy(loading); return false; }
                const width = this.width();
                const pageElement = await this.renderPage(pdf, 1, width, current, task => { this.initialRender = task; });
                if (!current()) { destroy(loading); return false; }
                this.commit(pdf, loading, pageElement, url, width, imageContext);
                return true;
            } catch (error) {
                destroy(loading);
                if (!current()) return false;
                if (imageContext) {
                    try {
                        loading = new ServerPreviewDocument(imageContext);
                        this.loadingTask = loading;
                        const width = this.width();
                        const pageElement = await this.renderPage(loading, 1, width, current, task => { this.initialRender = task; });
                        if (!current()) { destroy(loading); return false; }
                        this.commit(loading, loading, pageElement, url, width, imageContext);
                        return true;
                    } catch (imageError) {
                        destroy(loading);
                        if (!current()) return false;
                    }
                }
                // Keep a working sample when a complete document fails to render.
                if (this.document && this.root?.isConnected) throw error;
                if (this.document) this.clear();
                const fallback = document.createElement('a');
                fallback.className = 'btn btn-outline-secondary pdf-preview-open';
                fallback.href = url;
                fallback.target = '_blank';
                fallback.rel = 'noopener';
                fallback.textContent = 'Abrir prévia no navegador';
                const notice = document.createElement('p');
                notice.className = 'small text-muted mb-2';
                notice.setAttribute('role', 'status');
                notice.textContent = 'A prévia não pôde ser exibida nesta tela.';
                this.host.replaceChildren(notice, fallback);
                return true;
            } finally {
                if (this.loadingTask === loading) this.loadingTask = null;
                if (version === this.version) this.initialRender = null;
            }
        }

        commit(pdf, loading, pageElement, url, width, imageContext, page = 1) {
            if (this.loadingTask === loading) {
                this.loadingTask = null;
                this.initialRender = null;
            }
            if (this.pageLoadingTask !== loading) destroy(this.pageLoadingTask);
            this.pageLoadingTask = null;
            this.pageVersion++;
            if (this.pageRender) this.pageRender.cancel();
            this.pageRender = null;
            if (this.observer) this.observer.disconnect();
            if (this.resizeFrame) cancelAnimationFrame(this.resizeFrame);
            this.resizeFrame = null;
            // PDF.js 6 releases the document through its loading task.
            destroy(this.activeLoadingTask);
            this.activeLoadingTask = loading;
            this.document = pdf;
            this.currentPage = page;
            this.imageContext = imageContext;
            this.url = url;
            this.lastWidth = width;
            this.mount(pageElement, url);
        }

        mount(pageElement, url) {
            this.root = document.createElement('div');
            this.root.className = 'pdf-preview';
            const toolbar = document.createElement('div');
            toolbar.className = 'pdf-preview-toolbar';
            const navigation = document.createElement('div');
            navigation.className = 'pdf-preview-navigation';
            navigation.hidden = this.document.numPages <= 1;
            this.previous = this.button('‹', 'Página anterior', () => this.go(this.currentPage - 1));
            this.next = this.button('›', 'Próxima página', () => this.go(this.currentPage + 1));
            this.counter = document.createElement('span');
            this.counter.className = 'pdf-preview-counter';
            this.counter.setAttribute('role', 'status');
            navigation.append(this.previous, this.counter, this.next);
            const open = document.createElement('a');
            open.className = 'btn btn-outline-secondary btn-sm pdf-preview-open';
            open.href = url;
            open.target = '_blank';
            open.rel = 'noopener';
            open.title = 'Ampliar prévia em nova aba';
            open.setAttribute('aria-label', 'Ampliar prévia em nova aba');
            open.textContent = '⤢';
            this.pageArea = document.createElement('div');
            this.pageArea.className = 'pdf-preview-page';
            this.pageArea.append(pageElement);
            this.pageError = document.createElement('p');
            this.pageError.className = 'small text-danger mb-0 px-2';
            this.pageError.setAttribute('role', 'status');
            this.pageError.hidden = true;
            toolbar.append(navigation, open);
            this.root.append(toolbar, this.pageArea, this.pageError);
            this.host.replaceChildren(this.root);
            this.controls(false);
            if (!(this.document instanceof ServerPreviewDocument) && typeof ResizeObserver !== 'undefined') {
                this.observer = new ResizeObserver(() => {
                    if (!this.root?.isConnected || Math.abs(this.width() - this.lastWidth) <= 1) return;
                    if (this.resizeFrame) cancelAnimationFrame(this.resizeFrame);
                    this.resizeFrame = requestAnimationFrame(() => this.go(this.currentPage));
                });
                this.observer.observe(this.host);
            }
        }

        button(text, label, action) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-secondary btn-sm';
            button.setAttribute('aria-label', label);
            button.title = label;
            button.textContent = text;
            button.addEventListener('click', action);
            return button;
        }

        controls(busy) {
            this.root.setAttribute('aria-busy', busy ? 'true' : 'false');
            this.previous.disabled = busy || this.currentPage <= 1;
            this.next.disabled = busy || this.currentPage >= this.document.numPages;
            this.counter.textContent = `${this.currentPage} / ${this.document.numPages}`;
        }

        async go(number) {
            const pdf = this.document;
            if (!pdf || !this.root?.isConnected || number < 1 || number > pdf.numPages) return;
            const version = ++this.pageVersion;
            destroy(this.pageLoadingTask);
            this.pageLoadingTask = null;
            const current = () => this.document === pdf && version === this.pageVersion;
            if (this.pageRender) this.pageRender.cancel();
            this.controls(true);
            this.pageError.hidden = true;
            try {
                const width = this.width();
                const pageElement = await this.renderPage(pdf, number, width, current, task => { this.pageRender = task; });
                if (!current()) {
                    if (pdf instanceof ServerPreviewDocument) pdf.release(pageElement);
                    return;
                }
                if (pdf instanceof ServerPreviewDocument) pdf.release(this.pageArea.firstElementChild);
                this.pageArea.replaceChildren(pageElement);
                this.currentPage = number;
                this.lastWidth = width;
            } catch (error) {
                if (!current() || error.name === 'RenderingCancelledException') return;
                if (this.imageContext && !(pdf instanceof ServerPreviewDocument)) {
                    let fallback;
                    try {
                        fallback = new ServerPreviewDocument(this.imageContext);
                        this.pageLoadingTask = fallback;
                        const width = this.width();
                        const valid = () => current() && this.pageLoadingTask === fallback;
                        const pageElement = await this.renderPage(fallback, number, width, valid, task => { this.pageRender = task; });
                        if (!valid()) { destroy(fallback); return; }
                        this.commit(fallback, fallback, pageElement, this.url, width, this.imageContext, number);
                        return;
                    } catch {
                        destroy(fallback);
                        if (!current() || this.pageLoadingTask !== fallback) return;
                    } finally {
                        if (this.pageLoadingTask === fallback) this.pageLoadingTask = null;
                    }
                }
                this.pageError.textContent = 'Não foi possível exibir esta página. Use o ícone para ampliar.';
                this.pageError.hidden = false;
            } finally {
                if (current()) { this.pageRender = null; this.controls(false); }
            }
        }
    }

    window.createPrintPreview = host => new PrintPreview(host);
}());
