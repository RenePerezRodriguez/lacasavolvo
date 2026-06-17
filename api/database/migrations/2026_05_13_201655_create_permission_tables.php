<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adapts the legacy Caffeinated/Shinobi RBAC schema to Spatie Permission.
 * The existing `roles` and `permissions` tables are preserved and extended.
 * Legacy pivot tables (role_user, permission_role, permission_user) remain untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add guard_name to roles; make slug nullable (Spatie doesn't use it) ─
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'guard_name')) {
                $table->string('guard_name', 125)->default('web')->after('name');
            }
            $table->string('slug')->nullable()->default(null)->change();
        });
        DB::table('roles')->whereNull('guard_name')->orWhere('guard_name', '')->update(['guard_name' => 'web']);

        // ── 2. Add guard_name to permissions; make slug nullable ──────────
        Schema::table('permissions', function (Blueprint $table) {
            if (!Schema::hasColumn('permissions', 'guard_name')) {
                $table->string('guard_name', 125)->default('web')->after('name');
            }
            $table->string('slug')->nullable()->default(null)->change();
        });
        DB::table('permissions')->whereNull('guard_name')->orWhere('guard_name', '')->update(['guard_name' => 'web']);

        // Migrate Shinobi slug→name: Spatie usa 'name' como identificador del permiso.
        // Legacy: name='ventas', slug='ventas.index'. Spatie necesita name='ventas.index'.
        DB::statement("UPDATE permissions SET name = slug WHERE slug IS NOT NULL AND slug != '' AND name != slug");

        // ── 3. Create model_has_permissions ───────────────────────────────
        if (!Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
            });
        }

        // ── 4. Create model_has_roles (migrate from role_user) ────────────
        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
                $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            });

            // Migrate existing user→role assignments
            $assignments = DB::table('role_user')->get();
            foreach ($assignments as $row) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id'    => $row->role_id,
                    'model_type' => 'App\\Models\\User',
                    'model_id'   => $row->user_id,
                ]);
            }
        }

        // ── 5. Create role_has_permissions + migrate from legacy permission_role ─
        if (!Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            });
        }

        // Migrate permission→role assignments from legacy Shinobi permission_role
        if (Schema::hasTable('permission_role') && DB::table('role_has_permissions')->count() === 0) {
            $permRoles = DB::table('permission_role')->get();
            foreach ($permRoles as $row) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $row->permission_id,
                    'role_id'       => $row->role_id,
                ]);
            }
        }

        // ── 6. Add v2-specific permissions not present in legacy ──────────
        $v2Perms = ['costos.ver'];
        foreach ($v2Perms as $p) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $p, 'guard_name' => 'web', 'slug' => $p,
            ]);
        }
        // Assign costos.ver to ADMIN (id=1) and GERENTE (id=3)
        $costosPermId = DB::table('permissions')->where('name', 'costos.ver')->value('id');
        if ($costosPermId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                ['permission_id' => $costosPermId, 'role_id' => 1],
                ['permission_id' => $costosPermId, 'role_id' => 3],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');

        if (Schema::hasColumn('permissions', 'guard_name')) {
            Schema::table('permissions', fn (Blueprint $t) => $t->dropColumn('guard_name'));
        }
        if (Schema::hasColumn('roles', 'guard_name')) {
            Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('guard_name'));
        }
    }
};
