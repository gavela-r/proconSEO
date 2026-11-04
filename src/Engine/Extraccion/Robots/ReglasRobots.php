<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Extraccion\Robots;

/**
 * Reglas de un robots.txt ya leído. Responde si un robot puede rastrear una URL y por qué
 * (la regla y la línea), para usarlo como evidencia.
 */
final class ReglasRobots
{
    /**
     * @param list<array{agentes: list<string>, reglas: list<array{permitir: bool, patron: string, original: string, linea: int}>, linea: int}> $grupos
     * @param list<string> $sitemaps
     * @param list<array{linea: int, texto: string}> $lineasInvalidas
     * @param list<array{linea: int, texto: string}> $lineasIgnoradas Claves conocidas sin efecto en el permiso (crawl-delay…)
     */
    public function __construct(
        public readonly array $grupos,
        public readonly array $sitemaps,
        public readonly array $lineasInvalidas,
        public readonly array $lineasIgnoradas,
        public readonly bool $truncado,
    ) {
    }

    public function permite(string $agente, string $url): bool
    {
        return $this->evaluar($agente, $url)['permitido'];
    }

    /**
     * @param string $agente Token del robot (Googlebot, OAI-SearchBot…)
     * @param string $url    URL absoluta o ruta (con consulta) que se quiere rastrear
     *
     * @return array{permitido: bool, grupo: ?string, regla: ?string, linea: ?int}
     *         grupo: agente del grupo aplicado ("*" o el token); null si ningún grupo aplica
     */
    public function evaluar(string $agente, string $url): array
    {
        $ruta = self::ruta($url);
        if ($ruta === '/robots.txt') {
            return ['permitido' => true, 'grupo' => null, 'regla' => null, 'linea' => null];
        }

        [$grupo, $reglas] = $this->reglasPara(AnalizadorRobots::token($agente));
        if ($grupo === null) {
            return ['permitido' => true, 'grupo' => null, 'regla' => null, 'linea' => null];
        }

        $mejor = null;
        $mejorLongitud = -1;
        foreach ($reglas as $regla) {
            if (!self::coincide($regla['patron'], $ruta)) {
                continue;
            }
            $longitud = strlen($regla['patron']);
            // Gana la regla más larga; en empate, allow (la menos restrictiva).
            if ($longitud > $mejorLongitud || ($longitud === $mejorLongitud && $regla['permitir'] && !$mejor['permitir'])) {
                $mejor = $regla;
                $mejorLongitud = $longitud;
            }
        }

        if ($mejor === null) {
            return ['permitido' => true, 'grupo' => $grupo, 'regla' => null, 'linea' => null];
        }

        return [
            'permitido' => $mejor['permitir'],
            'grupo' => $grupo,
            'regla' => ($mejor['permitir'] ? 'Allow: ' : 'Disallow: ') . $mejor['original'],
            'linea' => $mejor['linea'],
        ];
    }

    /** true si hay algún grupo específico para ese robot (no solo el de "*"). */
    public function tieneGrupoPropio(string $agente): bool
    {
        $token = AnalizadorRobots::token($agente);
        foreach ($this->grupos as $g) {
            if (in_array($token, $g['agentes'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Combina todos los grupos del robot; si no hay ninguno, todos los de "*".
     *
     * @return array{0: ?string, 1: list<array{permitir: bool, patron: string, original: string, linea: int}>}
     */
    private function reglasPara(string $token): array
    {
        foreach ([$token, '*'] as $buscado) {
            $reglas = [];
            $encontrado = false;
            foreach ($this->grupos as $g) {
                if (in_array($buscado, $g['agentes'], true)) {
                    $encontrado = true;
                    array_push($reglas, ...$g['reglas']);
                }
            }
            if ($encontrado) {
                return [$buscado, $reglas];
            }
        }
        return [null, []];
    }

    private static function ruta(string $url): string
    {
        if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*://~', $url) === 1) {
            $partes = parse_url($url);
            $ruta = ($partes['path'] ?? '') === '' ? '/' : $partes['path'];
            if (isset($partes['query'])) {
                $ruta .= '?' . $partes['query'];
            }
        } else {
            $ruta = explode('#', $url, 2)[0];
            if ($ruta === '' || $ruta[0] !== '/') {
                $ruta = '/' . $ruta;
            }
        }
        return AnalizadorRobots::normalizarRuta($ruta);
    }

    /**
     * Coincidencia de prefijo con * (cualquier secuencia) y $ final (fin de la ruta).
     *
     * Sin expresiones regulares: un robots.txt malicioso con muchos * no puede provocar
     * un retroceso exponencial (el coste es como mucho longitud del patrón × de la ruta).
     */
    private static function coincide(string $patron, string $ruta): bool
    {
        $anclado = str_ends_with($patron, '$');
        $patron = $anclado ? substr($patron, 0, -1) : $patron . '*';

        $p = 0;
        $r = 0;
        $estrella = -1;
        $marca = 0;
        $lp = strlen($patron);
        $lr = strlen($ruta);
        while ($r < $lr) {
            if ($p < $lp && $patron[$p] === '*') {
                $estrella = $p++;
                $marca = $r;
            } elseif ($p < $lp && $patron[$p] === $ruta[$r]) {
                $p++;
                $r++;
            } elseif ($estrella !== -1) {
                $p = $estrella + 1;
                $r = ++$marca;
            } else {
                return false;
            }
        }
        while ($p < $lp && $patron[$p] === '*') {
            $p++;
        }
        return $p === $lp;
    }
}
