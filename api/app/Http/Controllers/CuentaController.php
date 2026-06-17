<?php

namespace App\Http\Controllers;

use App\Helpers\SearchHelper;
use App\Models\Cuenta;
use App\Models\Compra;
use App\Models\Venta;
use App\Models\Tranza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CuentaController extends Controller
{
    public function apiList(Request $request)
    {
        $q = Cuenta::whereIn('estado', ['ON', 'BAN']);

        if ($request->filled('tipo') && strtoupper($request->tipo) !== 'TODOS') {
            $q->where('tipo', strtoupper($request->tipo));
        } elseif (!$request->boolean('todos')) {
            $q->whereIn('tipo', ['CLIENTE', 'CLIE-PROV']);
        }

        if ($request->filled('search')) {
            SearchHelper::apply(
                $q, $request->search,
                ['nombre', 'nit', 'email']
            );
        }

        if ($request->has('skip')) {
            $total = $q->count();
            
            $sortCol = $request->get('sort', 'nombre');
            $sortDir = $request->get('dir', 'asc') === 'asc' ? 'asc' : 'desc';
            $allowed = ['id'=>'id', 'nombre'=>'nombre', 'nit'=>'nit', 'tipo'=>'tipo', 'telefono'=>'telefono', 'saldo'=>'saldo'];
            $q->orderBy($allowed[$sortCol] ?? 'nombre', $sortDir);

            $data  = $q->skip((int) $request->get('skip', 0))
                ->take((int) $request->get('take', 30))
                ->get()
                ->map(fn($c) => [
                    'id' => $c->id, 'nombre' => $c->nombre, 'nit' => $c->nit ?? '',
                    'tipo' => $c->tipo, 'telefono' => $c->telefono ?? '',
                    'email' => $c->email ?? '',
                    'saldo' => (float) ($c->saldo ?? 0),
                    'direccion' => $c->direccion ?? '',
                    'departamento' => $c->departamento ?? '',
                ]);
            return response()->json(['total' => $total, 'data' => $data]);
        }

        return response()->json($q->orderBy('nombre')->take((int) $request->get('take', 20))->get()->map(fn($c) => [
            'id' => $c->id, 'nombre' => $c->nombre, 'nit' => $c->nit ?? '', 'tipo' => $c->tipo,
        ]));
    }

    public function store(Request $request)
    {
        Gate::authorize('cuentas.create');
        $data = $request->validate([
            'nombre'       => 'required|string|max:191',
            'tipo'         => 'required|in:PROVEEDOR,CLIE-PROV,CLIENTE,INTERNO',
            // Anchos reales de columna en `cuentas` (varchar(191) salvo email varchar(255)).
            // Sin estos `max:` un valor largo pasaba la validación y reventaba el INSERT
            // con PDOException 1406 → 500 (mismo patrón cerrado en pedidos/envíos).
            'nit'          => 'nullable|string|max:191',
            'telefono'     => 'nullable|string|max:191',
            'direccion'    => 'nullable|string|max:191',
            'departamento' => 'nullable|string|max:191',
            'email'        => 'nullable|string|max:255',
        ]);
        $cuenta = Cuenta::create([
            'nombre'       => $data['nombre'],
            'tipo'         => $data['tipo'],
            'telefono'     => $request->telefono ?? '',
            'email'        => $request->email ?? '',
            'direccion'    => $request->direccion ?? '',
            'nit'          => $request->nit ?? '',
            'empresa_id'   => $request->empresa_id ?? 1,
            'localidad_id' => $request->localidad_id ?? 1,
            'departamento' => $request->departamento ?? 'COCHABAMBA',
            'estado'       => 'ON',
        ]);
        return response()->json(['id' => $cuenta->id]);
    }

    public function update(Request $request, Cuenta $cuenta)
    {
        Gate::authorize('cuentas.edit');
        abort_if($cuenta->id == 1, 403, 'No se puede modificar la cuenta principal del sistema.');
        $request->validate([
            'nombre'       => 'required|string|max:191',
            'tipo'         => 'required|in:PROVEEDOR,CLIE-PROV,CLIENTE,INTERNO',
            // Mismo alineamiento validador↔columna que en store (evita 1406 → 500).
            'nit'          => 'nullable|string|max:191',
            'telefono'     => 'nullable|string|max:191',
            'direccion'    => 'nullable|string|max:191',
            'departamento' => 'nullable|string|max:191',
            'email'        => 'nullable|string|max:255',
        ]);
        $cuenta->update($request->only(['nombre','telefono','email','direccion','nit','tipo','empresa_id','localidad_id','departamento']));
        return response()->json(['ok' => true]);
    }

    public function toggle(Cuenta $cuenta)
    {
        Gate::authorize('cuentas.edit');
        abort_if($cuenta->id == 1, 403, 'No se puede desactivar la cuenta principal del sistema.');
        $cuenta->estado = $cuenta->estado === 'ON' ? 'OFF' : 'ON';
        $cuenta->save();
        return response()->json(['ok' => true]);
    }

    public function apiShow(Cuenta $cuenta)
    {
        $sid = auth()->user()->sucursal_id;
        $isCentral = $sid == 1;

        $cQ = Compra::where('cuenta_id', $cuenta->id)->where('estado', 'VALIDO');
        $vQ = Venta::where('cuenta_id', $cuenta->id)->where('estado', 'VALIDO');
        if (!$isCentral) {
            $cQ->where('sucursal_id', $sid);
            $vQ->where('sucursal_id', $sid);
        }

        $kpiC = (clone $cQ)->selectRaw('COUNT(*) as n, COALESCE(SUM(total),0) as total, COALESCE(SUM(acuenta),0) as pagado, COALESCE(SUM(saldo),0) as saldo, MAX(fecha) as ultima')->first();
        $kpiV = (clone $vQ)->selectRaw('COUNT(*) as n, COALESCE(SUM(total),0) as total, COALESCE(SUM(acuenta),0) as pagado, COALESCE(SUM(saldo),0) as saldo, MAX(fecha) as ultima')->first();

        $totalPagos  = DB::table('tranzas')->where('cuenta_id', $cuenta->id)->where('tipo','EGRESO')->where('clase','PAG')->where('estado','ON')->sum('monto_egreso');
        $totalCobros = DB::table('tranzas')->where('cuenta_id', $cuenta->id)->where('tipo','INGRESO')->where('clase','COB')->where('estado','ON')->sum('monto_ingreso');

        return response()->json([
            'id'        => $cuenta->id,
            'nombre'    => $cuenta->nombre,
            'nit'       => $cuenta->nit,
            'tipo'      => $cuenta->tipo,
            'telefono'  => $cuenta->telefono ?? '',
            'email'     => $cuenta->email ?? '',
            'direccion' => $cuenta->direccion ?? '',
            'saldo'     => (float) ($cuenta->saldo ?? 0),
            'kpis'   => [
                'compras_n'      => (int)   ($kpiC->n      ?? 0),
                'compras_total'  => (float) ($kpiC->total  ?? 0),
                'compras_pagado' => (float) ($kpiC->pagado ?? 0),
                'compras_saldo'  => (float) ($kpiC->saldo  ?? 0),
                'compras_ultima' => $kpiC->ultima ?? null,
                'ventas_n'       => (int)   ($kpiV->n      ?? 0),
                'ventas_total'   => (float) ($kpiV->total  ?? 0),
                'ventas_pagado'  => (float) ($kpiV->pagado ?? 0),
                'ventas_saldo'   => (float) ($kpiV->saldo  ?? 0),
                'ventas_ultima'  => $kpiV->ultima ?? null,
                'pagos_total'    => (float) $totalPagos,
                'cobros_total'   => (float) $totalCobros,
            ],
        ]);
    }

    public function kpis()
    {
        $row = DB::table('cuentas')->selectRaw("
            SUM(CASE WHEN estado = 'ON'  THEN 1 ELSE 0 END) as activas,
            SUM(CASE WHEN estado = 'BAN' THEN 1 ELSE 0 END) as bloqueadas,
            SUM(CASE WHEN estado IN ('ON','BAN') AND tipo = 'CLIENTE'   THEN 1 ELSE 0 END) as clientes,
            SUM(CASE WHEN estado IN ('ON','BAN') AND tipo = 'PROVEEDOR' THEN 1 ELSE 0 END) as proveedores,
            SUM(CASE WHEN estado IN ('ON','BAN') AND tipo = 'CLIE-PROV' THEN 1 ELSE 0 END) as dual_count,
            SUM(CASE WHEN estado IN ('ON','BAN') AND saldo <> 0 THEN 1 ELSE 0 END) as con_saldo,
            COALESCE(SUM(CASE WHEN estado IN ('ON','BAN') THEN saldo ELSE 0 END), 0) as saldo_total
        ")->first();

        return response()->json([
            'activas'     => (int)   ($row->activas     ?? 0),
            'bloqueadas'  => (int)   ($row->bloqueadas  ?? 0),
            'clientes'    => (int)   ($row->clientes    ?? 0),
            'proveedores' => (int)   ($row->proveedores ?? 0),
            'dual'        => (int)   ($row->dual_count  ?? 0),
            'con_saldo'   => (int)   ($row->con_saldo   ?? 0),
            'saldo_total' => (float) ($row->saldo_total ?? 0),
        ]);
    }

    public function apiCompras(Cuenta $cuenta)
    {
        $sid = auth()->user()->sucursal_id;
        $q = Compra::with('sucursal')->where('cuenta_id', $cuenta->id)->where('estado', 'VALIDO');
        if ($sid != 1) $q->where('sucursal_id', $sid);

        $total = $q->count();
        $rows  = $q->orderBy('id', 'desc')->skip(request('skip', 0))->take(30)->get();

        return response()->json(['total' => $total, 'data' => $rows->map(fn($c) => [
            'id'       => $c->id,
            'sucursal' => $c->sucursal->nombre ?? '—',
            'fecha'    => $c->fecha ? \Carbon\Carbon::parse($c->fecha)->format('d/m/Y') : '—',
            'tipo'     => $c->tipo,
            'total'    => 'Bs. ' . number_format($c->total, 2),
            'estado'   => $c->estado,
            'pagado'   => $c->pagado,
            'saldo'    => number_format($c->saldo, 2),
        ])]);
    }

    public function apiVentas(Cuenta $cuenta)
    {
        $sid = auth()->user()->sucursal_id;
        $q = Venta::with('sucursal')->where('cuenta_id', $cuenta->id)->where('estado', 'VALIDO');
        if ($sid != 1) $q->where('sucursal_id', $sid);

        $total = $q->count();
        $rows  = $q->orderBy('id', 'desc')->skip(request('skip', 0))->take(30)->get();

        return response()->json(['total' => $total, 'data' => $rows->map(fn($v) => [
            'id'       => $v->id,
            'sucursal' => $v->sucursal->nombre ?? '—',
            'fecha'    => $v->fecha ? \Carbon\Carbon::parse($v->fecha)->format('d/m/Y') : '—',
            'tipo'     => $v->tipo,
            'total'    => 'Bs. ' . number_format($v->total, 2),
            'estado'   => $v->estado,
            'pagado'   => $v->pagado,
            'saldo'    => number_format($v->saldo, 2),
        ])]);
    }

    public function apiPagos(Cuenta $cuenta)
    {
        $rows = Tranza::where('cuenta_id', $cuenta->id)
            ->where('tipo', 'EGRESO')->where('clase', 'PAG')->where('estado', 'ON')
            ->orderBy('id', 'desc')->get();

        return response()->json(['data' => $rows->map(fn($t) => [
            'id'          => $t->id,
            'fecha'       => \Carbon\Carbon::parse($t->fecha)->format('d/m/Y'),
            'monto'       => number_format($t->monto_egreso, 2),
            'descripcion' => $t->descripcion ?: ('Compra #' . $t->registro),
        ])]);
    }

    public function apiCobros(Cuenta $cuenta)
    {
        $rows = Tranza::where('cuenta_id', $cuenta->id)
            ->where('tipo', 'INGRESO')->where('clase', 'COB')->where('estado', 'ON')
            ->orderBy('id', 'desc')->get();

        return response()->json(['data' => $rows->map(fn($t) => [
            'id'          => $t->id,
            'fecha'       => \Carbon\Carbon::parse($t->fecha)->format('d/m/Y'),
            'monto'       => number_format($t->monto_ingreso, 2),
            'descripcion' => $t->descripcion ?: ('Venta #' . $t->registro),
        ])]);
    }
}
