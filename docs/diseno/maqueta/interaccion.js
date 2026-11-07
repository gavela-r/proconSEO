// proconSEO · interacción de la maqueta. Fichero propio (nada externo ni en línea: CSP 'self').
//
// Ayuda en contexto (toggletip) según WCAG 1.4.13:
//  - se abre al pasar el ratón, al llegar con el teclado (foco) o al tocar/pulsar;
//  - se puede pasar el ratón por encima del globo sin que desaparezca;
//  - se cierra con Esc, al salir con el ratón o el foco, o al pulsar fuera;
//  - al pulsar se queda fijo hasta volver a pulsar.
(() => {
    'use strict';
    const abiertas = new Set();

    const cerrar = (ayuda) => {
        const globo = ayuda.querySelector('.ayuda__globo');
        const boton = ayuda.querySelector('[aria-expanded]');
        globo.hidden = true;
        boton.setAttribute('aria-expanded', 'false');
        ayuda.dataset.fija = '';
        abiertas.delete(ayuda);
    };

    const abrir = (ayuda) => {
        for (const otra of [...abiertas]) if (otra !== ayuda) cerrar(otra);
        const globo = ayuda.querySelector('.ayuda__globo');
        ayuda.querySelector('[aria-expanded]').setAttribute('aria-expanded', 'true');
        globo.hidden = false;
        abiertas.add(ayuda);
        // Si no cabe a la derecha, se abre hacia la izquierda.
        ayuda.classList.remove('ayuda--izquierda');
        if (globo.getBoundingClientRect().right > document.documentElement.clientWidth - 12) {
            ayuda.classList.add('ayuda--izquierda');
        }
    };

    for (const ayuda of document.querySelectorAll('.ayuda')) {
        const boton = ayuda.querySelector('[aria-expanded]');
        let espera;
        ayuda.addEventListener('mouseenter', () => { clearTimeout(espera); abrir(ayuda); });
        ayuda.addEventListener('mouseleave', () => {
            if (ayuda.dataset.fija !== 'si') espera = setTimeout(() => cerrar(ayuda), 300);
        });
        boton.addEventListener('focus', () => abrir(ayuda));
        ayuda.addEventListener('focusout', (e) => { if (!ayuda.contains(e.relatedTarget)) cerrar(ayuda); });
        boton.addEventListener('click', (e) => {
            e.preventDefault();
            if (ayuda.dataset.fija === 'si') { cerrar(ayuda); } else { abrir(ayuda); ayuda.dataset.fija = 'si'; }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || abiertas.size === 0) return;
        for (const ayuda of [...abiertas]) cerrar(ayuda);
    });
    document.addEventListener('click', (e) => {
        for (const ayuda of [...abiertas]) if (!ayuda.contains(e.target)) cerrar(ayuda);
    });

    // Copiar evidencias o tareas al portapapeles, con aviso para lectores de pantalla.
    const anuncio = document.querySelector('.anuncio');
    for (const boton of document.querySelectorAll('[data-copiar]')) {
        boton.addEventListener('click', async () => {
            const origen = document.querySelector(boton.dataset.copiar);
            const texto = origen ? (origen.dataset.texto || origen.innerText) : '';
            try {
                await navigator.clipboard.writeText(texto);
            } catch {
                const area = document.createElement('textarea');
                area.value = texto; document.body.append(area); area.select();
                document.execCommand('copy'); area.remove();
            }
            const antes = boton.textContent;
            boton.textContent = 'Copiado';
            if (anuncio) anuncio.textContent = 'Copiado al portapapeles';
            setTimeout(() => { boton.textContent = antes; if (anuncio) anuncio.textContent = ''; }, 1800);
        });
    }
})();
