<?php

namespace App\Http\Controllers;

use App\Models\Localidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LocalidadController extends Controller
{
    public function api()
    {
        return response()->json(
            Localidad::orderBy('nombre')->get(['id', 'nombre', 'estado'])
        );
    }

    public function store(Request $request)
    {
        Gate::authorize('localidades.create');
        $request->validate(['nombre' => 'required|string|max:191']);
        $localidad = Localidad::create(['nombre' => $request->nombre, 'estado' => 'ON']);
        return response()->json(['id' => $localidad->id]);
    }

    public function update(Request $request, Localidad $localidad)
    {
        Gate::authorize('localidades.edit');
        abort_if($localidad->id == 1, 403, 'No se puede modificar el registro principal del sistema.');
        // Validar igual que store: sin esto, `nombre` faltante mete NULL (NOT NULL → 500)
        // y un nombre > columna `varchar(191)` desborda el UPDATE (1406 → 500).
        $data = $request->validate(['nombre' => 'required|string|max:191']);
        $localidad->update(['nombre' => $data['nombre']]);
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Localidad $localidad)
    {
        Gate::authorize('localidades.destroy');
        abort_if($localidad->id == 1, 403, 'No se puede desactivar el registro principal del sistema.');
        $localidad->estado = 'OFF';
        $localidad->save();
        return response()->json(['ok' => true]);
    }

    public function toggle(Localidad $localidad)
    {
        Gate::authorize('localidades.edit');
        abort_if($localidad->id == 1, 403, 'No se puede desactivar el registro principal del sistema.');
        $localidad->estado = $localidad->estado === 'ON' ? 'OFF' : 'ON';
        $localidad->save();
        return response()->json(['ok' => true]);
    }

}
