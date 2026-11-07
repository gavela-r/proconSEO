// Mediciones de proconSEO dentro del navegador aislado.
//
// Se ejecuta en un «mundo aislado» (Page.createIsolatedWorld): comparte el DOM con la página,
// pero no sus variables, así que los scripts de la web no pueden ver ni alterar estas funciones.
// Solo lee: no hace clics, no envía formularios y no hace peticiones. El texto de la página es
// un dato: se recorta y se devuelve tal cual, sin interpretarlo (SEG-07).
(() => {
    'use strict';
    const MAX_MUESTRAS = 1500;
    const MAX_ELEMENTOS = 3000;
    const NO_TEXTO = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'TITLE', 'OPTION']);
    const GRAFICOS = new Set(['IMG', 'VIDEO', 'CANVAS', 'PICTURE', 'svg', 'SVG', 'IFRAME', 'OBJECT', 'EMBED']);

    // Cualquier color CSS (rgb, hsl, oklch, color(), nombres…) a [r, g, b, a] con un lienzo de 1×1.
    const lienzo = new OffscreenCanvas(1, 1).getContext('2d', { willReadFrequently: true });
    const colores = new Map();
    const rgba = (c) => {
        if (colores.has(c)) return colores.get(c);
        lienzo.clearRect(0, 0, 1, 1);
        lienzo.fillStyle = 'rgba(0,0,0,0)';
        lienzo.fillStyle = c;
        lienzo.fillRect(0, 0, 1, 1);
        const d = lienzo.getImageData(0, 0, 1, 1).data;
        const v = [d[0], d[1], d[2], Math.round((d[3] / 255) * 1000) / 1000];
        colores.set(c, v);
        return v;
    };

    const recortar = (t, n) => (t || '').replace(/\s+/g, ' ').trim().slice(0, n);

    // Selector corto para localizar el elemento: etiqueta, id o clases, hasta tres niveles.
    const selector = (el) => {
        const partes = [];
        for (let e = el; e && e.nodeType === 1 && partes.length < 3; e = e.parentElement) {
            let p = e.tagName.toLowerCase();
            if (e.id) { partes.unshift(p + '#' + CSS.escape(e.id).slice(0, 40)); break; }
            const clases = [...e.classList].slice(0, 2).map((c) => '.' + CSS.escape(c).slice(0, 30)).join('');
            partes.unshift(p + clases);
        }
        return partes.join(' > ').slice(0, 200);
    };

    const visible = (el) => {
        if (!el.checkVisibility({ opacityProperty: true, visibilityProperty: true })) return false;
        const r = el.getBoundingClientRect();
        return r.width > 1 && r.height > 1;
    };

    const terminarAnimaciones = (el) => {
        for (const a of el.getAnimations({ subtree: false })) {
            try { a.finish(); } catch (e) { /* animaciones infinitas: se dejan */ }
        }
    };

    const pf = {};

    pf.dom = (maxBytes) => {
        const html = document.documentElement ? document.documentElement.outerHTML : '';
        return { html: html.slice(0, maxBytes), truncado: html.length > maxBytes, url: location.href };
    };

    pf.palabras = () => {
        const t = document.body ? document.body.innerText : '';
        return (t.match(/[\p{L}\p{N}]+/gu) || []).length;
    };

    pf.alto = () => Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0);

    // ACC-05 (1.4.3): para cada elemento con texto propio, su color y las capas de fondo que
    // hay debajo de ese punto, de arriba abajo. El cálculo del contraste se hace en PHP.
    pf.contraste = () => {
        const elementos = new Map();
        const caminante = document.createTreeWalker(document.body || document.documentElement, NodeFilter.SHOW_TEXT);
        while (caminante.nextNode() && elementos.size < MAX_ELEMENTOS) {
            const t = caminante.currentNode;
            const p = t.parentElement;
            if (!p || NO_TEXTO.has(p.tagName) || t.nodeValue.trim() === '') continue;
            if (!elementos.has(p)) elementos.set(p, t);
        }
        const muestras = [];
        let tapados = 0, fueraDeVista = 0, inactivos = 0;
        const altoVista = window.innerHeight, anchoVista = window.innerWidth;
        for (const [el, nodo] of elementos) {
            if (muestras.length >= MAX_MUESTRAS) break;
            if (!visible(el)) continue;
            if (el.closest(':disabled, [aria-disabled="true"]')) { inactivos++; continue; }
            const rango = document.createRange();
            rango.selectNodeContents(nodo);
            const caja = [...rango.getClientRects()].find((r) => r.width > 0 && r.height > 0);
            if (!caja) continue;
            const x = caja.left + caja.width / 2, y = caja.top + caja.height / 2;
            if (x < 0 || y < 0 || x >= anchoVista || y >= altoVista) { fueraDeVista++; continue; }
            const pila = document.elementsFromPoint(x, y);
            const i = pila.indexOf(el);
            // Si encima hay algo que no es parte del propio elemento, el texto está tapado.
            if (i < 0 || pila.slice(0, i).some((e) => !el.contains(e))) { tapados++; continue; }
            const cs = getComputedStyle(el);
            let motivo = null;
            if (cs.backgroundClip === 'text' || cs.webkitBackgroundClip === 'text') motivo = 'Texto relleno con una imagen o un degradado';
            else if (cs.textShadow !== 'none') motivo = 'Texto con sombra';
            else if (parseFloat(cs.webkitTextStrokeWidth || '0') > 0) motivo = 'Texto con contorno';
            const capas = [];
            for (const e of pila.slice(i)) {
                if (motivo) break;
                if (e !== el && GRAFICOS.has(e.tagName)) { motivo = 'Texto sobre una imagen, un vídeo o un gráfico'; break; }
                const s = getComputedStyle(e);
                if (s.backgroundImage !== 'none') { motivo = 'Fondo con imagen o degradado'; break; }
                if (s.backdropFilter && s.backdropFilter !== 'none') { motivo = 'Fondo con filtros'; break; }
                if (s.filter && s.filter !== 'none' && e !== document.documentElement) { motivo = 'Elemento con filtros de color'; break; }
                const c = rgba(s.backgroundColor);
                if (c[3] > 0) { capas.push(c); if (c[3] >= 1) break; }
            }
            let opacidad = 1;
            for (let e = el; e && e.nodeType === 1; e = e.parentElement) opacidad *= parseFloat(getComputedStyle(e).opacity) || 0;
            muestras.push({
                texto: recortar(nodo.nodeValue, 80),
                selector: selector(el),
                color: rgba(cs.color),
                opacidad: Math.round(opacidad * 1000) / 1000,
                capas,
                motivo,
                tamano: parseFloat(cs.fontSize) || 16,
                peso: parseInt(cs.fontWeight, 10) || 400,
            });
        }
        return { muestras, tapados, fueraDeVista, inactivos, elementos: elementos.size };
    };

    // ACC-06 (2.1.2, 2.4.7): estado del foco tras cada Tab real (lo pulsa PHP).
    const estiloFoco = (e) => {
        terminarAnimaciones(e);
        const s = getComputedStyle(e);
        // Solo lo que se ve: un contorno oculto (outline: none) puede cambiar de grosor o de
        // desplazamiento sin que nadie lo note, y un borde de 0 px puede cambiar de color.
        const contorno = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0
            ? [s.outlineStyle, s.outlineWidth, s.outlineColor, s.outlineOffset].join(' ') : 'sin contorno';
        const bordes = ['Top', 'Right', 'Bottom', 'Left'].map((l) => (s['border' + l + 'Style'] !== 'none' && parseFloat(s['border' + l + 'Width']) > 0
            ? [s['border' + l + 'Style'], s['border' + l + 'Width'], s['border' + l + 'Color']].join(' ') : '-'));
        return [contorno, ...bordes, s.boxShadow, s.backgroundColor, s.color, s.textDecorationLine, s.textDecorationColor].join('|');
    };
    const foco = { pasos: [], anterior: null };
    pf.empezarFoco = () => {
        foco.pasos = []; foco.anterior = null;
        if (document.activeElement && document.activeElement !== document.body) document.activeElement.blur();
        window.scrollTo(0, 0);
        return document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [tabindex]:not([tabindex="-1"]), [contenteditable=""], [contenteditable=true], summary').length;
    };
    pf.paso = () => {
        const a = document.activeElement;
        const mismo = !!(foco.anterior && foco.anterior.el === a);
        const primero = foco.pasos.length > 1 && foco.pasos[0].el === a;
        if (foco.anterior && foco.anterior.el !== a && foco.anterior.sinFoco === null) foco.anterior.sinFoco = estiloFoco(foco.anterior.el);
        if (!a || a === document.body || a === document.documentElement) { foco.anterior = null; return { fin: true }; }
        if (a.tagName === 'IFRAME') { foco.anterior = null; return { fin: false, marco: true, selector: selector(a), etiqueta: 'iframe' }; }
        const r = { el: a, conFoco: estiloFoco(a), sinFoco: null };
        foco.pasos.push(r);
        foco.anterior = r;
        const caja = a.getBoundingClientRect();
        const enVista = caja.bottom > 0 && caja.right > 0 && caja.top < window.innerHeight && caja.left < window.innerWidth;
        return {
            fin: false,
            mismo,
            primero,
            indice: foco.pasos.length - 1,
            selector: selector(a),
            etiqueta: a.tagName.toLowerCase(),
            nombre: recortar(a.getAttribute('aria-label') || a.innerText || a.value || a.getAttribute('title') || a.getAttribute('alt') || '', 60),
            visible: visible(a) && enVista,
        };
    };
    pf.terminarFoco = () => {
        for (const p of foco.pasos) {
            if (p.sinFoco === null && p.el.isConnected) {
                if (document.activeElement === p.el) p.el.blur();
                p.sinFoco = estiloFoco(p.el);
            }
        }
        return foco.pasos.map((p) => (p.sinFoco === null ? null : p.conFoco !== p.sinFoco));
    };

    // ACC-04 (1.4.10): con 320 px de ancho, ¿hay desplazamiento horizontal y quién lo provoca?
    pf.reflow = () => {
        const ancho = document.documentElement.clientWidth;
        const total = document.documentElement.scrollWidth;
        const culpables = [];
        if (total > ancho + 1) {
            const desplaza = (e) => {
                for (let p = e.parentElement; p && p !== document.body; p = p.parentElement) {
                    const ox = getComputedStyle(p).overflowX;
                    if (ox === 'auto' || ox === 'scroll' || ox === 'hidden' || ox === 'clip') return true;
                }
                return false;
            };
            for (const e of document.body.querySelectorAll('*')) {
                if (culpables.length >= 8) break;
                const r = e.getBoundingClientRect();
                if (r.right <= ancho + 1 || r.width === 0 || !visible(e) || desplaza(e)) continue;
                // Solo el más externo de cada rama.
                if (culpables.some((c) => c.el.contains(e))) continue;
                culpables.push({ el: e, selector: selector(e), etiqueta: e.tagName.toLowerCase(), derecha: Math.round(r.right) });
            }
        }
        return { ancho, total, culpables: culpables.map(({ el, ...resto }) => resto) };
    };

    return pf;
})()
