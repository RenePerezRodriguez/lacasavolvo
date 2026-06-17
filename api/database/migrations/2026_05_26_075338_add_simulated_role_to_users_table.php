<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'simulated_role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('simulated_role_id')->nullable()->after('sucursal_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'simulated_role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('simulated_role_id');
            });
        }
    }
};
