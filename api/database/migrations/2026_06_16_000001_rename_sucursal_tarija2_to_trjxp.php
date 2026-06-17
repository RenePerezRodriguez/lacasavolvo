<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renombra la sucursal "Tarija 2" → "TRJ XP" (pedido de QA, 16/06/2026).
 *
 * Es un cambio de DATO (no de esquema), pero se hace por migración para que viaje en el
 * deploy a staging/producción (donde la sucursal todavía se llama "Tarija 2"). En dev,
 * si ya se renombró a mano, la migración es NO-OP (no encuentra "Tarija 2").
 *
 * Idempotente y tolerante a la grafía (UPPER+TRIM cubre "TARIJA 2"/"Tarija 2"). Mantiene
 * en sincronía la cuenta interna "SUCURSAL X" (destino de envíos, convención id==sucursal_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        $suc = DB::table('sucursals')->whereRaw("UPPER(TRIM(nombre)) = 'TARIJA 2'")->first();
        if ($suc) {
            DB::table('sucursals')->where('id', $suc->id)->update(['nombre' => 'TRJ XP']);
            DB::table('cuentas')->where('id', $suc->id)->update(['nombre' => 'SUCURSAL TRJ XP']);
        }
    }

    public function down(): void
    {
        $suc = DB::table('sucursals')->whereRaw("UPPER(TRIM(nombre)) = 'TRJ XP'")->first();
        if ($suc) {
            DB::table('sucursals')->where('id', $suc->id)->update(['nombre' => 'TARIJA 2']);
            DB::table('cuentas')->where('id', $suc->id)->update(['nombre' => 'SUCURSAL TARIJA 2']);
        }
    }
};
