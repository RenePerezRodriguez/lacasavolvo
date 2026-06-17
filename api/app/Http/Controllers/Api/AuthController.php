<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        if ($user->hasRole('SUSPENDIDO')) {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está suspendida.'],
            ]);
        }

        // Revocar tokens anteriores del mismo dispositivo
        $user->tokens()->where('name', 'spa')->delete();
        // Limpiar cualquier simulación anterior — cada login empieza limpio
        $user->update(['simulated_role_id' => null]);
        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        // Limpiar simulación al cerrar sesión
        $user->update(['simulated_role_id' => null]);
        $user->currentAccessToken()->delete();
        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function switchSucursal(Request $request)
    {
        $request->validate(['sucursal_id' => 'required|integer|exists:sucursals,id']);

        $user = $request->user();
        $sucursalId = $request->sucursal_id;

        // Verificar que el usuario tiene acceso activo a esa sucursal
        $tieneAcceso = $user->accesos()
            ->where('sucursal_id', $sucursalId)
            ->where('estado', 'ON')
            ->exists();

        if (!$tieneAcceso) {
            return response()->json(['error' => 'No tienes acceso a esa sucursal.'], 403);
        }

        $user->update(['sucursal_id' => $sucursalId]);

        return response()->json($this->userPayload($user->fresh(['sucursal', 'roles', 'accesos.sucursal'])));
    }

    private function userPayload(User $user): array
    {
        $user->load(['sucursal', 'roles', 'accesos.sucursal']);

        // getAllPermissions() tiene un override en User.php que respeta simulated_role_id
        $perms = $user->getAllPermissions()->pluck('name')->values();

        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'sucursal_id' => $user->sucursal_id,
            'sucursal'    => $user->sucursal ? [
                'id'     => $user->sucursal->id,
                'nombre' => $user->sucursal->nombre,
                'alias'  => $user->sucursal->alias,
            ] : null,
            'role'        => $user->simulated_role_id
                ? (\Spatie\Permission\Models\Role::findById($user->simulated_role_id, 'web')?->name ?? $user->roles->first()?->name ?? '')
                : $user->roles->first()?->name ?? '',
            'roles'       => $user->roles->pluck('name'),
            'permissions' => $perms,
            'simulated_role_id'   => $user->simulated_role_id,
            'simulated_role_name' => $user->simulated_role_id
                ? \Spatie\Permission\Models\Role::findById($user->simulated_role_id, 'web')?->name
                : null,
            'accesos'     => $user->accesos
                ->where('estado', 'ON')
                ->map(fn($a) => [
                    'sucursal_id' => $a->sucursal_id,
                    'nombre'      => $a->sucursal->nombre ?? '',
                ])
                ->values(),
        ];
    }
}
