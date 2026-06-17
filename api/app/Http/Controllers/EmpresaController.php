<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Cuenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmpresaController extends Controller
{
    public function api()
    {
        return response()->json(
            Empresa::orderBy('nombre')->get(['id', 'nombre', 'estado'])
        );
    }

    public function store(Request $request)
    {
        Gate::authorize('empresas.create');
        $request->validate(['nombre' => 'required|string|max:191']);
        $empresa = Empresa::create(['nombre' => $request->nombre, 'estado' => 'ON']);
        return response()->json(['id' => $empresa->id]);
    }

    public function cuentasJson(Empresa $empresa)
    {
        $cuentas = Cuenta::where('empresa_id', $empresa->id)
            ->where('estado', '!=', 'OFF')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'nit', 'tipo', 'telefono', 'saldo']);
        return response()->json([
            'empresa' => ['id' => $empresa->id, 'nombre' => $empresa->nombre],
            'cuentas' => $cuentas,
        ]);
    }

    public function update(Request $request, Empresa $empresa)
    {
        Gate::authorize('empresas.edit');
        if ($empresa->id <= 1) {
            return response()->json(['error' => 'No se puede editar.'], 403);
        }
        // Validar igual que store: sin esto, `nombre` faltante mete NULL (NOT NULL → 500)
        // y un nombre > columna `varchar(191)` desborda el UPDATE (1406 → 500).
        $data = $request->validate(['nombre' => 'required|string|max:191']);
        $empresa->update(['nombre' => $data['nombre']]);
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Empresa $empresa)
    {
        Gate::authorize('empresas.destroy');
        if ($empresa->id <= 1) {
            return response()->json(['error' => 'No se puede eliminar.'], 403);
        }
        $empresa->estado = 'OFF';
        $empresa->save();
        return response()->json(['ok' => true]);
    }

    public function toggle(Empresa $empresa)
    {
        Gate::authorize('empresas.edit');
        abort_if($empresa->id <= 1, 403, 'No se puede desactivar el registro principal del sistema.');
        $empresa->estado = $empresa->estado === 'ON' ? 'OFF' : 'ON';
        $empresa->save();
        return response()->json(['ok' => true]);
    }

}
