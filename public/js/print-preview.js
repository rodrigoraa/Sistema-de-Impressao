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
            this.frames = new Set();
            this.closed = false;
        }

        destroy() {
            this.closed = true;
            if (this.pending) this.pending.abort();
            this.frames.forEach(cleanup => cleanup());
            this.frames.clear();
        }

        async getPage(number) {
            if (this.closed) throw new Error('Prévia encerrada.');
            if (this.pending) this.pending.abort();
            const controller = new AbortController();
            this.pending = controller;
            let cleanup;
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
                const image = new Image();
                let released = false;
                cleanup = () => {
                    if (released) return;
                    released = true;
                    URL.revokeObjectURL(url);
                    image.src = '';
                    this.frames.delete(cleanup);
                };
                this.frames.add(cleanup);
                image.src = url;
                await image.decode();
                if (this.closed || controller.signal.aborted) throw new Error('Prévia cancelada.');
                return {
                    getViewport: ({ scale }) => ({ width: image.naturalWidth * scale, height: image.naturalHeight * scale }),
                    render: ({ canvasContext, viewport, transform }) => {
                        let cancelled = false;
                        const promise = Promise.resolve().then(() => {
                            if (cancelled || this.closed || controller.signal.aborted) throw new Error('Prévia cancelada.');
                            canvasContext.setTransform(...transform);
                            canvasContext.fillStyle = '#fff';
                            canvasContext.fillRect(0, 0, viewport.width, viewport.height);
                            canvasContext.drawImage(image, 0, 0, viewport.width, viewport.height);
                        });
                        return { promise, cancel: () => { cancelled = true; } };
                    },
                    cleanup
                };
            } catch (error) {
                if (cleanup) cleanup();
                throw error;
            } finally { if (this.pending === controller) this.pending = null; }
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

        async canvas(pdf, number, width, current, registerTask) {
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
                const canvas = await this.canvas(pdf, 1, width, current, task => { this.initialRender = task; });
                if (!current()) { destroy(loading); return false; }
                this.commit(pdf, loading, canvas, url, width, imageContext);
                return true;
            } catch (error) {
                destroy(loading);
                if (!current()) return false;
                if (imageContext) {
                    try {
                        loading = new ServerPreviewDocument(imageContext);
                        this.loadingTask = loading;
                        const width = this.width();
                        const canvas = await this.canvas(loading, 1, width, current, task => { this.initialRender = task; });
                        if (!current()) { destroy(loading); return false; }
                        this.commit(loading, loading, canvas, url, width, imageContext);
                        return true;
                    } catch (imageError) {
                        destroy(loading);
                        if (!current()) return false;
                    }
                }
                // Keep a working sample when a complete document fails to render.
                if (this.document) throw error;
                const fallback = document.createElement('a');
                fallback.className = 'btn btn-outline-secondary pdf-preview-open';
                fallback.href = url;
                fallback.target = '_blank';
                fallback.rel = 'noopener';
                fallback.textContent = 'Abrir prévia no navegador';
                this.host.replaceChildren(fallback);
                return true;
            } finally {
                if (this.loadingTask === loading) this.loadingTask = null;
                if (version === this.version) this.initialRender = null;
            }
        }

        commit(pdf, loading, canvas, url, width, imageContext, page = 1) {
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
            this.mount(canvas, url);
        }

        mount(canvas, url) {
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
            this.pageArea.append(canvas);
            this.pageError = document.createElement('p');
            this.pageError.className = 'small text-danger mb-0 px-2';
            this.pageError.setAttribute('role', 'status');
            this.pageError.hidden = true;
            toolbar.append(navigation, open);
            this.root.append(toolbar, this.pageArea, this.pageError);
            this.host.replaceChildren(this.root);
            this.controls(false);
            if (typeof ResizeObserver !== 'undefined') {
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
                const canvas = await this.canvas(pdf, number, width, current, task => { this.pageRender = task; });
                if (!current()) return;
                this.pageArea.replaceChildren(canvas);
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
                        const canvas = await this.canvas(fallback, number, width, valid, task => { this.pageRender = task; });
                        if (!valid()) { destroy(fallback); return; }
                        this.commit(fallback, fallback, canvas, this.url, width, this.imageContext, number);
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
