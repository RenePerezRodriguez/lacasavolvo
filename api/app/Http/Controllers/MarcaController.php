<?php

namespace App\Http\Controllers;

use App\Models\Marca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MarcaController extends Controller
{
    public function api()
    {
        return response()->json(Marca::orderBy('nombre')->get(['id', 'nombre', 'estado']));
    }

    public function store(Request $request)
    {
        Gate::authorize('marcas.create');
        $data = $request->validate(['nombre' => 'required|string|max:100']);
        $marca = Marca::create(['nombre' => $data['nombre'], 'estado' => 'ON']);
        return response()->json(['id' => $marca->id]);
    }

    public function update(Request $request, Marca $marca)
    {
        Gate::authorize('marcas.edit');
        abort_if($marca->id == 1, 403, 'No se puede modificar el registro principal del sistema.');
        $data = $request->validate(['nombre' => 'required|string|max:100']);
        $marca->update($data);
        return response()->json(['ok' => true]);
    }

    public function toggle(Marca $marca)
    {
        Gate::authorize('marcas.edit');
        abort_if($marca->id == 1, 403, 'No se puede desactivar el registro principal del sistema.');
        $marca->estado = $marca->estado === 'ON' ? 'OFF' : 'ON';
        $marca->save();
        return response()->json(['ok' => true]);
    }
}
