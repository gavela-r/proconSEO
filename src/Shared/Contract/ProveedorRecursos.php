<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

/**
 * Única puerta de acceso a la red. La implementa la Parte 1 (src/Fetch/).
 *
 * Aplica el ámbito autorizado, SEG-01/02/03/05, los límites del perfil y el ritmo por host.
 * Nunca lanza excepciones por fallos de red o de seguridad: los devuelve en
 * RecursoDescargado::$resultado para que la comprobación decida (normalmente no_verificable).
 */
interface ProveedorRecursos
{
    /**
     * @param string      $url              URL absoluta que se quiere consultar
     * @param string      $motivo           Para qué se necesita (queda registrado), p. ej. "robots.txt del origen"
     * @param string|null $idComprobacion   Comprobación que justifica la petición, p. ej. "IA-01.robots-crawlers"
     */
    public function obtener(string $url, string $motivo, ?string $idComprobacion = null): RecursoDescargado;
}
