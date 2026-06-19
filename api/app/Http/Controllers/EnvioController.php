<?php

namespace App\Http\Controllers;

use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Devenvio;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EnvioController extends Controller
{
    public function api(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $q = Envio::with('cuenta','sucursal','medio')
            ->where(function($q)use($sid){ $q->where('sucursal_id',$sid)->orWhere('cuenta_id',$sid); })
            ->whereIn('estado',['PROFORMA','ENVIADO','RECIBIDO','ANULADO']);
        if ($request->filled('search')) {
            $raw = ltrim(trim($request->search), '#');
            if (is_numeric($raw)) {
                $q->where('envios.id', (int)$raw);
            } else {
                $like = '%' . $raw . '%';
                $q->where(function ($q) use ($like) {
                    $q->whereHas('sucursal', fn($q) => $q->where('nombre', 'like', $like))
                      ->orWhereHas('cuenta', fn($q) => $q->where('nombre', 'like', $like));
                });
            }
        }
        // `fecha` es DATE → where() plano (no whereDate): no envuelve la columna en CAST.
        if ($request->filled('fecha_desde')) $q->where('fecha','>=',$request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha','<=',$request->fecha_hasta);
        if ($request->filled('estado_filtro')) $q->where('estado', strtoupper($request->estado_filtro));
        $total = $q->count();

        $sortCol = $request->get('sort', 'id');
        $sortDir = strtolower($request->get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $validCols = [
            'id' => 'envios.id',
            'fecha' => 'envios.fecha',
            'estado' => 'envios.estado',
            'monto' => 'envios.monto'
        ];

        if (array_key_exists($sortCol, $validCols)) {
            $q->orderBy($validCols[$sortCol], $sortDir);
        } else if ($sortCol === 'origen') {
            $q->join('sucursals', 'envios.sucursal_id', '=', 'sucursals.id')
              ->orderBy('sucursals.nombre', $sortDir);
        } else if ($sortCol === 'destino') {
            $q->join('cuentas', 'envios.cuenta_id', '=', 'cuentas.id')
              ->orderBy('cuentas.nombre', $sortDir);
        } else {
            $q->orderBy('envios.id', 'desc');
        }

        $envios = $q->select('envios.*')->skip($request->get('skip',0))->take($request->get('take',15))->get();
        return response()->json(['total'=>$total,'data'=>$envios->map(fn($e)=>[
            'id'=>$e->id,'fecha'=>$e->fecha->format('d/m/Y'),'origen'=>$e->sucursal->nombre??'','destino'=>$e->cuenta->nombre??'',
            'medio'=>$e->medio->nombre??'','monto'=>'Bs. '.number_format($e->monto,2),'pagado'=>$e->pagado,'estado'=>$e->estado,
            'medio_id'=>$e->medio_id,'monto_num'=>(float)$e->monto,'fecha_raw'=>$e->fecha->format('Y-m-d'),
            // `sucursal_id` = sucursal ORIGEN (la dueña del traslado). El front lo usa para
            // mostrar el envío en modo SOLO-LECTURA cuando el usuario está en la sucursal
            // destino (o cualquier otra): editar un envío que no le compete a la sucursal ya
            // lo bloquea el backend (403 en agregar/update/delete-item), pero la UI mostraba
            // los controles igual. `puede_editar` = origen + PROFORMA.
            'sucursal_id'  => $e->sucursal_id,
            'es_origen'    => $e->sucursal_id === $sid,
            'es_destino'   => $e->cuenta_id === $sid,
            'puede_editar' => $e->sucursal_id === $sid && $e->estado === 'PROFORMA',
        ])]);
    }

    public function kpis(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $q = Envio::where(function($q)use($sid){ $q->where('sucursal_id',$sid)->orWhere('cuenta_id',$sid); })->whereIn('estado',['PROFORMA','ENVIADO','RECIBIDO','ANULADO']);
        // `fecha` es DATE → where() plano (no whereDate): no envuelve la columna en CAST.
        if ($request->filled('fecha_desde')) $q->where('fecha','>=',$request->fecha_desde);
        if ($request->filled('fecha_hasta')) $q->where('fecha','<=',$request->fecha_hasta);
        return response()->json(['total'=>$q->count(),'proforma'=>(clone $q)->where('estado','PROFORMA')->count(),'enviado'=>(clone $q)->where('estado','ENVIADO')->count(),'recibido'=>(clone $q)->where('estado','RECIBIDO')->count()]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'fecha'     => 'required|date',
            'cuenta_id' => 'required|integer',
            // medio_id es NOT NULL en la BD: requerido (evita un 500 por constraint).
            'medio_id'  => 'required|integer|exists:medios,id',
            'monto'     => 'nullable|numeric|min:0',
            // `pagado` decide DÓNDE se cobra el flete: PAGADO → egreso en el origen al
            // enviar; POR PAGAR → egreso en el destino al recibir. Cualquier otro valor
            // dejaba el flete (monto>0) sin cobrar en ninguna caja → costo perdido.
            'pagado'    => 'nullable|in:PAGADO,POR PAGAR',
        ], [
            'medio_id.required' => 'Selecciona un medio de transporte.',
            'medio_id.exists'   => 'El medio de transporte seleccionado no existe.',
        ]);
        $ultimoCierre = Auth::user()->sucursal->ultimo_cierre;
        abort_if($ultimoCierre && $request->fecha <= $ultimoCierre, 422, 'Fecha fuera de rango (caja cerrada).');
        $envio = Envio::create([
            'sucursal_id'=>Auth::user()->sucursal_id,'fecha'=>$request->fecha,'cuenta_id'=>$request->cuenta_id,
            'medio_id'=>$request->medio_id,'monto'=>$request->monto,'pagado'=>$request->pagado??'PAGADO',
            'estado'=>'PROFORMA',
        ]);
        $envio->load('sucursal','cuenta','medio');
        return response()->json([
            'id'        => $envio->id,
            'origen'    => $envio->sucursal->nombre ?? '',
            'destino'   => $envio->cuenta->nombre ?? '',
            'cuenta_id' => $envio->cuenta_id,
            'sucursal_id'=> $envio->sucursal_id,
            'fecha'     => $envio->fecha->format('d/m/Y'),
            'fecha_raw' => $envio->fecha->format('Y-m-d'),
            'estado'    => $envio->estado,
            'medio'     => $envio->medio->nombre ?? '',
            'monto'     => 'Bs. '.number_format($envio->monto ?? 0, 2),
            // Recién creado por esta sucursal (origen) y en PROFORMA → editable.
            'es_origen'    => true,
            'es_destino'   => false,
            'puede_editar' => $envio->sucursal_id === Auth::user()->sucursal_id && $envio->estado === 'PROFORMA',
        ]);
    }

    public function show(Envio $envio)
    {
        $sid = Auth::user()->sucursal_id;
        if ($envio->sucursal_id != $sid && $envio->cuenta_id != $sid) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }
        return response()->json([
            'id'        => $envio->id,
            'origen'    => $envio->sucursal->nombre ?? '',
            'destino'   => $envio->cuenta->nombre ?? '',
            'cuenta_id' => $envio->cuenta_id,
            'sucursal_id'=> $envio->sucursal_id,
            'fecha'     => $envio->fecha->format('d/m/Y'),
            'fecha_raw' => $envio->fecha->format('Y-m-d'),
            'estado'    => $envio->estado,
            'medio'     => $envio->medio->nombre ?? '',
            'monto'     => 'Bs. '.number_format($envio->monto ?? 0, 2),
            // Crudos para pre-llenar el form de editar encabezado (pedido de QA).
            'medio_id'  => $envio->medio_id,
            'monto_num' => (float) ($envio->monto ?? 0),
            'pagado'    => $envio->pagado,
            // `observacion` faltaba en la respuesta → el detalle no mostraba las notas del
            // envío (ej. "LLEGO DE SANTA CRUZ") que el legacy SÍ mostraba (regresión de QA,
            // mismo patrón que cotizaciones). 199 envíos en prod la tienen cargada.
            'observacion' => $envio->observacion ?? '',
            // Solo el ORIGEN puede editar un envío en PROFORMA (la misma frontera que
            // imponen agregarItem/updateItem/deleteItem/updateEncabezado con abort_if 403).
            // El destino (cuenta_id) y cualquier otra sucursal lo ven en SOLO-LECTURA.
            // `es_origen`/`es_destino` permiten al front mostrar las acciones correctas:
            // editar/despachar/anular → origen; recibir/devolver → destino.
            'es_origen'    => $envio->sucursal_id === $sid,
            'es_destino'   => $envio->cuenta_id === $sid,
            'puede_editar' => $envio->sucursal_id === $sid && $envio->estado === 'PROFORMA',
        ]);
    }

    public function updateEncabezado(Request $request)
    {
        $envio = Envio::findOrFail($request->envio_id);
        if ($envio->sucursal_id !== Auth::user()->sucursal_id || $envio->estado !== 'PROFORMA') {
            return response()->json(['error' => 'Sin acceso o envío no es proforma.'], 403);
        }
        $request->validate([
            'cuenta_id' => 'required|integer',
            'fecha'     => 'required|date',
            'medio_id'  => 'nullable|integer',
            'monto'     => 'nullable|numeric|min:0',
            // Mismo contrato que `store`: el flete solo se cobra con PAGADO/POR PAGAR.
            'pagado'    => 'nullable|in:PAGADO,POR PAGAR',
            // varchar(191): cap explícito para no reventar el UPDATE con 192+ chars (1406→500).
            'observacion' => 'nullable|string|max:191',
        ]);
        abort_if($request->fecha <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');

        $envio->update($request->only(['cuenta_id','fecha','medio_id','monto','pagado','observacion']));
        return response()->json(true);
    }

    public function agregarItem(Request $request)
    {
        $request->validate([
            'envio_id'    => 'required|integer',
            'producto_id' => 'required|integer',
            'cantidad'    => 'required|integer|min:1|max:100000',
        ]);
        $envio = Envio::findOrFail($request->envio_id);
        abort_if($envio->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($envio->estado !== 'PROFORMA', 422, 'El envío no es proforma.');
        $prod = Producto::findOrFail($request->producto_id);

        $existe = Enviodetalle::where('envio_id', $envio->id)
            ->where('producto_id', $prod->id)
            ->where('estado', 'VALIDO')
            ->exists();
        if ($existe) {
            return response()->json(['duplicado' => true]);
        }

        Enviodetalle::create([
            'envio_id'=>$envio->id,'producto_id'=>$prod->id,'codigo'=>$prod->codigo,'descripcion'=>$prod->descripcion,
            'marca'=>$prod->marca->nombre??'','cantidad'=>$request->cantidad,'estado'=>'VALIDO',
        ]);
        return response()->json(true);
    }

    public function updateItem(Request $request)
    {
        $request->validate([
            'registro' => 'required|integer',
            'cantidad' => 'required|integer|min:1|max:100000',
        ]);
        $d = Enviodetalle::findOrFail($request->registro);
        abort_if($d->envio->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($d->envio->estado !== 'PROFORMA', 422, 'El envío no es proforma.');
        $d->update(['cantidad'=>$request->cantidad]);
        return response()->json(true);
    }

    public function deleteItem(Enviodetalle $detalle)
    {
        abort_if($detalle->envio->sucursal_id !== Auth::user()->sucursal_id, 403);
        abort_if($detalle->envio->estado !== 'PROFORMA', 422, 'El envío no es proforma.');
        $detalle->estado = 'ANULADO'; $detalle->save();
        return response()->json(true);
    }

    public function enviar(Request $request, Envio $envio)
    {
        abort_if($envio->sucursal_id !== Auth::user()->sucursal_id, 403);
        if ($envio->estado !== 'PROFORMA') {
            return response()->json(['error' => 'No es proforma.'], 422);
        }
        $hoy = now()->format('Y-m-d');
        abort_if($hoy <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');

        $detalles = $envio->detalles()->where('estado', 'VALIDO')->get();
        if ($detalles->isEmpty()) {
            return response()->json(['error' => 'No hay productos que enviar.'], 422);
        }

        // Guard de stock del lado servidor: el front bloquea enviar con stock insuficiente
        // (endpoint negativos), pero una llamada directa a la API podía dejar el stock de
        // la sucursal origen en negativo (sobreventa por traslado). Mismo criterio que
        // VentaController::validar.
        $insuficientes = [];
        $col = 'stock' . $envio->sucursal_id;
        foreach ($detalles as $d) {
            $p = Producto::find($d->producto_id);
            if ($p && $p->$col < $d->cantidad) {
                $insuficientes[] = ['id' => $p->id, 'codigo' => $p->codigo, 'stock' => $p->$col, 'pedido' => $d->cantidad];
            }
        }
        if (!empty($insuficientes)) {
            return response()->json(['error' => 'Stock insuficiente en la sucursal de origen para enviar el traslado.', 'items' => $insuficientes], 422);
        }

        DB::beginTransaction();
        try {
            $envio->estado = 'ENVIADO'; $envio->save();
            foreach ($detalles as $d) {
                $p = Producto::findOrFail($d->producto_id);
                $col = 'stock'.$envio->sucursal_id;
                $p->$col = $p->$col - $d->cantidad;
                $p->save();
            }

            if ($envio->pagado === 'PAGADO' && $envio->monto > 0) {
                \App\Models\Tranza::create([
                    'sucursal_id' => $envio->sucursal_id,
                    'cuenta_id'   => $envio->sucursal_id,
                    'fecha'       => $hoy,
                    'tipo'        => 'EGRESO',
                    'clase'       => 'ENV',
                    'registro'    => $envio->id,
                    'descripcion' => 'DESTINO: ' . ($envio->cuenta->nombre ?? ''),
                    'monto_ingreso'=> 0,
                    'monto_egreso'=> $envio->monto,
                    'user_id'     => Auth::id(),
                    'estado'      => 'ON',
                ]);
            }

            DB::commit();
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function recibir(Request $request, Envio $envio)
    {
        abort_if($envio->cuenta_id !== Auth::user()->sucursal_id, 403);
        if ($envio->estado !== 'ENVIADO') {
            return response()->json(['error' => 'No está en tránsito.'], 422);
        }
        $hoy = now()->format('Y-m-d');
        abort_if($hoy <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');
        
        DB::beginTransaction();
        try {
            $envio->estado = 'RECIBIDO'; $envio->save();
            foreach ($envio->detalles()->where('estado','VALIDO')->get() as $d) {
                $p = Producto::findOrFail($d->producto_id);
                $col = 'stock'.$envio->cuenta_id;
                $p->$col = $p->$col + $d->cantidad;
                $p->save();
            }

            if ($envio->pagado === 'POR PAGAR' && $envio->monto > 0) {
                \App\Models\Tranza::create([
                    'sucursal_id' => $envio->cuenta_id,
                    'cuenta_id'   => $envio->cuenta_id,
                    'fecha'       => $hoy,
                    'tipo'        => 'EGRESO',
                    'clase'       => 'ENV',
                    'registro'    => $envio->id,
                    'descripcion' => 'ORIGEN: ' . ($envio->sucursal->nombre ?? ''),
                    'monto_ingreso'=> 0,
                    'monto_egreso'=> $envio->monto,
                    'user_id'     => Auth::id(),
                    'estado'      => 'ON',
                ]);
            }

            DB::commit();
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function apiDetalles(Envio $envio)
    {
        $sid = Auth::user()->sucursal_id;
        if ($envio->sucursal_id !== $sid && $envio->cuenta_id !== $sid) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }

        return response()->json($envio->detalles()->where('estado','VALIDO')->get()->map(fn($d)=>[
            'id'=>$d->id,'producto_id'=>$d->producto_id,'codigo'=>$d->codigo,'descripcion'=>$d->descripcion,'marca'=>$d->marca,'cantidad'=>$d->cantidad,
        ]));
    }

    public function pdf(Envio $envio)
    {
        // Guard de lectura asimétrico (IDOR, D1): solo el origen o el destino del
        // traslado pueden descargar el PDF — igual que `show`/`apiDetalles`. Sin esto
        // una sucursal ajena descargaba el comprobante de un traslado que no es suyo.
        $sid = Auth::user()->sucursal_id;
        if ($envio->sucursal_id != $sid && $envio->cuenta_id != $sid) {
            return response()->json(['error' => 'Sin acceso.'], 403);
        }
        $envio->load(['sucursal', 'cuenta', 'medio']);
        $detalles = $envio->detalles()->where('estado', 'VALIDO')->get();
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('envios.pdf', compact('envio', 'detalles'))->setPaper('a3', 'landscape');
        return $pdf->stream('Envio_'.$envio->id.'.pdf');
    }

    public function destroy(Request $request, Envio $envio)
    {
        abort_if($envio->sucursal_id !== Auth::user()->sucursal_id, 403);
        $hoy = now()->format('Y-m-d');
        abort_if($hoy <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');

        DB::beginTransaction();
        try {
            if ($envio->estado === 'ENVIADO') {
                foreach ($envio->detalles()->where('estado','VALIDO')->get() as $d) {
                    $p = Producto::findOrFail($d->producto_id);
                    $col = 'stock'.$envio->sucursal_id;
                    $p->$col = $p->$col + $d->cantidad;
                    $p->save();
                }
            } elseif ($envio->estado === 'RECIBIDO') {
                foreach ($envio->detalles()->where('estado','VALIDO')->get() as $d) {
                    $p = Producto::findOrFail($d->producto_id);
                    $totalDevuelto = Devenvio::where('envio_id', $envio->id)
                        ->where('producto_id', $p->id)
                        ->where('estado', 'ON')
                        ->sum('cantidad');
                    
                    $colOrigen = 'stock'.$envio->sucursal_id;
                    $colDestino = 'stock'.$envio->cuenta_id;
                    
                    $p->$colOrigen = $p->$colOrigen + ($d->cantidad - $totalDevuelto);
                    $p->$colDestino = $p->$colDestino - ($d->cantidad - $totalDevuelto);
                    $p->save();
                }
            }

            \App\Models\Tranza::where('sucursal_id', $envio->sucursal_id)
                ->where('registro', $envio->id)->where('clase', 'ENV')->update(['estado' => 'OFF']);
            \App\Models\Tranza::where('sucursal_id', $envio->cuenta_id)
                ->where('registro', $envio->id)->where('clase', 'ENV')->update(['estado' => 'OFF']);

            $envio->estado = 'ANULADO'; 
            $envio->save();
            DB::commit();
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function devItem(Request $request)
    {
        $request->validate([
            'envio_id' => 'required|integer',
            'registro' => 'required|integer',
            'cantidad' => 'required|integer|min:1|max:100000',
        ]);
        $envio   = Envio::findOrFail($request->envio_id);
        $detalle = Enviodetalle::findOrFail($request->registro);

        abort_if(now()->format('Y-m-d') <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');

        if ($envio->cuenta_id !== Auth::user()->sucursal_id || $envio->estado !== 'RECIBIDO') {
            return response()->json(['ok' => false, 'lim' => false]);
        }

        $yaDevuelto = Devenvio::where('envio_id', $envio->id)
            ->where('producto_id', $detalle->producto_id)->where('estado', 'ON')->sum('cantidad');

        if (($yaDevuelto + $request->cantidad) > $detalle->cantidad) {
            return response()->json(['ok' => false, 'lim' => true]);
        }

        DB::beginTransaction();
        try {
            $prod = Producto::findOrFail($detalle->producto_id);
            $colOrigen  = 'stock' . $envio->sucursal_id;
            $colDestino = 'stock' . $envio->cuenta_id;
            $prod->$colDestino = $prod->$colDestino - $request->cantidad;
            $prod->$colOrigen  = $prod->$colOrigen  + $request->cantidad;
            $prod->save();

            Devenvio::create([
                'sucursal_id' => $envio->sucursal_id,
                'envio_id'    => $envio->id,
                'registro'    => $detalle->id,
                'producto_id' => $detalle->producto_id,
                'codigo'      => $detalle->codigo,
                'descripcion' => $detalle->descripcion,
                'marca'       => $detalle->marca,
                'cantidad'    => $request->cantidad,
                'estado'      => 'ON',
                'user_id'     => Auth::id(),
            ]);

            $envio->n_dev = ($envio->n_dev ?? 0) + 1;
            $envio->save();
            DB::commit();
            return response()->json(['ok' => true, 'n_dev' => $envio->n_dev, 'lim' => false]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['ok' => false, 'lim' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiDevoluciones(Envio $envio)
    {
        $devs = Devenvio::where('envio_id', $envio->id)->where('estado', 'ON')->get();
        return response()->json(['data' => $devs->map(fn($d) => [
            'id'          => $d->id,
            'codigo'      => $d->codigo,
            'descripcion' => $d->descripcion,
            'marca'       => $d->marca,
            'cantidad'    => $d->cantidad,
        ])]);
    }

    public function deleteItemDev(Request $request)
    {
        $devenvio = Devenvio::findOrFail($request->registro);
        $envio    = Envio::findOrFail($devenvio->envio_id);

        abort_if(now()->format('Y-m-d') <= Auth::user()->sucursal->ultimo_cierre, 422, 'Fecha fuera de rango (caja cerrada).');

        if ($envio->cuenta_id !== Auth::user()->sucursal_id) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }
        // Solo se revierte una devolución de un envío RECIBIDO. Si ya fue ANULADO, la
        // anulación restituyó el stock neto entre origen y destino; revertir acá lo
        // movería de nuevo (doble conteo). Simétrico con devItem (que exige RECIBIDO).
        if ($envio->estado !== 'RECIBIDO') {
            return response()->json(['error' => 'El envío no está recibido; no se puede revertir la devolución.'], 422);
        }

        DB::beginTransaction();
        try {
            $prod = Producto::findOrFail($devenvio->producto_id);
            $colOrigen  = 'stock' . $envio->sucursal_id;
            $colDestino = 'stock' . $envio->cuenta_id;
            $prod->$colDestino = $prod->$colDestino + $devenvio->cantidad;
            $prod->$colOrigen  = $prod->$colOrigen  - $devenvio->cantidad;
            $prod->save();

            $devenvio->estado = 'OFF';
            $devenvio->save();

            $envio->n_dev = max(0, ($envio->n_dev ?? 1) - 1);
            $envio->save();
            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function negativos(Request $request)
    {
        $envio = Envio::findOrFail($request->envio_id);
        abort_if($envio->sucursal_id !== Auth::user()->sucursal_id, 403);
        $detalles = Enviodetalle::where('envio_id', $envio->id)->where('estado', 'VALIDO')->get();
        $insuficientes = [];

        foreach ($detalles as $detalle) {
            $producto = Producto::findOrFail($detalle->producto_id);
            $stockCol = 'stock' . $envio->sucursal_id;
            if ($producto->$stockCol < $detalle->cantidad) {
                $insuficientes[] = [
                    'id'     => $producto->id,
                    'codigo' => $producto->codigo,
                    'marca'  => $producto->marca->nombre ?? '',
                    'stock'  => $producto->$stockCol,
                    'pedido' => $detalle->cantidad,
                ];
            }
        }

        return response()->json(['negativo' => count($insuficientes) > 0, 'items' => $insuficientes]);
    }
}
