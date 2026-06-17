<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
class EstadisticaController extends Controller
{
    /** Cuenta anónima de mostrador ("CLIENTE SIN NOMBRE"): se excluye de los rankings
     *  de clientes porque acumula casi todas las ventas y vuelve inútil el Top Clientes. */
    private const CUENTA_MOSTRADOR = 6;

    /**
     * Autorización: ADMIN y GERENTE tienen acceso completo a estadísticas,
     * otros roles necesitan el permiso granular 'estadisticas.index'.
     *
     * IMPORTANTE — frontera de simulación de roles: se usa el ROL EFECTIVO
     * (effectiveRoleIs, que respeta simulated_role_id), NO hasRole() nativo de
     * Spatie. hasRole() reporta el rol REAL del usuario, así que un ADMIN que
     * simula VENDEDOR pasaría el atajo y vería estadísticas que su rol simulado
     * no debería ver (fuga del simulador). Con el rol efectivo, cuando hay
     * simulación el atajo NO aplica y se cae al chequeo can('estadisticas.index'),
     * que sí respeta la simulación (Gate::before evalúa contra el rol simulado).
     */
    private function autorizarEstadisticas()
    {
        $user = Auth::user();
        if ($user->effectiveRoleIs(['ADMIN', 'GERENTE'])) return;
        if (!$user->can('estadisticas.index')) abort(403, 'No tienes permiso para ver estadísticas.');
    }

    /**
     * Rotación de inventario: calcula el porcentaje de unidades vendidas de cada compra
     * utilizando tracking FIFO (primeras unidades compradas = primeras vendidas).
     *
     * La query agrupa por (compra_id, producto_id) para evitar filas duplicadas cuando
     * una misma compra tiene múltiples líneas del mismo producto (distintos costos).
     * El costo devuelto es el costo total de la línea (cantidad × costo unitario promedio).
     */
    public function rotacion(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid = $this->validarAccesoSucursal((int) $request->get('rotSucursal', Auth::user()->sucursal_id));
        [$take, $skip] = $this->paginacion($request);
        $desde = $request->get('rotDesde', now()->subMonths(3)->toDateString());
        $hasta = $request->get('rotHasta', now()->toDateString());
        $corte = $request->get('rotCorte', now()->toDateString());

        // Subquery agrupada por (compra_id, producto_id) para evitar duplicados (Bug #1)
        $q = DB::table('compradetalles')
            ->join('compras', 'compras.id', '=', 'compradetalles.compra_id')
            ->join('productos', 'productos.id', '=', 'compradetalles.producto_id')
            ->join('cuentas', 'compras.cuenta_id', '=', 'cuentas.id')
            ->join('sucursals', 'compras.sucursal_id', '=', 'sucursals.id')
            ->where('compras.estado', 'VALIDO')
            ->where('compradetalles.estado', 'VALIDO')
            ->whereBetween('compras.fecha', [$desde, $hasta])
            ->when($sid > 0, fn($q) => $q->where('compras.sucursal_id', $sid))
            ->select(
                'compradetalles.producto_id',
                'compras.id as compra_id',
                'compras.sucursal_id',
                DB::raw('MAX(compras.fecha) as compra_fecha'),
                DB::raw('MAX(sucursals.nombre) as sucursal_nombre'),
                DB::raw('MAX(cuentas.nombre) as proveedor_nombre'),
                DB::raw('MAX(compradetalles.codigo) as codigo'),
                DB::raw('MAX(compradetalles.descripcion) as descripcion'),
                DB::raw('SUM(compradetalles.cantidad) as cantidad_comprada'),
                DB::raw('SUM(compradetalles.cantidad * compradetalles.costo) as costo_total'),
                DB::raw('ROUND(SUM(compradetalles.cantidad * compradetalles.costo) / SUM(compradetalles.cantidad), 2) as costo_unitario'),
                DB::raw('COUNT(*) as lineas')
            )
            ->groupBy('compradetalles.producto_id', 'compras.id', 'compras.sucursal_id');

        // Conteo de grupos: `getCountForPagination` no es fiable con GROUP BY, pero
        // `->get()->count()` MATERIALIZABA todos los grupos en PHP solo para contar (coste
        // O(n) de memoria/transferencia en datasets grandes). Se cuenta en SQL envolviendo
        // la query agrupada en una subquery → `SELECT COUNT(*) FROM (…) sub`: 1 sola fila.
        $total = DB::query()->fromSub(clone $q, 'sub')->count();
        $items = (clone $q)->orderByDesc('compra_id')->skip($skip)->take($take)->get();

        $pids = $items->pluck('producto_id')->unique()->toArray();
        $tracking = $this->calcularTrackingFIFO($pids, $corte, $sid);

        $data = $items->map(function ($item) use ($corte, $tracking) {
            $k = $item->compra_id . '-' . $item->producto_id;
            $ventas = (float) ($tracking[$k]['vendidos'] ?? 0);
            $utilidad = (float) ($tracking[$k]['utilidad'] ?? 0);

            $pct = $item->cantidad_comprada > 0 ? round(($ventas / $item->cantidad_comprada) * 100) : 0;

            return [
                'producto_id'       => (int)$item->producto_id,
                'codigo'            => $item->codigo,
                'compra_id'         => '#'.$item->compra_id,
                'fecha'             => \Carbon\Carbon::parse($item->compra_fecha)->format('d/m/Y'),
                'sucursal'          => $item->sucursal_nombre,
                'sucursal_id'       => (int)$item->sucursal_id,
                'proveedor'         => $item->proveedor_nombre,
                'cantidad_comprada' => (int)$item->cantidad_comprada,
                'ventas'            => (int)$ventas,
                'rotacion'          => $pct,
                'semaforo'          => $pct <= 0 ? 'gris' : ($pct >= 70 ? 'verde' : ($pct >= 30 ? 'amarillo' : 'rojo')),
                'costo_unitario'    => 'Bs. '.number_format((float)$item->costo_unitario, 2),
                'costo_total'       => 'Bs. '.number_format((float)$item->costo_total, 2),
                'utilidad'          => 'Bs. '.number_format($utilidad, 2),
                'dias'              => (int) \Carbon\Carbon::parse($item->compra_fecha)->diffInDays(\Carbon\Carbon::parse($corte)),
                'lineas'            => (int)$item->lineas,
            ];
        });

        return response()->json(['total' => $total, 'data' => $data]);
    }

    public function ventasPeriodo(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid = $this->validarAccesoSucursal((int) $request->get('vpSucursal', 0));
        $desde = $request->get('vpDesde', now()->subMonth()->toDateString());
        $hasta = $request->get('vpHasta', now()->toDateString());
        $gran = $request->get('vpGran', 'month');
        if (!in_array($gran, ['day', 'week', 'month'])) $gran = 'month';

        $label = match($gran) { 'week' => "DATE_FORMAT(fecha,'%x-W%v')", 'month' => "DATE_FORMAT(fecha,'%Y-%m')", default => "DATE(fecha)" };
        return response()->json(
            DB::table('ventas')->where('estado', 'VALIDO')->whereBetween('fecha', [$desde, $hasta])
                ->when($sid > 0, fn($q) => $q->where('sucursal_id', $sid))
                ->select(DB::raw("{$label} as dia"), DB::raw('COUNT(*) as ventas'), DB::raw('SUM(total) as total'))
                ->groupBy(DB::raw($label))->orderBy('dia')->get()
        );
    }

    public function topProductos(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid = $this->validarAccesoSucursal((int) $request->get('tpSucursal', 0));
        $desde = $request->get('tpDesde', now()->subMonth()->toDateString());
        $hasta = $request->get('tpHasta', now()->toDateString());
        $metrica = $request->get('tpMet', 'unidades');
        [$take, $skip] = $this->paginacion($request);
        $orderCol = $metrica === 'monto' ? 'total_monto' : 'total_vendido';

        $q = DB::table('ventadetalles')->join('ventas', 'ventas.id', '=', 'ventadetalles.venta_id')->join('productos', 'productos.id', '=', 'ventadetalles.producto_id')
            ->leftJoin('marcas', 'productos.marca_id', '=', 'marcas.id')
            ->where('ventas.estado', 'VALIDO')->where('ventadetalles.estado', 'VALIDO')->whereBetween('ventas.fecha', [$desde, $hasta])
            ->when($sid > 0, fn($q) => $q->where('ventas.sucursal_id', $sid))
            ->select('productos.codigo', 'productos.descripcion', DB::raw("COALESCE(marcas.nombre, '-') as marca"), DB::raw('SUM(ventadetalles.cantidad) as total_vendido'), DB::raw('SUM(ventadetalles.cantidad * ventadetalles.costo) as total_monto'))
            ->groupBy('productos.id', 'productos.codigo', 'productos.descripcion', DB::raw("COALESCE(marcas.nombre, '-')"));

        $total = $q->getCountForPagination();
        $data = (clone $q)->orderByDesc($orderCol)->skip($skip)->take($take)->get();
        return response()->json(['total' => $total, 'data' => $data]);
    }

    // ── Dashboard (pantalla de inicio) ─────────────────────────────────────────
    // El INICIO es para TODOS los roles (decisión de producto 2026-06-17), a diferencia
    // del módulo Estadísticas (ADMIN/GERENTE). Por eso estos gemelos NO llaman a
    // autorizarEstadisticas() y SIEMPRE acotan a la sucursal ACTIVA del usuario (nunca
    // global): el inicio refleja la sucursal del selector de arriba.

    /**
     * Ventas por período para el inicio — siempre acotado a la sucursal activa.
     * Mismo shape que ventasPeriodo(), pero accesible a cualquier rol autenticado.
     */
    public function dashboardVentasPeriodo(Request $request)
    {
        $sid = $this->sucursalActivaDashboard($request);
        $desde = $request->get('vpDesde', now()->subMonth()->toDateString());
        $hasta = $request->get('vpHasta', now()->toDateString());
        $gran = $request->get('vpGran', 'month');
        if (!in_array($gran, ['day', 'week', 'month'])) $gran = 'month';

        $label = match ($gran) { 'week' => "DATE_FORMAT(fecha,'%x-W%v')", 'month' => "DATE_FORMAT(fecha,'%Y-%m')", default => "DATE(fecha)" };
        return response()->json(
            DB::table('ventas')->where('estado', 'VALIDO')->whereBetween('fecha', [$desde, $hasta])
                ->where('sucursal_id', $sid)
                ->select(DB::raw("{$label} as dia"), DB::raw('COUNT(*) as ventas'), DB::raw('SUM(total) as total'))
                ->groupBy(DB::raw($label))->orderBy('dia')->get()
        );
    }

    /**
     * Top productos para el inicio — siempre acotado a la sucursal activa.
     * Mismo shape que topProductos(), pero accesible a cualquier rol autenticado.
     */
    public function dashboardTopProductos(Request $request)
    {
        $sid = $this->sucursalActivaDashboard($request);
        $desde = $request->get('tpDesde', now()->subMonth()->toDateString());
        $hasta = $request->get('tpHasta', now()->toDateString());
        $metrica = $request->get('tpMet', 'unidades');
        [$take, $skip] = $this->paginacion($request);
        $orderCol = $metrica === 'monto' ? 'total_monto' : 'total_vendido';

        $q = DB::table('ventadetalles')->join('ventas', 'ventas.id', '=', 'ventadetalles.venta_id')->join('productos', 'productos.id', '=', 'ventadetalles.producto_id')
            ->leftJoin('marcas', 'productos.marca_id', '=', 'marcas.id')
            ->where('ventas.estado', 'VALIDO')->where('ventadetalles.estado', 'VALIDO')->whereBetween('ventas.fecha', [$desde, $hasta])
            ->where('ventas.sucursal_id', $sid)
            ->select('productos.codigo', 'productos.descripcion', DB::raw("COALESCE(marcas.nombre, '-') as marca"), DB::raw('SUM(ventadetalles.cantidad) as total_vendido'), DB::raw('SUM(ventadetalles.cantidad * ventadetalles.costo) as total_monto'))
            ->groupBy('productos.id', 'productos.codigo', 'productos.descripcion', DB::raw("COALESCE(marcas.nombre, '-')"));

        $total = $q->getCountForPagination();
        $data = (clone $q)->orderByDesc($orderCol)->skip($skip)->take($take)->get();
        return response()->json(['total' => $total, 'data' => $data]);
    }

    /**
     * Sucursal activa para el inicio. SIEMPRE > 0 (nunca global). Cualquier rol puede
     * verla, pero un no-ADMIN solo una sucursal a la que tenga acceso (se valida contra
     * `accesos`); ADMIN puede mirar cualquiera. Default: la sucursal activa del usuario.
     */
    private function sucursalActivaDashboard(Request $request): int
    {
        $sid = (int) $request->get('sucursal', 0);
        if ($sid <= 0) $sid = (int) Auth::user()->sucursal_id;
        if ($sid > 0 && !Auth::user()->effectiveRoleIs('ADMIN')) {
            $tieneAcceso = DB::table('accesos')
                ->where('user_id', Auth::id())
                ->where('sucursal_id', $sid)
                ->where('estado', 'ON')
                ->exists();
            if (!$tieneAcceso) {
                abort(403, 'No tiene acceso a la sucursal solicitada.');
            }
        }
        return max(1, $sid);
    }

    public function topClientes(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid = $this->validarAccesoSucursal((int) $request->get('tcSucursal', 0));
        $desde = $request->get('tcDesde', now()->subMonth()->toDateString());
        $hasta = $request->get('tcHasta', now()->toDateString());
        $metrica = $request->get('tcMet', 'monto');
        [$take, $skip] = $this->paginacion($request);
        $orderCol = $metrica === 'ventas' ? 'ventas' : 'monto';

        // Base reutilizable: ventas VALIDAS del período/sucursal.
        $base = DB::table('ventas')->join('cuentas', 'ventas.cuenta_id', '=', 'cuentas.id')
            ->where('ventas.estado', 'VALIDO')->whereBetween('ventas.fecha', [$desde, $hasta])
            ->when($sid > 0, fn($q) => $q->where('ventas.sucursal_id', $sid));

        // Ranking de clientes REALES (excluye la cuenta de mostrador).
        $q = (clone $base)
            ->where('cuentas.id', '!=', self::CUENTA_MOSTRADOR)
            ->select(
                'cuentas.nombre as cliente',
                DB::raw('COUNT(*) as ventas'),
                DB::raw('SUM(ventas.total) as monto'),
                DB::raw('IF(COUNT(*) > 0, SUM(ventas.total)/COUNT(*), 0) as ticket')
            )
            ->groupBy('cuentas.id', 'cuentas.nombre');

        $total = $q->getCountForPagination();
        $data = (clone $q)->orderByDesc($orderCol)->skip($skip)->take($take)->get();

        // Total del mostrador (ventas sin nombre) aparte, para no perder el dato.
        $mostrador = (clone $base)->where('cuentas.id', self::CUENTA_MOSTRADOR)
            ->selectRaw('COUNT(*) as ventas, COALESCE(SUM(ventas.total),0) as monto')->first();

        return response()->json([
            'total' => $total,
            'data' => $data,
            'mostrador' => ['ventas' => (int) ($mostrador->ventas ?? 0), 'monto' => (float) ($mostrador->monto ?? 0)],
        ]);
    }

    // ── Exportaciones CSV ─────────────────────────────────────────────────────

    /**
     * Exporta la tabla de rotación a CSV con misma lógica de agrupación que rotacion().
     */
    public function exportarRotacion(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid   = $this->validarAccesoSucursal((int) $request->get('rotSucursal', Auth::user()->sucursal_id));
        $desde = $request->get('rotDesde', now()->subMonths(3)->toDateString());
        $hasta = $request->get('rotHasta', now()->toDateString());
        $corte = $request->get('rotCorte', now()->toDateString());

        // Agrupado por (compra_id, producto_id) — igual que rotacion()
        $items = DB::table('compradetalles')
            ->join('compras', 'compras.id', '=', 'compradetalles.compra_id')
            ->join('productos', 'productos.id', '=', 'compradetalles.producto_id')
            ->join('cuentas', 'compras.cuenta_id', '=', 'cuentas.id')
            ->join('sucursals', 'compras.sucursal_id', '=', 'sucursals.id')
            ->where('compras.estado', 'VALIDO')->where('compradetalles.estado', 'VALIDO')
            ->whereBetween('compras.fecha', [$desde, $hasta])
            ->when($sid > 0, fn($q) => $q->where('compras.sucursal_id', $sid))
            ->select(
                'compras.id as compra_id',
                DB::raw('MAX(compras.fecha) as compra_fecha'),
                DB::raw('MAX(sucursals.nombre) as sucursal_nombre'),
                DB::raw('MAX(cuentas.nombre) as proveedor_nombre'),
                DB::raw('MAX(compradetalles.codigo) as codigo'),
                DB::raw('SUM(compradetalles.cantidad) as cantidad_comprada'),
                DB::raw('ROUND(SUM(compradetalles.cantidad * compradetalles.costo) / SUM(compradetalles.cantidad), 2) as costo_unitario'),
                DB::raw('SUM(compradetalles.cantidad * compradetalles.costo) as costo_total'),
                'compradetalles.producto_id'
            )
            ->groupBy('compradetalles.producto_id', 'compras.id')
            ->orderByDesc('compras.id')->get();

        $headers = ['N° Compra', 'Fecha', 'Sucursal', 'Proveedor', 'Código', 'Unid. compradas', 'Unid. vendidas', '% Rotación', 'Costo unit.', 'Costo total', 'Utilidad', 'Días'];

        $pids = $items->pluck('producto_id')->unique()->toArray();
        $tracking = $this->calcularTrackingFIFO($pids, $corte, $sid);

        $rows = $items->map(function ($item) use ($corte, $tracking) {
            $k = $item->compra_id . '-' . $item->producto_id;
            $ventas = (float) ($tracking[$k]['vendidos'] ?? 0);
            $utilidad = (float) ($tracking[$k]['utilidad'] ?? 0);
            $pct = $item->cantidad_comprada > 0 ? round(($ventas / $item->cantidad_comprada) * 100) : 0;

            return [
                '#' . $item->compra_id,
                \Carbon\Carbon::parse($item->compra_fecha)->format('d/m/Y'),
                $item->sucursal_nombre,
                $item->proveedor_nombre,
                $item->codigo,
                (int) $item->cantidad_comprada,
                (int) $ventas,
                $pct . '%',
                number_format($item->costo_unitario, 2),
                number_format($item->costo_total, 2),
                number_format($utilidad, 2),
                (int) \Carbon\Carbon::parse($item->compra_fecha)->diffInDays($corte),
            ];
        });

        return $this->streamCsv('rotacion_inventario', $headers, $rows);
    }

    public function exportarVentasPeriodo(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid   = $this->validarAccesoSucursal((int) $request->get('vpSucursal', 0));
        $desde = $request->get('vpDesde', now()->subMonth()->toDateString());
        $hasta = $request->get('vpHasta', now()->toDateString());
        $gran  = $request->get('vpGran', 'month');
        if (!in_array($gran, ['day', 'week', 'month'])) $gran = 'month';

        $label = match($gran) {
            'week'  => "DATE_FORMAT(fecha,'%x-W%v')",
            'month' => "DATE_FORMAT(fecha,'%Y-%m')",
            default => "DATE(fecha)",
        };

        $rows = DB::table('ventas')->where('estado', 'VALIDO')->whereBetween('fecha', [$desde, $hasta])
            ->when($sid > 0, fn($q) => $q->where('sucursal_id', $sid))
            ->select(DB::raw("{$label} as dia"), DB::raw('COUNT(*) as ventas'), DB::raw('SUM(total) as total'))
            ->groupBy(DB::raw($label))->orderBy('dia')->get()
            ->map(fn($r) => [$r->dia, $r->ventas, number_format($r->total, 2)]);

        return $this->streamCsv('ventas_periodo', ['Período', 'N° Ventas', 'Total Bs.'], $rows);
    }

    public function exportarTopProductos(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid    = $this->validarAccesoSucursal((int) $request->get('tpSucursal', 0));
        $desde  = $request->get('tpDesde', now()->subMonth()->toDateString());
        $hasta  = $request->get('tpHasta', now()->toDateString());
        $metrica = $request->get('tpMet', 'unidades');
        $orderCol = $metrica === 'monto' ? 'total_monto' : 'total_vendido';

        $rows = DB::table('ventadetalles')->join('ventas', 'ventas.id', '=', 'ventadetalles.venta_id')
            ->join('productos', 'productos.id', '=', 'ventadetalles.producto_id')
            ->leftJoin('marcas', 'productos.marca_id', '=', 'marcas.id')
            ->where('ventas.estado', 'VALIDO')->where('ventadetalles.estado', 'VALIDO')
            ->whereBetween('ventas.fecha', [$desde, $hasta])
            ->when($sid > 0, fn($q) => $q->where('ventas.sucursal_id', $sid))
            ->select('productos.codigo', 'productos.descripcion', DB::raw("COALESCE(marcas.nombre, '-') as marca"),
                DB::raw('SUM(ventadetalles.cantidad) as total_vendido'),
                DB::raw('SUM(ventadetalles.cantidad * ventadetalles.costo) as total_monto'))
            ->groupBy('productos.id', 'productos.codigo', 'productos.descripcion', DB::raw("COALESCE(marcas.nombre, '-')"))
            ->orderByDesc($orderCol)->get()
            ->map(fn($r) => [$r->codigo, $r->descripcion, $r->marca, $r->total_vendido, number_format($r->total_monto, 2)]);

        return $this->streamCsv('top_productos', ['Código', 'Descripción', 'Marca', 'Unidades vendidas', 'Monto Bs.'], $rows);
    }

    public function exportarTopClientes(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid    = $this->validarAccesoSucursal((int) $request->get('tcSucursal', 0));
        $desde  = $request->get('tcDesde', now()->subMonth()->toDateString());
        $hasta  = $request->get('tcHasta', now()->toDateString());
        $metrica = $request->get('tcMet', 'monto');
        $orderCol = $metrica === 'ventas' ? 'ventas' : 'monto';

        $rows = DB::table('ventas')->join('cuentas', 'ventas.cuenta_id', '=', 'cuentas.id')
            ->where('ventas.estado', 'VALIDO')->whereBetween('ventas.fecha', [$desde, $hasta])
            ->where('cuentas.id', '!=', self::CUENTA_MOSTRADOR)   // excluir mostrador, igual que el ranking
            ->when($sid > 0, fn($q) => $q->where('ventas.sucursal_id', $sid))
            ->select(
                'cuentas.nombre as cliente',
                DB::raw('COUNT(*) as ventas'),
                DB::raw('SUM(ventas.total) as monto'),
                DB::raw('IF(COUNT(*) > 0, SUM(ventas.total)/COUNT(*), 0) as ticket')
            )
            ->groupBy('cuentas.id', 'cuentas.nombre')
            ->orderByDesc($orderCol)->get()
            ->map(fn($r) => [$r->cliente, $r->ventas, number_format($r->monto, 2), number_format($r->ticket, 2)]);

        // Encabezados: 4 columnas para que coincidan con las 4 de cada fila (antes faltaba "Ticket").
        return $this->streamCsv('top_clientes', ['Cliente', 'N° Ventas', 'Monto Bs.', 'Ticket prom. Bs.'], $rows);
    }

    // ── Detalle de rotación por compra individual ──────────────────────────
    public function rotacionDetalle(Request $request, int $compraId)
    {
        $this->autorizarEstadisticas();
        $corte = $request->get('fecha_corte', now()->toDateString());
        // El detalle respeta el MISMO filtro de sucursal que la lista (0 = toda la red),
        // para que los números del modal coincidan con la fila de la que se abrió.
        $sid   = $this->validarAccesoSucursal((int) $request->get('rotSucursal', 0));

        $compra = DB::table('compras')
            ->join('cuentas', 'compras.cuenta_id', '=', 'cuentas.id')
            ->where('compras.id', $compraId)
            ->select('compras.id', 'compras.fecha', 'compras.sucursal_id', 'cuentas.nombre as proveedor')
            ->first();

        if (!$compra) return response()->json(['error' => 'Compra no encontrada.'], 404);

        $detalles = DB::table('compradetalles')
            ->join('productos', 'compradetalles.producto_id', '=', 'productos.id')
            ->leftJoin('marcas', 'productos.marca_id', '=', 'marcas.id')
            ->where('compradetalles.compra_id', $compraId)
            ->where('compradetalles.estado', 'VALIDO')
            ->select('compradetalles.producto_id', 'productos.codigo', 'productos.descripcion',
                     DB::raw("COALESCE(marcas.nombre,'-') as marca"),
                     'compradetalles.cantidad', 'compradetalles.costo')
            ->get();

        if ($detalles->isEmpty()) {
            return response()->json(['compra' => $compra, 'items' => []]);
        }

        $pids = $detalles->pluck('producto_id')->unique()->all();

        // Reutiliza el MISMO motor FIFO que la lista de rotación, para que el detalle y la
        // fila de la lista siempre coincidan. Incluye devoluciones de compra (cola) y de
        // venta (LIFO), que la versión anterior de este método ignoraba (bug de inconsistencia).
        $tracking = $this->calcularTrackingFIFO($pids, $corte, $sid);

        $items = $detalles->map(function ($d) use ($tracking, $compraId) {
            $t   = $tracking[$compraId . '-' . $d->producto_id]
                   ?? ['vendidos' => 0, 'utilidad' => 0, 'primera_venta' => null, 'ultima_venta' => null];
            $rot = $d->cantidad > 0 ? round(($t['vendidos'] / $d->cantidad) * 100, 2) : 0;
            return [
                'id' => $d->producto_id, 'codigo' => $d->codigo, 'descripcion' => $d->descripcion,
                'marca' => $d->marca, 'cantidad' => (float)$d->cantidad, 'costo' => (float)$d->costo,
                'vendidos' => round($t['vendidos'], 2), 'rotacion' => $rot,
                'semaforo' => $rot <= 0 ? 'gris' : ($rot >= 70 ? 'verde' : ($rot >= 30 ? 'amarillo' : 'rojo')),
                'utilidad' => round($t['utilidad'], 2),
                'primera_venta' => $t['primera_venta'], 'ultima_venta' => $t['ultima_venta'],
            ];
        });

        return response()->json(['compra' => $compra, 'items' => $items]);
    }

    // ── Rotación por sucursal (turnover real, considera traslados) ──────────

    /**
     * Rotación de inventario POR SUCURSAL. A diferencia de la rotación por compra
     * (compra-céntrica, cuya lente natural es global), responde la pregunta de la
     * sucursal: de TODO lo que ENTRÓ a la sucursal en el período (comprado localmente
     * + recibido por traslados, neto de devoluciones), ¿cuánto se vendió? Maneja los
     * traslados por construcción, sin rastrear lotes.
     */
    public function rotacionSucursal(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid   = $this->validarAccesoSucursal((int) $request->get('rsSucursal', 0));
        $desde = $request->get('rsDesde', now()->subMonths(3)->toDateString());
        $hasta = $request->get('rsHasta', now()->toDateString());

        if ($sid <= 0) {
            return response()->json(['error' => 'Seleccioná una sucursal para ver su rotación.', 'resumen' => null, 'data' => []], 422);
        }

        return response()->json($this->calcularRotacionSucursal($sid, $desde, $hasta));
    }

    public function exportarRotacionSucursal(Request $request)
    {
        $this->autorizarEstadisticas();
        $sid   = $this->validarAccesoSucursal((int) $request->get('rsSucursal', 0));
        $desde = $request->get('rsDesde', now()->subMonths(3)->toDateString());
        $hasta = $request->get('rsHasta', now()->toDateString());
        if ($sid <= 0) abort(422, 'Seleccioná una sucursal para exportar su rotación.');

        $res = $this->calcularRotacionSucursal($sid, $desde, $hasta);
        $headers = ['Código', 'Descripción', 'Marca', 'Comprado', 'Recibido', 'Despachado', 'Disponible', 'Vendido', '% Rotación', 'Utilidad Bs.'];
        $rows = collect($res['data'])->map(fn($r) => [
            $r['codigo'], $r['descripcion'], $r['marca'], $r['comprado'], $r['recibido'], $r['despachado'],
            $r['disponible'], $r['vendido'], $r['rotacion'] . '%', number_format($r['utilidad'], 2),
        ]);
        return $this->streamCsv('rotacion_sucursal', $headers, $rows);
    }

    /**
     * Núcleo de la rotación por sucursal. Direcciones verificadas en EnvioController:
     *   envios.sucursal_id = sucursal ORIGEN (despacha) · envios.cuenta_id = DESTINO (recibe)
     *   devenvios.sucursal_id = origen del envío devuelto.
     * Costo (COGS): ventadetalles.p_comp es el costo de compra capturado al vender
     *   (ventadetalles.costo es el PRECIO de venta, no el costo — ver VentaController).
     */
    private function calcularRotacionSucursal(int $sid, string $desde, string $hasta): array
    {
        $iniIso = $desde . ' 00:00:00';
        $finIso = $hasta . ' 23:59:59';
        $nombre = DB::table('sucursals')->where('id', $sid)->value('nombre');

        // Entradas: comprado localmente (neto devolución de compra) + recibido por traslados (destino, neto devolución de envío)
        $comprado = DB::table('compradetalles')->join('compras', 'compradetalles.compra_id', '=', 'compras.id')
            ->where('compras.sucursal_id', $sid)->where('compras.estado', 'VALIDO')->where('compradetalles.estado', 'VALIDO')
            ->whereBetween('compras.fecha', [$desde, $hasta])
            ->groupBy('compradetalles.producto_id')
            ->selectRaw('compradetalles.producto_id as pid, SUM(compradetalles.cantidad) as t')->pluck('t', 'pid');

        $devCompra = DB::table('devcompras')->where('sucursal_id', $sid)->where('estado', 'ON')
            ->whereBetween('created_at', [$iniIso, $finIso])
            ->groupBy('producto_id')->selectRaw('producto_id as pid, SUM(cantidad) as t')->pluck('t', 'pid');

        $recibido = DB::table('enviodetalles')->join('envios', 'enviodetalles.envio_id', '=', 'envios.id')
            ->where('envios.cuenta_id', $sid)->where('envios.estado', 'RECIBIDO')->where('enviodetalles.estado', 'VALIDO')
            ->whereBetween('envios.fecha', [$desde, $hasta])
            ->groupBy('enviodetalles.producto_id')
            ->selectRaw('enviodetalles.producto_id as pid, SUM(enviodetalles.cantidad) as t')->pluck('t', 'pid');

        $devRecibido = DB::table('devenvios')->join('envios', 'devenvios.envio_id', '=', 'envios.id')
            ->where('envios.cuenta_id', $sid)->where('devenvios.estado', 'ON')
            ->whereBetween('devenvios.created_at', [$iniIso, $finIso])
            ->groupBy('devenvios.producto_id')
            ->selectRaw('devenvios.producto_id as pid, SUM(devenvios.cantidad) as t')->pluck('t', 'pid');

        // Salida no-venta: despachado a otras sucursales (origen, neto de lo devuelto a su origen)
        $despachado = DB::table('enviodetalles')->join('envios', 'enviodetalles.envio_id', '=', 'envios.id')
            ->where('envios.sucursal_id', $sid)->whereIn('envios.estado', ['ENVIADO', 'RECIBIDO'])->where('enviodetalles.estado', 'VALIDO')
            ->whereBetween('envios.fecha', [$desde, $hasta])
            ->groupBy('enviodetalles.producto_id')
            ->selectRaw('enviodetalles.producto_id as pid, SUM(enviodetalles.cantidad) as t')->pluck('t', 'pid');

        $devDespachado = DB::table('devenvios')->where('sucursal_id', $sid)->where('estado', 'ON')
            ->whereBetween('created_at', [$iniIso, $finIso])
            ->groupBy('producto_id')->selectRaw('producto_id as pid, SUM(cantidad) as t')->pluck('t', 'pid');

        // Venta (neto devolución) con ingreso y COGS (p_comp = costo de compra capturado)
        $ventas = DB::table('ventadetalles')->join('ventas', 'ventadetalles.venta_id', '=', 'ventas.id')
            ->where('ventas.sucursal_id', $sid)->where('ventas.estado', 'VALIDO')->where('ventadetalles.estado', 'VALIDO')
            ->whereBetween('ventas.fecha', [$desde, $hasta])
            ->groupBy('ventadetalles.producto_id')
            ->selectRaw('ventadetalles.producto_id as pid, SUM(ventadetalles.cantidad) as vendido, SUM(ventadetalles.subtotal) as ingreso, SUM(ventadetalles.cantidad * ventadetalles.p_comp) as cogs')
            ->get()->keyBy('pid');

        // Devoluciones de venta del período, neteadas POR RENGLÓN (exacto, no prorrateo):
        //   - cantidad e ingreso (refund) salen directo de devventas (total = precio_venta × cant).
        //   - el COGS de lo devuelto se recupera con EXACTITUD uniendo devventas.registro →
        //     ventadetalles.id (el renglón vendido original) y tomando su p_comp real. Así la
        //     utilidad neta usa el costo de compra exacto de las unidades devueltas y NO un
        //     promedio del período (que diverge cuando el producto se vendió en lotes con
        //     costos/precios distintos). COALESCE(p_comp,0): si una devolución legacy no enlaza
        //     a su renglón, no se baja COGS por ella (utilidad conservadora, nunca inflada).
        $devVenta = DB::table('devventas')
            ->leftJoin('ventadetalles', 'ventadetalles.id', '=', 'devventas.registro')
            ->where('devventas.sucursal_id', $sid)->where('devventas.estado', 'ON')
            ->whereBetween('devventas.created_at', [$iniIso, $finIso])
            ->groupBy('devventas.producto_id')
            ->selectRaw('devventas.producto_id as pid, SUM(devventas.cantidad) as qty, SUM(devventas.total) as ing_dev, SUM(devventas.cantidad * COALESCE(ventadetalles.p_comp, 0)) as cogs_dev')
            ->get()->keyBy('pid');

        $pids = collect()->merge($comprado->keys())->merge($recibido->keys())
            ->merge($despachado->keys())->merge($ventas->keys())
            ->map(fn($x) => (int) $x)->unique()->values()->all();

        if (empty($pids)) {
            return ['resumen' => ['sucursal' => $nombre, 'productos' => 0, 'entrada_total' => 0, 'vendido_total' => 0, 'rotacion_promedio' => 0, 'utilidad_total' => 0, 'desde' => $desde, 'hasta' => $hasta], 'data' => []];
        }

        $info = DB::table('productos')->leftJoin('marcas', 'productos.marca_id', '=', 'marcas.id')
            ->whereIn('productos.id', $pids)
            ->select('productos.id', 'productos.codigo', 'productos.descripcion', DB::raw("COALESCE(marcas.nombre,'-') as marca"))
            ->get()->keyBy('id');

        $rows = []; $totEntrada = 0; $totVendido = 0; $totUtil = 0; $totDisp = 0;
        foreach ($pids as $pid) {
            $comp = max(0, (float)($comprado[$pid] ?? 0) - (float)($devCompra[$pid] ?? 0));
            $reci = max(0, (float)($recibido[$pid] ?? 0) - (float)($devRecibido[$pid] ?? 0));
            $desp = max(0, (float)($despachado[$pid] ?? 0) - (float)($devDespachado[$pid] ?? 0));
            $v       = $ventas->get($pid);
            $dev     = $devVenta->get($pid);
            $vendBru = (float)($v->vendido ?? 0);                                  // vendido BRUTO (antes de devoluciones)
            $vend    = max(0, $vendBru - (float)($dev->qty ?? 0));                 // vendido NETO de devoluciones
            $ing     = (float)($v->ingreso ?? 0);
            $cogs    = (float)($v->cogs ?? 0);

            $entrada = $comp + $reci;
            if ($entrada <= 0) continue;   // el reporte analiza lo que ENTRÓ a la sucursal en el período
            $disponible = max(0, $entrada - $desp);
            $rot  = $disponible > 0 ? round(min(100, ($vend / $disponible) * 100), 1) : 0;

            // Utilidad NETA de devoluciones, EXACTA por renglón: al ingreso y al COGS brutos
            // (SUM de los renglones VALIDO) se les resta el ingreso y el COGS reales de lo
            // devuelto (ver query $devVenta). No se prorratea por margen promedio, así que el
            // resultado es correcto aunque el producto se haya vendido en lotes con costos o
            // precios distintos. max(0,...) evita negativos por desfases de período; $util SÍ
            // puede quedar negativo (vender bajo costo es una pérdida real, no un error).
            $ingNeto  = max(0.0, $ing  - (float)($dev->ing_dev ?? 0));
            $cogsNeto = max(0.0, $cogs - (float)($dev->cogs_dev ?? 0));
            $util     = round($ingNeto - $cogsNeto, 4);
            $p = $info->get($pid);

            $rows[] = [
                'producto_id' => $pid, 'codigo' => $p->codigo ?? '-', 'descripcion' => $p->descripcion ?? '-', 'marca' => $p->marca ?? '-',
                'comprado' => round($comp, 2), 'recibido' => round($reci, 2), 'despachado' => round($desp, 2),
                'entrada' => round($entrada, 2), 'disponible' => round($disponible, 2), 'vendido' => round($vend, 2),
                'rotacion' => $rot, 'semaforo' => $rot <= 0 ? 'gris' : ($rot >= 70 ? 'verde' : ($rot >= 30 ? 'amarillo' : 'rojo')),
                'utilidad' => round($util, 2),
            ];
            $totEntrada += $entrada; $totVendido += $vend; $totUtil += $util; $totDisp += $disponible;
        }

        usort($rows, fn($a, $b) => $a['rotacion'] <=> $b['rotacion']);   // peor rotación primero (lo estancado)

        return [
            'resumen' => [
                'sucursal' => $nombre, 'productos' => count($rows),
                'entrada_total' => round($totEntrada, 2), 'vendido_total' => round($totVendido, 2),
                'rotacion_promedio' => $totDisp > 0 ? round(min(100, ($totVendido / $totDisp) * 100), 1) : 0,
                'utilidad_total' => round($totUtil, 2), 'desde' => $desde, 'hasta' => $hasta,
            ],
            'data' => $rows,
        ];
    }

    // ── Helper paginación ──────────────────────────────────────────────────

    /**
     * Normaliza los parámetros de paginación de los rankings (rotacion/topProductos/
     * topClientes) a un rango seguro. SIN esto, un `take` negativo (p.ej. -1) genera
     * `LIMIT -1` en MySQL (error 1064 → 500) y un `skip` negativo genera `OFFSET -1`,
     * dos vectores de fuzzing que rompían el contrato del API. Se clampa:
     *   take ∈ [1, 100]  (1 mínimo útil; 100 tope de cordura, igual que antes)
     *   skip ∈ [0, ∞)    (offset nunca negativo)
     * Valores no numéricos caen a 0 vía cast (int), que se reclampan acá.
     *
     * @return array{0:int,1:int} [take, skip]
     */
    private function paginacion(Request $request): array
    {
        $take = (int) $request->get('take', 25);
        $skip = (int) $request->get('skip', 0);
        $take = max(1, min($take, 100));
        $skip = max(0, $skip);
        return [$take, $skip];
    }

    // ── Helper Access ──────────────────────────────────────────────────────
    private function validarAccesoSucursal(int $sucursalId): int
    {
        // Rol EFECTIVO (respeta simulated_role_id): el simulador debe restringir la sucursal
        // EXACTAMENTE como el rol simulado, no como el rol real del usuario. Ver el gemelo en
        // VentaController::validarAccesoSucursal.
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

    // ── Helper FIFO ───────────────────────────────────────────────────────

    /**
     * Construye el tracking FIFO de ventas por (compra_id, producto_id).
     * Retorna array [ "compraId-productoId" => ['vendidos','utilidad','primera_venta','ultima_venta'] ].
     *
     * @param int $sid  Sucursal a la que se acota el FIFO. 0 = toda la red (global).
     *                  Con $sid > 0 solo se consideran compras/ventas/devoluciones de esa
     *                  sucursal, coherente con el stock por sucursal (stock1..stock5). Así el
     *                  filtro "Sucursal" del reporte alterna entre vista global y por sucursal.
     */
    private function calcularTrackingFIFO(array $pids, string $corte, int $sid = 0): array
    {
        // 1. Cola FIFO de compras hasta la fecha de corte
        $colas = [];
        DB::table('compradetalles')->join('compras', 'compradetalles.compra_id', '=', 'compras.id')
            ->whereIn('compradetalles.producto_id', $pids)
            ->where('compradetalles.estado', 'VALIDO')->where('compras.estado', 'VALIDO')
            ->where('compras.fecha', '<=', $corte)
            ->when($sid > 0, fn($q) => $q->where('compras.sucursal_id', $sid))
            ->orderBy('compras.fecha')->orderBy('compras.id')
            ->select('compradetalles.producto_id', 'compradetalles.compra_id', 'compradetalles.cantidad', 'compradetalles.costo')
            ->each(function ($r) use (&$colas) {
                $colas[$r->producto_id][] = ['compra_id' => $r->compra_id, 'cant_disp' => (float)$r->cantidad, 'costo' => (float)$r->costo];
            });

        // 2. Descontar devoluciones de compra
        DB::table('devcompras')->whereIn('producto_id', $pids)->where('estado', 'ON')
            ->when($sid > 0, fn($q) => $q->where('sucursal_id', $sid))
            ->where('created_at', '<=', $corte . ' 23:59:59')->orderBy('created_at')->orderBy('id')
            ->each(function ($dc) use (&$colas) {
                $this->consumirCola($colas, $dc->producto_id, (float)$dc->cantidad);
            });

        // 3. Consumir cola con ventas (FIFO) → tracking
        $tracking = [];
        DB::table('ventadetalles')->join('ventas', 'ventadetalles.venta_id', '=', 'ventas.id')
            ->whereIn('ventadetalles.producto_id', $pids)
            ->where('ventadetalles.estado', 'VALIDO')->where('ventas.estado', 'VALIDO')
            ->where('ventas.fecha', '<=', $corte)
            ->when($sid > 0, fn($q) => $q->where('ventas.sucursal_id', $sid))
            ->orderBy('ventas.fecha')->orderBy('ventas.id')
            ->select('ventadetalles.producto_id', 'ventadetalles.cantidad', 'ventas.id as vid', 'ventas.fecha',
                     DB::raw('CASE WHEN ventadetalles.cantidad>0 THEN ventadetalles.subtotal/ventadetalles.cantidad ELSE 0 END as p_unit'))
            ->each(function ($v) use (&$colas, &$tracking) {
                $cant = (float)$v->cantidad;
                $pid  = (int)$v->producto_id;
                if (!isset($colas[$pid]) || $cant <= 0) return;
                $cola = &$colas[$pid];
                $rest = $cant;
                while ($rest > 0 && !empty($cola)) {
                    $lote = &$cola[0];
                    $usar = min($rest, $lote['cant_disp']);
                    
                    $k = $lote['compra_id'] . '-' . $pid;
                    if (!isset($tracking[$k])) $tracking[$k] = ['vendidos' => 0, 'utilidad' => 0, 'primera_venta' => null, 'ultima_venta' => null];
                    $tracking[$k]['vendidos'] += $usar;
                    $tracking[$k]['utilidad'] += $usar * ((float)$v->p_unit - $lote['costo']);
                    if (!$tracking[$k]['primera_venta']) $tracking[$k]['primera_venta'] = $v->fecha;
                    $tracking[$k]['ultima_venta'] = $v->fecha;

                    $lote['cant_disp'] -= $usar; $rest -= $usar;
                    if ($lote['cant_disp'] <= 0) array_shift($cola);
                    unset($lote);
                }
                unset($cola);
            });

        // 4. Descontar devoluciones de venta (LIFO: últimas ventas = primeras devueltas)
        $devVentasAgg = DB::table('devventas')
            ->whereIn('producto_id', $pids)->where('estado', 'ON')
            ->when($sid > 0, fn($q) => $q->where('sucursal_id', $sid))
            ->where('created_at', '<=', $corte . ' 23:59:59')
            ->select('producto_id', DB::raw('SUM(cantidad) as total_dev'))
            ->groupBy('producto_id')->pluck('total_dev', 'producto_id');

        foreach ($devVentasAgg as $pid => $totalDev) {
            $totalDev = (float)$totalDev;
            if ($totalDev <= 0) continue;
            // Obtener entradas de tracking para este producto, ordenadas por compra_id DESC (LIFO)
            $entries = [];
            foreach ($tracking as $k => $t) {
                if (str_ends_with($k, '-' . $pid)) {
                    $compraId = (int) explode('-', $k)[0];
                    $entries[] = ['key' => $k, 'compra_id' => $compraId, 'vendidos' => $t['vendidos']];
                }
            }
            // Ordenar por compra_id descendente: las compras más recientes primero (LIFO)
            usort($entries, fn($a, $b) => $b['compra_id'] <=> $a['compra_id']);
            $rest = $totalDev;
            foreach ($entries as $entry) {
                if ($rest <= 0) break;
                $k = $entry['key'];
                $vend = $tracking[$k]['vendidos'];
                if ($vend <= 0) continue;
                $descontar = min($vend, $rest);
                // Revertir la utilidad proporcionalmente al margen promedio del lote
                // (cada lote tiene un único costo de compra). Antes solo se bajaba 'vendidos'
                // y la utilidad quedaba inflada cuando había devoluciones de venta (bug).
                $margenUnit = $tracking[$k]['utilidad'] / $vend;
                $tracking[$k]['vendidos'] -= $descontar;
                $tracking[$k]['utilidad'] -= $descontar * $margenUnit;
                $rest -= $descontar;
            }
        }

        return $tracking;
    }

    private function consumirCola(array &$colas, int $pid, float $cant): void
    {
        if (!isset($colas[$pid])) return;
        $cola = &$colas[$pid];
        $rest = $cant;
        while ($rest > 0 && !empty($cola)) {
            $usar = min($rest, $cola[0]['cant_disp']);
            $cola[0]['cant_disp'] -= $usar; $rest -= $usar;
            if ($cola[0]['cant_disp'] <= 0) array_shift($cola);
        }
        unset($cola);
    }

    private function streamCsv(string $baseName, array $headers, $rows)
    {
        if (!Auth::user()->roles->whereIn('name', ['ADMIN', 'GERENTE'])->isNotEmpty()) {
            abort(403, 'Sin permisos para exportar reportes.');
        }

        $filename = $baseName . '_' . date('Ymd_His') . '.csv';

        $tmp = fopen('php://temp', 'r+');
        fwrite($tmp, "\xEF\xBB\xBF");   // BOM UTF-8 para Excel
        fwrite($tmp, "sep=;\r\n");       // hint separador Excel ES
        fputcsv($tmp, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($tmp, (array) $row, ';');
        }
        rewind($tmp);
        $content = stream_get_contents($tmp);
        fclose($tmp);

        return response($content, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
