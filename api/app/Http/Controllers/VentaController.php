<?php

namespace App\Http\Controllers;

use App\Helpers\SearchHelper;
use App\Models\Venta;
use App\Models\Ventadetalle;
use App\Models\Devventa;
use App\Models\Tranza;
use App\Models\Cuenta;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class VentaController extends Controller
{
    public function api(Request $request)
    {
        $sid = $this->validarAccesoSucursal((int) $request->get('sucursal_id', Auth::user()->sucursal_id));

        $q = Venta::with(['cuenta','sucursal'])->whereIn('estado',['PROFORMA','VALIDO','ANULADO'])->select('ventas.*');
        if ($sid > 0) $q->where('sucursal_id', $sid);
        // `fecha` es DATE → where() plano (no whereDate): preserva `ventas_fecha_idx` usable.
        if ($request->filled('fecha_desde')) $q->where('fecha','>=',$request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha','<=',$request->fecha_hasta);
        if ($request->filled('estado_filtro')) $q->where('estado', strtoupper($request->estado_filtro));
        if ($request->filled('pagado_filtro')) $q->where('pagado', $request->pagado_filtro);
        if ($request->filled('search')) {
            SearchHelper::apply(
                $q, $request->search,
                ['ventas.id', 'ventas.tipo'],
                ['cuenta.nombre']
            );
        }
        $total = $q->count();

        $sort = $request->get('sort');
        $dir  = $request->get('dir') === 'asc' ? 'asc' : 'desc';
        if ($sort === 'cuenta') {
            $q->leftJoin('cuentas','ventas.cuenta_id','=','cuentas.id')->orderBy('cuentas.nombre', $dir);
        } else {
            $allowed = ['id'=>'ventas.id','fecha'=>'ventas.fecha','tipo'=>'ventas.tipo','total'=>'ventas.total','pagado'=>'ventas.pagado','estado'=>'ventas.estado'];
            $q->orderBy($allowed[$sort] ?? 'ventas.id', $dir);
        }

        $ventas = $q->skip($request->get('skip',0))->take($request->get('take',30))->get();
        return response()->json(['total'=>$total, 'data'=>$ventas->map(fn($v) => [
            'id'        => $v->id,
            'fecha'     => $v->fecha->format('d/m/Y'),
            'fecha_raw' => $v->fecha->format('Y-m-d'),
            'tipo'      => $v->tipo,
            'cuenta_id' => $v->cuenta_id,
            'cuenta'    => $v->cuenta->nombre ?? '',
            'nit'       => $v->cuenta->nit ?? '',
            'sucursal'  => $v->sucursal->nombre ?? '—',
            'total'     => 'Bs. '.number_format($v->total, 2),
            'total_num' => (float) $v->total,
            'saldo'     => (float) $v->saldo,
            'pagado'    => $v->pagado,
            'estado'    => $v->estado,
        ])]);
    }

    public function kpis(Request $request)
    {
        $sid = $this->validarAccesoSucursal((int) $request->get('sucursal_id', Auth::user()->sucursal_id));

        $q = Venta::whereIn('estado',['PROFORMA','VALIDO']);
        if ($sid > 0) $q->where('sucursal_id', $sid);
        // `fecha` es DATE → where() plano (no whereDate): preserva `ventas_fecha_idx` usable.
        if ($request->filled('fecha_desde')) $q->where('fecha','>=',$request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha','<=',$request->fecha_hasta);

        // Conteo de ANULADAS: query independiente (el $q base solo incluye PROFORMA/VALIDO).
        // El front lo expone como contador "Anulaciones" en el índice de ventas.
        $anuladas = Venta::where('estado', 'ANULADO');
        if ($sid > 0) $anuladas->where('sucursal_id', $sid);
        if ($request->filled('fecha_desde')) $anuladas->where('fecha','>=',$request->fecha_desde);
        if ($request->filled('fecha_hasta')) $anuladas->where('fecha','<=',$request->fecha_hasta);

        return response()->json([
            'total'   => $q->count(),
            'proforma'=> (clone $q)->where('estado','PROFORMA')->count(),
            'valido'  => (clone $q)->where('estado','VALIDO')->count(),
            'anulado' => $anuladas->count(),
            'monto'   => 'Bs. '.number_format((clone $q)->where('estado','VALIDO')->sum('total'), 2),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'fecha'     => 'required|date',
            'cuenta_id' => 'required|integer|exists:cuentas,id',
            'tipo'      => 'required|in:CONTADO,CREDITO',
        ]);

        $sid = $this->validarAccesoSucursal((int) $request->get('sucursal_id', Auth::user()->sucursal_id));
        if ($sid <= 0) $sid = Auth::user()->sucursal_id;

        $ultimo_cierre = \App\Models\Sucursal::where('id', $sid)->value('ultimo_cierre');
        if ($request->fecha <= $ultimo_cierre) {
            return response()->json(['error' => 'La fecha de la venta pertenece a una caja cerrada.'], 422);
        }

        $venta = Venta::create([
            'sucursal_id' => $sid,
            'fecha'       => $request->fecha,
            'tipo'        => $request->tipo,
            'cuenta_id'   => $request->cuenta_id,
            'pagado'      => $request->tipo === 'CREDITO' ? 'POR PAGAR' : 'PAGADO',
            'estado'      => 'PROFORMA',
            'total'       => 0, 'monto' => 0, 'descuento' => 0, 'acuenta' => 0, 'saldo' => 0,
        ]);

        return response()->json(['id' => $venta->id]);
    }

    public function updateEncabezado(Request $request)
    {
        $request->validate([
            'venta_id'  => 'required|integer',
            'cuenta_id' => 'required|integer|exists:cuentas,id',
            'tipo'      => 'required|in:CONTADO,CREDITO',
            'fecha'     => 'required|date',
        ]);

        $venta = Venta::findOrFail($request->venta_id);
        $this->validarAccesoSucursal($venta->sucursal_id);

        $ultimo_cierre = \App\Models\Sucursal::where('id', $venta->sucursal_id)->value('ultimo_cierre');
        if ($request->fecha <= $ultimo_cierre) {
            return response()->json(['error' => 'La fecha asignada pertenece a una caja cerrada.'], 422);
        }
        if ($request->fecha > now()->format('Y-m-d')) {
            return response()->json(['error' => 'La fecha no puede ser futura.'], 422);
        }

        if ($venta->estado !== 'PROFORMA') {
            return response()->json(['error' => 'La venta no es PROFORMA.'], 422);
        }

        $venta->cuenta_id = $request->cuenta_id;
        $venta->fecha     = $request->fecha;

        // Si cambia el tipo, resetear acuenta/saldo/pagado igual que el legacy
        if ($venta->tipo !== $request->tipo) {
            if ($request->tipo === 'CREDITO') {
                $venta->acuenta = 0;
                $venta->saldo   = $venta->total;
                $venta->pagado  = 'POR PAGAR';
            } else {
                $venta->acuenta = 0;
                $venta->saldo   = 0;
                $venta->pagado  = 'PAGADO';
            }
            $venta->tipo = $request->tipo;
        }

        $venta->save();
        return response()->json(true);
    }

    public function agregarItem(Request $request)
    {
        $request->validate([
            'venta_id'    => 'required|integer',
            'producto_id' => 'required|integer',
            // cantidad es int(11) en BD: `numeric` admitía fraccionarios que se
            // truncaban silenciosamente (monto inconsistente) o reventaban (overflow → 500).
            'cantidad'    => 'nullable|integer|min:1|max:100000',
            // nueva_linea: fuerza un renglón NUEVO aunque el producto ya esté en la venta,
            // para venderlo a OTRO precio en la misma venta (pedido de QA aprobado). Sin el
            // flag se mantiene el comportamiento por defecto: sumar a la línea existente.
            'nueva_linea' => 'nullable|boolean',
        ]);

        $venta = Venta::findOrFail($request->venta_id);
        $this->validarAccesoSucursal($venta->sucursal_id);

        if ($venta->estado !== 'PROFORMA') {
            return response()->json(['error' => 'La venta no es PROFORMA.'], 422);
        }

        $prod     = Producto::findOrFail($request->producto_id);
        $cantidad = $request->cantidad ?? 1;
        $costo    = $request->filled('costo') ? (float) $request->costo : $prod->p_norm;

        // Por defecto, si el producto ya está en la venta (renglón VALIDO) sumamos la cantidad
        // en lugar de crear una línea duplicada. Con `nueva_linea` se fuerza un renglón nuevo
        // para venderlo a otro precio (la devolución/anulación ya agregan por producto, no por
        // renglón — ver devItem() y destroy()).
        $detalle = $request->boolean('nueva_linea')
            ? null
            : Ventadetalle::where('venta_id', $venta->id)
                ->where('producto_id', $prod->id)
                ->where('estado', 'VALIDO')
                ->first();

        if ($detalle) {
            $detalle->cantidad = $detalle->cantidad + $cantidad;
            $detalle->monto    = $detalle->costo * $detalle->cantidad;
            $detalle->subtotal = $detalle->monto;
            $detalle->save();
        } else {
            Ventadetalle::create([
                'venta_id'    => $venta->id,
                'producto_id' => $prod->id,
                'codigo'      => $prod->codigo,
                'descripcion' => $prod->descripcion,
                'marca'       => $prod->marca->nombre ?? '',
                'p_comp'      => $prod->p_comp,
                'costo'       => $costo,
                'p_norm'      => $prod->p_norm,
                'p_fact'      => $prod->p_fact,
                'cantidad'    => $cantidad,
                'monto'       => $costo * $cantidad,
                'descuento'   => 0,
                'subtotal'    => $costo * $cantidad,
                'estado'      => 'VALIDO',
            ]);
        }

        $this->recalcular($venta);
        return response()->json(true);
    }

    public function updateItem(Request $request)
    {
        $request->validate([
            'registro' => 'required|integer',
            'costo'    => 'required|numeric|min:0',
            'cantidad' => 'required|integer|min:1|max:100000',
        ]);

        $d = Ventadetalle::findOrFail($request->registro);
        $this->validarAccesoSucursal($d->venta->sucursal_id);

        if ($d->venta->estado !== 'PROFORMA') {
            return response()->json(['error' => 'La venta no es PROFORMA.'], 422);
        }

        // Refrescar precios de referencia desde el producto (igual que legacy)
        $prod  = Producto::findOrFail($d->producto_id);
        $monto = (float) $request->costo * (float) $request->cantidad;
        $d->update([
            'costo'    => $request->costo,
            'cantidad' => $request->cantidad,
            // Mantener el subtotal/monto del renglón consistente con costo*cantidad
            // (igual que agregarItem y cotizaciones: evita columnas obsoletas en BD/PDF).
            'monto'    => $monto,
            'subtotal' => $monto,
            'p_comp'   => $prod->p_comp,
            'p_norm'   => $prod->p_norm,
            'p_fact'   => $prod->p_fact,
        ]);
        $this->recalcular($d->venta);
        return response()->json(true);
    }

    public function deleteItem(Ventadetalle $detalle)
    {
        $this->validarAccesoSucursal($detalle->venta->sucursal_id);

        if ($detalle->venta->estado !== 'PROFORMA') {
            return response()->json(['error' => 'La venta no es PROFORMA.'], 422);
        }

        $venta = $detalle->venta;
        $detalle->estado = 'ANULADO';
        $detalle->save();
        $this->recalcular($venta);
        return response()->json(true);
    }

    public function validar(Venta $venta, Request $request)
    {
        $this->validarAccesoSucursal($venta->sucursal_id);

        // $venta->fecha es Carbon (cast 'date'): comparar objeto vs string siempre da
        // "objeto mayor" en PHP, por eso se formatea a Y-m-d antes de comparar.
        $ultimo_cierre = \App\Models\Sucursal::where('id', $venta->sucursal_id)->value('ultimo_cierre');
        if ($ultimo_cierre && $venta->fecha->format('Y-m-d') <= $ultimo_cierre) {
            return response()->json(['error' => 'La venta pertenece a una caja cerrada.'], 422);
        }

        if ($venta->estado !== 'PROFORMA') {
            return response()->json(['error' => 'No es proforma.'], 422);
        }

        // Guard de stock del lado servidor: el front ya bloquea validar con stock
        // insuficiente (endpoint negativos), pero una llamada directa a la API podía
        // dejar el stock negativo (sobreventa). Replicamos el chequeo aquí.
        // Chequeo AGRUPADO por producto: con líneas duplicadas del mismo producto (mismo
        // producto a distintos precios), cada renglón podía pasar el chequeo individual pero
        // la SUMA sobregiraba el stock. Se compara stock vs cantidad TOTAL del producto.
        $insuficientes = [];
        $col = 'stock' . $venta->sucursal_id;
        foreach ($venta->detalles()->where('estado','VALIDO')->get()->groupBy('producto_id') as $producto_id => $lineas) {
            $p = Producto::find($producto_id);
            if (!$p) continue;
            $totalPedido = $lineas->sum('cantidad');
            if ($p->$col < $totalPedido) {
                $insuficientes[] = ['id' => $p->id, 'codigo' => $p->codigo, 'stock' => $p->$col, 'pedido' => $totalPedido];
            }
        }
        if (!empty($insuficientes)) {
            return response()->json(['error' => 'Stock insuficiente para validar la venta.', 'items' => $insuficientes], 422);
        }

        DB::beginTransaction();
        try {
            $venta->estado = 'VALIDO';
            $venta->save();

            // Descontar stock
            foreach ($venta->detalles()->where('estado','VALIDO')->get() as $d) {
                $p   = Producto::findOrFail($d->producto_id);
                $col = 'stock' . $venta->sucursal_id;
                $p->$col = $p->$col - $d->cantidad;
                $p->save();
            }

            // Crear tranza de ingreso solo para ventas CONTADO (igual que legacy)
            if ($venta->tipo === 'CONTADO') {
                Tranza::create([
                    'sucursal_id'   => $venta->sucursal_id,
                    'cuenta_id'     => $venta->cuenta_id,
                    'fecha'         => $venta->fecha,
                    'tipo'          => 'INGRESO',
                    'clase'         => 'VEN',
                    'registro'      => $venta->id,
                    'descripcion'   => 'CUENTA: ' . ($venta->cuenta->nombre ?? ''),
                    'monto_ingreso' => $venta->total,
                    'monto_egreso'  => 0,
                    'user_id'       => Auth::id(),
                    'estado'        => 'ON',
                ]);
            }

            DB::commit();
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Venta $venta, Request $request)
    {
        $this->validarAccesoSucursal($venta->sucursal_id);

        // Carbon vs string: formatear antes de comparar (ver validar()).
        $ultimo_cierre = \App\Models\Sucursal::where('id', $venta->sucursal_id)->value('ultimo_cierre');
        if ($ultimo_cierre && $venta->fecha->format('Y-m-d') <= $ultimo_cierre) {
            return response()->json(['error' => 'No se puede anular una venta de una caja cerrada.'], 422);
        }

        if ($venta->estado === 'ANULADO') {
            return response()->json(['error' => 'La venta ya está anulada.'], 422);
        }

        DB::beginTransaction();
        try {
            if ($venta->estado === 'VALIDO') {
                // Restaurar stock descontando devoluciones ya hechas (igual que legacy).
                // Se AGRUPA por producto: con líneas duplicadas del mismo producto (mismo
                // producto a distintos precios), el reintegro debe ser (totalVendido −
                // totalDevuelto) UNA vez por producto. Antes recorría por renglón y le restaba
                // el devuelto TOTAL a cada línea → con devoluciones parciales reintegraba de
                // menos (descuadre de stock).
                $col = 'stock' . $venta->sucursal_id;
                foreach ($venta->detalles()->where('estado','VALIDO')->get()->groupBy('producto_id') as $producto_id => $lineas) {
                    $prod = Producto::find($producto_id);
                    if (!$prod) continue;
                    $totalVendido = $lineas->sum('cantidad');
                    $cantDev      = Devventa::where('venta_id', $venta->id)
                                    ->where('producto_id', $producto_id)
                                    ->where('estado','ON')
                                    ->sum('cantidad');
                    $prod->$col = $prod->$col + ($totalVendido - $cantDev);
                    $prod->save();
                }

                // Anular todas las tranzas asociadas (VEN, D-VEN, COB)
                Tranza::where('sucursal_id', $venta->sucursal_id)
                    ->where('registro', $venta->id)
                    ->whereIn('clase', ['VEN','D-VEN','COB'])
                    ->where('estado','ON')
                    ->update(['estado' => 'OFF']);
            }

            $venta->estado = 'ANULADO';
            $venta->save();

            DB::commit();
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function negativos(Request $request)
    {
        $venta = Venta::findOrFail($request->venta_id);
        $this->validarAccesoSucursal($venta->sucursal_id);

        // Agrupado por producto: con líneas duplicadas (mismo producto a distintos precios)
        // se compara el stock contra la cantidad TOTAL del producto, no renglón por renglón
        // (mismo criterio que validar()).
        $insuficientes = [];
        $col = 'stock' . $venta->sucursal_id;
        foreach ($venta->detalles()->where('estado','VALIDO')->get()->groupBy('producto_id') as $producto_id => $lineas) {
            $p = Producto::findOrFail($producto_id);
            $totalPedido = $lineas->sum('cantidad');
            if ($p->$col < $totalPedido) {
                $insuficientes[] = [
                    'id'     => $p->id,
                    'codigo' => $p->codigo,
                    'marca'  => $p->marca->nombre ?? '',
                    'stock'  => $p->$col,
                    'pedido' => $totalPedido,
                ];
            }
        }

        return response()->json(['negativo' => count($insuficientes) > 0, 'items' => $insuficientes]);
    }

    public function devItem(Request $request)
    {
        $request->validate([
            'venta_id'    => 'required|integer',
            'producto_id' => 'required|integer',
            'cantidad'    => 'required|integer|min:1|max:100000',
            'costo'       => 'nullable|numeric|min:0',
        ]);

        $venta = Venta::findOrFail($request->venta_id);
        $this->validarAccesoSucursal($venta->sucursal_id);

        // Solo se devuelven ítems de una venta VALIDO: una PROFORMA no descontó stock
        // y una ANULADO ya lo restituyó; devolver en esos estados inflaría el stock.
        if ($venta->estado !== 'VALIDO') {
            return response()->json(['error' => 'Solo se pueden devolver ítems de una venta validada.'], 422);
        }

        $ultimo_cierre = \App\Models\Sucursal::where('id', $venta->sucursal_id)->value('ultimo_cierre');
        $hoy = now()->format('Y-m-d');
        if ($hoy <= $ultimo_cierre) {
            return response()->json(['error' => 'No se pueden procesar devoluciones si la caja de hoy está cerrada.'], 422);
        }

        $prod     = Producto::findOrFail($request->producto_id);
        $cantidad = $request->cantidad;

        // Buscar el detalle original para verificar límite y obtener el costo real
        $detalle = Ventadetalle::where('venta_id', $venta->id)
            ->where('producto_id', $prod->id)
            ->where('estado','VALIDO')
            ->first();

        if (!$detalle) {
            return response()->json(['error' => 'Producto no encontrado en la venta.'], 422);
        }

        // La devolución se valora al costo del renglón vendido (igual que legacy):
        // lo que realmente pagó el cliente, no el precio actual del producto.
        // Así el egreso de caja por la devolución coincide con el ingreso de la venta.
        $costo = $detalle->costo;

        // Límite = total vendido del producto en la venta (suma de TODOS los renglones
        // VALIDO), no solo el primero — cubre ventas legacy con líneas duplicadas.
        $totalVendido = Ventadetalle::where('venta_id', $venta->id)
            ->where('producto_id', $prod->id)
            ->where('estado','VALIDO')
            ->sum('cantidad');

        // Verificar que no supere el límite (igual que legacy)
        $cantDevAcum = Devventa::where('venta_id', $venta->id)
            ->where('producto_id', $prod->id)
            ->where('estado','ON')
            ->sum('cantidad');

        if (($cantDevAcum + $cantidad) > $totalVendido) {
            return response()->json(['error' => 'La cantidad a devolver supera el límite del ítem.'], 422);
        }

        $total = $costo * $cantidad;

        DB::beginTransaction();
        try {
            // Restaurar stock
            $col        = 'stock' . $venta->sucursal_id;
            $prod->$col = $prod->$col + $cantidad;
            $prod->save();

            // acuenta/saldo se recalculan abajo (recalcularSaldoCredito) tras registrar
            // la nota de crédito, derivándolos de los hechos en vez de mutarlos con deltas.

            // Reembolso en efectivo de la devolución, conservando el dinero (decisión de negocio
            // tomada por el equipo: ni el cliente ni la tienda pierden):
            //  - CONTADO: el cliente pagó en efectivo → se le devuelve el valor del ítem.
            //  - CREDITO: solo se reembolsa en efectivo la parte que el cliente YA pagó de MÁS por
            //    lo devuelto (lo que excede su deuda); el resto reduce la deuda (egreso 0). Esto
            //    cubre el borde de sobrepago parcial (pagó 90 de 100, devuelve 25 → le vuelven 15
            //    en efectivo y su deuda queda en 0) sin perder plata de nadie.
            if ($venta->tipo === 'CONTADO') {
                $montoEgreso = $total;
            } else {
                $cobros = (float) Tranza::where('registro', $venta->id)
                    ->where('sucursal_id', $venta->sucursal_id)
                    ->where('clase', 'COB')->where('estado', 'ON')->sum('monto_ingreso');
                $devsPrev = (float) Devventa::where('venta_id', $venta->id)
                    ->where('estado', 'ON')->sum('total');
                $creditoAntes   = $cobros + $devsPrev;
                $creditoDespues = $creditoAntes + $total;
                $montoEgreso    = max(0.0, $creditoDespues - max((float) $venta->total, $creditoAntes));
            }

            $tranza = Tranza::create([
                'sucursal_id'  => $venta->sucursal_id,
                'cuenta_id'    => $venta->cuenta_id,
                'fecha'        => now()->format('Y-m-d'),
                'tipo'         => 'EGRESO',
                'clase'        => 'D-VEN',
                'registro'     => $venta->id,
                'descripcion'  => 'ITEM: ' . $prod->codigo . ' [' . $prod->id . '] - ' . $cantidad . ' Pzs',
                'monto_ingreso'=> 0,
                'monto_egreso' => $montoEgreso,
                'user_id'      => Auth::id(),
                'estado'       => 'ON',
            ]);

            Devventa::create([
                'sucursal_id' => $venta->sucursal_id,
                'venta_id'    => $venta->id,
                'registro'    => $detalle->id,
                'tranza_id'   => $tranza->id,
                'producto_id' => $prod->id,
                'codigo'      => $prod->codigo,
                'descripcion' => $prod->descripcion,
                'marca'       => $prod->marca->nombre ?? '',
                'costo'       => $costo,
                'cantidad'    => $cantidad,
                'total'       => $total,
                'estado'      => 'ON',
                'user_id'     => Auth::id(),
            ]);

            // Recalcular acuenta/saldo desde los hechos (cobros + notas de crédito ON),
            // incluida la devolución recién creada. Determinista: nunca saldo < 0.
            $this->recalcularSaldoCredito($venta);

            $venta->n_dev = ($venta->n_dev ?? 0) + 1;
            $venta->save();

            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function deleteItemDev(Request $request)
    {
        $devventa = Devventa::findOrFail($request->registro);
        $tranza   = Tranza::findOrFail($devventa->tranza_id);
        $venta    = Venta::findOrFail($devventa->venta_id);
        $this->validarAccesoSucursal($venta->sucursal_id);

        // Solo se revierte una devolución de una venta VALIDO. Si la venta ya fue ANULADA,
        // la anulación ya restituyó el stock neto; revertir la devolución acá lo descontaría
        // de nuevo (doble conteo → stock perdido). Ver StockIntegrityTest.
        if ($venta->estado !== 'VALIDO') {
            return response()->json(['error' => 'La venta no está validada; no se puede revertir la devolución.'], 422);
        }

        $ultimo_cierre = \App\Models\Sucursal::where('id', $venta->sucursal_id)->value('ultimo_cierre');
        if ($tranza->fecha <= $ultimo_cierre) {
            return response()->json(['error' => 'La devolución se hizo en una caja que ya está cerrada.'], 422);
        }

        DB::beginTransaction();
        try {
            // Revertir stock
            $prod       = Producto::findOrFail($devventa->producto_id);
            $col        = 'stock' . $venta->sucursal_id;
            $prod->$col = $prod->$col - $devventa->cantidad;
            $prod->save();

            // Dar de baja la nota de crédito y su egreso ANTES de recalcular.
            $tranza->estado   = 'OFF';
            $tranza->save();

            $devventa->estado = 'OFF';
            $devventa->save();

            // Recalcular acuenta/saldo desde los hechos restantes (simétrico a devItem):
            // al quitar esta devolución, su crédito deja de contar. Sin deltas frágiles
            // que dejaban estado asimétrico al revertir una devolución sobre venta pagada.
            $this->recalcularSaldoCredito($venta);

            $venta->n_dev = max(0, ($venta->n_dev ?? 1) - 1);
            $venta->save();

            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function cobrarVenta(Request $request)
    {
        $request->validate([
            'venta_id' => 'required|integer',
            'monto'    => 'required|numeric|min:0.01',
        ]);

        $venta = Venta::findOrFail($request->venta_id);
        $this->validarAccesoSucursal($venta->sucursal_id);

        $ultimo_cierre = \App\Models\Sucursal::where('id', $venta->sucursal_id)->value('ultimo_cierre');
        $fecha = $request->filled('fecha') ? $request->fecha : now()->format('Y-m-d');

        if ($ultimo_cierre && $fecha <= $ultimo_cierre) {
            return response()->json(['error' => 'La fecha de cobro pertenece a una caja cerrada.'], 422);
        }
        // $venta->fecha es Carbon: formatear a Y-m-d para comparar como string
        // (comparar string vs Carbon directamente siempre da true y bloqueaba todos los cobros).
        if ($fecha < $venta->fecha->format('Y-m-d')) {
            return response()->json(['error' => 'La fecha de cobro no puede ser anterior a la venta.'], 422);
        }
        if ($fecha > now()->format('Y-m-d')) {
            return response()->json(['error' => 'La fecha no puede ser futura.'], 422);
        }

        // Solo ventas validadas pueden cobrarse
        if ($venta->estado !== 'VALIDO') {
            return response()->json(['error' => 'Solo se pueden cobrar ventas validadas.'], 422);
        }

        // Solo ventas a crédito (igual que legacy)
        if ($venta->tipo !== 'CREDITO') {
            return response()->json(['error' => 'La venta no es a crédito.'], 422);
        }

        // El monto no puede superar el saldo (igual que legacy)
        if ($request->monto > $venta->saldo) {
            return response()->json(['error' => 'El monto supera el saldo pendiente.'], 422);
        }

        DB::beginTransaction();
        try {
            Tranza::create([
                'sucursal_id'   => $venta->sucursal_id,
                'cuenta_id'     => $venta->cuenta_id,
                'fecha'         => $fecha,
                'tipo'          => 'INGRESO',
                'clase'         => 'COB',
                'registro'      => $venta->id,
                'descripcion'   => 'CUENTA: ' . ($venta->cuenta->nombre ?? ''),
                'monto_ingreso' => $request->monto,
                'monto_egreso'  => 0,
                'user_id'       => Auth::id(),
                'estado'        => 'ON',
            ]);

            $venta->acuenta = ($venta->acuenta ?? 0) + $request->monto;
            $venta->saldo   = $venta->total - $venta->acuenta;
            if ($venta->saldo <= 0) {
                $venta->pagado = 'PAGADO';
                $venta->saldo  = 0;
            }
            $venta->save();

            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function apiDetalles(Venta $venta)
    {
        return response()->json($venta->detalles()->where('estado','VALIDO')->get()->map(fn($d) => [
            'id'          => $d->id,
            'producto_id' => $d->producto_id,
            'codigo'      => $d->codigo,
            'descripcion' => $d->descripcion,
            'marca'       => $d->marca,
            // Numérico crudo (NO number_format): "1,500.00" rompe parseFloat() en el
            // front (→ 1) y corrompía el renglón al editar cantidad. El front formatea.
            'costo'       => (float) $d->costo,
            // Precios de referencia del renglón (sin/con factura) para que el POS ofrezca
            // botones rápidos "S/F" y "C/F" y el precio quede EDITABLE hasta la v2 (pedido
            // del cliente: el precio de venta no debe ser estático todavía).
            'p_norm'      => (float) $d->p_norm,
            'p_fact'      => (float) $d->p_fact,
            'cantidad'    => $d->cantidad,
            // El subtotal GUARDADO preserva la precisión del precio tipeado (legacy: 83.3333×12
            // = 1000.00 entra en decimal(9,2)). NO se recalcula desde `costo` truncado a 2
            // decimales (daría 999.96 y no cuadraría la venta). `subtotal_num` para sumar en el front.
            'subtotal'     => 'Bs. '.number_format($d->subtotal, 2),
            'subtotal_num' => (float) $d->subtotal,
        ]));
    }

    public function apiDevoluciones(Venta $venta)
    {
        return response()->json(
            Devventa::where('venta_id', $venta->id)->where('estado','ON')->get()->map(fn($d) => [
                'id'          => $d->id,
                'producto_id' => $d->producto_id,
                'fecha'       => $d->created_at?->format('d/m/Y') ?? '',
                'codigo'      => $d->codigo,
                'descripcion' => $d->descripcion,
                'marca'       => $d->marca,
                'cantidad'    => $d->cantidad,
                'costo'       => number_format($d->costo, 2),
                'total'       => 'Bs. '.number_format($d->total, 2),
                'total_num'   => (float) $d->total,
            ])
        );
    }

    public function apiCobros(Venta $venta)
    {
        return response()->json(
            Tranza::where('registro', $venta->id)
                ->where('clase','COB')
                ->where('estado','ON')
                ->where('sucursal_id', $venta->sucursal_id)
                ->orderBy('id','desc')->get()
                ->map(fn($t) => [
                    'id'          => $t->id,
                    'fecha'       => \Carbon\Carbon::parse($t->fecha)->format('d/m/Y'),
                    'monto'       => 'Bs. '.number_format($t->monto_ingreso, 2),
                    'monto_num'   => (float) $t->monto_ingreso,
                    'descripcion' => $t->descripcion,
                ])
        );
    }

    public function pdf(Venta $venta)
    {
        $venta->load('cuenta');
        $detalles = $venta->detalles()->where('estado', 'VALIDO')->get();
        $pdf = Pdf::loadView('ventas.pdf', compact('venta', 'detalles'))->setPaper('letter');
        return $pdf->stream('Venta_'.$venta->id.'.pdf');
    }

    private function recalcular(Venta $venta): void
    {
        // Suma los SUBTOTALES guardados (no `costo * cantidad`): el subtotal preserva la
        // precisión del precio tipeado (legacy), mientras que `costo` está truncado a 2
        // decimales. Para datos viejos subtotal == costo*cantidad, así que no cambia nada;
        // solo afecta a renglones nuevos con precio de más de 2 decimales (cuadre exacto).
        $t = $venta->detalles()->where('estado','VALIDO')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as total')
            ->value('total');
        $venta->monto = $t;
        $venta->total = $t;
        if ($venta->tipo === 'CREDITO') {
            $venta->saldo = $t - ($venta->acuenta ?? 0);
        }
        $venta->save();
    }

    /**
     * Recalcula acuenta/saldo/pagado de una venta CREDITO desde los hechos atómicos:
     * cobros en efectivo (tranzas COB ON) + notas de crédito por devoluciones (devventas ON).
     *
     * Determinista e idempotente: acuenta = min(total, cobros + devs), saldo = max(0, …).
     * Reemplaza los deltas frágiles que dejaban saldo negativo (devolver tras pagar todo)
     * o estado asimétrico al revertir. No aplica a CONTADO (saldo siempre 0).
     */
    private function recalcularSaldoCredito(Venta $venta): void
    {
        if ($venta->tipo !== 'CREDITO') {
            return;
        }

        $cobros = (float) Tranza::where('registro', $venta->id)
            ->where('sucursal_id', $venta->sucursal_id)
            ->where('clase', 'COB')
            ->where('estado', 'ON')
            ->sum('monto_ingreso');

        $devs = (float) Devventa::where('venta_id', $venta->id)
            ->where('estado', 'ON')
            ->sum('total');

        $total   = (float) $venta->total;
        $credito = $cobros + $devs;

        $venta->acuenta = min($total, $credito);
        $venta->saldo   = max(0.0, $total - $credito);
        $venta->pagado  = $venta->saldo <= 0 ? 'PAGADO' : 'POR PAGAR';
        $venta->save();
    }

    private function validarAccesoSucursal(int $sucursalId): int
    {
        // Rol EFECTIVO (respeta simulated_role_id): cuando un ADMIN simula otro rol, la
        // frontera de sucursal debe comportarse EXACTAMENTE como ese rol (decisión del
        // humano 2026-06-15). hasRole()/hasAnyRole() nativos de Spatie reportan el rol REAL
        // → dejarían el bypass de admin abierto durante el simulacro (fuga del simulador).
        $isAdmin = Auth::user()->effectiveRoleIs(['ADMIN', 'GERENTE']);

        if ($sucursalId === 0 && !$isAdmin) {
            $sucursalId = Auth::user()->sucursal_id;
        }
        if ($sucursalId > 0 && !Auth::user()->effectiveRoleIs('ADMIN')) {
            $tieneAcceso = DB::table('accesos')
                ->where('user_id', Auth::id())
                ->where('sucursal_id', $sucursalId)
                ->exists();
            if (!$tieneAcceso) {
                abort(403, 'No tiene acceso a la sucursal solicitada.');
            }
        }
        return $sucursalId;
    }
}
