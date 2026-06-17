<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // cierres: columnas acumulativas (sumas) necesitan más dígitos
        Schema::table('cierres', function (Blueprint $table) {
            $table->decimal('apertura', 12, 2)->default(0)->change();
            $table->decimal('ingresos', 12, 2)->default(0)->change();
            $table->decimal('egresos',  12, 2)->default(0)->change();
            $table->decimal('cierre',   12, 2)->default(0)->change();
        });

        // aperturas: el monto de apertura puede heredar saldos grandes
        Schema::table('aperturas', function (Blueprint $table) {
            $table->decimal('apertura', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('cierres', function (Blueprint $table) {
            $table->decimal('apertura', 9, 2)->default(0)->change();
            $table->decimal('ingresos', 9, 2)->default(0)->change();
            $table->decimal('egresos',  9, 2)->default(0)->change();
            $table->decimal('cierre',   9, 2)->default(0)->change();
        });

        Schema::table('aperturas', function (Blueprint $table) {
            $table->decimal('apertura', 9, 2)->default(0)->change();
        });
    }
};
