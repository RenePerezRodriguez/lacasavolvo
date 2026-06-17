<?php

namespace App\Http\Controllers;

use App\Models\Industria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class IndustriaController extends Controller
{
    public function api()
    {
        return response()->json(Industria::orderBy('nombre')->get(['id', 'nombre', 'estado']));
    }

    public function store(Request $request)
    {
        Gate::authorize('industrias.create');
        $data = $request->validate(['nombre' => 'required|string|max:100']);
        $industria = Industria::create(['nombre' => $data['nombre'], 'estado' => 'ON']);
        return response()->json(['id' => $industria->id]);
    }

    public function update(Request $request, Industria $industria)
    {
        Gate::authorize('industrias.edit');
        abort_if($industria->id == 1, 403, 'No se puede modificar el registro principal del sistema.');
        $data = $request->validate(['nombre' => 'required|string|max:100']);
        $industria->update($data);
        return response()->json(['ok' => true]);
    }

    public function toggle(Industria $industria)
    {
        Gate::authorize('industrias.edit');
        abort_if($industria->id == 1, 403, 'No se puede desactivar el registro principal del sistema.');
        $industria->estado = $industria->estado === 'ON' ? 'OFF' : 'ON';
        $industria->save();
        return response()->json(['ok' => true]);
    }
}
