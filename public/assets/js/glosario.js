// Glosario: filtra los términos mientras se escribe y por bloque. Fichero propio (CSP 'self').
// Sin JavaScript se ve todo y el índice de letras funciona igual.
(() => {
    'use strict';
    const texto = document.getElementById('glosario-texto');
    const resultado = document.getElementById('glosario-resultado');
    const vacio = document.getElementById('glosario-vacio');
    const botones = [...document.querySelectorAll('.glosario__bloque')];
    const fichas = [...document.querySelectorAll('.glosario__ficha')];
    const grupos = [...document.querySelectorAll('.glosario__grupo')];
    const letras = [...document.querySelectorAll('.glosario__letras a')];
    if (!texto || fichas.length === 0) return;

    // Sin tildes, para que «indexacion» encuentre «indexación».
    const normal = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    for (const f of fichas) f.dataset.normal = normal(f.dataset.busqueda || '');
    let bloque = '';
    let espera;

    const aplicar = () => {
        const q = normal(texto.value.trim());
        let visibles = 0;
        for (const f of fichas) {
            const ok = (bloque === '' || f.dataset.bloque === bloque) && (q === '' || f.dataset.normal.includes(q));
            f.hidden = !ok;
            if (ok) visibles++;
        }
        for (const g of grupos) {
            const alguna = [...g.querySelectorAll('.glosario__ficha')].some((f) => !f.hidden);
            g.hidden = !alguna;
            const enlace = letras.find((a) => a.dataset.letra === g.dataset.letra);
            if (enlace) {
                enlace.classList.toggle('glosario__letra--sin', !alguna);
                if (alguna) enlace.removeAttribute('aria-disabled'); else enlace.setAttribute('aria-disabled', 'true');
            }
        }
        vacio.hidden = visibles !== 0;
        const filtrando = q !== '' || bloque !== '';
        resultado.textContent = filtrando ? (visibles === 1 ? '1 término coincide' : `${visibles} términos coinciden`) : '';
    };

    texto.addEventListener('input', () => { clearTimeout(espera); espera = setTimeout(aplicar, 120); });
    texto.addEventListener('keydown', (e) => { if (e.key === 'Escape' && texto.value !== '') { texto.value = ''; aplicar(); } });
    for (const b of botones) {
        b.addEventListener('click', () => {
            bloque = b.dataset.bloque || '';
            for (const otro of botones) otro.setAttribute('aria-pressed', String(otro === b));
            aplicar();
        });
    }
    // Si se entra con #término (desde un resultado), que no quede oculto por un filtro.
    if (location.hash) {
        const destino = document.getElementById(decodeURIComponent(location.hash.slice(1)));
        if (destino) destino.hidden = false;
    }
})();
