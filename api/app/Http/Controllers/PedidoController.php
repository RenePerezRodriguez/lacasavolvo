<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\Pedidodetalle;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class PedidoController extends Controller
{
    public function api(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $q = Pedido::with('sucursal')
            ->whereIn('estado', ['PROFORMA', 'VALIDO', 'ANULADO']);

        // Central (id=1) ve todos los pedidos; las demás sucursales solo los suyos
        if ($sid !== 1) {
            $q->where('sucursal_id', $sid);
        }

        // `fecha` es DATE → where() plano (no whereDate): no envuelve la columna en CAST.
        if ($request->filled('fecha_desde')) $q->where('fecha', '>=', $request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha', '<=', $request->fecha_hasta);
        if ($request->filled('estado_filtro')) $q->where('estado', strtoupper($request->estado_filtro));
        if ($request->filled('search')) {
            $raw = ltrim(trim($request->search), '#');
            if (is_numeric($raw)) {
                $q->where('pedidos.id', (int)$raw);
            } else {
                $like = '%' . $raw . '%';
                $q->where(function ($q) use ($like) {
                    $q->where('observacion', 'like', $like)
                      ->orWhereHas('sucursal', fn($q) => $q->where('nombre', 'like', $like));
                });
            }
        }

        $total = $q->count();

        $sortCol = $request->get('sort', 'id');
        $sortDir = strtolower($request->get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $validCols = [
            'id' => 'pedidos.id',
            'fecha' => 'pedidos.fecha',
            'estado' => 'pedidos.estado',
            'total' => 'pedidos.total'
        ];

        if (array_key_exists($sortCol, $validCols)) {
            $q->orderBy($validCols[$sortCol], $sortDir);
        } else {
            $q->orderBy('pedidos.id', 'desc');
        }

        $pedidos = $q->with('user')->select('pedidos.*')->skip($request->get('skip', 0))->take($request->get('take', 15))->get();

        return response()->json([
            'total' => $total,
            'data'  => $pedidos->map(fn($p) => [
                'id'          => $p->id,
                'fecha'       => $p->fecha->format('d/m/Y'),
                'observacion' => $p->observacion ?? '',
                'estado'      => $p->estado,
                'sucursal'    => $p->sucursal->nombre ?? '',
                'usuario'     => $p->user->name ?? '',
            ]),
        ]);
    }

    public function kpis(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $q = Pedido::whereIn('estado', ['PROFORMA', 'VALIDO', 'ANULADO']);
        if ($sid !== 1) {
            $q->where('sucursal_id', $sid);
        }
        // `fecha` es DATE → where() plano (no whereDate): no envuelve la columna en CAST.
        if ($request->filled('fecha_desde')) $q->where('fecha', '>=', $request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha', '<=', $request->fecha_hasta);

        return response()->json([
            'total'   => $q->count(),
            'proforma'=> (clone $q)->where('estado', 'PROFORMA')->count(),
            'valido'  => (clone $q)->where('estado', 'VALIDO')->count(),
            'anulado' => (clone $q)->where('estado', 'ANULADO')->count(),
        ]);
    }

    public function store(Request $request)
    {
        // La columna `pedidos.observacion` es varchar(191): el validador DEBE coincidir
        // con el ancho real o un valor de 192..500 chars pasa la validación y revienta la
        // inserción con un 500 (Data too long). Cap = 191 → 422 limpio.
        $request->validate(['observacion' => 'nullable|string|max:191']);

        $pedido = Pedido::create([
            'sucursal_id' => Auth::user()->sucursal_id,
            'fecha'       => Carbon::now()->format('Y-m-d'),
            'observacion' => $request->observacion,
            'user_id'     => Auth::id(),
            'estado'      => 'PROFORMA',
        ]);

        return response()->json([
            'id'          => $pedido->id,
            'sucursal_id' => $pedido->sucursal_id,
            'sucursal'    => $pedido->sucursal->nombre ?? '',
            'fecha'       => $pedido->fecha->format('d/m/Y'),
            'fecha_raw'   => $pedido->fecha->format('Y-m-d'),
            'estado'      => $pedido->estado,
            'observacion' => $pedido->observacion ?? '',
        ]);
    }

    public function show(Pedido $pedido)
    {
        $sid = Auth::user()->sucursal_id;
        if ($sid !== 1 && $pedido->sucursal_id !== $sid) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }
        return response()->json([
            'id'          => $pedido->id,
            'sucursal_id' => $pedido->sucursal_id,
            'sucursal'    => $pedido->sucursal->nombre ?? '',
            'fecha'       => $pedido->fecha->format('d/m/Y'),
            'fecha_raw'   => $pedido->fecha->format('Y-m-d'),
            'estado'      => $pedido->estado,
            'observacion' => $pedido->observacion ?? '',
        ]);
    }

    public function validar(Request $request, Pedido $pedido)
    {
        if ($pedido->sucursal_id != Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Validación denegada.'], 403);
        }
        if ($pedido->estado !== 'PROFORMA') {
            return response()->json(['error' => 'No es proforma.'], 422);
        }
        $pedido->estado = 'VALIDO';
        $pedido->save();
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Pedido $pedido)
    {
        if ($pedido->sucursal_id != Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Eliminación denegada.'], 403);
        }
        if ($pedido->estado !== 'ANULADO') {
            $pedido->estado = 'ANULADO';
            $pedido->save();
        }
        return response()->json(['ok' => true]);
    }

    public function updateEncabezado(Request $request)
    {
        // Mismo contrato de longitud que `store`: sin esto, una observacion > 191 chars
        // reventaba con un 500 (col overflow) en vez de un 422 limpio.
        $request->validate(['observacion' => 'nullable|string|max:191']);
        $pedido = Pedido::findOrFail($request->pedido_id);
        if ($pedido->sucursal_id != Auth::user()->sucursal_id || $pedido->estado !== 'PROFORMA') {
            return response()->json(['error' => 'No permitido'], 403);
        }
        $pedido->observacion = $request->observacion;
        $pedido->save();
        return response()->json(true);
    }

    public function agregarItem(Request $request)
    {
        $request->validate([
            'pedido_id'   => 'required|integer',
            'producto_id' => 'required|integer',
            'cantidad'    => 'required|integer|min:1|max:100000',
        ]);
        $pedido = Pedido::findOrFail($request->pedido_id);
        abort_if($pedido->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($pedido->estado !== 'PROFORMA', 422);
        $prod = Producto::findOrFail($request->producto_id);

        $existe = Pedidodetalle::where('pedido_id', $pedido->id)
            ->where('producto_id', $prod->id)
            ->where('estado', 'VALIDO')
            ->count();

        if ($existe > 0) {
            return response()->json(['duplicado' => true]);
        }

        Pedidodetalle::create([
            'pedido_id'   => $pedido->id,
            'producto_id' => $prod->id,
            'codigo'      => $prod->codigo,
            'descripcion' => $prod->descripcion,
            'marca'       => $prod->marca->nombre ?? '',
            'cantidad'    => $request->cantidad,
            'estado'      => 'VALIDO',
        ]);

        return response()->json(true);
    }

    public function updateItem(Request $request)
    {
        $request->validate([
            'registro' => 'required|integer',
            'cantidad' => 'required|integer|min:1|max:100000',
        ]);
        $detalle = Pedidodetalle::findOrFail($request->registro);
        abort_if($detalle->pedido->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($detalle->pedido->estado !== 'PROFORMA', 422);
        $detalle->update(['cantidad' => $request->cantidad]);
        return response()->json(true);
    }

    public function deleteItem(Pedidodetalle $detalle)
    {
        abort_if($detalle->pedido->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($detalle->pedido->estado !== 'PROFORMA', 422);
        $detalle->estado = 'ANULADO';
        $detalle->save();
        return response()->json(true);
    }

    public function apiDetalles(Pedido $pedido)
    {
        $sid = Auth::user()->sucursal_id;
        if ($sid !== 1 && $pedido->sucursal_id !== $sid) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }

        return response()->json(
            $pedido->detalles()->where('estado', 'VALIDO')->get()->map(fn($d) => [
                // `id` = id de la LÍNEA (pedidodetalle), usado para update/delete del renglón.
                // `producto_id` = id real del PRODUCTO: es el que el front debe MOSTRAR (#5504),
                // no el de la línea (#29361). Sin esto el front caía al id de línea → confundía
                // al usuario y no se podía buscar el producto (observación de QA).
                'id'          => $d->id,
                'producto_id' => $d->producto_id,
                'codigo'      => $d->codigo,
                'descripcion' => $d->descripcion,
                'marca'       => $d->marca,
                'cantidad'    => $d->cantidad,
            ])
        );
    }

    public function pdf(Pedido $pedido)
    {
        // Misma frontera de LECTURA que show()/apiDetalles(): la central (sid=1) ve todos
        // los pedidos; las demás sucursales solo los suyos. Sin este guard, una sucursal
        // ajena podía descargar el PDF (con historial de precios) de cualquier pedido (IDOR).
        $sid = Auth::user()->sucursal_id;
        if ($sid !== 1 && $pedido->sucursal_id !== $sid) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }
        $pedido->load('sucursal');
        $detalles = $pedido->detalles()->where('estado', 'VALIDO')->with('producto')->get();

        $historiales = collect();
        if (auth()->user()->hasAnyRole(['ADMIN', 'GERENTE'])) {
            $productIds = $detalles->pluck('producto_id')->unique()->values();
            $historiales = DB::table('compradetalles')
                ->join('compras', 'compradetalles.compra_id', '=', 'compras.id')
                ->join('cuentas', 'compras.cuenta_id', '=', 'cuentas.id')
                ->select('compradetalles.producto_id', 'cuentas.nombre', DB::raw('DATE_FORMAT(compras.fecha,"%Y-%m-%d") as fecha'), 'compradetalles.costo')
                ->whereIn('compradetalles.producto_id', $productIds)
                ->where('compras.sucursal_id', 1)
                ->where('compras.estado', 'VALIDO')
                ->where('compradetalles.estado', 'VALIDO')
                ->orderBy('compras.fecha', 'desc')
                ->get()
                ->groupBy('producto_id')
                ->map(fn($g) => $g->take(2));
        }

        $pdf = Pdf::loadView('pedidos.pdf', compact('pedido', 'detalles', 'historiales'))->setPaper('a3', 'landscape');
        return $pdf->stream('Pedido_' . $pedido->id . '.pdf');
    }
}
