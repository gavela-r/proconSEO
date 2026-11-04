<?php

declare(strict_types=1);

use ProconSEO\Shared\Aplicacion;

require dirname(__DIR__) . '/vendor/autoload.php';

Aplicacion::crear(dirname(__DIR__))->atender();
