// proconSEO · interacción común a todas las pantallas (Parte 0). Fichero propio: CSP 'self'.
//
// Ayuda en contexto (.pista) según WCAG 1.4.13:
//  - se abre al pasar el ratón por encima o al llegar con el teclado;
//  - se puede mover el ratón al globo sin que desaparezca;
//  - se cierra con Esc, al salir con el ratón o con el foco.
// Sin JavaScript no pasa nada malo: el término es un enlace normal al glosario.
(() => {
    'use strict';
    const abiertas = new Set();

    const cerrar = (pista) => {
        const globo = pista.querySelector('.pista__globo');
        if (globo) globo.hidden = true;
        abiertas.delete(pista);
    };

    const abrir = (pista) => {
        for (const otra of [...abiertas]) if (otra !== pista) cerrar(otra);
        const globo = pista.querySelector('.pista__globo');
        if (!globo) return;
        globo.hidden = false;
        abiertas.add(pista);
        // Si no cabe a la derecha, se abre hacia la izquierda.
        pista.classList.remove('pista--izquierda');
        if (globo.getBoundingClientRect().right > document.documentElement.clientWidth - 12) {
            pista.classList.add('pista--izquierda');
        }
    };

    for (const pista of document.querySelectorAll('.pista')) {
        const disparador = pista.querySelector('a, button');
        if (!disparador) continue;
        let espera;
        pista.addEventListener('mouseenter', () => { clearTimeout(espera); abrir(pista); });
        pista.addEventListener('mouseleave', () => { espera = setTimeout(() => cerrar(pista), 300); });
        disparador.addEventListener('focus', () => abrir(pista));
        disparador.addEventListener('blur', () => cerrar(pista));
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') for (const pista of [...abiertas]) cerrar(pista);
    });

    // Botones «Copiar» (data-copiar="#id"): copian el texto del elemento y lo anuncian.
    for (const boton of document.querySelectorAll('[data-copiar]')) {
        boton.addEventListener('click', async () => {
            const origen = document.querySelector(boton.dataset.copiar);
            const texto = origen ? (origen.dataset.texto || origen.textContent || '') : '';
            try {
                await navigator.clipboard.writeText(texto);
            } catch {
                return;
            }
            const antes = boton.textContent;
            boton.textContent = 'Copiado';
            setTimeout(() => { boton.textContent = antes; }, 1800);
        });
    }
})();
