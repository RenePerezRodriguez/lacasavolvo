<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MedioController extends Controller
{
    public function api()
    {
        return response()->json(\App\Models\Medio::orderBy('nombre')->get(['id', 'nombre', 'estado']));
    }

    public function store(Request $request)
    {
        Gate::authorize('medios.create');
        $request->validate(['nombre' => 'required|string|max:191']);
        $medio = \App\Models\Medio::create(['nombre' => $request->nombre, 'estado' => 'ON']);
        return response()->json(['id' => $medio->id]);
    }

    public function update(Request $request, $medio)
    {
        Gate::authorize('medios.edit');
        $m = \App\Models\Medio::findOrFail($medio);
        abort_if($m->id == 1, 403, 'No se puede modificar el registro principal del sistema.');
        // Validar igual que store: sin esto, `nombre` faltante mete NULL (NOT NULL → 500)
        // y un nombre > columna `varchar(191)` desborda el UPDATE (1406 → 500).
        $data = $request->validate(['nombre' => 'required|string|max:191']);
        $m->update(['nombre' => $data['nombre']]);
        return response()->json(['ok' => true]);
    }

    public function toggle($medio)
    {
        Gate::authorize('medios.edit');
        $m = \App\Models\Medio::findOrFail($medio);
        abort_if($m->id == 1, 403, 'No se puede desactivar el registro principal del sistema.');
        $m->estado = $m->estado === 'ON' ? 'OFF' : 'ON';
        $m->save();
        return response()->json(['ok' => true]);
    }
}
