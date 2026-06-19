<?php

namespace App\Http\Controllers;

use App\Models\Tranza;
use App\Models\Apertura;
use App\Models\Cierre;
use App\Models\Compradetalle;
use App\Models\Ventadetalle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CajaController extends Controller
{
    public function kpis(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $hoy = Carbon::today()->toDateString();

        $apertura = Apertura::where('sucursal_id', $sid)->where('estado', 'ON')
            ->orderBy('id', 'desc')->first();

        // DOBLE CONTEO (bug reportado en Tarija: saldo salía el doble). El cierre crea la
        // apertura siguiente fechada MAÑANA con `apertura = saldo`, que YA incluye el neto del
        // día cerrado. Si el saldo se calcula sumando las tranzas de "hoy" sobre esa apertura,
        // el día se cuenta DOS veces. Solución: la ventana de ingresos/egresos arranca en la
        // FECHA de la apertura activa (su monto ya contiene todo lo anterior):
        //  - tras cerrar hoy: apertura=mañana → rango [mañana..hoy] invertido → 0 → saldo=apertura. ✓
        //  - apertura abierta hoy: rango [hoy..hoy] → movimientos de hoy (igual que antes). ✓
        //  - apertura multi-día sin cerrar: suma desde que se abrió (antes sólo sumaba hoy → era
        //    otro error latente; ahora también queda correcto).
        $base  = $apertura ? Carbon::parse($apertura->fecha)->toDateString() : $hoy;
        $desde = $request->get('fecha_desde', $base);
        $hasta = $request->get('fecha_hasta', $hoy);

        // `fecha` es una columna DATE: comparar con where() plano (no whereDate). whereDate
        // envuelve la columna en CAST(fecha AS DATE), lo que INUTILIZA `tranzas_fecha_idx` y
        // degrada a full table scan (coste lineal en el nº de tranzas). El CAST es además un
        // no-op semántico sobre DATE → misma lógica, índice usable. Ver PerformanceAuditTest.
        $ingresos = Tranza::where('sucursal_id', $sid)->where('tipo', 'INGRESO')->where('estado', 'ON')->where('fecha', '>=', $desde)->where('fecha', '<=', $hasta)->sum('monto_ingreso');
        $egresos  = Tranza::where('sucursal_id', $sid)->where('tipo', 'EGRESO')->where('estado', 'ON')->where('fecha', '>=', $desde)->where('fecha', '<=', $hasta)->sum('monto_egreso');
        $montoAp  = $apertura ? (float) $apertura->apertura : 0;

        return response()->json([
            'ingresos'       => (float) $ingresos,
            'egresos'        => (float) $egresos,
            'saldo'          => (float) ($montoAp + $ingresos - $egresos),
            'apertura_monto' => (float) $montoAp,
            'abierta'        => $apertura !== null,
            'apertura_id'    => $apertura?->id,
        ]);
    }

    public function movimientos(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $hoy = Carbon::today()->toDateString();
        // Mismo período que el saldo (kpis): los movimientos del día son los del rango de la
        // apertura activa, para que lo listado coincida con lo que compone el saldo (evita la
        // confusión del doble conteo: ver kpis()).
        $apertura = Apertura::where('sucursal_id', $sid)->where('estado', 'ON')->orderBy('id', 'desc')->first();
        $base = $apertura ? Carbon::parse($apertura->fecha)->toDateString() : $hoy;
        // `fecha` es DATE → where() plano (no whereDate): mantiene usable `tranzas_fecha_idx`.
        $q = Tranza::with('cuenta')->where('sucursal_id', $sid)->where('estado', 'ON')
            ->where('fecha', '>=', $request->get('fecha_desde', $base))
            ->where('fecha', '<=', $request->get('fecha_hasta', $hoy));

        if ($request->filled('fecha_desde')) $q->where('fecha', '>=', $request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha', '<=', $request->fecha_hasta);
        if ($request->filled('tipo')) $q->where('tipo', $request->tipo);
        if ($request->filled('clase')) $q->where('clase', $request->clase);

        $total = $q->count();

        $sortCol = $request->get('sort', 'id');
        $sortDir = strtolower($request->get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $validCols = [
            'id' => 'tranzas.id',
            'fecha' => 'tranzas.fecha',
            'tipo' => 'tranzas.tipo',
            'clase' => 'tranzas.clase',
            'ingreso' => 'tranzas.monto_ingreso',
            'egreso' => 'tranzas.monto_egreso'
        ];

        if (array_key_exists($sortCol, $validCols)) {
            $q->orderBy($validCols[$sortCol], $sortDir);
        } else if ($sortCol === 'cuenta') {
            $q->leftJoin('cuentas', 'tranzas.cuenta_id', '=', 'cuentas.id')
              ->orderBy('cuentas.nombre', $sortDir);
        } else {
            $q->orderBy('tranzas.id', 'desc');
        }

        $movs = $q->select('tranzas.*')->skip($request->get('skip', 0))->take($request->get('take', 30))->get();

        return response()->json([
            'total' => $total,
            'data' => $movs->map(fn($t) => [
                'id' => $t->id, 'fecha' => Carbon::parse($t->fecha)->format('d/m/Y'),
                'tipo' => $t->tipo, 'clase' => $t->clase, 'cuenta' => $t->cuenta->nombre ?? '',
                // registro = id del documento que originó la tranza (venta/compra/envío),
                // para poder ubicarlo rápido desde caja. En ENT/SAL es la apertura (no es doc).
                'registro' => $t->registro,
                'ingreso' => (float) $t->monto_ingreso,
                'egreso'  => (float) $t->monto_egreso,
                'descripcion' => $t->descripcion,
            ]),
        ]);
    }

    public function apertura(Request $request)
    {
        $sid = Auth::user()->sucursal_id;

        // Evitar doble apertura el mismo día
        $existe = Apertura::where('sucursal_id', $sid)->where('estado', 'ON')
            ->where('fecha', Carbon::today()->toDateString())->exists();
        if ($existe) {
            return response()->json(['error' => 'Ya existe una apertura para hoy.'], 422);
        }

        Apertura::create([
            'sucursal_id' => $sid, 'fecha' => Carbon::today(),
            'apertura' => $request->monto ?? 0, 'user_id' => Auth::id(), 'cerrado' => 'NO', 'estado' => 'ON',
        ]);
        return response()->json(['ok' => true]);
    }

    public function cierre(Request $request)
    {
        // `fecha_cierre` define el período conciliado (whereBetween) y se persiste como
        // fecha del cierre, del arrastre y de `sucursal.ultimo_cierre`. Sin validar, un
        // valor manipulado (basura, futuro, anterior a la apertura) falsea la conciliación,
        // huérfana tranzas reales y corrompe el guard de período. Se valida el TIPO acá y el
        // RANGO de negocio abajo (necesita `apertura->fecha`).
        $request->validate([
            'fecha_cierre' => 'nullable|date',
        ]);

        $sid = Auth::user()->sucursal_id;
        // Filtra estado='ON' (igual que ingresar/egresar/apertura): una apertura OFF con
        // cerrado='NO' (residuo que deja revertir-cierre en el arrastre) NO debe elegirse.
        // Orden determinista por id (latest() por created_at empata con timestamps iguales).
        $apertura = Apertura::where('sucursal_id', $sid)->where('cerrado', 'NO')
            ->where('estado', 'ON')->latest('id')->first();
        if (!$apertura) {
            return response()->json(['error' => 'No hay apertura activa'], 422);
        }

        // Evitar cierres duplicados: la apertura ya fue cerrada
        if (Cierre::where('apertura_id', $apertura->id)->where('estado', 'ON')->exists()) {
            return response()->json(['error' => 'Esta apertura ya fue cerrada.'], 422);
        }

        // Evitar cerrar la apertura de mañana recién creada
        if ($apertura->fecha > Carbon::today()->toDateString()) {
            return response()->json(['error' => 'La apertura activa es de mañana. La caja de hoy ya fue cerrada.'], 422);
        }

        $hoy = Carbon::today()->toDateString();
        $ini = $apertura->fecha;
        $fin = $request->fecha_cierre ? Carbon::parse($request->fecha_cierre)->toDateString() : $hoy;

        // El período de cierre no puede ser anterior a la apertura (whereBetween invertido →
        // 0 filas → ignora tranzas reales) ni futuro (contaría dinero que aún no ocurre y
        // fijaría ultimo_cierre adelantado, rompiendo el guard de período).
        if ($fin < $ini || $fin > $hoy) {
            return response()->json([
                'error' => 'La fecha de cierre debe estar entre la apertura (' . $ini . ') y hoy (' . $hoy . ').',
            ], 422);
        }

        $ingresos = Tranza::where('sucursal_id', $sid)->where('estado', 'ON')
            ->whereBetween('fecha', [$ini, $fin])->sum('monto_ingreso');
        $egresos = Tranza::where('sucursal_id', $sid)->where('estado', 'ON')
            ->whereBetween('fecha', [$ini, $fin])->sum('monto_egreso');
        $montoApertura = $apertura->apertura;
        $saldo = $montoApertura + $ingresos - $egresos;

        DB::beginTransaction();
        try {
            $cierre = Cierre::create([
                'sucursal_id' => $sid, 'apertura_id' => $apertura->id,
                'fecha' => $fin, 'apertura' => $montoApertura,
                'ingresos' => $ingresos, 'egresos' => $egresos, 'cierre' => $saldo,
                'user_id' => Auth::id(), 'estado' => 'ON',
            ]);
            $apertura->update(['cerrado' => 'SI']);

            Apertura::create([
                'sucursal_id' => $sid, 
                'fecha' => Carbon::now()->addDays(1)->format('Y-m-d'),
                'apertura' => $saldo, 
                'user_id' => Auth::id(), 
                'cerrado' => 'NO', 
                'estado' => 'ON',
            ]);

            $sucursal = \App\Models\Sucursal::findOrFail($sid);
            $sucursal->ultimo_cierre = $fin;
            $sucursal->save();

            DB::commit();
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function revertirCierre(Request $request)
    {
        $request->validate([
            'cierre_id' => 'required|integer'
        ]);

        $cierre = Cierre::findOrFail($request->cierre_id);
        $sid = Auth::user()->sucursal_id;

        if ($cierre->sucursal_id !== $sid) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $ultimo_cierre = Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->latest('id')->first();

        if (!$ultimo_cierre || $ultimo_cierre->id !== $cierre->id) {
            return response()->json(['error' => 'Solo se puede eliminar el último cierre activo.'], 422);
        }

        DB::beginTransaction();
        try {
            $ultima_apertura = Apertura::where('sucursal_id', $sid)->where('estado', 'ON')->latest('id')->first();
            if ($ultima_apertura && $ultima_apertura->cerrado == 'NO') {
                $ultima_apertura->estado = 'OFF';
                $ultima_apertura->save();
            }

            $apertura_anterior = Apertura::findOrFail($cierre->apertura_id);
            $apertura_anterior->cerrado = 'NO';
            $apertura_anterior->save();

            $cierre->estado = 'OFF';
            $cierre->save();

            $sucursal = \App\Models\Sucursal::findOrFail($sid);
            $sucursal->ultimo_cierre = $apertura_anterior->fecha;
            $sucursal->save();

            DB::commit();
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function apiTranzas(Apertura $apertura)
    {
        abort_if($apertura->sucursal_id !== Auth::user()->sucursal_id, 403);
        $ini = $apertura->fecha;
        $fin = Cierre::where('apertura_id', $apertura->id)->value('fecha') ?? Carbon::today()->toDateString();
        $rows = Tranza::where('sucursal_id', $apertura->sucursal_id)
            ->whereBetween('fecha', [$ini, $fin])->where('estado', 'ON')
            ->orderBy('fecha')->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn($t) => [
            'id' => $t->id, 'fecha' => $t->fecha, 'clase' => $t->clase,
            'registro' => $t->registro, 'descripcion' => $t->descripcion,
            'ingreso' => (float) $t->monto_ingreso,
            'egreso'  => (float) $t->monto_egreso,
            'editable' => in_array($t->clase, ['ENT', 'SAL']),
        ])]);
    }

    public function apiCompras(Apertura $apertura)
    {
        abort_if($apertura->sucursal_id !== Auth::user()->sucursal_id, 403);
        $ini = $apertura->fecha;
        $fin = Cierre::where('apertura_id', $apertura->id)->value('fecha') ?? Carbon::today()->toDateString();
        $rows = Compradetalle::join('compras', 'compras.id', '=', 'compradetalles.compra_id')
            ->select('compras.fecha', 'compradetalles.producto_id', 'compradetalles.codigo', 'compradetalles.descripcion',
                     'compradetalles.marca', 'compradetalles.costo', 'compradetalles.cantidad', 'compradetalles.subtotal')
            ->where('compras.sucursal_id', $apertura->sucursal_id)
            ->whereBetween('compras.fecha', [$ini, $fin])
            ->where('compras.tipo', 'CONTADO')->where('compras.estado', 'VALIDO')
            ->where('compradetalles.estado', 'VALIDO')
            ->orderBy('compras.fecha', 'desc')->get();

        return response()->json(['data' => $rows->map(fn($r) => [
            'fecha' => $r->fecha, 'producto_id' => $r->producto_id, 'codigo' => $r->codigo, 'descripcion' => $r->descripcion,
            'marca' => $r->marca, 'costo' => (float) $r->costo,
            'cantidad' => $r->cantidad, 'subtotal' => (float) $r->subtotal,
        ])]);
    }

    public function apiVentas(Apertura $apertura)
    {
        abort_if($apertura->sucursal_id !== Auth::user()->sucursal_id, 403);
        $ini = $apertura->fecha;
        $fin = Cierre::where('apertura_id', $apertura->id)->value('fecha') ?? Carbon::today()->toDateString();
        $rows = Ventadetalle::join('ventas', 'ventas.id', '=', 'ventadetalles.venta_id')
            ->select('ventas.fecha', 'ventadetalles.producto_id', 'ventadetalles.codigo', 'ventadetalles.descripcion',
                     'ventadetalles.marca', 'ventadetalles.costo', 'ventadetalles.cantidad', 'ventadetalles.subtotal')
            ->where('ventas.sucursal_id', $apertura->sucursal_id)
            ->whereBetween('ventas.fecha', [$ini, $fin])
            ->where('ventas.tipo', 'CONTADO')->where('ventas.estado', 'VALIDO')
            ->where('ventadetalles.estado', 'VALIDO')
            ->orderBy('ventas.fecha', 'desc')->get();

        return response()->json(['data' => $rows->map(fn($r) => [
            'fecha' => $r->fecha, 'producto_id' => $r->producto_id, 'codigo' => $r->codigo, 'descripcion' => $r->descripcion,
            'marca' => $r->marca, 'costo' => (float) $r->costo,
            'cantidad' => $r->cantidad, 'subtotal' => (float) $r->subtotal,
        ])]);
    }

    public function ingresar(Request $request)
    {
        $request->validate([
            'monto'       => 'required|numeric|min:0.01',
            'descripcion' => 'nullable|string|max:500',
            // Permite registrar el ingreso con una fecha (p. ej. cargar un gasto de ayer),
            // como en el sistema legacy. Si no viene, es del día. El guard de periodo cerrado
            // (abajo) impide fechar dentro de un cierre ya hecho.
            'fecha'       => 'nullable|date',
        ]);
        $fecha = $request->fecha ?? Carbon::today()->toDateString();
        $sucursal = \App\Models\Sucursal::findOrFail(Auth::user()->sucursal_id);
        if ($fecha <= $sucursal->ultimo_cierre) {
            return response()->json(['error' => 'No se pueden ingresar tranzas en un periodo ya cerrado.'], 422);
        }

        $aperturaId = Apertura::where('sucursal_id', Auth::user()->sucursal_id)
            ->where('cerrado', 'NO')->where('estado', 'ON')->latest('id')->value('id') ?? 0;

        Tranza::create([
            'sucursal_id' => Auth::user()->sucursal_id, 'cuenta_id' => Auth::user()->sucursal_id,
            'fecha' => $fecha, 'tipo' => 'INGRESO',
            'clase' => 'ENT', 'registro' => $aperturaId, 'descripcion' => $request->descripcion,
            'monto_ingreso' => $request->monto, 'user_id' => Auth::id(), 'estado' => 'ON',
        ]);
        return response()->json(['ok' => true]);
    }

    public function egresar(Request $request)
    {
        $request->validate([
            'monto'       => 'required|numeric|min:0.01',
            'descripcion' => 'nullable|string|max:500',
            // Permite registrar el egreso/gasto con fecha (p. ej. un gasto de ayer), como el
            // legacy. Si no viene, es del día. El guard de periodo cerrado lo limita abajo.
            'fecha'       => 'nullable|date',
        ]);
        $fecha = $request->fecha ?? Carbon::today()->toDateString();
        $sucursal = \App\Models\Sucursal::findOrFail(Auth::user()->sucursal_id);
        if ($fecha <= $sucursal->ultimo_cierre) {
            return response()->json(['error' => 'No se pueden egresar tranzas en un periodo ya cerrado.'], 422);
        }

        $aperturaId = Apertura::where('sucursal_id', Auth::user()->sucursal_id)
            ->where('cerrado', 'NO')->where('estado', 'ON')->latest('id')->value('id') ?? 0;

        Tranza::create([
            'sucursal_id' => Auth::user()->sucursal_id, 'cuenta_id' => Auth::user()->sucursal_id,
            'fecha' => $fecha, 'tipo' => 'EGRESO',
            'clase' => 'SAL', 'registro' => $aperturaId, 'descripcion' => $request->descripcion,
            'monto_egreso' => $request->monto, 'user_id' => Auth::id(), 'estado' => 'ON',
        ]);
        return response()->json(['ok' => true]);
    }

    public function updateTranza(Request $request)
    {
        $request->validate([
            'tranza_id'   => 'required|integer',
            'fecha'       => 'nullable|date',
            'monto'       => 'nullable|numeric|min:0.01',
            'descripcion' => 'nullable|string|max:500',
        ]);
        $tranza = Tranza::findOrFail($request->tranza_id);
        abort_if($tranza->sucursal_id !== Auth::user()->sucursal_id, 403);
        
        $sucursal = \App\Models\Sucursal::findOrFail(Auth::user()->sucursal_id);
        if ($tranza->fecha <= $sucursal->ultimo_cierre) {
            return response()->json(['error' => 'No se puede modificar una tranza de un periodo ya cerrado.'], 422);
        }
        if ($request->filled('fecha') && $request->fecha <= $sucursal->ultimo_cierre) {
            return response()->json(['error' => 'La nueva fecha no puede pertenecer a un periodo ya cerrado.'], 422);
        }

        if ($request->filled('fecha')) $tranza->fecha = $request->fecha;
        if ($request->filled('monto')) {
            if ($tranza->clase == 'ENT') {
                $tranza->monto_ingreso = $request->monto;
            } elseif ($tranza->clase == 'SAL') {
                $tranza->monto_egreso = $request->monto;
            }
        }
        // `descripcion` solo se actualiza si vino en el request. Asignar el null implícito
        // de un request que solo trae `monto` reventaba el INSERT (columna NOT NULL → 500).
        // El front siempre la envía; preservar-si-ausente no altera ese flujo.
        if ($request->has('descripcion')) {
            $tranza->descripcion = $request->descripcion ?? '';
        }
        $tranza->save();
        return response()->json(true);
    }

    public function deleteTranza(Request $request)
    {
        $request->validate([
            'tranza_id' => 'required|integer',
        ]);
        $tranza = Tranza::findOrFail($request->tranza_id);
        abort_if($tranza->sucursal_id !== Auth::user()->sucursal_id, 403);

        $sucursal = \App\Models\Sucursal::findOrFail(Auth::user()->sucursal_id);
        if ($tranza->fecha <= $sucursal->ultimo_cierre) {
            return response()->json(['error' => 'No se puede eliminar una tranza de un periodo ya cerrado.'], 422);
        }

        $tranza->estado = 'OFF';
        $tranza->save();
        return response()->json(true);
    }

    public function apiHistorialTranzas(Request $request)
    {
        $sid  = Auth::user()->sucursal_id;
        $ini  = $request->get('desde', Carbon::today()->toDateString());
        $fin  = $request->get('hasta', Carbon::today()->toDateString());

        $rows = Tranza::where('sucursal_id', $sid)->whereBetween('fecha', [$ini, $fin])
            ->where('estado', 'ON')->orderBy('fecha')->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn($t) => [
            'id' => $t->id, 'fecha' => $t->fecha, 'clase' => $t->clase,
            'descripcion' => $t->descripcion,
            'ingreso' => (float) $t->monto_ingreso,
            'egreso'  => (float) $t->monto_egreso,
        ]), 'total_ingresos' => (float) $rows->sum('monto_ingreso'),
            'total_egresos'  => (float) $rows->sum('monto_egreso')]);
    }

    public function apiHistorialCompras(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $ini = $request->get('desde', Carbon::today()->toDateString());
        $fin = $request->get('hasta', Carbon::today()->toDateString());

        $rows = \App\Models\Compradetalle::join('compras', 'compras.id', '=', 'compradetalles.compra_id')
            ->select('compras.fecha', 'compradetalles.producto_id', 'compradetalles.codigo', 'compradetalles.descripcion',
                     'compradetalles.marca', 'compradetalles.costo', 'compradetalles.cantidad', 'compradetalles.subtotal')
            ->where('compras.sucursal_id', $sid)->whereBetween('compras.fecha', [$ini, $fin])
            ->where('compras.tipo', 'CONTADO')->where('compras.estado', 'VALIDO')
            ->where('compradetalles.estado', 'VALIDO')->orderBy('compras.fecha', 'desc')->get();

        return response()->json(['data' => $rows->map(fn($r) => [
            'fecha' => $r->fecha, 'producto_id' => $r->producto_id, 'codigo' => $r->codigo, 'descripcion' => $r->descripcion,
            'marca' => $r->marca, 'costo' => (float) $r->costo,
            'cantidad' => $r->cantidad, 'subtotal' => (float) $r->subtotal,
        ]), 'total' => (float) $rows->sum('subtotal')]);
    }

    public function apiHistorialVentas(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $ini = $request->get('desde', Carbon::today()->toDateString());
        $fin = $request->get('hasta', Carbon::today()->toDateString());

        $rows = \App\Models\Ventadetalle::join('ventas', 'ventas.id', '=', 'ventadetalles.venta_id')
            ->select('ventas.fecha', 'ventadetalles.producto_id', 'ventadetalles.codigo', 'ventadetalles.descripcion',
                     'ventadetalles.marca', 'ventadetalles.costo', 'ventadetalles.cantidad', 'ventadetalles.subtotal')
            ->where('ventas.sucursal_id', $sid)->whereBetween('ventas.fecha', [$ini, $fin])
            ->where('ventas.tipo', 'CONTADO')->where('ventas.estado', 'VALIDO')
            ->where('ventadetalles.estado', 'VALIDO')->orderBy('ventas.fecha', 'desc')->get();

        return response()->json(['data' => $rows->map(fn($r) => [
            'fecha' => $r->fecha, 'producto_id' => $r->producto_id, 'codigo' => $r->codigo, 'descripcion' => $r->descripcion,
            'marca' => $r->marca, 'costo' => (float) $r->costo,
            'cantidad' => $r->cantidad, 'subtotal' => (float) $r->subtotal,
        ]), 'total' => (float) $rows->sum('subtotal')]);
    }

    public function apiHistorialEfectivos(Request $request)
    {
        $sid  = Auth::user()->sucursal_id;
        $ini  = $request->get('desde', Carbon::today()->toDateString());
        $fin  = $request->get('hasta', Carbon::today()->toDateString());
        $clases = ['ENT', 'SAL', 'D-COM', 'D-VEN', 'ENV', 'REC', 'PAG', 'COB'];

        $rows = Tranza::where('sucursal_id', $sid)->whereBetween('fecha', [$ini, $fin])
            ->where('estado', 'ON')->whereIn('clase', $clases)
            ->orderBy('fecha', 'desc')->orderBy('id', 'desc')->get();

        return response()->json(['data' => $rows->map(fn($t) => [
            'id' => $t->id, 'fecha' => $t->fecha, 'clase' => $t->clase,
            'descripcion' => $t->descripcion,
            'ingreso' => (float) $t->monto_ingreso,
            'egreso'  => (float) $t->monto_egreso,
            'editable' => in_array($t->clase, ['ENT', 'SAL']),
        ]), 'total_ingresos' => (float) $rows->sum('monto_ingreso'),
            'total_egresos'  => (float) $rows->sum('monto_egreso')]);
    }

    public function apiAperturas(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $ini = $request->get('desde', Carbon::today()->subDays(30)->toDateString());
        $fin = $request->get('hasta', Carbon::today()->toDateString());

        $aperturas = Apertura::where('sucursal_id', $sid)
            ->whereBetween('fecha', [$ini, $fin])
            ->where('estado', 'ON')
            ->orderBy('fecha', 'desc')
            ->get();

        return response()->json(['data' => $aperturas->map(fn($a) => [
            'id'      => $a->id,
            'fecha'   => $a->fecha,
            'monto'   => (float) $a->apertura,
            'cerrado' => $a->cerrado,
        ])]);
    }

    /**
     * Lista de Cierres (réplica del legacy "Lista de Cierres"): cada cierre con la fecha de su
     * apertura, los montos conciliados (apertura/ingresos/egresos/efectivo) y el usuario. Los datos
     * salen de `cierres` (estado ON); no se recalcula nada (es el snapshot del momento del cierre).
     *
     * @param  \Illuminate\Http\Request  $request  Params opcionales: desde, hasta (rango por fecha de cierre).
     * @return \Illuminate\Http\JsonResponse  { data: [{ id, apertura_id, fecha_apertura, fecha_cierre,
     *         apertura, ingresos, egresos, efectivo, usuario, es_ultimo }] }
     */
    public function apiCierres(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $sucursalNombre = \App\Models\Sucursal::where('id', $sid)->value('nombre') ?? '';

        // La Lista de Cierres es el landing de Caja (igual que el legacy): paginada y buscable,
        // sin ventana de fechas forzada (muestra todos los cierres de la sucursal). `desde/hasta`
        // son filtros opcionales.
        $q = Cierre::with('user')->where('sucursal_id', $sid)->where('estado', 'ON');
        if ($request->filled('desde')) $q->where('fecha', '>=', $request->desde);
        if ($request->filled('hasta')) $q->where('fecha', '<=', $request->hasta);
        if ($request->filled('search')) {
            $s = trim($request->search);
            $q->where(function ($w) use ($s) {
                $w->where('id', $s)->orWhere('fecha', 'like', "%{$s}%");
            });
        }

        $total = (clone $q)->count();
        $cierres = $q->orderBy('fecha', 'desc')->orderBy('id', 'desc')
            ->skip((int) $request->get('skip', 0))->take((int) $request->get('take', 10))->get();

        // OJO: en el modelo Cierre la COLUMNA `apertura` (monto) tapa la relación apertura()
        // ($c->apertura = monto, no el modelo). La apertura (fecha + usuario) se resuelve por id (sin N+1).
        $aperturas = Apertura::with('user')
            ->whereIn('id', $cierres->pluck('apertura_id')->filter()->unique())
            ->get()->keyBy('id');

        // Solo el ÚLTIMO cierre activo se puede eliminar (fiel al legacy: destroy() deniega los demás).
        $ultimoId = (int) Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->max('id');

        return response()->json([
            'total' => $total,
            'data'  => $cierres->map(function ($c) use ($aperturas, $ultimoId, $sucursalNombre) {
                $ap = $aperturas->get($c->apertura_id);
                return [
                    'id'             => $c->id,
                    'apertura_id'    => $c->apertura_id,
                    'sucursal'       => $sucursalNombre,
                    'fecha_apertura' => $ap?->fecha,
                    'fecha_cierre'   => $c->fecha,
                    'apertura'       => (float) $c->apertura,
                    'ingresos'       => (float) $c->ingresos,
                    'egresos'        => (float) $c->egresos,
                    'efectivo'       => (float) $c->cierre,
                    'usuario'        => $c->user?->name ?? $ap?->user?->name ?? '',
                    'es_ultimo'      => $c->id === $ultimoId,
                ];
            }),
        ]);
    }

    /**
     * Vista de una apertura (réplica del legacy caja.show): panel de totales para la pantalla
     * "CAJA [VISTA]". Sirve tanto para la apertura activa ("Última Apertura") como para un cierre
     * pasado (el 👁 de la Lista de Cierres). Recalcula en vivo como el legacy (ini=apertura.fecha,
     * fin=cierre.fecha o hoy; efectivo = apertura + ingresos − egresos). Guard de sucursal (IDOR).
     *
     * @param  \App\Models\Apertura  $apertura
     * @return \Illuminate\Http\JsonResponse
     */
    public function apiAperturaShow(Apertura $apertura)
    {
        $sid = Auth::user()->sucursal_id;
        abort_if((int) $apertura->sucursal_id !== (int) $sid, 403);

        $apertura->load('user');
        $cierre = Cierre::with('user')->where('apertura_id', $apertura->id)->where('estado', 'ON')->first();

        $ini = Carbon::parse($apertura->fecha)->toDateString();
        $fin = $cierre ? Carbon::parse($cierre->fecha)->toDateString() : Carbon::today()->toDateString();

        // `fecha` es DATE → where() plano (no whereDate): mantiene usable `tranzas_fecha_idx`.
        $ingresos = Tranza::where('sucursal_id', $sid)->where('estado', 'ON')->where('fecha', '>=', $ini)->where('fecha', '<=', $fin)->sum('monto_ingreso');
        $egresos  = Tranza::where('sucursal_id', $sid)->where('estado', 'ON')->where('fecha', '>=', $ini)->where('fecha', '<=', $fin)->sum('monto_egreso');
        $apMonto  = (float) $apertura->apertura;

        $ultimoCierreId = (int) Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->max('id');

        return response()->json([
            'apertura_id'      => $apertura->id,
            'cerrado'          => $apertura->cerrado,            // 'NO' (abierta) | 'SI' (cerrada)
            'sucursal'         => $apertura->sucursal?->nombre ?? '',
            'fecha_apertura'   => $apertura->fecha,
            'fecha_cierre'     => $cierre?->fecha,
            'apertura'         => $apMonto,
            'ingresos'         => (float) $ingresos,
            'egresos'          => (float) $egresos,
            'efectivo'         => $apMonto + (float) $ingresos - (float) $egresos,
            'usuario_apertura' => $apertura->user?->name ?? '',
            'usuario_cierre'   => $cierre?->user?->name ?? '',
            'cierre_id'        => $cierre?->id,
            'es_ultimo_cierre' => $cierre && $cierre->id === $ultimoCierreId,
        ]);
    }

    /**
     * Detalle de un cierre (el "ojito" del legacy show()): panel resumen + movimientos del período.
     * El rango es [apertura.fecha, cierre.fecha]; los montos del resumen son el snapshot del cierre
     * (consistentes con la Lista de Cierres). Incluye guard de pertenencia de sucursal (IDOR).
     *
     * @param  \App\Models\Cierre  $cierre  Resuelto por route-model binding ({cierre}).
     * @return \Illuminate\Http\JsonResponse
     */
    public function apiCierreDetalle(Cierre $cierre)
    {
        $sid = Auth::user()->sucursal_id;
        // Un cierre de otra sucursal no se expone (mismo patrón que revertirCierre).
        if ((int) $cierre->sucursal_id !== (int) $sid) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $cierre->load('user');
        // La columna `apertura` (monto) tapa la relación apertura(): se busca el modelo por id.
        $apertura = Apertura::with('user')->find($cierre->apertura_id);
        $ini = $apertura ? Carbon::parse($apertura->fecha)->toDateString() : Carbon::parse($cierre->fecha)->toDateString();
        $fin = Carbon::parse($cierre->fecha)->toDateString();

        // `fecha` es DATE → where() plano (no whereDate): mantiene usable `tranzas_fecha_idx`.
        $movs = Tranza::with('cuenta')->where('sucursal_id', $sid)->where('estado', 'ON')
            ->where('fecha', '>=', $ini)->where('fecha', '<=', $fin)
            ->orderBy('id', 'desc')->get();

        $ultimoId = (int) Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->max('id');

        return response()->json([
            'id'               => $cierre->id,
            'apertura_id'      => $cierre->apertura_id,
            'fecha_apertura'   => $apertura?->fecha,
            'fecha_cierre'     => $cierre->fecha,
            'apertura'         => (float) $cierre->apertura,
            'ingresos'         => (float) $cierre->ingresos,
            'egresos'          => (float) $cierre->egresos,
            'efectivo'         => (float) $cierre->cierre,
            'usuario_apertura' => $apertura?->user?->name ?? '',
            'usuario_cierre'   => $cierre->user?->name ?? '',
            'es_ultimo'        => $cierre->id === $ultimoId,
            'movimientos'      => $movs->map(fn($t) => [
                'id'          => $t->id,
                'fecha'       => Carbon::parse($t->fecha)->format('d/m/Y'),
                'clase'       => $t->clase,
                'cuenta'      => $t->cuenta->nombre ?? '',
                'descripcion' => $t->descripcion,
                'ingreso'     => (float) $t->monto_ingreso,
                'egreso'      => (float) $t->monto_egreso,
            ]),
        ]);
    }

    /**
     * "Imprimir" un cierre (PDF) — réplica del botón Imprimir del ojito legacy. Reutiliza la vista
     * `caja.pdf` con el período [apertura.fecha, cierre.fecha]. Guard de pertenencia de sucursal.
     *
     * @param  \App\Models\Cierre  $cierre
     * @return \Illuminate\Http\Response  Stream del PDF.
     */
    public function cierrePdf(Cierre $cierre)
    {
        $sid = Auth::user()->sucursal_id;
        if ((int) $cierre->sucursal_id !== (int) $sid) {
            abort(403, 'No autorizado');
        }

        // La columna `apertura` (monto) tapa la relación apertura(): se busca el modelo por id.
        $apertura = Apertura::find($cierre->apertura_id);
        $desde = $apertura ? Carbon::parse($apertura->fecha)->toDateString() : Carbon::parse($cierre->fecha)->toDateString();
        $hasta = Carbon::parse($cierre->fecha)->toDateString();

        $movs = Tranza::with('cuenta')->where('sucursal_id', $sid)->where('estado', 'ON')
            ->where('fecha', '>=', $desde)->where('fecha', '<=', $hasta)
            ->orderBy('id', 'desc')->get();

        $ingresos = $movs->sum('monto_ingreso');
        $egresos  = $movs->sum('monto_egreso');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('caja.pdf', compact('movs', 'ingresos', 'egresos', 'desde', 'hasta'))->setPaper('letter');
        return $pdf->stream('Cierre_' . $cierre->id . '_' . $hasta . '.pdf');
    }

    public function reportCaja(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $desde = $request->get('fecha_desde', Carbon::today()->toDateString());
        $hasta = $request->get('fecha_hasta', Carbon::today()->toDateString());

        // `fecha` es DATE → where() plano (no whereDate): mantiene usable `tranzas_fecha_idx`.
        $movs = Tranza::with('cuenta')->where('sucursal_id', $sid)->where('estado', 'ON')
            ->where('fecha', '>=', $desde)->where('fecha', '<=', $hasta)
            ->orderBy('id', 'desc')->get();

        $ingresos = $movs->sum('monto_ingreso');
        $egresos = $movs->sum('monto_egreso');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('caja.pdf', compact('movs', 'ingresos', 'egresos', 'desde', 'hasta'))->setPaper('letter');
        return $pdf->stream('Caja_' . $desde . '_' . $hasta . '.pdf');
    }
}
