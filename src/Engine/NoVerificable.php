<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use RuntimeException;

/**
 * Falta un dato del que depende la comprobación (la página no se descargó, robots.txt dio un
 * error temporal, el alcance no permite consultar otra URL…). Nunca es un problema de la web:
 * el resultado es no_verificable y no_ejecutada, con este motivo.
 */
final class NoVerificable extends RuntimeException
{
}
