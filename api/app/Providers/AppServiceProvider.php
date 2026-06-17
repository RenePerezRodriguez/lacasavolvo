<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // ADMIN bypasses every permission/can check (unless simulating)
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('ADMIN') && !$user->simulated_role_id) {
                return true;
            }

            // Role simulation: check permission ONLY against the simulated role.
            // Return false (deny) if the simulated role lacks the permission —
            // otherwise Spatie would fall through to the user's REAL permissions.
            if ($user->simulated_role_id) {
                $simRole = \Spatie\Permission\Models\Role::findById($user->simulated_role_id, 'web');
                try {
                    return $simRole->hasPermissionTo($ability) ? true : false;
                } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
                    return false; // permission doesn't exist in DB → deny
                }
            }

            return null; // fall through to normal Spatie permission check
        });
    }
}
