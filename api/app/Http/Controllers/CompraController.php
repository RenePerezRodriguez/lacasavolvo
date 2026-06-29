<?php

namespace App\Http\Controllers;

use App\Helpers\SearchHelper;
use App\Models\Compra;
use App\Models\Compradetalle;
use App\Models\Precio;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class CompraController extends Controller
{
    public function api(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $q = Compra::with('cuenta')
            ->where('sucursal_id', $sid)
            ->whereIn('estado', ['PROFORMA','VALIDO','ANULADO'])
            ->select('compras.*');

        // `fecha` es DATE → where() plano (no whereDate): preserva `compras_fecha_idx` usable.
        if ($request->filled('fecha_desde')) $q->where('fecha','>=',$request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha','<=',$request->fecha_hasta);
        if ($request->filled('estado_filtro')) $q->where('estado', strtoupper($request->estado_filtro));
        if ($request->filled('pagado_filtro')) $q->where('pagado', $request->pagado_filtro);
        if ($request->filled('search')) {
            SearchHelper::apply(
                $q, $request->search,
                ['compras.id', 'compras.tipo'],
                ['cuenta.nombre']
            );
        }

        $total = $q->count();

        $sort = $request->get('sort');
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';
        if ($sort === 'cuenta') {
            $q->leftJoin('cuentas', 'compras.cuenta_id', '=', 'cuentas.id')
              ->orderBy('cuentas.nombre', $dir);
        } else {
            $allowedSorts = ['id'=>'compras.id', 'fecha'=>'compras.fecha', 'tipo'=>'compras.tipo', 'total'=>'compras.total', 'pagado'=>'compras.pagado', 'estado'=>'compras.estado'];
            $sortCol = $allowedSorts[$sort] ?? 'compras.id';
            $q->orderBy($sortCol, $dir);
        }

        $compras = $q->skip($request->get('skip',0))->take($request->get('take',30))->get();

        return response()->json(['total'=>$total,'data'=>$compras->map(fn($c)=>[
            'id'=>$c->id,'fecha'=>$c->fecha->format('d/m/Y'),'tipo'=>$c->tipo,
            'cuenta'=>$c->cuenta->nombre??'','total'=>'Bs. '.number_format($c->total,2),'pagado'=>$c->pagado,
            'estado'=>$c->estado,
        ])]);
    }

    public function kpis(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $q = Compra::where('sucursal_id',$sid)->whereIn('estado',['PROFORMA','VALIDO']);
        // `fecha` es DATE → where() plano (no whereDate): preserva `compras_fecha_idx` usable.
        if ($request->filled('fecha_desde')) $q->where('fecha','>=',$request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha','<=',$request->fecha_hasta);

        // El monto validadas solo se expone a ADMIN/GERENTE. Se usa el ROL EFECTIVO
        // (effectiveRoleIs) —NO hasAnyRole()— para que RESPETE la simulación: hasAnyRole
        // reportaba el rol REAL → un ADMIN simulando VENDEDOR seguía viendo el monto (fuga
        // del simulador). Mismo criterio de rol que el legacy (@role admin/gerente).
        $puedeVerMonto = Auth::user()->effectiveRoleIs(['ADMIN', 'GERENTE']);

        return response()->json([
            'total'   => $q->count(),
            'proforma'=> (clone $q)->where('estado','PROFORMA')->count(),
            'valido'  => (clone $q)->where('estado','VALIDO')->count(),
            'monto'   => $puedeVerMonto ? 'Bs. '.number_format((clone $q)->where('estado','VALIDO')->sum('total'),2) : null,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(['fecha'=>'required|date','cuenta_id'=>'required|integer','tipo'=>'required|in:CONTADO,CREDITO']);
        abort_if($request->fecha <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');
        $compra = Compra::create([
            'sucursal_id'=>Auth::user()->sucursal_id,'fecha'=>$request->fecha,'tipo'=>$request->tipo,
            'cuenta_id'=>$request->cuenta_id,'pagado'=>$request->tipo==='CREDITO'?'POR PAGAR':'PAGADO',
            'estado'=>'PROFORMA','user_id'=>Auth::id(),'total'=>0,'monto'=>0,'descuento'=>0,'acuenta'=>0,'saldo'=>0,
        ]);
        return response()->json([
            'id'        => $compra->id,
            'cuenta'    => $compra->cuenta->nombre ?? '',
            'cuenta_id' => $compra->cuenta_id,
            'fecha'     => $compra->fecha->format('d/m/Y'),
            'fecha_raw' => $compra->fecha->format('Y-m-d'),
            'tipo'      => $compra->tipo,
            'estado'    => $compra->estado,
            'pagado'    => $compra->pagado,
            'saldo'     => (float) ($compra->saldo ?? 0),
            'acuenta'   => (float) ($compra->acuenta ?? 0),
            'total'     => 'Bs. ' . number_format($compra->total, 2),
        ]);
    }

    public function show(Compra $compra)
    {
        if ($compra->sucursal_id != Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }
        return response()->json([
            'id'        => $compra->id,
            'cuenta'    => $compra->cuenta->nombre ?? '',
            'cuenta_id' => $compra->cuenta_id,
            'fecha'     => $compra->fecha->format('d/m/Y'),
            'fecha_raw' => $compra->fecha->format('Y-m-d'),
            'tipo'      => $compra->tipo,
            'estado'    => $compra->estado,
            'pagado'    => $compra->pagado,
            'saldo'     => (float) ($compra->saldo ?? 0),
            'acuenta'   => (float) ($compra->acuenta ?? 0),
            'total'     => 'Bs. ' . number_format($compra->total, 2),
        ]);
    }

    public function updateEncabezado(Request $request)
    {
        $request->validate([
            'compra_id' => 'required|integer',
        ]);
        $compra = Compra::findOrFail($request->compra_id);
        abort_if($compra->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($compra->estado !== 'PROFORMA', 422, 'La compra no es proforma.');
        $request->validate(['cuenta_id'=>'required|integer','tipo'=>'required|in:CONTADO,CREDITO','fecha'=>'required|date']);
        abort_if($request->fecha <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');
        
        $data = $request->only(['cuenta_id','tipo','fecha']);
        if ($compra->tipo !== $request->tipo) {
            if ($request->tipo === 'CREDITO') {
                $data['acuenta'] = 0;
                $data['saldo'] = $compra->total;
                $data['pagado'] = 'POR PAGAR';
            } else {
                $data['acuenta'] = 0;
                $data['saldo'] = 0;
                $data['pagado'] = 'PAGADO';
            }
        }
        $compra->update($data);
        return response()->json(true);
    }

    /**
     * Agrega un renglón de producto a una compra PROFORMA.
     *
     * A diferencia de Ventas (que consolida el mismo producto en una sola línea), en Compras
     * NO se admite el mismo repuesto en dos renglones (decisión de la clienta 25/6: notificar/
     * bloquear). Un duplicado en una compra oculta errores que recién se detectan cuando el total
     * no cuadra con la proforma física, así que se rechaza con 422 si el producto ya está cargado.
     *
     * @param  \Illuminate\Http\Request  $request  compra_id, producto_id, cantidad, costo (opcional)
     * @return \Illuminate\Http\JsonResponse
     */
    public function agregarItem(Request $request)
    {
        $request->validate([
            'compra_id'   => 'required|integer',
            'producto_id' => 'required|integer',
            'cantidad'    => 'required|integer|min:1|max:100000',
            'costo'       => 'nullable|numeric|min:0',
        ]);
        $compra   = Compra::findOrFail($request->compra_id);
        abort_if($compra->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($compra->estado !== 'PROFORMA', 422, 'La compra no es proforma.');
        $prod     = Producto::findOrFail($request->producto_id);
        // Bloqueo de duplicados: el repuesto no puede repetirse en la misma compra.
        abort_if(
            Compradetalle::where('compra_id', $compra->id)
                ->where('producto_id', $prod->id)
                ->where('estado', 'VALIDO')
                ->exists(),
            422,
            'El repuesto ya está cargado en esta compra.'
        );
        $cantidad = $request->cantidad;
        $costo    = $request->filled('costo') ? (float) $request->costo : $prod->p_comp;
        $monto    = $costo * $cantidad;
        Compradetalle::create([
            'compra_id'   => $compra->id,
            'producto_id' => $prod->id,
            'codigo'      => $prod->codigo,
            'descripcion' => $prod->descripcion,
            'marca'       => $prod->marca->nombre ?? '',
            'p_comp'      => $prod->p_comp,
            'p_norm'      => $prod->p_norm,
            'p_fact'      => $prod->p_fact,
            'costo'       => $costo,
            'cantidad'    => $cantidad,
            'monto'       => $monto,
            'descuento'   => 0,
            'subtotal'    => $monto,
            'user_id'     => Auth::id(),
            'estado'      => 'VALIDO',
        ]);
        $this->recalcularTotales($compra);
        return response()->json(true);
    }

    public function updateItem(Request $request)
    {
        $request->validate([
            'registro' => 'required|integer',
            'costo'    => 'nullable|numeric|min:0',
            'cantidad' => 'required|integer|min:1|max:100000',
        ]);
        $detalle = Compradetalle::findOrFail($request->registro);
        abort_if($detalle->compra->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($detalle->compra->estado !== 'PROFORMA', 422, 'La compra no es proforma.');
        $prod  = Producto::findOrFail($detalle->producto_id);
        $costo = $request->filled('costo') ? (float) $request->costo : $detalle->costo;
        $monto = $costo * (float) $request->cantidad;
        $detalle->update([
            'costo'    => $costo,
            'cantidad' => $request->cantidad,
            // Subtotal/monto del renglón consistente con costo*cantidad (igual que agregarItem).
            'monto'    => $monto,
            'subtotal' => $monto,
            'p_comp'   => $prod->p_comp,
            'p_norm'   => $prod->p_norm,
            'p_fact'   => $prod->p_fact,
        ]);
        $this->recalcularTotales($detalle->compra);
        return response()->json(true);
    }

    public function deleteItem(Compradetalle $detalle)
    {
        abort_if($detalle->compra->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($detalle->compra->estado !== 'PROFORMA', 422, 'La compra no es proforma.');
        $compra = $detalle->compra;
        $detalle->estado = 'ANULADO'; $detalle->save();
        $this->recalcularTotales($compra);
        return response()->json(true);
    }

    public function validar(Request $request, Compra $compra)
    {
        abort_if($compra->sucursal_id !== Auth::user()->sucursal_id, 403);
        if ($compra->estado !== 'PROFORMA') {
            return response()->json(['error' => 'Compra no es proforma.'], 422);
        }
        // $compra->fecha es Carbon (cast 'date'): comparar objeto vs string siempre da
        // "objeto mayor" en PHP, por eso se formatea a Y-m-d antes de comparar.
        $ultimoCierre = Auth::user()->sucursal->ultimo_cierre;
        abort_if($ultimoCierre && $compra->fecha->format('Y-m-d') <= $ultimoCierre, 422, 'Fecha fuera de rango (caja cerrada).');
        DB::beginTransaction();
        try {
            $compra->estado = 'VALIDO'; $compra->save();
            foreach ($compra->detalles()->where('estado','VALIDO')->get() as $d) {
                $p = Producto::findOrFail($d->producto_id);
                $col = 'stock'.$compra->sucursal_id;
                $p->$col = $p->$col + $d->cantidad;

                if ($d->costo != $p->p_comp) {
                    Precio::create([
                        'tipo'        => 'COMPRA',
                        'registro'    => $compra->id,
                        'producto_id' => $p->id,
                        'p_comp_orig' => $p->p_comp,
                        'p_comp'      => $d->costo,
                        'p_norm_orig' => $p->p_norm,
                        'p_norm'      => $p->p_norm,
                        'p_fact_orig' => $p->p_fact,
                        'p_fact'      => $p->p_fact,
                        'user_id'     => Auth::id(),
                    ]);
                }

                $p->save();
            }

            if ($compra->tipo === 'CONTADO') {
                \App\Models\Tranza::create([
                    'sucursal_id' => $compra->sucursal_id,
                    'cuenta_id'   => $compra->cuenta_id,
                    'fecha'       => $compra->fecha,
                    'tipo'        => 'EGRESO',
                    'clase'       => 'COM',
                    'registro'    => $compra->id,
                    'descripcion' => 'CUENTA: ' . ($compra->cuenta->nombre ?? 'N/A'),
                    'monto_ingreso'=> 0,
                    'monto_egreso'=> $compra->total,
                    'user_id'     => Auth::id(),
                    'estado'      => 'ON',
                ]);
            }

            DB::commit();
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al validar.'], 500);
        }
    }

    private function recalcularTotales($compra)
    {
        // Suma los SUBTOTALES guardados (no `costo * cantidad`): el subtotal preserva la
        // precisión del precio tipeado (legacy: 83.3333×12 = 1000.00), mientras que `costo`
        // está truncado a 2 decimales en su columna. Espejo de VentaController::recalcular —
        // sin esto el total de compras daba 999.96 y no reconciliaba con ventas/cotizaciones.
        $monto = $compra->detalles()->where('estado', 'VALIDO')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as total')
            ->value('total');
        $total = max(0, $monto - ($compra->descuento ?? 0));
        $data  = ['monto' => $monto, 'total' => $total];
        if ($compra->tipo === 'CREDITO') {
            $data['saldo'] = max(0, $total - ($compra->acuenta ?? 0));
        }
        $compra->update($data);
    }

    /**
     * Recalcula acuenta/saldo/pagado de una compra CREDITO desde los hechos atómicos:
     * pagos al proveedor (tranzas PAG ON) + crédito por devoluciones (devcompras ON, valor
     * pleno de la mercadería devuelta). Espejo de VentaController::recalcularSaldoCredito.
     *
     * Determinista e idempotente: acuenta = min(total, pagos + devs), saldo = max(0, …).
     * Reemplaza los deltas frágiles que dejaban acuenta > total (devolver tras pagar) o
     * saldo inconsistente al revertir. No aplica a CONTADO (saldo siempre 0).
     */
    private function recalcularSaldoCredito(Compra $compra): void
    {
        if ($compra->tipo !== 'CREDITO') {
            return;
        }

        $pagos = (float) \App\Models\Tranza::where('registro', $compra->id)
            ->where('sucursal_id', $compra->sucursal_id)
            ->where('clase', 'PAG')
            ->where('estado', 'ON')
            ->sum('monto_egreso');

        $devs = (float) \App\Models\Devcompra::where('compra_id', $compra->id)
            ->where('estado', 'ON')
            ->sum('total');

        $total   = (float) $compra->total;
        $credito = $pagos + $devs;

        $compra->acuenta = min($total, $credito);
        $compra->saldo   = max(0.0, $total - $credito);
        $compra->pagado  = $compra->saldo <= 0 ? 'PAGADO' : 'POR PAGAR';
        $compra->save();
    }

    public function apiDetalles(Compra $compra)
    {
        if ($compra->sucursal_id !== Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }
        // FIEL AL LEGACY: en Compras el costo SÍ se ve a todos los roles. Las vistas de
        // LECTURA del legacy (compras/show.blade.php y index.blade.php) muestran las columnas
        // `costo`/`subtotal`/`monto`/`total` SIN ningún `@role` — solo el modal de EDICIÓN
        // (edit.blade.php) gateaba el costo. Compras es un módulo de costos por naturaleza; el
        // vendedor que tiene compras.index/show lo ve (decisión del humano 2026-06-16, confirmada
        // contra el legacy). El ocultamiento de costos aplica al RESTO (productos/movimientos/
        // valor-inventario), NO a Compras.
        return response()->json(
            $compra->detalles()->where('estado','VALIDO')->get()->map(fn($d)=>[
                'id'=>$d->id,'producto_id'=>$d->producto_id,
                'codigo'=>$d->codigo,'descripcion'=>$d->descripcion,
                'marca'=>$d->marca,'costo'=>(float)$d->costo,
                'cantidad'=>$d->cantidad,
                // Subtotal GUARDADO (NO costo*cantidad recalculado): preserva la precisión del
                // precio tipeado para que el total cuadre, igual que VentaController::apiDetalles.
                // `subtotal_num` para que el front sume sin reparsear el string formateado.
                'subtotal'=>'Bs. '.number_format($d->subtotal,2),
                'subtotal_num'=>(float)$d->subtotal,
            ])
        );
    }

    public function pdf(Compra $compra)
    {
        $compra->load(['cuenta', 'sucursal']);
        $detalles = $compra->detalles()->where('estado', 'VALIDO')->get();
        $pdf = Pdf::loadView('compras.pdf', compact('compra', 'detalles'))->setPaper('a3', 'landscape');
        return $pdf->stream('Compra_'.$compra->id.'.pdf');
    }

    public function devItem(Request $request)
    {
        $request->validate([
            'compra_id'   => 'required|integer',
            'producto_id' => 'required|integer',
            'cantidad'    => 'required|integer|min:1|max:100000',
            'costo'       => 'nullable|numeric|min:0',
        ]);
        $compra = Compra::findOrFail($request->compra_id);
        abort_if($compra->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if(now()->format('Y-m-d') <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');

        $prod     = Producto::findOrFail($request->producto_id);
        $cantidad = $request->cantidad;

        // Obtener el detalle original para costo y referencia de registro
        $detalle = Compradetalle::where('compra_id', $compra->id)
            ->where('producto_id', $prod->id)
            ->where('estado', 'VALIDO')
            ->firstOrFail();

        // Límite = total comprado del producto en la compra (suma de TODOS los renglones
        // VALIDO), no solo el primero — agregarItem de compras permite líneas duplicadas
        // del mismo producto, así que limitar por una sola línea bloqueaba devoluciones
        // legítimas (mismo criterio que VentaController::devItem).
        $totalComprado = Compradetalle::where('compra_id', $compra->id)
            ->where('producto_id', $prod->id)
            ->where('estado', 'VALIDO')
            ->sum('cantidad');

        $cantDevAcum = \App\Models\Devcompra::where('compra_id', $compra->id)
            ->where('producto_id', $prod->id)
            ->where('estado', 'ON')
            ->sum('cantidad');

        if ($cantDevAcum + $cantidad > $totalComprado) {
            return response()->json(['error' => 'La cantidad supera el límite de devolución.'], 422);
        }

        $costo = $detalle->costo;
        $total = $costo * $cantidad;

        DB::beginTransaction();
        try {
            $desc = 'ITEM: ' . $detalle->codigo . ' [ ' . $prod->id . ' ] - ' . $cantidad . ' Pzs';

            // Reembolso en efectivo de la devolución a proveedor, conservando el dinero
            // (espejo de VentaController::devItem; decisión de negocio ya tomada por el equipo):
            //  - CONTADO: el egreso de la compra ya salió en efectivo → el proveedor reembolsa
            //    el valor de lo devuelto.
            //  - CREDITO: solo se reembolsa en efectivo la parte que la tienda YA pagó de MÁS por
            //    lo devuelto (lo que excede su deuda); el resto reduce la deuda (ingreso 0). Cubre
            //    el sobrepago parcial (pagó 90 de 100, devuelve 30 → le vuelven 20 en efectivo y la
            //    deuda queda en 0) sin que acuenta supere el total ni el saldo se vuelva negativo.
            if ($compra->tipo === 'CONTADO') {
                $monto_ingreso = $total;
            } else {
                $pagos = (float) \App\Models\Tranza::where('registro', $compra->id)
                    ->where('sucursal_id', $compra->sucursal_id)
                    ->where('clase', 'PAG')->where('estado', 'ON')->sum('monto_egreso');
                $devsPrev = (float) \App\Models\Devcompra::where('compra_id', $compra->id)
                    ->where('estado', 'ON')->sum('total');
                $creditoAntes   = $pagos + $devsPrev;
                $creditoDespues = $creditoAntes + $total;
                $monto_ingreso  = max(0.0, $creditoDespues - max((float) $compra->total, $creditoAntes));
                if ($monto_ingreso <= 0) {
                    $desc .= ' [COM-CREDITO]';
                }
            }

            $tranza = \App\Models\Tranza::create([
                'sucursal_id' => $compra->sucursal_id, 'cuenta_id' => $compra->cuenta_id,
                'fecha' => now()->format('Y-m-d'), 'tipo' => 'INGRESO', 'clase' => 'D-COM',
                'registro' => $compra->id, 'descripcion' => $desc,
                'monto_ingreso' => $monto_ingreso, 'monto_egreso' => 0, 'user_id' => Auth::id(), 'estado' => 'ON',
            ]);

            \App\Models\Devcompra::create([
                'sucursal_id' => $compra->sucursal_id, 'compra_id' => $compra->id,
                'registro'    => $detalle->id, 'tranza_id' => $tranza->id,
                'producto_id' => $prod->id, 'codigo' => $detalle->codigo,
                'descripcion' => $detalle->descripcion, 'marca' => $detalle->marca,
                'costo' => $costo, 'cantidad' => $cantidad, 'total' => $total,
                'estado' => 'ON', 'user_id' => Auth::id(),
            ]);

            $col = 'stock' . $compra->sucursal_id;
            $prod->$col = $prod->$col - $cantidad;
            $prod->save();

            // Recalcular acuenta/saldo desde los HECHOS (pagos PAG ON + devoluciones D-COM ON),
            // incluida la devolución recién creada. Determinista: nunca saldo < 0 ni acuenta > total.
            $this->recalcularSaldoCredito($compra);

            $compra->n_dev = ($compra->n_dev ?? 0) + 1;
            $compra->save();

            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function apiDevoluciones(Compra $compra)
    {
        if ($compra->sucursal_id !== Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }
        $devs = \App\Models\Devcompra::where('compra_id', $compra->id)->where('estado', 'ON')->get();
        return response()->json($devs->map(fn($d) => [
            'id' => $d->id, 'producto_id' => $d->producto_id,
            'fecha' => $d->created_at?->format('d/m/Y') ?? '',
            'codigo' => $d->codigo, 'descripcion' => $d->descripcion,
            'marca' => $d->marca, 'cantidad' => $d->cantidad,
            'costo' => number_format($d->costo, 2),
            'total' => 'Bs. ' . number_format($d->total, 2),
            'total_num' => (float) $d->total,
        ]));
    }

    public function deleteItemDev(Request $request)
    {
        $devcompra = \App\Models\Devcompra::findOrFail($request->registro);
        $tranza    = \App\Models\Tranza::findOrFail($devcompra->tranza_id);
        $compra    = Compra::findOrFail($devcompra->compra_id);
        abort_if($compra->sucursal_id !== Auth::user()->sucursal_id, 403);
        // Solo se revierte una devolución de una compra VALIDO. Si ya fue ANULADA, la
        // anulación restituyó el stock neto; revertir acá lo sumaría de nuevo (stock fantasma).
        abort_if($compra->estado !== 'VALIDO', 422, 'La compra no está validada; no se puede revertir la devolución.');
        abort_if($tranza->fecha <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');

        DB::beginTransaction();
        try {
            $prod   = Producto::findOrFail($devcompra->producto_id);

            $col = 'stock' . $compra->sucursal_id;
            $prod->$col = $prod->$col + $devcompra->cantidad;
            $prod->save();

            // Dar de baja la nota de crédito y su tranza ANTES de recalcular, para que
            // dejen de contar como crédito hacia la deuda.
            $tranza->estado    = 'OFF'; $tranza->save();
            $devcompra->estado = 'OFF'; $devcompra->save();

            // Recalcular acuenta/saldo desde los hechos restantes (simétrico a devItem):
            // al quitar esta devolución, su crédito deja de contar. Sin deltas frágiles que
            // dejaban acuenta inflada/saldo inconsistente al revertir sobre una compra pagada.
            $this->recalcularSaldoCredito($compra);

            $compra->n_dev = max(0, ($compra->n_dev ?? 1) - 1);
            $compra->save();

            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function pagarCompra(Request $request)
    {
        $request->validate([
            'compra_id' => 'required|integer',
            'monto'     => 'required|numeric|min:0.01',
        ]);
        $compra = Compra::findOrFail($request->compra_id);
        abort_if($compra->sucursal_id !== Auth::user()->sucursal_id, 403);

        // Solo compras validadas pueden pagarse
        if ($compra->estado !== 'VALIDO') {
            return response()->json(['error' => 'Solo se pueden pagar compras validadas.'], 422);
        }
        if ($compra->tipo !== 'CREDITO') {
            return response()->json(['error' => 'La compra no es a crédito.'], 422);
        }
        if ($request->monto > $compra->saldo) {
            return response()->json(['error' => 'El monto supera el saldo pendiente.'], 422);
        }

        $hoy = now()->format('Y-m-d');
        abort_if($hoy <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');

        $monto = $request->monto;

        DB::beginTransaction();
        try {
            \App\Models\Tranza::create([
                'sucursal_id' => $compra->sucursal_id, 'cuenta_id' => $compra->cuenta_id,
                'fecha' => $hoy, 'tipo' => 'EGRESO', 'clase' => 'PAG',
                'registro' => $compra->id, 'descripcion' => 'PAGO COMPRA #' . $compra->id,
                'monto_ingreso' => 0, 'monto_egreso' => $monto, 'user_id' => Auth::id(), 'estado' => 'ON',
            ]);

            // Recalcular acuenta/saldo desde los hechos (pagos PAG ON + devoluciones D-COM ON),
            // incluido el pago recién creado. acuenta = min(total, …) → nunca supera el total
            // aunque una devolución previa ya hubiera acreditado parte de la deuda.
            $this->recalcularSaldoCredito($compra);

            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function apiPagos(Compra $compra)
    {
        $pagos = \App\Models\Tranza::where('registro', $compra->id)
            ->where('clase', 'PAG')->where('estado', 'ON')
            ->where('sucursal_id', $compra->sucursal_id)
            ->orderBy('id', 'desc')->get();
        return response()->json($pagos->map(fn($t) => [
            'id' => $t->id, 'fecha' => \Carbon\Carbon::parse($t->fecha)->format('d/m/Y'),
            'monto' => 'Bs. ' . number_format($t->monto_egreso, 2),
            'monto_num' => (float) $t->monto_egreso,
            'descripcion' => $t->descripcion,
        ]));
    }

    public function destroy(Request $request, Compra $compra)
    {
        abort_if($compra->sucursal_id !== Auth::user()->sucursal_id, 403);
        // Carbon vs string: formatear antes de comparar (ver validar()).
        $ultimoCierre = Auth::user()->sucursal->ultimo_cierre;
        abort_if($ultimoCierre && $compra->fecha->format('Y-m-d') <= $ultimoCierre, 422, 'Fecha fuera de rango (caja cerrada).');

        if ($compra->estado === 'ANULADO') {
            return response()->json(['error' => 'Ya está anulada.'], 422);
        }
        if ($compra->estado === 'VALIDO') {
            DB::beginTransaction();
            try {
                $compra->estado = 'ANULADO'; $compra->save();
                // Solo los detalles VALIDO afectaron stock al validar; los ANULADO
                // (ítems borrados en proforma) no deben restarse.
                foreach ($compra->detalles()->where('estado', 'VALIDO')->get() as $detalle) {
                    $prod = Producto::find($detalle->producto_id);
                    if ($prod) {
                        $cnt_pro_dev = \App\Models\Devcompra::where('compra_id', $compra->id)
                            ->where('producto_id', $prod->id)
                            ->where('estado', 'ON')
                            ->sum('cantidad');
                        
                        $col = 'stock' . $compra->sucursal_id;
                        $prod->$col = $prod->$col - ($detalle->cantidad - $cnt_pro_dev);
                        $prod->save();
                    }
                }

                \App\Models\Tranza::where('sucursal_id', $compra->sucursal_id)
                    ->where('registro', $compra->id)
                    ->whereIn('clase', ['COM', 'DEV-C', 'D-COM', 'PAG', 'PAGO'])
                    ->update(['estado' => 'OFF']);

                DB::commit();
                return response()->json(['ok' => true]);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }
        $compra->estado = 'ANULADO'; $compra->save();
        return response()->json(['ok' => true]);
    }
}
