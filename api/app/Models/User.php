<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'sucursal_id', 'simulated_role_id', 'avatar'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

    public function accesos()
    {
        return $this->hasMany(Acceso::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Devuelve el nombre del rol efectivo, respetando simulated_role_id.
     */
    public function effectiveRole(): ?string
    {
        if ($this->simulated_role_id) {
            return \Spatie\Permission\Models\Role::findById($this->simulated_role_id, 'web')?->name;
        }
        return $this->roles->first()?->name;
    }

    /**
     * ¿El rol efectivo es uno de los dados? Respeta simulación.
     */
    public function effectiveRoleIs(array|string $roles): bool
    {
        return in_array($this->effectiveRole(), (array) $roles);
    }

    /**
     * Override de Spatie: cuando hay simulated_role_id, verifica contra
     * el rol simulado en vez de los permisos reales del usuario.
     * 
     * Spatie registra un Gate::before que llama a este método ANTES que
     * nuestro AppServiceProvider::boot. Sin este override, Spatie encuentra
     * los permisos reales del usuario (ADMIN = todo) y nunca llega a
     * nuestro callback que chequea simulated_role_id.
     */
    public function checkPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->simulated_role_id) {
            try {
                $simRole = \Spatie\Permission\Models\Role::findById($this->simulated_role_id, 'web');
                return $simRole->hasPermissionTo($permission);
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
                return false;
            }
        }
        // Spatie's original logic: delegate to hasPermissionTo
        try {
            return $this->hasPermissionTo($permission, $guardName);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Override de Spatie: cuando hay simulated_role_id, devuelve los permisos
     * del rol simulado en vez de los permisos reales. Así el frontend recibe
     * los permisos correctos vía userPayload y cualquier cambio en la BD
     * (agregar/quitar permisos a un rol) se refleja instantáneamente.
     */
    public function getAllPermissions(): Collection
    {
        if ($this->simulated_role_id) {
            $simRole = \Spatie\Permission\Models\Role::findById($this->simulated_role_id, 'web');
            return $simRole?->permissions ?? collect();
        }
        // Solo ADMIN tiene acceso total. GERENTE usa sus permisos reales de BD (83 en legacy).
        if ($this->hasRole('ADMIN')) {
            return \Spatie\Permission\Models\Permission::all();
        }
        // Spatie original
        $permissions = $this->permissions;
        if ($this->roles->isNotEmpty()) {
            $permissions = $permissions->merge($this->getPermissionsViaRoles());
        }
        return $permissions->sort()->values();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

}
