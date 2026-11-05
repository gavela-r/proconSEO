<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use PDO;
use ProconSEO\Engine\Motor;
use ProconSEO\Fetch\Ambito;
use ProconSEO\Fetch\ClienteHttpSeguro;
use ProconSEO\Fetch\Http\TransporteCurl;
use ProconSEO\Fetch\LimitesDescarga;
use ProconSEO\Fetch\ResolvedorDnsSistema;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Fetch\ValidadorUrl;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Contract\MotorAnalisis;

/**
 * Monta las piezas reales de la Parte 1 (para las rutas web y el worker).
 * En los tests se construyen a mano con transporte y DNS simulados.
 */
final class Fabrica
{
    private ?Catalogo $catalogo = null;
    private ?Motor $motor = null;

    public function __construct(private readonly ?PDO $bd = null)
    {
    }

    public function bd(): PDO
    {
        return $this->bd ?? BaseDatos::conexion();
    }

    public function repositorio(): RepositorioAnalisis
    {
        return new RepositorioAnalisis($this->bd());
    }

    public function catalogo(): Catalogo
    {
        return $this->catalogo ??= ($this->motor ??= Motor::v1())->catalogo();
    }

    public function validador(): ValidadorDestino
    {
        $perfil = $this->repositorio()->perfilVigente();
        $puertos = $perfil === null ? [80, 443] : LimitesDescarga::desdePerfil($perfil['parametros'])->puertosPermitidos;

        return new ValidadorDestino(new ValidadorUrl($puertos), new ResolvedorDnsSistema());
    }

    public function lanzador(): LanzadorAnalisis
    {
        return new LanzadorAnalisis($this->repositorio(), $this->validador(), $this->catalogo(), $this->interruptor(), $this->auditoria());
    }

    public function auditoria(): RegistroAuditoria
    {
        return new RegistroAuditoria($this->bd());
    }

    public function interruptor(): Interruptor
    {
        return new Interruptor($this->bd(), $this->auditoria());
    }

    /** IP de quien hace la petición web actual. */
    public static function ipOrigen(): ?string
    {
        return IpOrigen::desde($_SERVER, IpOrigen::proxies((string) Config::obtener('PROXIES_DE_CONFIANZA', '')));
    }

    /** Motor de la Parte 2, con las comprobaciones implementadas hasta ahora. */
    public function motor(): MotorAnalisis
    {
        return $this->motor ??= Motor::v1();
    }

    public function orquestador(): Orquestador
    {
        return new Orquestador(
            $this->repositorio(),
            new GuardadoResultados($this->bd()),
            $this->motor(),
            $this->validador(),
            static fn (Ambito $ambito, LimitesDescarga $limites): ClienteHttpSeguro => new ClienteHttpSeguro(
                new TransporteCurl(),
                new ValidadorDestino(new ValidadorUrl($limites->puertosPermitidos), new ResolvedorDnsSistema()),
                $ambito,
                $limites,
            ),
        );
    }
}
