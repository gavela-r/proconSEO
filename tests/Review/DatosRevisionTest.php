<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Review;

use PHPUnit\Framework\TestCase;
use ProconSEO\Projects\ErroresFormulario;
use ProconSEO\Review\DatosRevision;
use ProconSEO\Shared\Enum\DecisionRevision;
use ProconSEO\Shared\Enum\EstadoResultado;

/**
 * Validación de la revisión humana (especificación 4.6, RF-40 a RF-43).
 */
final class DatosRevisionTest extends TestCase
{
    private const BASE = [
        'revisor' => '  Marta López ',
        'motivo' => 'Compruebo el bloqueo de GPTBot con el cliente',
        'decision' => 'confirmar',
        'justificacion' => 'El cliente lo confirma por correo.',
    ];

    public function testConfirmarConLoMinimo(): void
    {
        $datos = DatosRevision::desdeFormulario(self::BASE, 'decision_revisar');

        self::assertSame('Marta López', $datos->revisor);
        self::assertSame(DecisionRevision::Confirmar, $datos->decision);
        self::assertNull($datos->nuevoEstado);
        self::assertNull($datos->evidenciaTexto);
    }

    public function testElRevisorEsObligatorio(): void
    {
        // RF-40: no hay login, así que el nombre del revisor es obligatorio.
        self::assertArrayHasKey('revisor', $this->errores(['revisor' => '   '] + self::BASE));
    }

    public function testMotivoDecisionYJustificacionSonObligatorios(): void
    {
        $errores = $this->errores(['revisor' => 'Marta']);

        self::assertArrayHasKey('motivo', $errores);
        self::assertArrayHasKey('decision', $errores);
        self::assertArrayHasKey('justificacion', $errores);
        self::assertArrayHasKey('decision', $this->errores(['decision' => 'borrar_resultado'] + self::BASE));
    }

    public function testCambiarEstadoExigeUnEstadoNuevoYDistinto(): void
    {
        $cambio = ['decision' => 'cambiar_estado'] + self::BASE;

        self::assertArrayHasKey('nuevo_estado', $this->errores($cambio));
        self::assertArrayHasKey('nuevo_estado', $this->errores(['nuevo_estado' => 'aprobado'] + $cambio));
        self::assertArrayHasKey('nuevo_estado', $this->errores(['nuevo_estado' => 'mejorable'] + $cambio, 'mejorable'));

        $datos = DatosRevision::desdeFormulario(['nuevo_estado' => 'mejorable'] + $cambio, 'problema');
        self::assertSame(EstadoResultado::Mejorable, $datos->nuevoEstado);
    }

    public function testPasarACorrectoExigeEvidencia(): void
    {
        // RF-43: un comentario sin evidencia no puede convertir un resultado en "Correcto".
        $aCorrecto = ['decision' => 'cambiar_estado', 'nuevo_estado' => 'correcto'] + self::BASE;

        self::assertArrayHasKey('evidencia_texto', $this->errores($aCorrecto, 'no_verificable'));
        self::assertArrayHasKey('evidencia_texto', $this->errores(['evidencia_texto' => "  \n "] + $aCorrecto, 'no_verificable'));

        $conTexto = DatosRevision::desdeFormulario(['evidencia_texto' => 'Correo del cliente: «lo bloqueamos a propósito».'] + $aCorrecto, 'no_verificable');
        self::assertSame(EstadoResultado::Correcto, $conTexto->nuevoEstado);

        $conCaptura = DatosRevision::desdeFormulario($aCorrecto, 'no_verificable', hayCaptura: true);
        self::assertSame(EstadoResultado::Correcto, $conCaptura->nuevoEstado);
    }

    public function testElNuevoEstadoSeIgnoraSiNoSeCambia(): void
    {
        $datos = DatosRevision::desdeFormulario(['decision' => 'pedir_evidencia', 'nuevo_estado' => 'correcto'] + self::BASE, 'no_verificable');

        self::assertNull($datos->nuevoEstado, 'Solo «Cambiar estado» cambia el estado');
    }

    public function testLimitesDeLongitudYCamposManipulados(): void
    {
        self::assertArrayHasKey('revisor', $this->errores(['revisor' => str_repeat('a', DatosRevision::MAX_REVISOR + 1)] + self::BASE));
        self::assertArrayHasKey('justificacion', $this->errores(['justificacion' => str_repeat('a', DatosRevision::MAX_TEXTO + 1)] + self::BASE));
        self::assertArrayHasKey('evidencia_texto', $this->errores(['evidencia_texto' => str_repeat('a', DatosRevision::MAX_EVIDENCIA + 1)] + self::BASE));
        self::assertArrayHasKey('revisor', $this->errores(['revisor' => ['Marta']] + self::BASE), 'Un array no cuela como texto');
    }

    /** @return array<string, string> */
    private function errores(array $entrada, string $estadoActual = 'no_verificable'): array
    {
        try {
            DatosRevision::desdeFormulario($entrada, $estadoActual);
        } catch (ErroresFormulario $e) {
            return $e->errores;
        }
        self::fail('Debía rechazar la revisión');
    }
}
