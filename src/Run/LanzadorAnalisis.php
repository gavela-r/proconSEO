<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\LimitesDescarga;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Fetch\ValidadorUrl;
use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Crea un análisis a partir del formulario: valida la URL y su IP (SEG-01/02) ANTES de
 * guardar nada, fija el ámbito y lo deja en cola para el worker.
 */
final class LanzadorAnalisis
{
    public function __construct(
        private readonly RepositorioAnalisis $repositorio,
        private readonly ValidadorDestino $validador,
        private readonly Catalogo $catalogo,
    ) {
    }

    /**
     * @param string $hostsExtra Otros hosts autorizados, separados por comas, espacios o saltos de línea
     */
    public function lanzar(int $proyectoId, string $url, string $alcance, ?string $objetivo, string $hostsExtra = ''): int
    {
        if ($this->repositorio->proyecto($proyectoId) === null) {
            throw new ErrorDominio('proyecto_no_encontrado', 'El proyecto no existe.', 404);
        }

        $alcanceEnum = Alcance::tryFrom($alcance)
            ?? throw new ErrorDominio('alcance_no_valido', 'Elige un alcance válido.', 422);

        $perfil = $this->repositorio->perfilVigente()
            ?? throw new ErrorDominio('sin_perfil', 'No hay perfil de ejecución. Ejecuta composer migrar.', 500);

        // Lanza DestinoRechazado (422) si la URL no es segura: no se crea nada.
        $destino = $this->validador->validar($url);

        $hosts = [$destino->url->host];
        $validadorHosts = new ValidadorUrl(LimitesDescarga::desdePerfil($perfil['parametros'])->puertosPermitidos);
        foreach (preg_split('/[\s,;]+/', trim($hostsExtra), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $extra) {
            try {
                $hosts[] = $validadorHosts->validar('https://' . $extra . '/')->host;
            } catch (DestinoRechazado $e) {
                throw new ErrorDominio('host_extra_no_valido', "El host autorizado «{$extra}» no es válido: " . $e->getMessage(), 422);
            }
        }

        $objetivo = $objetivo === null ? null : (trim($objetivo) === '' ? null : mb_substr(trim($objetivo), 0, 1000));

        return $this->repositorio->crear(
            $proyectoId,
            mb_substr(trim($url), 0, 2048),
            $destino->url->url(),
            $alcanceEnum,
            $objetivo,
            array_values(array_unique($hosts)),
            $perfil['id'],
            $this->catalogo->version(),
        );
    }
}
