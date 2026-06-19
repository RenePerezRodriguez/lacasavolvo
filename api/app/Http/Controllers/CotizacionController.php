<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\Cotizaciondetalle;
use App\Models\Venta;
use App\Models\Ventadetalle;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class CotizacionController extends Controller
{
    public function api(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $q = Cotizacion::with('cuenta')
            ->where('sucursal_id', $sid)
            ->whereIn('estado', ['VALIDO', 'ANULADO', 'CONVERTIDA']);

        // `fecha` es DATE → where() plano (no whereDate): no envuelve la columna en CAST.
        if ($request->filled('fecha_desde')) $q->where('fecha', '>=', $request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha', '<=', $request->fecha_hasta);
        if ($request->filled('estado_filtro')) $q->where('estado', strtoupper($request->estado_filtro));
        if ($request->filled('search')) {
            $raw = ltrim(trim($request->search), '#');
            if (is_numeric($raw)) {
                $q->where('cotizacions.id', (int)$raw);
            } else {
                // Búsqueda por texto: nombre de cliente u observación (ahí va el dato real
                // del cliente cuando la cuenta es genérica). Se quitó `orWhere('tipo')`: esa
                // columna NO existe en `cotizacions` → buscar por texto reventaba con SQL 1054
                // (Unknown column 'tipo') → 500. Verificado contra el esquema real de prod.
                $like = '%' . $raw . '%';
                $q->where(function ($q) use ($like) {
                    $q->whereHas('cuenta', fn($q) => $q->where('nombre', 'like', $like))
                      ->orWhere('observacion', 'like', $like);
                });
            }
        }

        $total = $q->count();

        // Ordenamiento dinámico
        $sortCol = $request->get('sort', 'id');
        $sortDir = strtolower($request->get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        
        $validCols = [
            'id' => 'cotizacions.id',
            'fecha' => 'cotizacions.fecha',
            'estado' => 'cotizacions.estado',
            'total' => 'cotizacions.total'
        ];

        if (array_key_exists($sortCol, $validCols)) {
            $q->orderBy($validCols[$sortCol], $sortDir);
        } else if ($sortCol === 'cuenta') {
            $q->join('cuentas', 'cotizacions.cuenta_id', '=', 'cuentas.id')
              ->orderBy('cuentas.nombre', $sortDir);
        } else {
            $q->orderBy('cotizacions.id', 'desc');
        }

        $cotizaciones = $q->select('cotizacions.*')->skip($request->get('skip', 0))->take($request->get('take', 15))->get();

        return response()->json([
            'total' => $total,
            'data'  => $cotizaciones->map(fn($c) => [
                'id'        => $c->id,
                'fecha'     => $c->fecha->format('d/m/Y'),
                'cuenta'    => $c->cuenta->nombre ?? '',
                'monto'     => 'Bs. ' . number_format($c->monto, 2),
                'descuento' => 'Bs. ' . number_format($c->descuento, 2),
                'total'     => 'Bs. ' . number_format($c->total, 2),
                'estado'    => $c->estado,
            ]),
        ]);
    }

    public function kpis(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $q = Cotizacion::where('sucursal_id', $sid)->whereIn('estado', ['VALIDO', 'ANULADO', 'CONVERTIDA']);
        // `fecha` es DATE → where() plano (no whereDate): no envuelve la columna en CAST.
        if ($request->filled('fecha_desde')) $q->where('fecha', '>=', $request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha', '<=', $request->fecha_hasta);

        // El monto total solo se expone a ADMIN/GERENTE (los demás roles no deben verlo)
        $puedeVerMonto = Auth::user()->hasAnyRole(['ADMIN', 'GERENTE']);

        return response()->json([
            'total'  => $q->count(),
            'valido' => (clone $q)->where('estado', 'VALIDO')->count(),
            'anulado'=> (clone $q)->where('estado', 'ANULADO')->count(),
            'monto'  => $puedeVerMonto ? 'Bs. ' . number_format((clone $q)->where('estado', 'VALIDO')->sum('total'), 2) : null,
        ]);
    }

    public function store(Request $request)
    {
        // `descuento` debe ser numérico y >= 0: un descuento negativo se persistía sin
        // validación y luego `recalcular()` inflaba el total (total = monto - (-X)).
        $request->validate([
            'fecha'     => 'required|date',
            'cuenta_id' => 'required|integer',
            'descuento' => 'nullable|numeric|min:0',
            // `observacion` es varchar(191): sin este max, 192+ chars reventaban el INSERT
            // con PDOException 1406 → 500 (gemelo del bug de Pedidos loop 14). 422 limpio.
            'observacion' => 'nullable|string|max:191',
        ]);

        $cotizacion = Cotizacion::create([
            'sucursal_id' => Auth::user()->sucursal_id,
            'fecha'       => $request->fecha,
            'cuenta_id'   => $request->cuenta_id,
            'descuento'   => $request->descuento ?? 0,
            'observacion' => $request->observacion,
            'estado'      => 'VALIDO',
            'user_id'     => Auth::id(),
            'monto'       => 0,
            'total'       => 0,
        ]);

        return response()->json([
            'id'        => $cotizacion->id,
            'cuenta'    => $cotizacion->cuenta->nombre ?? '',
            'cuenta_id' => $cotizacion->cuenta_id,
            'fecha'     => $cotizacion->fecha->format('d/m/Y'),
            'fecha_raw' => $cotizacion->fecha->format('Y-m-d'),
            'estado'    => $cotizacion->estado,
            'monto'     => (float) $cotizacion->monto,
            'descuento' => (float) $cotizacion->descuento,
            'total'     => 'Bs. ' . number_format($cotizacion->total, 2),
        ]);
    }

    public function show(Cotizacion $cotizacion)
    {
        if ($cotizacion->sucursal_id != Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }
        return response()->json([
            'id'        => $cotizacion->id,
            'cuenta'    => $cotizacion->cuenta->nombre ?? '',
            'cuenta_id' => $cotizacion->cuenta_id,
            'fecha'     => $cotizacion->fecha->format('d/m/Y'),
            'fecha_raw' => $cotizacion->fecha->format('Y-m-d'),
            'estado'    => $cotizacion->estado,
            // `observacion` faltaba en la respuesta → el detalle no podía mostrar los datos
            // del cliente/notas que el legacy SÍ guardaba ahí (nombre, teléfono, etc.). Sin
            // esto, cotizaciones con cliente "SIN NOMBRE" parecían vacías (regresión de QA).
            'observacion' => $cotizacion->observacion,
            'monto'     => (float) $cotizacion->monto,
            'descuento' => (float) $cotizacion->descuento,
            'total'     => 'Bs. ' . number_format($cotizacion->total, 2),
        ]);
    }

    public function destroy(Cotizacion $cotizacion)
    {
        if ($cotizacion->sucursal_id != Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Eliminación denegada.'], 403);
        }
        $cotizacion->estado = 'ANULADO';
        $cotizacion->save();
        return response()->json(['ok' => true]);
    }

    public function updateEncabezado(Request $request)
    {
        // `descuento` debe ser numérico y >= 0: con un descuento negativo el guard de
        // "mitad del monto" no aplicaba y `total = monto - (-X)` quedaba INFLADO por
        // encima del subtotal. Un descuento no numérico además reventaba la aritmética
        // del guard (TypeError: string - string → 500). El validador lo cierra: 422 limpio.
        $request->validate([
            'cotizacion_id' => 'required|integer',
            'cuenta_id'     => 'required|integer',
            'fecha'         => 'required|date',
            'descuento'     => 'nullable|numeric|min:0',
            // varchar(191): evita el 1406 → 500 al editar (mismo guard que store).
            'observacion'   => 'nullable|string|max:191',
        ]);
        $cotizacion = Cotizacion::findOrFail($request->cotizacion_id);
        if ($cotizacion->sucursal_id != Auth::user()->sucursal_id) {
            return response()->json(['error' => 'No permitido'], 403);
        }
        // No se edita el encabezado de una cotización en estado terminal (anulada o ya
        // convertida en venta): mutarlo deja el documento inconsistente con la venta.
        if (in_array($cotizacion->estado, ['ANULADO', 'CONVERTIDA'], true)) {
            return response()->json(['error' => 'La cotización no es editable (anulada o convertida).'], 422);
        }
        $descuento = $request->descuento ?? 0;
        // Borde monto=0: el guard de "mitad" se saltaba por `&& monto > 0`, dejando
        // `total = 0 - descuento` NEGATIVO. Cualquier descuento > 0 sobre subtotal 0 es ilegal.
        if ($cotizacion->monto <= 0 && $descuento > 0) {
            return response()->json(['error' => 'No se puede aplicar descuento sin ítems.'], 422);
        }
        if ($descuento >= ($cotizacion->monto) / 2 && $cotizacion->monto > 0) {
            return response()->json(['error' => 'El descuento sobrepasa la mitad del monto.'], 422);
        }
        $cotizacion->cuenta_id   = $request->cuenta_id;
        $cotizacion->fecha       = $request->fecha;
        $cotizacion->descuento   = $descuento;
        $cotizacion->total       = $cotizacion->monto - $descuento;
        $cotizacion->observacion = $request->observacion;
        $cotizacion->save();
        return response()->json(true);
    }

    public function agregarItem(Request $request)
    {
        $request->validate([
            'cotizacion_id' => 'required|integer',
            'producto_id'   => 'required|integer',
            'cantidad'      => 'required|integer|min:1|max:100000',
            'precio'        => 'nullable|numeric|min:0',
        ]);
        $cotizacion = Cotizacion::findOrFail($request->cotizacion_id);
        abort_if($cotizacion->sucursal_id !== Auth::user()->sucursal_id, 403);
        // Estado terminal: una cotización ANULADA o ya CONVERTIDA en venta no admite
        // nuevos renglones (mutar la convertida la desincroniza de la venta generada).
        abort_if(in_array($cotizacion->estado, ['ANULADO', 'CONVERTIDA'], true), 422);
        $prod = Producto::findOrFail($request->producto_id);

        $costo    = $request->precio ?? $prod->p_norm;
        $cantidad = $request->cantidad ?? 1;
        $monto    = $costo * $cantidad;

        Cotizaciondetalle::create([
            'cotizacion_id' => $cotizacion->id,
            'producto_id'   => $prod->id,
            'codigo'        => $prod->codigo,
            'descripcion'   => $prod->descripcion,
            'marca'         => $prod->marca->nombre ?? '',
            'p_comp'        => $prod->p_comp,
            'p_norm'        => $prod->p_norm,
            'p_fact'        => $prod->p_fact,
            'costo'         => $costo,
            'cantidad'      => $cantidad,
            'monto'         => $monto,
            'descuento'     => 0,
            'subtotal'      => $monto,
            'estado'        => 'VALIDO',
        ]);

        $this->recalcular($cotizacion);
        return response()->json(true);
    }

    public function updateItem(Request $request)
    {
        $request->validate([
            'registro' => 'required|integer',
            'cantidad' => 'required|integer|min:1|max:100000',
            'precio'   => 'nullable|numeric|min:0',
        ]);
        $detalle  = Cotizaciondetalle::findOrFail($request->registro);
        abort_if($detalle->cotizacion->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if(in_array($detalle->cotizacion->estado, ['ANULADO', 'CONVERTIDA'], true), 422);
        $costo    = $request->precio ?? $detalle->costo;
        $cantidad = $request->cantidad ?? $detalle->cantidad;
        $monto    = $costo * $cantidad;

        $detalle->update([
            'costo'    => $costo,
            'cantidad' => $cantidad,
            'monto'    => $monto,
            'subtotal' => $monto,
        ]);

        $this->recalcular($detalle->cotizacion);
        return response()->json(true);
    }

    public function deleteItem(Cotizaciondetalle $detalle)
    {
        abort_if($detalle->cotizacion->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if(in_array($detalle->cotizacion->estado, ['ANULADO', 'CONVERTIDA'], true), 422);
        $cotizacion = $detalle->cotizacion;
        $detalle->estado = 'ANULADO';
        $detalle->save();
        $this->recalcular($cotizacion);
        return response()->json(true);
    }

    private function recalcular(Cotizacion $cotizacion)
    {
        $monto = $cotizacion->detalles()->where('estado', 'VALIDO')->sum('subtotal');
        // Saneo del descuento heredado: un valor negativo o mayor al subtotal inflaría
        // o haría negativo el total. Invariante de dinero defendido en el chokepoint que
        // alimenta `total` tras cada cambio de renglón: 0 <= total <= subtotal (monto).
        $descuento = max(0, min((float) $cotizacion->descuento, (float) $monto));
        $cotizacion->descuento = $descuento;
        $cotizacion->monto = $monto;
        $cotizacion->total = $monto - $descuento;
        $cotizacion->save();
    }

    public function apiDetalles(Cotizacion $cotizacion)
    {
        return response()->json(
            $cotizacion->detalles()->where('estado', 'VALIDO')->get()->map(fn($d) => [
                'id'          => $d->id,
                'producto_id' => $d->producto_id,
                'codigo'      => $d->codigo,
                'descripcion' => $d->descripcion,
                'marca'       => $d->marca,
                'costo'       => (float) $d->costo,
                // Precios de referencia (sin/con factura) para botones rápidos S/F y C/F y
                // precio editable en el renglón — misma política que la venta (pedido del
                // cliente: edición de precios también en cotizaciones).
                'p_norm'      => (float) $d->p_norm,
                'p_fact'      => (float) $d->p_fact,
                'cantidad'    => $d->cantidad,
                'subtotal'     => 'Bs. ' . number_format($d->subtotal, 2),
                // `subtotal_num` para que el front sume el subtotal GUARDADO (preserva la
                // precisión del precio tipeado), no `costo × cantidad` con el costo truncado.
                'subtotal_num' => (float) $d->subtotal,
            ])
        );
    }

    public function pdf(Cotizacion $cotizacion)
    {
        if ($cotizacion->sucursal_id != Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }
        $cotizacion->load('cuenta');
        $detalles = $cotizacion->detalles()->where('estado', 'VALIDO')->get();
        $pdf = Pdf::loadView('cotizaciones.pdf', compact('cotizacion', 'detalles'))->setPaper('letter');
        return $pdf->stream('Cotizacion_' . $cotizacion->id . '.pdf');
    }

    public function ventaCotizacion(Cotizacion $cotizacion)
    {
        if ($cotizacion->sucursal_id != Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }

        // Idempotencia: una cotización se convierte a venta UNA sola vez. Sin este guard,
        // un doble-submit (doble-click) creaba DOS ventas (y doble descuento de stock al
        // validar ambas). Tras convertir, la cotización queda en estado terminal CONVERTIDA.
        if ($cotizacion->estado !== 'VALIDO') {
            return response()->json(['error' => 'La cotización no está disponible para convertir (anulada o ya convertida).'], 422);
        }

        DB::beginTransaction();
        try {
            $venta = Venta::create([
                'sucursal_id' => Auth::user()->sucursal_id,
                'fecha'       => $cotizacion->fecha,
                'tipo'        => 'CONTADO',
                'cuenta_id'   => $cotizacion->cuenta_id,
                'pagado'      => 'PAGADO',
                'monto'       => $cotizacion->total,
                'total'       => $cotizacion->total,
                'descuento'   => 0,
                'acuenta'     => 0,
                'saldo'       => 0,
                'estado'      => 'PROFORMA',
            ]);

            $detallesOrig = $cotizacion->detalles()->where('estado', 'VALIDO')->get();
            $items        = $detallesOrig->count();
            $descItem     = $items > 0 ? round($cotizacion->descuento / $items, 0) : 0;
            $descFinal    = $cotizacion->descuento - ($descItem * $items);

            foreach ($detallesOrig as $cd) {
                $costo = $cd->costo;
                if (($cd->subtotal) / 2 > $descItem) {
                    $costo      = round(($cd->subtotal - $descItem) / $cd->cantidad, 0);
                    $descFinal += ($cd->subtotal - $descItem) - ($costo * $cd->cantidad);
                } else {
                    $descFinal += $descItem;
                }

                $subtotal = $costo * $cd->cantidad;
                Ventadetalle::create([
                    'venta_id'    => $venta->id,
                    'producto_id' => $cd->producto_id,
                    'codigo'      => $cd->codigo,
                    'descripcion' => $cd->descripcion,
                    'marca'       => $cd->marca,
                    'p_comp'      => $cd->p_comp ?? 0,
                    'costo'       => $costo,
                    'p_norm'      => $cd->p_norm,
                    'p_fact'      => $cd->p_fact,
                    'cantidad'    => $cd->cantidad,
                    'monto'       => $subtotal,
                    'descuento'   => 0,
                    'subtotal'    => $subtotal,
                    'estado'      => 'VALIDO',
                ]);
            }

            if ($descFinal > 0) {
                foreach (Ventadetalle::where('venta_id', $venta->id)->where('estado', 'VALIDO')->get() as $vd) {
                    if (($vd->costo) / 2 > $descFinal) {
                        $vd->costo = $vd->costo - $descFinal;
                        $vd->save();
                        break;
                    }
                }
            }

            // Fiel al legacy: el total de la venta = total de la cotización (precio acordado
            // con descuento). El reparto del descuento por ítem redondea y puede no sumar
            // exacto; el legacy NO recalcula el header por eso. Recalcular sumando los
            // detalles propagaba ese redondeo y la venta quedaba por DEBAJO del total acordado.
            $venta->update(['monto' => $cotizacion->total, 'total' => $cotizacion->total]);

            // Marcar la cotización como convertida (estado terminal) → bloquea re-conversión.
            $cotizacion->estado = 'CONVERTIDA';
            $cotizacion->save();

            DB::commit();
            $venta->load('cuenta');
            return response()->json([
                'id'        => $venta->id,
                'cuenta_id' => $venta->cuenta_id,
                'cuenta'    => $venta->cuenta->nombre ?? '',
                'nit'       => $venta->cuenta->nit ?? '',
                'tipo'      => $venta->tipo,
                'fecha_raw' => $venta->fecha->format('Y-m-d'),
                'estado'    => $venta->estado,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al convertir: ' . $e->getMessage()], 500);
        }
    }
}
