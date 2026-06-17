<?php

namespace App\Http\Controllers;

use App\Models\Acceso;
use App\Models\User;
use App\Models\Sucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function api(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = User::with(['sucursal', 'roles', 'accesos.sucursal']);
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $q->where(fn($q) => $q->where('id', 'like', $s)->orWhere('name', 'like', $s)->orWhere('email', 'like', $s));
        }

        $total = $q->count();

        $sortCol = $request->get('sort', 'name');
        $sortDir = strtolower($request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $validCols = [
            'id' => 'users.id',
            'name' => 'users.name',
            'email' => 'users.email'
        ];

        if (array_key_exists($sortCol, $validCols)) {
            $q->orderBy($validCols[$sortCol], $sortDir);
        } else if ($sortCol === 'sucursal') {
            $q->join('sucursals', 'users.sucursal_id', '=', 'sucursals.id')
              ->orderBy('sucursals.nombre', $sortDir)
              ->select('users.*');
        } else {
            $q->orderBy('users.name', 'asc');
        }

        $users = $q->skip($request->get('skip', 0))->take($request->get('take', 15))->get();
        return response()->json(['total' => $total, 'data' => $users->map(fn($u) => [
            'id'          => $u->id,
            'name'        => $u->name,
            'email'       => $u->email,
            'sucursal_id' => $u->sucursal_id,
            'sucursal'    => $u->sucursal->nombre ?? '',
            'roles'       => $u->roles->pluck('name'),
            'role'        => $u->roles->first()?->name ?? '',
            'accesos'     => $u->accesos->map(fn($a) => [
                'sucursal_id' => $a->sucursal_id,
                'nombre'      => $a->sucursal->nombre ?? '',
                'alias'       => $a->sucursal->alias ?? '',
                'estado'      => $a->estado,
            ]),
        ])]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:191',
            'email'       => 'required|email|unique:users,email',
            'password'    => 'required|string|min:8|confirmed',
            'sucursal_id' => 'required|integer|exists:sucursals,id',
            'role'        => 'required|string|exists:roles,name',
            'accesos'     => 'nullable|array',
            'accesos.*'   => 'integer|exists:sucursals,id',
        ]);

        // Autorizar la asignación de rol ANTES de crear nada: si un no-ADMIN
        // intenta fabricar un usuario ADMIN, abortamos sin dejar un user huérfano.
        $this->autorizarAsignacionDeRol($request->role);

        $user = User::create([
            'name'        => $request->name,
            'email'       => $request->email,
            'password'    => Hash::make($request->password),
            'sucursal_id' => $request->sucursal_id,
        ]);

        $user->assignRole($request->role);

        // Accesos seleccionados en el formulario; la sucursal predeterminada siempre queda ON.
        $accesosOn = collect($request->input('accesos', []))
            ->map(fn($id) => (int) $id)
            ->push((int) $request->sucursal_id)
            ->unique();

        $sucursales = Sucursal::all();
        foreach ($sucursales as $s) {
            Acceso::create([
                'user_id'     => $user->id,
                'sucursal_id' => $s->id,
                'estado'      => $accesosOn->contains($s->id) ? 'ON' : 'OFF',
            ]);
        }

        return response()->json(['id' => $user->id]);
    }

    public function update(Request $request, User $user)
    {
        abort_if($user->id == 1, 403, 'No se puede modificar al Súper Administrador desde este panel.');
        
        $request->validate([
            'name'        => 'required|string|max:191',
            'email'       => 'required|email|unique:users,email,' . $user->id,
            'sucursal_id' => 'required|integer|exists:sucursals,id',
            'role'        => 'nullable|string|exists:roles,name',
            'password'    => 'nullable|string|min:8|confirmed',
        ]);

        // Autorizar el cambio de rol ANTES de persistir cualquier campo: un
        // intento de (auto)promoción a ADMIN no debe dejar ni siquiera el cambio
        // de name/email aplicado.
        if ($request->filled('role')) {
            $this->autorizarAsignacionDeRol($request->role);
        }

        $user->name = $request->name;
        $user->email = $request->email;
        $user->sucursal_id = $request->sucursal_id;
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }
        $user->save();

        if ($request->filled('role')) {
            $user->syncRoles([$request->role]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Impide la ESCALADA DE PRIVILEGIOS al asignar roles: solo un ADMIN real
     * puede otorgar (o auto-otorgarse) el rol ADMIN. Un GERENTE con users.create/
     * users.edit NO debe poder fabricar un super-usuario ni promoverse a ADMIN.
     *
     * @param string|null $role Nombre del rol que se intenta asignar.
     */
    private function autorizarAsignacionDeRol(?string $role): void
    {
        if ($role !== 'ADMIN') {
            return;
        }
        $actorRole = Auth::user()?->roles->first()?->name;
        abort_if(
            $actorRole !== 'ADMIN',
            403,
            'No puede asignar el rol ADMIN: excede su jerarquía.'
        );
    }

    public function acces(User $user, Sucursal $sucursal, string $acceso)
    {
        if ($user->id <= 1) {
            return response()->json(['error' => 'No se puede editar este usuario.'], 403);
        }

        if ($acceso === 'OFF') {
            $activos = Acceso::where('user_id', $user->id)->where('estado', 'ON')->count();
            if ($activos <= 1) {
                return response()->json(['error' => 'El usuario debe tener al menos 1 acceso activo.'], 422);
            }
            Acceso::where('user_id', $user->id)->where('sucursal_id', $sucursal->id)->update(['estado' => 'OFF']);
            // Solo reasignar la sucursal de inicio si era justamente la que se desactivó
            // (en nuestra UI la sucursal predeterminada se controla con su propio selector,
            // a diferencia del legacy que la movía en cada toggle).
            if ($user->sucursal_id == $sucursal->id) {
                $primerAcceso = Acceso::where('user_id', $user->id)->where('estado', 'ON')->first();
                if ($primerAcceso) {
                    $user->update(['sucursal_id' => $primerAcceso->sucursal_id]);
                }
            }
            return response()->json(['ok' => true]);
        }

        // $acceso === 'ON' — activar acceso sin tocar la sucursal de inicio
        // (la controla el selector "Sucursal predeterminada" del formulario).
        Acceso::updateOrCreate(
            ['user_id' => $user->id, 'sucursal_id' => $sucursal->id],
            ['estado' => 'ON']
        );
        return response()->json(['ok' => true]);
    }

    public function destroy(User $user)
    {
        if ($user->id <= 1 || $user->id === Auth::id()) {
            return response()->json(['error' => 'No se puede desactivar este usuario.'], 403);
        }

        $suspendido = Role::where('name', 'SUSPENDIDO')->first();
        if ($suspendido) {
            $user->roles()->sync([$suspendido->id]);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return response()->json(['ok' => true]);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        $request->validate([
            'name'     => 'required|string|max:191',
            'email'    => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $user->name  = $request->name;
        $user->email = $request->email;
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }
        $user->save();

        return response()->json(['ok' => true, 'name' => $user->name, 'email' => $user->email]);
    }

    public function simulateRole(Request $request)
    {
        $request->validate(['role_id' => 'required|integer|exists:roles,id']);

        $user = Auth::user();
        // Verificar usando el rol REAL (no el simulado) para autorizar quién
        // puede usar el simulador.
        $realRole = $user->roles->first()?->name;
        abort_if(!in_array($realRole, ['ADMIN', 'GERENTE']), 403, 'Solo ADMIN o GERENTE pueden simular roles.');

        $role = Role::findById($request->role_id, 'web');

        // ESCALADA: un no-ADMIN NUNCA puede simular ADMIN. El rol ADMIN tiene el
        // bypass total de Gate::before; simularlo equivaldría a heredar acceso
        // irrestricto por encima de la jerarquía real del usuario (un GERENTE se
        // volvería ADMIN). Solo un ADMIN real puede simular el rol ADMIN.
        abort_if(
            $role->name === 'ADMIN' && $realRole !== 'ADMIN',
            403,
            'No puede simular el rol ADMIN: excede su jerarquía.'
        );

        $user->simulated_role_id = $role->id;
        $user->save();

        // Limpiar caché de permisos para que tome efecto inmediato
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['ok' => true, 'simulated_role' => $role->name]);
    }

    public function stopSimulate()
    {
        $user = Auth::user();
        $user->simulated_role_id = null;
        $user->save();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['ok' => true]);
    }
}
