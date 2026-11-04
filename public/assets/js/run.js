// Parte 1: actualiza la pantalla de progreso y permite cancelar sin recargar.
(() => {
    const contenedor = document.getElementById('progreso');
    if (!contenedor || contenedor.dataset.enMarcha !== '1') {
        return;
    }

    const id = contenedor.dataset.id;
    const form = document.getElementById('form-cancelar');
    const token = form?.querySelector('input[name="_csrf"]')?.value ?? '';
    const estadoTexto = document.getElementById('estado-texto');
    const motivo = document.getElementById('motivo');
    const verResultados = document.getElementById('ver-resultados');

    const pintar = (datos) => {
        estadoTexto.textContent = datos.estado_texto;
        for (const f of datos.fases) {
            const li = contenedor.querySelector(`[data-fase="${f.fase}"]`);
            if (!li) continue;
            li.className = `fase fase--${f.estado}`;
            const progreso = f.total > 0 ? ` (${f.hechos} de ${f.total})` : '';
            li.querySelector('.fase__estado').textContent = f.estado_texto + progreso;
            li.querySelector('.fase__mensaje').textContent = f.mensaje ?? '';
        }
        if (datos.motivo) {
            motivo.textContent = datos.motivo;
            motivo.hidden = false;
        }
        if (datos.terminado) {
            form.hidden = true;
            verResultados.hidden = false;
        }
    };

    let temporizador;
    const consultar = async () => {
        try {
            const respuesta = await fetch(`/api/analisis/${id}/progreso`, { headers: { Accept: 'application/json' } });
            if (respuesta.ok) {
                const datos = await respuesta.json();
                pintar(datos);
                if (datos.terminado) return;
            }
        } catch (e) {
            // Sin conexión momentánea: se reintenta en el siguiente ciclo.
        }
        temporizador = setTimeout(consultar, 2000);
    };

    form?.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        if (!confirm('¿Cancelar el análisis? Se conservarán los resultados obtenidos hasta ahora.')) return;
        await fetch(`/api/analisis/${id}/cancelar`, { method: 'POST', headers: { 'X-CSRF-Token': token, Accept: 'application/json' } });
        clearTimeout(temporizador);
        consultar();
    });

    consultar();
})();
