<?php

namespace App\Http\Controllers;

use App\Helpers\SearchHelper;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductoController extends Controller
{
    public function kpis()
    {
        $sid    = Auth::user()->sucursal_id;
        $stockC = 'stock' . $sid;

        // El VALOR DE INVENTARIO deriva del precio/costo del producto → dato sensible: solo
        // lo ven ADMIN/GERENTE. Se gatea por ROL EFECTIVO (effectiveRoleIs, que respeta la
        // simulación: un ADMIN simulando VENDEDOR NO lo ve), mismo criterio que el @role del
        // legacy y sin depender de que el permiso costos.ver esté asignado en la BD. Los
        // conteos (activos/sin_stock/stock_critico) NO son sensibles → van a todos los roles.
        $verValor = Auth::user()->effectiveRoleIs(['ADMIN', 'GERENTE']);

        $row = DB::table('productos')
            ->selectRaw("
                SUM(CASE WHEN estado = 'ON'  THEN 1 ELSE 0 END) as activos,
                SUM(CASE WHEN estado = 'DES' THEN 1 ELSE 0 END) as descontinuados,
                SUM(CASE WHEN estado IN ('ON','DES') AND {$stockC} <= 0 THEN 1 ELSE 0 END) as sin_stock,
                SUM(CASE WHEN estado IN ('ON','DES') AND {$stockC} > 0 AND {$stockC} <= 5 THEN 1 ELSE 0 END) as stock_critico,
                COALESCE(SUM(CASE WHEN estado IN ('ON','DES') AND {$stockC} > 0 THEN {$stockC} * p_norm ELSE 0 END), 0) as valor_inventario
            ")
            ->first();

        return response()->json([
            'activos'          => (int)   ($row->activos          ?? 0),
            'descontinuados'   => (int)   ($row->descontinuados   ?? 0),
            'sin_stock'        => (int)   ($row->sin_stock        ?? 0),
            'stock_critico'    => (int)   ($row->stock_critico    ?? 0),
            // null (no 0) cuando no se puede ver: el front OCULTA la tarjeta en vez de
            // mostrar "Bs 0" (que parecería un inventario sin valor).
            'valor_inventario' => $verValor ? (float) ($row->valor_inventario ?? 0) : null,
        ]);
    }

    public function api(Request $request)
    {
        $sid      = Auth::user()->sucursal_id;
        $stockCol = 'stock' . $sid;
        // `p_comp` es el COSTO de compra (dato sensible). Solo se expone a quien puede ver
        // costos; para los demás roles viaja null (defensa en backend, no solo ocultar la
        // columna en el front, que igual dejaba el costo en el payload de red). Respeta la
        // simulación de roles (ver kpis()). p_norm/p_fact son precios de venta → siempre van.
        $verCosto = Auth::user()->effectiveRoleIs(['ADMIN', 'GERENTE']);

        $q = Producto::with(['marca', 'industria'])
            ->whereIn('productos.estado', ['ON', 'DES']);

        if ($request->filled('marca_id'))     $q->where('marca_id',     $request->marca_id);
        if ($request->filled('industria_id')) $q->where('industria_id', $request->industria_id);
        if ($request->filled('search')) {
            SearchHelper::apply(
                $q, $request->search,
                ['productos.id', 'productos.codigo', 'productos.descripcion'],
                ['marca.nombre', 'industria.nombre']
            );
        }

        $total = $q->count();

        $sortCol = $request->get('sort', 'id');
        $sortDir = $request->get('dir', 'asc') === 'asc' ? 'asc' : 'desc';

        // Si hay búsqueda y no se pidió orden explícito, ordenar por relevancia
        $hasSearch = $request->filled('search');
        $explicitSort = $request->has('sort');

        if ($hasSearch && !$explicitSort) {
            // Ranking: marca + código + descripción + ID (usando subqueries, no JOINs)
            // Se normaliza el '#' inicial igual que SearchHelper para que "#123" rankee el ID 123.
            $search = ltrim(trim($request->search), '#');
            $rel = self::buildRelevanceSQL($search);
            if ($rel['sql'] !== '') {
                $q->orderByRaw("({$rel['sql']}) desc", $rel['bindings']);
            }
            $q->orderBy('productos.id', 'asc');
        } elseif ($sortCol === 'marca') {
            $q->leftJoin('marcas', 'productos.marca_id', '=', 'marcas.id')
              ->orderBy('marcas.nombre', $sortDir);
        } elseif ($sortCol === 'industria') {
            $q->leftJoin('industrias', 'productos.industria_id', '=', 'industrias.id')
              ->orderBy('industrias.nombre', $sortDir);
        } else {
            $allowed = ['id'=>'productos.id', 'codigo'=>'productos.codigo', 'descripcion'=>'productos.descripcion', 'stock'=>$stockCol];
            $q->orderBy($allowed[$sortCol] ?? 'productos.id', $sortDir);
        }

        $productos = $q->skip($request->get('skip', 0))
            ->take($request->get('take', 50))
            ->get(['productos.*']);

        $sucs = \App\Models\Sucursal::orderBy('id')->get(['id', 'alias']);

        return response()->json([
            'total' => $total,
            'data'  => $productos->map(fn($p) => [
                'id'          => $p->id,
                'codigo'      => $p->codigo,
                'descripcion' => $p->descripcion,
                'marca'       => $p->marca->nombre ?? '',
                'industria'   => $p->industria->nombre ?? '',
                'unidad'      => $p->unidad,
                // Precios como FLOAT crudo (no number_format): el front formatea para mostrar.
                // number_format mete coma de miles ("2,505.00") y rompía dos cosas: el costo
                // salía NaN en pantalla para valores >= 1000, y al EDITAR el producto ese
                // string llegaba al backend como `(float)"2,505.00" = 2.0` → corrompía el
                // costo guardado (bug reportado en modo admin). Ver Bug #1 del CLAUDE.md.
                'p_comp'      => $verCosto ? (float) $p->p_comp : null,
                'p_norm'      => (float) $p->p_norm,
                'p_fact'      => (float) $p->p_fact,
                'stock'       => $p->$stockCol,
                'estado'      => $p->estado,
                'stocks'      => $sucs->map(fn($s) => [
                    'alias' => $s->alias ?: 'S'.$s->id,
                    'stock' => $p->{'stock'.$s->id} ?? 0,
                ]),
            ]),
        ]);
    }

    public function show(Producto $producto)
    {
        $sucursales = \App\Models\Sucursal::orderBy('id')->get(['id', 'alias', 'nombre']);
        $sid = Auth::user()->sucursal_id;
        $stockCol = 'stock' . $sid;
        // p_comp = costo: solo a quien puede ver costos (respeta simulación). Ver api().
        $verCosto = Auth::user()->effectiveRoleIs(['ADMIN', 'GERENTE']);
        return response()->json([
            'id'          => $producto->id,
            'codigo'      => $producto->codigo,
            'descripcion' => $producto->descripcion,
            'marca'       => $producto->marca->nombre ?? '',
            'industria'   => $producto->industria->nombre ?? '',
            'unidad'      => $producto->unidad,
            'p_comp'      => $verCosto ? (float) $producto->p_comp : null,
            'p_norm'      => (float) $producto->p_norm,
            'p_fact'      => (float) $producto->p_fact,
            'stock'       => $producto->$stockCol,
            'estado'      => $producto->estado,
            'stocks'      => $sucursales->map(fn($s) => [
                'id'    => $s->id,
                'alias' => $s->alias ?: 'S'.$s->id,
                'nombre'=> $s->nombre,
                'stock' => $producto->{'stock'.$s->id} ?? 0,
            ]),
        ]);
    }

    /**
     * Construye la expresión SQL de ranking de relevancia para búsqueda de productos.
     * ID exacto = 15, Código exacto = 10, Código prefix = 8, Marca = 5, Código LIKE = 4, Descripción = 2.
     *
     * SEGURIDAD: usa placeholders `?` + bindings en vez de interpolar el token en el SQL.
     * La versión anterior escapaba solo comillas (no backslash): en MySQL el `\` es escape,
     * así que un término como `a\` rompía/insertaba SQL en el ORDER BY (SQL injection + 500).
     *
     * @param string $search Término de búsqueda ya normalizado (sin '#' inicial).
     * @return array{sql:string, bindings:array} SQL con placeholders y sus bindings.
     *         sql='' cuando no hay tokens rankeables → el caller omite el orderByRaw.
     */
    private static function buildRelevanceSQL(string $search): array
    {
        $tokens = array_values(array_filter(explode(' ', $search), fn($t) => strlen($t) >= 2));
        $parts = [];
        $bindings = [];

        // ID exacto
        if (is_numeric($search)) {
            $parts[]    = "(CASE WHEN productos.id = ? THEN 15 ELSE 0 END)";
            $bindings[] = (int) $search;
        }
        foreach ($tokens as $token) {
            $like = '%' . $token . '%';
            if (is_numeric($token)) {
                $parts[]    = "(CASE WHEN productos.id = ? THEN 15 ELSE 0 END)";
                $bindings[] = (int) $token;
            }
            // Marca via subquery (evita conflicto con eager loading)
            $parts[]    = "(CASE WHEN EXISTS (SELECT 1 FROM marcas WHERE marcas.id = productos.marca_id AND marcas.nombre LIKE ?) THEN 5 ELSE 0 END)";
            $bindings[] = $like;
            $parts[]    = "(CASE WHEN productos.codigo = ? THEN 10 WHEN productos.codigo LIKE ? THEN 8 WHEN productos.codigo LIKE ? THEN 4 ELSE 0 END)";
            $bindings[] = $token;
            $bindings[] = $token . '%';
            $bindings[] = $like;
            $parts[]    = "(CASE WHEN productos.descripcion LIKE ? THEN 2 ELSE 0 END)";
            $bindings[] = $like;
        }

        return ['sql' => implode(' + ', $parts), 'bindings' => $bindings];
    }

    public function apiQuickSearch(Request $request)
    {
        // Normalizar '#' inicial (los usuarios buscan "#123" porque la UI muestra "#ID · código")
        $search   = ltrim(trim($request->get('search', '')), '#');
        $sid      = Auth::user()->sucursal_id;
        $stockCol = 'stock' . $sid;
        // p_comp = costo: solo a quien puede ver costos (respeta simulación). Ver api().
        $verCosto = Auth::user()->effectiveRoleIs(['ADMIN', 'GERENTE']);

        if ($search === '') {
            return response()->json([]);
        }

        $sucursales = \App\Models\Sucursal::orderBy('id')->get(['id', 'alias', 'nombre']);

        $productos = Producto::with(['marca'])
            ->whereIn('estado', ['ON', 'DES']);

        SearchHelper::apply(
            $productos, $search,
            ['productos.id', 'codigo', 'descripcion'],
            ['marca.nombre']
        );

        // Ranking de relevancia: cada token suma puntos según dónde coincide
        // ID exacto = 15, Código exacto = 10, Código prefix = 8, Marca = 5, Código LIKE = 4, Descripción = 2
        $rel = self::buildRelevanceSQL($search);
        if ($rel['sql'] !== '') {
            $productos->orderByRaw("({$rel['sql']}) desc", $rel['bindings']);
        }

        $productos = $productos
            ->orderByRaw("CASE WHEN productos.codigo = ? THEN 0 ELSE 1 END", [$search])
            ->limit(8)
            ->get();

        return response()->json(
            $productos->map(fn($p) => [
                'id'          => $p->id,
                'codigo'      => $p->codigo,
                'descripcion' => $p->descripcion,
                'marca'       => $p->marca->nombre ?? '',
                'p_comp'      => $verCosto ? (float) $p->p_comp : null,
                'p_norm'      => (float) $p->p_norm,
                'p_fact'      => (float) $p->p_fact,
                'stock'       => $p->$stockCol,
                'stocks'      => $sucursales->map(fn($s) => [
                    'id'    => $s->id,
                    'alias' => $s->alias ?: 'S'.$s->id,
                    'stock' => $p->{'stock'.$s->id} ?? 0,
                ]),
            ])
        );
    }

    public function movimientos(Producto $producto, Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $canSeeCosto = Auth::user()->effectiveRoleIs(['ADMIN', 'GERENTE']);

        $r1 = DB::table('ventadetalles')->join('ventas','ventas.id','=','ventadetalles.venta_id')->join('cuentas','ventas.cuenta_id','=','cuentas.id')
            ->selectRaw("'VEN' as tipo, ventadetalles.venta_id as registro, ventas.fecha, cuentas.nombre, '-' as ingreso, ventadetalles.cantidad as egreso, ventadetalles.costo as costo")
            ->where('ventadetalles.producto_id',$producto->id)->where('ventas.sucursal_id',$sid)->where('ventas.estado','VALIDO')->where('ventadetalles.estado','VALIDO');
        
        $r2 = DB::table('devventas')->join('ventas','ventas.id','=','devventas.venta_id')->join('cuentas','ventas.cuenta_id','=','cuentas.id')
            ->selectRaw("'D-VEN' as tipo, devventas.venta_id as registro, ventas.fecha, cuentas.nombre, devventas.cantidad as ingreso, '-' as egreso, NULL as costo")
            ->where('devventas.producto_id',$producto->id)->where('ventas.sucursal_id',$sid)->where('ventas.estado','VALIDO')->where('devventas.estado','ON');

        $r3 = DB::table('compradetalles')->join('compras','compras.id','=','compradetalles.compra_id')->join('cuentas','compras.cuenta_id','=','cuentas.id')
            ->selectRaw("'COM' as tipo, compradetalles.compra_id as registro, compras.fecha, cuentas.nombre, compradetalles.cantidad as ingreso, '-' as egreso, compradetalles.costo as costo")
            ->where('compradetalles.producto_id',$producto->id)->where('compras.sucursal_id',$sid)->where('compras.estado','VALIDO')->where('compradetalles.estado','VALIDO');

        $r4 = DB::table('devcompras')->join('compras','compras.id','=','devcompras.compra_id')->join('cuentas','compras.cuenta_id','=','cuentas.id')
            ->selectRaw("'D-COM' as tipo, devcompras.compra_id as registro, compras.fecha, cuentas.nombre, '-' as ingreso, devcompras.cantidad as egreso, NULL as costo")
            ->where('devcompras.producto_id',$producto->id)->where('compras.sucursal_id',$sid)->where('compras.estado','VALIDO')->where('devcompras.estado','ON');

        $r5 = DB::table('enviodetalles')->join('envios','envios.id','=','enviodetalles.envio_id')->join('cuentas','envios.cuenta_id','=','cuentas.id')
            ->selectRaw("'ENV' as tipo, enviodetalles.envio_id as registro, envios.fecha, cuentas.nombre, '-' as ingreso, enviodetalles.cantidad as egreso, NULL as costo")
            ->where('enviodetalles.producto_id',$producto->id)->where('envios.sucursal_id',$sid)->where('envios.estado','ENVIADO')->where('enviodetalles.estado','VALIDO');

        $r6 = DB::table('enviodetalles')->join('envios','envios.id','=','enviodetalles.envio_id')->join('sucursals','envios.sucursal_id','=','sucursals.id')
            ->selectRaw("'REC' as tipo, enviodetalles.envio_id as registro, envios.fecha, sucursals.nombre, enviodetalles.cantidad as ingreso, '-' as egreso, NULL as costo")
            ->where('enviodetalles.producto_id',$producto->id)->where('envios.cuenta_id',$sid)->where('envios.estado','RECIBIDO')->where('enviodetalles.estado','VALIDO');

        $r7 = DB::table('enviodetalles')->join('envios','envios.id','=','enviodetalles.envio_id')->join('cuentas','envios.cuenta_id','=','cuentas.id')
            ->selectRaw("'ENV' as tipo, enviodetalles.envio_id as registro, envios.fecha, cuentas.nombre, '-' as ingreso, enviodetalles.cantidad as egreso, NULL as costo")
            ->where('enviodetalles.producto_id',$producto->id)->where('envios.sucursal_id',$sid)->where('envios.estado','RECIBIDO')->where('enviodetalles.estado','VALIDO');

        $r8 = DB::table('devenvios')->join('envios','envios.id','=','devenvios.envio_id')->join('cuentas','envios.cuenta_id','=','cuentas.id')
            ->selectRaw("'D-ENV' as tipo, devenvios.envio_id as registro, envios.fecha, cuentas.nombre, devenvios.cantidad as ingreso, '-' as egreso, NULL as costo")
            ->where('devenvios.producto_id',$producto->id)->where('envios.sucursal_id',$sid)->where('envios.estado','RECIBIDO')->where('devenvios.estado','ON');

        $r9 = DB::table('devenvios')->join('envios','envios.id','=','devenvios.envio_id')->join('sucursals','envios.sucursal_id','=','sucursals.id')
            ->selectRaw("'D-REC' as tipo, devenvios.envio_id as registro, envios.fecha, sucursals.nombre, '-' as ingreso, devenvios.cantidad as egreso, NULL as costo")
            ->where('devenvios.producto_id',$producto->id)->where('envios.cuenta_id',$sid)->where('envios.estado','RECIBIDO')->where('devenvios.estado','ON');

        $r10 = DB::table('ajustes')->join('sucursals','ajustes.sucursal_id','=','sucursals.id')
            ->selectRaw("'A-POS' as tipo, ajustes.id as registro, DATE_FORMAT(ajustes.created_at,'%Y-%m-%d') as fecha, ajustes.observacion as nombre, ajustes.cantidad as ingreso, '-' as egreso, NULL as costo")
            ->where('ajustes.producto_id',$producto->id)->where('ajustes.sucursal_id',$sid)->where('ajustes.estado','ON')->where('ajustes.tipo','POSITIVO');

        $r11 = DB::table('ajustes')->join('sucursals','ajustes.sucursal_id','=','sucursals.id')
            ->selectRaw("'A-NEG' as tipo, ajustes.id as registro, DATE_FORMAT(ajustes.created_at,'%Y-%m-%d') as fecha, ajustes.observacion as nombre, '-' as ingreso, ajustes.cantidad as egreso, NULL as costo")
            ->where('ajustes.producto_id',$producto->id)->where('ajustes.sucursal_id',$sid)->where('ajustes.estado','ON')->where('ajustes.tipo','NEGATIVO');

        $results = $r1->unionAll($r2)->unionAll($r3)->unionAll($r4)->unionAll($r5)->unionAll($r6)->unionAll($r7)->unionAll($r8)->unionAll($r9)->unionAll($r10)->unionAll($r11)
            ->orderBy('fecha','desc')->orderBy('tipo')->get();

        return response()->json([
            'can_see_costo' => $canSeeCosto,
            'data' => $results->map(fn($m) => [
                'tipo'    => $m->tipo,
                'registro' => $m->registro,
                'fecha'   => $m->fecha,
                'nombre'  => $m->nombre,
                'ingreso' => $m->ingreso,
                'egreso'  => $m->egreso,
                // "Precio de referencia" del movimiento (observación de QA: faltaba la
                // columna; el sistema viejo la mostraba). En VENTAS es el precio de VENTA
                // → lo ve cualquier rol (no es un costo). En COMPRAS es el costo real →
                // solo quien puede ver costos. El resto de movimientos no tiene precio (null).
                'costo'   => ($m->costo !== null && ($canSeeCosto || $m->tipo !== 'COM'))
                    ? (float) $m->costo
                    : null,
            ]),
        ]);
    }

    public function apiAjustes(Request $request)
    {
        $sid = Auth::user()->sucursal_id;
        $q = \App\Models\Ajuste::where('sucursal_id', $sid)->where('estado', 'ON');
        if ($request->filled('search')) {
            // La tabla ajustes guarda codigo/descripcion/marca desnormalizados del producto;
            // producto_id permite buscar por #ID exacto.
            $raw = ltrim(trim($request->search), '#');
            $s   = '%' . $raw . '%';
            $q->where(function ($q) use ($s, $raw) {
                $q->where('codigo', 'like', $s)
                  ->orWhere('descripcion', 'like', $s)
                  ->orWhere('marca', 'like', $s);
                if (is_numeric($raw)) {
                    $q->orWhere('producto_id', (int) $raw);
                }
            });
        }
        if ($request->filled('tipo') && strtoupper($request->tipo) !== 'TODOS') {
            $q->where('tipo', strtoupper($request->tipo));
        }
        $total  = $q->count();
        $ajustes = $q->orderBy('id', 'desc')->skip($request->get('skip', 0))->take($request->get('take', 15))->get();
        return response()->json([
            'total' => $total,
            'data'  => $ajustes->map(fn($a) => [
                'id'          => $a->id,
                'tipo'        => $a->tipo,
                'codigo'      => $a->codigo,
                'descripcion' => $a->descripcion,
                'marca'       => $a->marca,
                'cantidad'    => $a->cantidad,
                'observacion' => $a->observacion,
                'fecha'       => $a->created_at->format('d/m/Y'),
            ]),
        ]);
    }

    public function ajustePositivo(Request $request)
    {
        $request->validate([
            'producto_id' => 'required|integer|exists:productos,id',
            'cantidad'    => 'required|integer|min:1|max:100000',
            'observacion' => 'nullable|string|max:500',
        ]);
        $prod = Producto::findOrFail($request->producto_id);
        $sid  = Auth::user()->sucursal_id;
        $col  = 'stock' . $sid;

        DB::beginTransaction();
        try {
            \App\Models\Ajuste::create([
                'sucursal_id' => $sid, 'tipo' => 'POSITIVO',
                'producto_id' => $prod->id, 'codigo' => $prod->codigo,
                'descripcion' => $prod->descripcion, 'marca' => $prod->marca->nombre ?? '',
                'cantidad'    => $request->cantidad, 'observacion' => $request->observacion ?? '',
                'user_id'     => Auth::id(), 'estado' => 'ON',
            ]);
            $prod->$col = $prod->$col + $request->cantidad;
            $prod->save();
            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function ajusteNegativo(Request $request)
    {
        $request->validate([
            'producto_id' => 'required|integer|exists:productos,id',
            'cantidad'    => 'required|integer|min:1|max:100000',
            'observacion' => 'nullable|string|max:500',
        ]);
        $prod = Producto::findOrFail($request->producto_id);
        $sid  = Auth::user()->sucursal_id;
        $col  = 'stock' . $sid;

        // Guard de NO-NEGATIVIDAD: un ajuste negativo no puede sacar más de lo que hay.
        // Stock físico negativo es corrupción de inventario (no se pueden tener -3 piezas)
        // y envenena valor_inventario/KPIs/ventas futuras. Misma postura que el guard de
        // sobreventa en VentaController::validar (la API directa no debe dejar stock < 0).
        if ($request->cantidad > $prod->$col) {
            return response()->json([
                'error' => 'No se puede ajustar negativamente más que el stock disponible.',
                'stock_disponible' => (int) $prod->$col,
                'cantidad' => (int) $request->cantidad,
            ], 422);
        }

        DB::beginTransaction();
        try {
            \App\Models\Ajuste::create([
                'sucursal_id' => $sid, 'tipo' => 'NEGATIVO',
                'producto_id' => $prod->id, 'codigo' => $prod->codigo,
                'descripcion' => $prod->descripcion, 'marca' => $prod->marca->nombre ?? '',
                'cantidad'    => $request->cantidad, 'observacion' => $request->observacion ?? '',
                'user_id'     => Auth::id(), 'estado' => 'ON',
            ]);
            $prod->$col = $prod->$col - $request->cantidad;
            $prod->save();
            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function ajusteDestroy(Request $request)
    {
        $request->validate(['ajuste_id' => 'required|integer']);
        $ajuste = \App\Models\Ajuste::findOrFail($request->ajuste_id);
        abort_if($ajuste->sucursal_id !== Auth::user()->sucursal_id, 403);

        // Guard de IDEMPOTENCIA: solo se revierte un ajuste VIVO (estado ON). Sin este
        // guard, destruir el mismo ajuste dos veces (doble-submit) revertía el stock DOS
        // veces (doble-conteo) — misma clase que el bug ya cerrado de deleteItemDev sobre
        // documento anulado. Un ajuste OFF ya fue revertido: re-destruirlo es no-op.
        if ($ajuste->estado !== 'ON') {
            return response()->json(true);
        }

        $prod   = Producto::findOrFail($ajuste->producto_id);
        $sid    = Auth::user()->sucursal_id;
        $col    = 'stock' . $sid;

        // Guard de NO-NEGATIVIDAD (2º orden): revertir un ajuste POSITIVO resta su cantidad.
        // Si ese stock ya fue consumido por ajustes negativos posteriores, la resta dejaría
        // stock < 0 (corrupción de inventario). Se rechaza: primero hay que reponer el stock
        // (o revertir los negativos) antes de poder deshacer este ajuste positivo.
        if ($ajuste->tipo === 'POSITIVO' && $ajuste->cantidad > $prod->$col) {
            return response()->json([
                'error' => 'No se puede revertir este ajuste: dejaría el stock negativo. Repón stock primero.',
                'stock_disponible' => (int) $prod->$col,
                'cantidad' => (int) $ajuste->cantidad,
            ], 422);
        }

        DB::beginTransaction();
        try {
            $prod->$col = $ajuste->tipo === 'POSITIVO'
                ? $prod->$col - $ajuste->cantidad
                : $prod->$col + $ajuste->cantidad;
            $prod->save();
            $ajuste->estado = 'OFF';
            $ajuste->save();
            DB::commit();
            return response()->json(true);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            // SIN `unique`: el catálogo heredado tiene >1000 productos con código repetido
            // (480 con "SIN CODIGO", "---", etc.) — duplicar código es normal en este negocio
            // y el sistema legacy NUNCA lo exigió. `max:191` = tamaño real de la columna.
            'codigo'       => 'required|string|max:191',
            // `descripcion` es columna TEXT (sin tope práctico); 500 da margen para descripciones
            // técnicas largas sin reventar (antes 255 cortaba pegados largos → 422 genérico).
            'descripcion'  => 'required|string|max:500',
            'marca_id'     => 'required|integer|exists:marcas,id',
            'industria_id' => 'required|integer|exists:industrias,id',
            'unidad'       => 'nullable|string|max:10',
            // Los precios faltantes se guardan en 0 (las columnas son NOT NULL): un alta sin
            // algún precio reventaba el INSERT pese a marcarse "(opcional)" en el formulario.
            // `max:9999999.99` = tope de la columna DECIMAL(9,2). Sin él, un precio mayor con
            // STRICT_TRANS_TABLES dispara SQL 1264 (out of range) → 500; con el max es 422 limpio.
            'p_comp'       => 'nullable|numeric|min:0|max:9999999.99',
            'p_norm'       => 'nullable|numeric|min:0|max:9999999.99',
            'p_fact'       => 'nullable|numeric|min:0|max:9999999.99',
        ]);
        $data['p_comp'] = $request->filled('p_comp') ? (float) $request->p_comp : 0;
        $data['p_norm'] = $request->filled('p_norm') ? (float) $request->p_norm : 0;
        $data['p_fact'] = $request->filled('p_fact') ? (float) $request->p_fact : 0;
        $producto = Producto::create($data + ['estado' => 'ON']);
        return response()->json(['id' => $producto->id]);
    }

    public function update(Request $request, Producto $producto)
    {
        $data = $request->validate([
            // SIN `unique`: editar un producto cuyo código se repite en el catálogo heredado
            // (>1000 casos, p. ej. marcas DFG/TECNOPARTS) fallaba con 422 al guardar. El legacy
            // nunca exigió código único. `max:191` = tamaño real de la columna.
            'codigo'       => 'required|string|max:191',
            'descripcion'  => 'required|string|max:500',
            'marca_id'     => 'required|integer|exists:marcas,id',
            'industria_id' => 'required|integer|exists:industrias,id',
            'unidad'       => 'nullable|string|max:10',
            // `max:9999999.99` = tope de la columna DECIMAL(9,2): sin él, un precio mayor revienta
            // el UPDATE con SQL 1264 → 500 (STRICT mode). Con el max es un 422 limpio.
            'p_comp'       => 'nullable|numeric|min:0|max:9999999.99',
            'p_norm'       => 'nullable|numeric|min:0|max:9999999.99',
            'p_fact'       => 'nullable|numeric|min:0|max:9999999.99',
        ]);

        // Los precios son opcionales: si no vienen, se conserva el valor actual
        // (las columnas de precios no admiten NULL).
        $pComp = $request->filled('p_comp') ? (float) $request->p_comp : (float) $producto->p_comp;
        $pNorm = $request->filled('p_norm') ? (float) $request->p_norm : (float) $producto->p_norm;
        $pFact = $request->filled('p_fact') ? (float) $request->p_fact : (float) $producto->p_fact;
        $data['p_comp'] = $pComp;
        $data['p_norm'] = $pNorm;
        $data['p_fact'] = $pFact;

        if ($pComp != $producto->p_comp || $pNorm != $producto->p_norm || $pFact != $producto->p_fact) {
            \App\Models\Precio::create([
                'tipo'        => 'EDICION',
                'registro'    => 0,
                'producto_id' => $producto->id,
                'p_comp_orig' => $producto->p_comp,
                'p_comp'      => $pComp,
                'p_norm_orig' => $producto->p_norm,
                'p_norm'      => $pNorm,
                'p_fact_orig' => $producto->p_fact,
                'p_fact'      => $pFact,
                'user_id'     => Auth::id(),
            ]);
        }

        $producto->update($data);
        return response()->json(['ok' => true]);
    }

    public function destroy(Producto $producto)
    {
        // OFF = eliminado (soft delete, igual que legacy): desaparece del listado,
        // que solo muestra estados ON/DES. DES queda reservado para "descontinuado",
        // un producto que sigue visible pero ya no se reabastece.
        $producto->update(['estado' => 'OFF']);
        return response()->json(['ok' => true]);
    }
}
