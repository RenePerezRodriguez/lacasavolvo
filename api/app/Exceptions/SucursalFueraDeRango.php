<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Sentinela: se lanza dentro de la transacción de `SucursalController::store`
 * cuando el id que el INSERT asignaría a la nueva sucursal queda fuera del
 * rango de columnas de stock (stock1..stock5). Al lanzarse, la transacción
 * revierte el INSERT (no queda ninguna sucursal sin columna de stock) y el
 * controlador la traduce a una respuesta 422 limpia.
 */
class SucursalFueraDeRango extends RuntimeException
{
}
