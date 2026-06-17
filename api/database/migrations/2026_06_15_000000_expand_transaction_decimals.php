<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía las columnas de DINERO de los documentos transaccionales de DECIMAL(9,2)
 * (tope ≈ 9,999,999.99 Bs) a DECIMAL(12,2) (tope ≈ 999,999,999,999.99 Bs).
 *
 * Continúa el patrón ya aplicado a cierres/aperturas (2026_05_26_000001): una venta
 * o compra legítima > ~10M Bs desbordaba la columna → 500 / corrupción. Se amplían solo
 * las columnas AGREGADAS (monto/total/subtotal/saldo/acuenta/descuento y montos de caja);
 * los precios UNITARIOS (p_comp/p_norm/p_fact/costo) se dejan en (9,2) — un precio por
 * unidad > 10M no es realista. Cambio no destructivo (ampliación) y con guardas hasTable.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> tabla => columnas con DEFAULT 0.00 */
    private array $conDefault = [
        'ventas'      => ['monto', 'descuento', 'total', 'acuenta', 'saldo'],
        'compras'     => ['monto', 'descuento', 'total', 'acuenta', 'saldo'],
        'cotizacions' => ['monto', 'descuento', 'total'],
        'tranzas'     => ['monto_ingreso', 'monto_egreso'],
        'devventas'   => ['total'],
        'devcompras'  => ['total'],
    ];

    /** @var array<string, array<int, string>> tabla => columnas NOT NULL sin default */
    private array $sinDefault = [
        'ventadetalles'      => ['monto', 'subtotal'],
        'compradetalles'     => ['monto', 'subtotal'],
        'cotizaciondetalles' => ['monto', 'subtotal'],
    ];

    public function up(): void
    {
        $this->aplicar(12);
    }

    public function down(): void
    {
        $this->aplicar(9);
    }

    private function aplicar(int $precision): void
    {
        foreach ($this->conDefault as $tabla => $cols) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $t) use ($cols, $precision, $tabla) {
                foreach ($cols as $col) {
                    if (Schema::hasColumn($tabla, $col)) {
                        $t->decimal($col, $precision, 2)->default(0)->change();
                    }
                }
            });
        }

        foreach ($this->sinDefault as $tabla => $cols) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $t) use ($cols, $precision, $tabla) {
                foreach ($cols as $col) {
                    if (Schema::hasColumn($tabla, $col)) {
                        $t->decimal($col, $precision, 2)->change();
                    }
                }
            });
        }
    }
};
