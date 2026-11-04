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
 * Crea un análisis a partir del formulario: comprueba el interruptor general y los límites
 * contra el abuso (SEG-11), valida la URL y su IP (SEG-01/02) ANTES de guardar nada, fija el
 * ámbito y lo deja en cola para el worker. Todo, también lo rechazado, queda en la auditoría (SEG-15).
 */
final class LanzadorAnalisis
{
    public function __construct(
        private readonly RepositorioAnalisis $repositorio,
        private readonly ValidadorDestino $validador,
        private readonly Catalogo $catalogo,
        private readonly Interruptor $interruptor,
        private readonly RegistroAuditoria $auditoria,
    ) {
    }

    /**
     * @param string      $hostsExtra Otros hosts autorizados, separados por comas, espacios o saltos de línea
     * @param string|null $ipOrigen   IP de quien lo lanza (null desde la línea de órdenes)
     */
    public function lanzar(int $proyectoId, string $url, string $alcance, ?string $objetivo, string $hostsExtra = '', ?string $ipOrigen = null): int
    {
        $rechazar = function (ErrorDominio $error, string $regla) use ($proyectoId, $url, $ipOrigen): never {
            $this->auditoria->registrar(RegistroAuditoria::LANZAMIENTO_RECHAZADO, null, $proyectoId, $ipOrigen, $url, $regla, $error->getMessage());
            throw $error;
        };

        if ($this->interruptor->activo()) {
            $rechazar(new ErrorDominio('herramienta_detenida', 'Los análisis están detenidos temporalmente por seguridad. Inténtalo más tarde.', 503), 'SEG-11');
        }

        if ($this->repositorio->proyecto($proyectoId) === null) {
            throw new ErrorDominio('proyecto_no_encontrado', 'El proyecto no existe.', 404);
        }

        $alcanceEnum = Alcance::tryFrom($alcance)
            ?? throw new ErrorDominio('alcance_no_valido', 'Elige un alcance válido.', 422);

        $perfil = $this->repositorio->perfilVigente()
            ?? throw new ErrorDominio('sin_perfil', 'No hay perfil de ejecución. Ejecuta composer migrar.', 500);
        $maxPorIp = $perfil['parametros']['abuso']['analisis_por_ip_hora']['valor'] ?? null;
        $maxPorDominio = $perfil['parametros']['abuso']['analisis_por_dominio_hora']['valor'] ?? null;
        if (!is_int($maxPorIp) || !is_int($maxPorDominio)) {
            throw new ErrorDominio('sin_limites', 'El perfil de ejecución no tiene los límites contra el abuso. Ejecuta composer migrar.', 500);
        }

        if ($ipOrigen !== null && $this->repositorio->lanzadosUltimaHoraPorIp($ipOrigen) >= $maxPorIp) {
            $rechazar(new ErrorDominio('limite_por_ip', "Has lanzado {$maxPorIp} análisis en la última hora, que es el máximo. Espera un poco.", 429), 'SEG-11');
        }

        // Lanza DestinoRechazado (422) si la URL no es segura: no se crea nada.
        try {
            $destino = $this->validador->validar($url);
        } catch (DestinoRechazado $e) {
            $rechazar($e, $e->regla);
        }

        $dominio = self::dominio($destino->url->host);
        if ($this->repositorio->lanzadosUltimaHoraPorDominio($dominio) >= $maxPorDominio) {
            $rechazar(new ErrorDominio('limite_por_dominio', "«{$dominio}» ya se ha analizado {$maxPorDominio} veces en la última hora, que es el máximo. Espera un poco.", 429), 'SEG-11');
        }

        $hosts = [$destino->url->host];
        $validadorHosts = new ValidadorUrl(LimitesDescarga::desdePerfil($perfil['parametros'])->puertosPermitidos);
        foreach (preg_split('/[\s,;]+/', trim($hostsExtra), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $extra) {
            try {
                $hosts[] = $validadorHosts->validar('https://' . $extra . '/')->host;
            } catch (DestinoRechazado $e) {
                $rechazar(new ErrorDominio('host_extra_no_valido', "El host autorizado «{$extra}» no es válido: " . $e->getMessage(), 422), $e->regla);
            }
        }

        $objetivo = $objetivo === null ? null : (trim($objetivo) === '' ? null : mb_substr(trim($objetivo), 0, 1000));

        $id = $this->repositorio->crear(
            $proyectoId,
            mb_substr(trim($url), 0, 2048),
            $destino->url->url(),
            $alcanceEnum,
            $objetivo,
            array_values(array_unique($hosts)),
            $perfil['id'],
            $this->catalogo->version(),
            $ipOrigen,
            $dominio,
        );
        $this->auditoria->registrar(RegistroAuditoria::ANALISIS_LANZADO, $id, $proyectoId, $ipOrigen, $destino->url->url(), detalle: 'Alcance: ' . $alcanceEnum->etiqueta());

        return $id;
    }

    /** «www.ejemplo.es» y «ejemplo.es» cuentan como el mismo dominio para los límites. */
    public static function dominio(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
