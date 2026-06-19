/* ════════════════════════════════════════════════════════════════
   Permisos frontend — toda la lógica viene de la BD via /api/user
   y /api/roles. No hay permisos hardcodeados.
   ════════════════════════════════════════════════════════════════ */

// Permiso requerido por cada ruta / ítem de nav
// Usa permisos granulares: si el usuario tiene ventas.index, puede ver el módulo.
// null = siempre accesible (dashboard, perfil)
const ROUTE_PERMISSION = {
  'dashboard':      null,
  'estadisticas':   'estadisticas.index',
  'ventas':         'ventas.index',
  'venta-nueva':    'ventas.create',
  'venta-detail':   'ventas.show',
  'cotizaciones':   'cotizaciones.index',
  'pedidos':        'pedidos.index',
  'pedido-detail':  'pedidos.show',
  'compras':        'compras.index',
  'compra-detail':  'compras.show',
  'envios':         'envios.index',
  'envio-detail':   'envios.show',
  'productos':      'productos.index',
  'producto-detail':'productos.show',
  'ajustes':        'productos.ajustes',
  'cuentas':        'cuentas.index',
  'cuenta-detail':  'cuentas.show',
  'caja':           'caja.index',
  'caja-vista':     'caja.show',
  'historial-caja': 'caja.show',
  'marcas':         'marcas.index',
  'industrias':     'industrias.index',
  'medios':         'medios.index',
  'empresas':       'empresas.index',
  'localidades':    'localidades.index',
  'sucursales':     'sucursales.index',
  'usuarios':       'users.index',
  'roles':          'roles.index',
  'perfil':         null,
};

function hasPerm(perms, isAdmin, permission) {
  if (!permission) return true;
  if (isAdmin) return true;
  if (!perms) return true; // sin datos aún: no bloquear (el backend sí lo hará)
  return perms.includes(permission);
}

// Rutas que solo ADMIN puede ver (fiel al legacy @role('admin'))
const ADMIN_ONLY = ['sucursales'];
const GERENTE_OR_ADMIN = ['sucursales', 'estadisticas'];

export function canAccess(routeName, perms, isAdmin, userRole) {
  const effectiveRole = userRole || '';
  if (GERENTE_OR_ADMIN.includes(routeName) && (isAdmin || effectiveRole === 'GERENTE')) return true;
  if (ADMIN_ONLY.includes(routeName) && !isAdmin) return false;
  const perm = ROUTE_PERMISSION[routeName];
  if (perm === undefined) return true; // ruta desconocida: no bloquear en frontend
  return hasPerm(perms, isAdmin, perm);
}

export function filterNav(sections, perms, isAdmin, userRole) {
  if (isAdmin) return sections;
  // La visibilidad del ítem debe coincidir EXACTAMENTE con el acceso real: delegamos en
  // canAccess para no duplicar (ni desincronizar) la lógica. Antes filterNav tenía su
  // propia versión: un rol con `sucursales.index` (ej. VENDEDOR) veía "Sucursales" en el
  // menú aunque canAccess —que sí respeta ADMIN_ONLY— rebotaba la navegación al Dashboard.
  return sections
    .map(s => ({
      ...s,
      items: s.items.filter(it => canAccess(it.id, perms, isAdmin, userRole)),
    }))
    .filter(s => s.items.length > 0);
}

export function canQuickAction(targetRoute, perms, isAdmin) {
  return canAccess(targetRoute, perms, isAdmin);
}
