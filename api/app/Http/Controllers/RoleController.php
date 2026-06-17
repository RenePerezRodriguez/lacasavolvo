<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    /**
     * Roles NÚCLEO de los que depende la lógica del sistema y que NO pueden
     * renombrarse, vaciarse de permisos ni borrarse:
     *   - ADMIN: tiene el bypass total de Gate::before (hasRole('ADMIN')).
     *     Renombrarlo/borrarlo dejaría al sistema sin súper-administrador.
     *   - SUSPENDIDO: lo asigna `UserController::destroy` para suspender, y el
     *     login lo usa para bloquear acceso. Borrarlo rompería la suspensión.
     *
     * La protección es por NOMBRE (no por id): los ids son auto-increment y en
     * producción/test NO son necesariamente 1 y 2 (el guard `[1,2]` previo
     * protegía ids inexistentes → era placebo).
     */
    private const ROLES_NUCLEO = ['ADMIN', 'SUSPENDIDO'];

    public function permissions(): \Illuminate\Http\JsonResponse
    {
        return response()->json(
            Permission::orderBy('name')->pluck('name')
        );
    }

    public function api(): \Illuminate\Http\JsonResponse
    {
        return response()->json(
            Role::with('permissions')->get()->map(fn($r) => [
                'id'                => $r->id,
                'name'              => $r->name,
                'users_count'       => $r->users()->count(),
                'permissions_count' => $r->permissions->count(),
                'permissions'       => $r->permissions->pluck('name'),
            ])
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|max:50|unique:roles,name',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);
        $role = Role::create(['name' => strtoupper($request->name), 'guard_name' => 'web']);
        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions);
        }
        return response()->json(['id' => $role->id]);
    }

    public function update(Request $request, Role $role)
    {
        if (in_array($role->name, self::ROLES_NUCLEO, true)) {
            return response()->json(['error' => 'No se puede modificar este rol.'], 403);
        }
        $request->validate([
            'name'          => 'required|string|max:50|unique:roles,name,' . $role->id,
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);
        $role->update(['name' => strtoupper($request->name)]);
        $role->syncPermissions($request->permissions ?? []);
        return response()->json(['ok' => true]);
    }

    public function destroy(Role $role)
    {
        if (in_array($role->name, self::ROLES_NUCLEO, true)) {
            return response()->json(['error' => 'No se puede eliminar este rol.'], 403);
        }
        $role->delete();
        return response()->json(['ok' => true]);
    }
}
