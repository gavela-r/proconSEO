<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Utilidades comunes de los enums.
 */
trait ConValores
{
    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }

    /** @return array<string, string> valor => etiqueta, para listas y para el frontend */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->etiqueta();
        }

        return $opciones;
    }
}
