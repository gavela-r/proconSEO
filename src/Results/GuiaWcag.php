<?php

declare(strict_types=1);

namespace ProconSEO\Results;

/**
 * Cómo revisar a mano cada criterio WCAG 2.2 A y AA: pasos cortos que puede seguir cualquiera del
 * equipo con un navegador, el teclado y, cuando hace falta, un lector de pantalla (NVDA en Windows,
 * gratuito). Resumen práctico de los «Understanding» y de las técnicas del W3C; no los sustituye.
 *
 * Cada guía dice qué hacer y qué tiene que pasar para que esté bien. Si no se cumple, es «problema».
 */
final class GuiaWcag
{
    /** @var array<string, list<string>> criterio => pasos */
    private const PASOS = [
        '1.1.1' => [
            'Recorre las imágenes importantes (logo, fotos de producto, iconos que son botones, gráficos).',
            'Mira su texto alternativo (clic derecho > Inspeccionar, atributo alt, o con el lector de pantalla).',
            'Bien si cada imagen que informa tiene un texto que dice lo mismo que la imagen, y las decorativas tienen alt="" vacío.',
        ],
        '1.2.1' => [
            'Busca audios o vídeos sin sonido (animaciones, podcasts).',
            'Bien si junto a cada uno hay una transcripción o una descripción de texto con la misma información.',
        ],
        '1.2.2' => [
            'Reproduce cada vídeo grabado con voz.',
            'Bien si tiene subtítulos (no automáticos sin revisar) que recogen lo que se dice y los sonidos importantes.',
        ],
        '1.2.3' => [
            'Reproduce cada vídeo con imágenes que informan (textos en pantalla, demostraciones).',
            'Bien si hay audiodescripción o una transcripción que cuenta también lo que se ve.',
        ],
        '1.2.4' => [
            'Solo si la web emite en directo (eventos, webinars).',
            'Bien si la emisión tiene subtítulos en directo. Si no hay directos, marca «No aplica».',
        ],
        '1.2.5' => [
            'Reproduce cada vídeo grabado en el que pasan cosas que no se dicen en voz alta.',
            'Bien si tiene una pista o versión con audiodescripción.',
        ],
        '1.3.1' => [
            'Con el lector de pantalla, recorre la página por encabezados (tecla H en NVDA) y por listas y tablas.',
            'Bien si lo que se ve como título es un encabezado, lo que se ve como lista es una lista y las tablas de datos tienen cabeceras.',
            'Revisa los formularios: cada campo debe anunciar su etiqueta.',
        ],
        '1.3.2' => [
            'Recorre la página con el lector de pantalla de principio a fin (flecha abajo).',
            'Bien si el orden en que se lee tiene sentido y coincide con el que se ve.',
        ],
        '1.3.3' => [
            'Busca instrucciones que dependan solo de la forma, el color, el tamaño o la posición («pulsa el botón verde», «el menú de la derecha»).',
            'Bien si cada instrucción se entiende también sin ver la pantalla.',
        ],
        '1.3.4' => [
            'Abre la web en el móvil y gíralo (o en el navegador, modo dispositivo, girando la vista).',
            'Bien si se puede usar tanto en vertical como en horizontal.',
        ],
        '1.3.5' => [
            'Revisa los formularios con datos personales (nombre, correo, teléfono, dirección).',
            'Bien si el navegador ofrece autocompletarlos (atributo autocomplete correcto).',
        ],
        '1.4.1' => [
            'Busca información que se dé solo con color: enlaces dentro del texto, campos con error, leyendas de gráficos, estados.',
            'Bien si además del color hay otra señal (subrayado, icono, texto).',
        ],
        '1.4.2' => [
            'Carga las páginas con sonido.',
            'Bien si ningún sonido de más de 3 segundos empieza solo, o si hay un botón visible para pararlo o bajarlo.',
        ],
        '1.4.3' => [
            'Mira los textos sobre imágenes, en botones y en estados (al pasar el ratón, con el foco), que la herramienta no mide.',
            'Comprueba el contraste con el selector de color de las herramientas del navegador.',
            'Bien si llega a 4,5:1 (3:1 en textos grandes).',
        ],
        '1.4.4' => [
            'Amplía el navegador al 200 % (Ctrl y +).',
            'Bien si se puede leer todo y no se corta ni se solapa ningún texto.',
        ],
        '1.4.5' => [
            'Busca imágenes que contengan texto (banners, botones hechos con imagen).',
            'Bien si ese texto está como texto real, salvo logos o casos en que la imagen sea imprescindible.',
        ],
        '1.4.10' => [
            'Pon la ventana a 320 píxeles de ancho (o el navegador al 400 %).',
            'Bien si no hace falta desplazarse en horizontal para leer, salvo en tablas, mapas o gráficos.',
        ],
        '1.4.11' => [
            'Mira los bordes de los campos de formulario, los iconos que son botones y el foco.',
            'Bien si se distinguen del fondo con un contraste de al menos 3:1.',
        ],
        '1.4.12' => [
            'Aumenta el espaciado del texto (con un marcador o extensión de «text spacing»).',
            'Bien si no se corta ni se pierde ningún texto.',
        ],
        '1.4.13' => [
            'Pasa el ratón y el foco por menús desplegables y tooltips.',
            'Bien si se pueden cerrar con Esc, se puede mover el ratón encima sin que desaparezcan y no se van solos.',
        ],
        '2.1.1' => [
            'Deja el ratón y recorre la página solo con Tab, Mayús+Tab, Intro, espacio y flechas.',
            'Prueba el menú, los desplegables, los formularios, los carruseles, el chat y las ventanas emergentes.',
            'Bien si se puede llegar a todo y usarlo con el teclado.',
        ],
        '2.1.2' => [
            'Con Tab, entra en cada componente (vídeos, mapas, ventanas, chat).',
            'Bien si siempre se puede salir con el teclado (Tab, Esc) sin quedarse atrapado.',
        ],
        '2.1.4' => [
            'Busca atajos de una sola tecla (letras, números) en buscadores, reproductores o chats.',
            'Bien si no los hay, o se pueden desactivar o cambiar.',
        ],
        '2.2.1' => [
            'Busca límites de tiempo: sesiones que caducan, formularios con tiempo, mensajes que desaparecen.',
            'Bien si se puede desactivar, ampliar o se avisa antes y se puede pedir más tiempo.',
        ],
        '2.2.2' => [
            'Busca carruseles, animaciones o textos que se mueven solos más de 5 segundos.',
            'Bien si hay un botón para pausarlos o pararlos.',
        ],
        '2.3.1' => [
            'Busca vídeos o animaciones con destellos.',
            'Bien si nada destella más de tres veces por segundo.',
        ],
        '2.4.1' => [
            'Pulsa Tab nada más cargar la página.',
            'Bien si aparece un enlace «Saltar al contenido» que funciona, o si la página tiene encabezados y zonas (main, nav) para saltar.',
        ],
        '2.4.2' => [
            'Mira el título de la pestaña en varias páginas.',
            'Bien si cada página tiene un título que dice qué es y de qué web.',
        ],
        '2.4.3' => [
            'Recorre la página con Tab.',
            'Bien si el foco va en un orden lógico; y al abrir una ventana, el foco entra en ella y vuelve al cerrarla.',
        ],
        '2.4.4' => [
            'Lee los enlaces «Leer más», «Aquí», «Ver» y los de iconos.',
            'Bien si por el propio enlace o por la frase que lo rodea se entiende adónde lleva.',
        ],
        '2.4.5' => [
            'Busca al menos dos formas de llegar a las páginas: menú, buscador, mapa del sitio, enlaces relacionados.',
            'Bien si las hay (no hace falta en los pasos de un proceso, como un pago).',
        ],
        '2.4.6' => [
            'Lee los encabezados y las etiquetas de los formularios.',
            'Bien si describen de qué trata cada parte o qué hay que escribir en cada campo.',
        ],
        '2.4.7' => [
            'Recorre la página con Tab.',
            'Bien si siempre se ve dónde está el foco (un borde, un subrayado, un cambio claro).',
        ],
        '2.4.11' => [
            'Recorre la página con Tab con la cabecera fija y los avisos (cookies, chat) visibles.',
            'Bien si el elemento con foco nunca queda tapado del todo por ellos.',
        ],
        '2.5.1' => [
            'Busca acciones con gestos de varios dedos o con trazos (pellizcar, deslizar en una dirección).',
            'Bien si también se pueden hacer con un toque o clic sencillo.',
        ],
        '2.5.2' => [
            'En los botones importantes, pulsa y, sin soltar, aparta el dedo o el ratón.',
            'Bien si la acción no se hace al pulsar, sino al soltar (y se puede cancelar apartándose).',
        ],
        '2.5.3' => [
            'Compara el texto visible de los botones y enlaces con su nombre accesible (lector de pantalla).',
            'Bien si el nombre que se anuncia contiene el texto que se ve.',
        ],
        '2.5.4' => [
            'Busca funciones que se activen al mover o agitar el móvil.',
            'Bien si también tienen un botón y se pueden desactivar. Si no hay, «No aplica».',
        ],
        '2.5.7' => [
            'Busca acciones de arrastrar (sliders, ordenar elementos, mapas).',
            'Bien si también se pueden hacer con clics o toques sin arrastrar.',
        ],
        '2.5.8' => [
            'Mira botones e iconos pequeños, sobre todo en el móvil.',
            'Bien si miden al menos 24 por 24 píxeles o tienen espacio suficiente alrededor.',
        ],
        '3.1.1' => [
            'Comprueba el idioma declarado de la página (atributo lang en el html).',
            'Bien si coincide con el idioma principal del texto.',
        ],
        '3.1.2' => [
            'Busca frases o bloques en otro idioma (citas, nombres de productos en inglés, otro idioma oficial).',
            'Bien si están marcados con su idioma (lang) para que el lector los pronuncie bien.',
        ],
        '3.2.1' => [
            'Recorre los elementos con Tab.',
            'Bien si recibir el foco no cambia nada por sorpresa (no abre ventanas, no cambia de página, no envía).',
        ],
        '3.2.2' => [
            'Cambia el valor de desplegables, casillas y campos.',
            'Bien si nada cambia de página o se envía solo sin avisar antes.',
        ],
        '3.2.3' => [
            'Compara el menú y la cabecera en varias páginas.',
            'Bien si están en el mismo sitio y en el mismo orden.',
        ],
        '3.2.4' => [
            'Mira los elementos que se repiten (buscador, carrito, iconos).',
            'Bien si lo que hace lo mismo se llama y se ve igual en todas las páginas.',
        ],
        '3.2.6' => [
            'Busca las formas de pedir ayuda (teléfono, correo, chat, preguntas frecuentes).',
            'Bien si, donde las haya, están en el mismo sitio relativo en todas las páginas.',
        ],
        '3.3.1' => [
            'Envía los formularios con errores (campos vacíos, correo mal escrito).',
            'Bien si se dice con texto qué campo está mal y por qué, no solo con color.',
        ],
        '3.3.2' => [
            'Revisa los formularios.',
            'Bien si cada campo tiene una etiqueta visible y se indica qué es obligatorio y qué formato se espera.',
        ],
        '3.3.3' => [
            'Provoca errores en los formularios.',
            'Bien si el mensaje sugiere cómo corregirlo («escribe el correo como nombre@dominio.es»).',
        ],
        '3.3.4' => [
            'Solo en compras, reservas, datos legales o borrado de datos.',
            'Bien si se puede revisar antes de confirmar, corregir o deshacer. Si no hay, «No aplica».',
        ],
        '3.3.7' => [
            'Recorre los procesos de varios pasos (compra, alta, solicitud).',
            'Bien si no se pide escribir de nuevo lo que ya se dio, o se rellena solo o se puede elegir.',
        ],
        '3.3.8' => [
            'Revisa el inicio de sesión y los registros.',
            'Bien si no obliga a resolver acertijos o recordar datos; se puede pegar la contraseña y usar el gestor de contraseñas.',
        ],
        '4.1.2' => [
            'Con el lector de pantalla, recorre los controles personalizados (menús, pestañas, acordeones, casillas, interruptores).',
            'Bien si cada uno anuncia su nombre, qué es (botón, pestaña…) y su estado (abierto, marcado).',
        ],
        '4.1.3' => [
            'Haz acciones que muestren mensajes sin cambiar de página (añadir al carrito, buscar, enviar).',
            'Bien si el lector de pantalla anuncia el mensaje sin que haya que ir a buscarlo.',
        ],
    ];

    /** @return list<string> */
    public static function pasos(string $criterio): array
    {
        return self::PASOS[$criterio] ?? [];
    }

    /** @return list<string> criterios con guía */
    public static function criterios(): array
    {
        return array_map('strval', array_keys(self::PASOS));
    }
}
