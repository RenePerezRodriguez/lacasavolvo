/**
 * @fileoverview Cliente HTTP de La Casa Volvo.
 * Exporta objetos agrupados por módulo de negocio (auth, ventas, compras, etc.).
 * Cada objeto expone métodos que retornan Promises de Axios.
 * El interceptor de request adjunta automáticamente el Bearer token de localStorage.
 *
 * BASE_URL apunta a `http://localhost:8000/api` (artisan serve).
 */
import axios from 'axios';

export const BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

const http = axios.create({ baseURL: BASE_URL, headers: { Accept: 'application/json' } });

http.interceptors.request.use((config) => {
  const token = localStorage.getItem('lcv_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Interceptor de RESPONSE: ante un 401 (token vencido/revocado en otro dispositivo, o sesión
// cerrada por inactividad en el server) limpia el token y vuelve al login, para no quedar en
// una pantalla rota. Excluye /login (un 401 ahí = credenciales inválidas → lo maneja el form,
// sin recargar). Solo recarga si HABÍA token, para no entrar en bucle en el arranque sin sesión.
http.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status;
    const url = error?.config?.url || '';
    if (status === 401 && !url.includes('/login')) {
      const hadToken = localStorage.getItem('lcv_token') || sessionStorage.getItem('lcv_token');
      localStorage.removeItem('lcv_token');
      try { sessionStorage.removeItem('lcv_token'); } catch { /* noop */ }
      if (hadToken) window.location.reload();
    }
    return Promise.reject(error);
  }
);

// ── Auth ──────────────────────────────────────────────────────────────────────
export const auth = {
  login:           (email, password) => http.post('/login', { email, password }),
  logout:          ()                => http.post('/logout'),
  me:              ()                => http.get('/user'),
  switchSucursal:  (sucursal_id)     => http.post('/switch-sucursal', { sucursal_id }),
  // Público (sin token): solo el conteo de sucursales activas para el login.
  publicInfo:      ()                => http.get('/public-info'),
};

// ── Ventas ────────────────────────────────────────────────────────────────────
export const ventas = {
  list:             (params) => http.get('/ventas', { params }),
  kpis:             (params) => http.get('/ventas/kpis', { params }),
  store:            (data)   => http.post('/ventas', data),
  updateEncabezado: (data)   => http.post('/ventas/update-encabezado', data),
  agregarItem:      (data)   => http.post('/ventas/agregar-item', data),
  updateItem:       (data)   => http.post('/ventas/update-item', data),
  deleteItem:       (id)     => http.post(`/ventas/delete-item/${id}`),
  validar:          (id)     => http.post(`/ventas/validar/${id}`),
  devItem:          (data)   => http.post('/ventas/dev-item', data),
  deleteItemDev:    (data)   => http.post('/ventas/delete-item-dev', data),
  cobrar:           (data)   => http.post('/ventas/cobrar', data),
  negativos:        (data)   => http.post('/ventas/negativos', data),
  detalles:         (id)     => http.get(`/ventas/${id}/detalles`),
  devoluciones:     (id)     => http.get(`/ventas/${id}/devoluciones`),
  cobros:           (id)     => http.get(`/ventas/${id}/cobros`),
  destroy:          (id)     => http.delete(`/ventas/${id}`),
};

export const openPdf = async (url) => {
  try {
    const res = await http.get(url, { responseType: 'blob' });
    const blobUrl = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
    window.open(blobUrl, '_blank');
  } catch (e) {
    console.error('Error al descargar PDF:', e);
    alert('No se pudo generar el PDF. Revisa tu conexión o el estado de la venta.');
  }
};

// ── Compras ───────────────────────────────────────────────────────────────────
export const compras = {
  list:             (params) => http.get('/compras', { params }),
  kpis:             ()       => http.get('/compras/kpis'),
  store:            (data)   => http.post('/compras', data),
  updateEncabezado: (data)   => http.post('/compras/update-encabezado', data),
  agregarItem:      (data)   => http.post('/compras/agregar-item', data),
  updateItem:       (data)   => http.post('/compras/update-item', data),
  deleteItem:       (id)     => http.post(`/compras/delete-item/${id}`),
  validar:          (id)     => http.post(`/compras/validar/${id}`),
  devItem:          (data)   => http.post('/compras/dev-item', data),
  deleteItemDev:    (data)   => http.post('/compras/delete-item-dev', data),
  pagar:            (data)   => http.post('/compras/pagar', data),
  detalles:         (id)     => http.get(`/compras/${id}/detalles`),
  devoluciones:     (id)     => http.get(`/compras/${id}/devoluciones`),
  pagos:            (id)     => http.get(`/compras/${id}/pagos`),
  destroy:          (id)     => http.delete(`/compras/${id}`),
  show:             (id)     => http.get(`/compras/${id}`),
};

// ── Caja ──────────────────────────────────────────────────────────────────────
export const caja = {
  kpis:           ()       => http.get('/caja/kpis'),
  movimientos:    (params) => http.get('/caja/movimientos', { params }),
  apertura:       (data)   => http.post('/caja/apertura', data),
  cierre:         (data)   => http.post('/caja/cierre', data),
  ingreso:        (data)   => http.post('/caja/ingreso', data),
  egreso:         (data)   => http.post('/caja/egreso', data),
  updateTranza:   (data)   => http.post('/caja/update-tranza', data),
  deleteTranza:   (data)   => http.post('/caja/delete-tranza', data),
  report:         ()       => http.get('/caja/report', { responseType: 'blob' }),
  historialTranzas:   (params) => http.get('/caja/historial/tranzas',  { params }),
  historialCompras:   (params) => http.get('/caja/historial/compras',  { params }),
  historialVentas:    (params) => http.get('/caja/historial/ventas',   { params }),
  historialEfectivos: (params) => http.get('/caja/historial/efectivos',{ params }),
  tranzas:        (id)     => http.get(`/caja/${id}/tranzas`),
  compras:        (id)     => http.get(`/caja/${id}/compras`),
  ventasCaja:     (id)     => http.get(`/caja/${id}/ventas`),
  aperturas:      (params) => http.get('/caja/aperturas', { params }),
  // Cierres (réplica del legacy "Lista de Cierres" + ojito): lista, detalle, PDF y eliminar (revertir).
  cierres:        (params) => http.get('/caja/cierres', { params }),
  aperturaShow:   (id)     => http.get(`/caja/aperturas/${id}`),
  cierreDetalle:  (id)     => http.get(`/caja/cierres/${id}/detalle`),
  cierrePdf:      (id)     => http.get(`/caja/cierres/${id}/pdf`, { responseType: 'blob' }),
  revertirCierre: (data)   => http.post('/caja/revertir-cierre', data),
};

// ── Cotizaciones ──────────────────────────────────────────────────────────────
export const cotizaciones = {
  list:             (params) => http.get('/cotizaciones', { params }),
  kpis:             ()       => http.get('/cotizaciones/kpis'),
  store:            (data)   => http.post('/cotizaciones', data),
  updateEncabezado: (data)   => http.post('/cotizaciones/update-encabezado', data),
  agregarItem:      (data)   => http.post('/cotizaciones/agregar-item', data),
  updateItem:       (data)   => http.post('/cotizaciones/update-item', data),
  deleteItem:       (id)     => http.post(`/cotizaciones/delete-item/${id}`),
  venta:            (id)     => http.post(`/cotizaciones/${id}/venta`),
  detalles:         (id)     => http.get(`/cotizaciones/${id}/detalles`),
  show:             (id)     => http.get(`/cotizaciones/${id}`),
  destroy:          (id)     => http.delete(`/cotizaciones/${id}`),
};

// ── Pedidos ───────────────────────────────────────────────────────────────────
export const pedidos = {
  list:             (params) => http.get('/pedidos', { params }),
  kpis:             ()       => http.get('/pedidos/kpis'),
  store:            (data)   => http.post('/pedidos', data),
  updateEncabezado: (data)   => http.post('/pedidos/update-encabezado', data),
  agregarItem:      (data)   => http.post('/pedidos/agregar-item', data),
  updateItem:       (data)   => http.post('/pedidos/update-item', data),
  deleteItem:       (id)     => http.post(`/pedidos/delete-item/${id}`),
  validar:          (id)     => http.post(`/pedidos/validar/${id}`),
  detalles:         (id)     => http.get(`/pedidos/${id}/detalles`),
  show:             (id)     => http.get(`/pedidos/${id}`),
  destroy:          (id)     => http.delete(`/pedidos/${id}`),
};

// ── Envíos ────────────────────────────────────────────────────────────────────
export const envios = {
  list:             (params) => http.get('/envios', { params }),
  kpis:             ()       => http.get('/envios/kpis'),
  store:            (data)   => http.post('/envios', data),
  updateEncabezado: (data)   => http.post('/envios/update-encabezado', data),
  agregarItem:      (data)   => http.post('/envios/agregar-item', data),
  updateItem:       (data)   => http.post('/envios/update-item', data),
  deleteItem:       (id)     => http.post(`/envios/delete-item/${id}`),
  enviar:           (id)     => http.post(`/envios/enviar/${id}`),
  recibir:          (id)     => http.post(`/envios/recibir/${id}`),
  devItem:          (data)   => http.post('/envios/dev-item', data),
  deleteItemDev:    (data)   => http.post('/envios/delete-item-dev', data),
  negativos:        (data)   => http.post('/envios/negativos', data),
  detalles:         (id)     => http.get(`/envios/${id}/detalles`),
  devoluciones:     (id)     => http.get(`/envios/${id}/devoluciones`),
  destroy:          (id)     => http.delete(`/envios/${id}`),
  show:             (id)     => http.get(`/envios/${id}`),
};

// ── Productos ─────────────────────────────────────────────────────────────────
export const productos = {
  list:           (params) => http.get('/productos', { params }),
  kpis:           ()       => http.get('/productos/kpis'),
  store:          (data)   => http.post('/productos', data),
  ajustes:        (params) => http.get('/productos/ajustes', { params }),
  ajustePositivo: (data)   => http.post('/productos/ajuste-positivo', data),
  ajusteNegativo: (data)   => http.post('/productos/ajuste-negativo', data),
  ajusteDestroy:  (data)   => http.post('/productos/ajuste-destroy', data),
  quicksearch:    (search) => http.get('/productos/quicksearch', { params: { search } }),
  show:           (id)     => http.get(`/productos/${id}`),
  movimientos:    (id)     => http.get(`/productos/${id}/movimientos`),
  update:         (id, d)  => http.put(`/productos/${id}`, d),
  destroy:        (id)     => http.delete(`/productos/${id}`),
};

// ── Cuentas ───────────────────────────────────────────────────────────────────
export const cuentas = {
  list:     (params)  => http.get('/cuentas', { params }),
  kpis:     ()        => http.get('/cuentas/kpis'),
  store:    (data)    => http.post('/cuentas', data),
  update:   (id, d)   => http.put(`/cuentas/${id}`, d),
  toggle:   (id)      => http.get(`/cuentas/${id}/toggle`),
  compras:  (id)      => http.get(`/cuentas/${id}/compras`),
  ventas:   (id)      => http.get(`/cuentas/${id}/ventas`),
  pagos:    (id)      => http.get(`/cuentas/${id}/pagos`),
  cobros:   (id)      => http.get(`/cuentas/${id}/cobros`),
  show:     (id)      => http.get(`/cuentas/${id}`),
};

// ── Estadísticas ──────────────────────────────────────────────────────────────
export const estadisticas = {
  rotacion:              (params) => http.get('/estadisticas/rotacion', { params }),
  rotacionDetalle:       (id, params) => http.get(`/estadisticas/rotacion-detalle/${id}`, { params }),
  rotacionSucursal:      (params) => http.get('/estadisticas/rotacion-sucursal', { params }),
  ventasPeriodo:         (params) => http.get('/estadisticas/ventas-periodo', { params }),
  topProductos:          (params) => http.get('/estadisticas/top-productos', { params }),
  topClientes:           (params) => http.get('/estadisticas/top-clientes', { params }),
  // Dashboard/inicio: cualquier rol autenticado, acotado a la sucursal activa.
  dashVentasPeriodo:     (params) => http.get('/dashboard/ventas-periodo', { params }),
  dashTopProductos:      (params) => http.get('/dashboard/top-productos', { params }),
  exportarRotacion:      (params) => http.get('/estadisticas/exportar-rotacion',       { params, responseType: 'arraybuffer', headers: { Accept: 'text/csv,*/*' } }),
  exportarRotacionSucursal: (params) => http.get('/estadisticas/exportar-rotacion-sucursal', { params, responseType: 'arraybuffer', headers: { Accept: 'text/csv,*/*' } }),
  exportarVentasPeriodo: (params) => http.get('/estadisticas/exportar-ventas-periodo', { params, responseType: 'arraybuffer', headers: { Accept: 'text/csv,*/*' } }),
  exportarTopProductos:  (params) => http.get('/estadisticas/exportar-top-productos',  { params, responseType: 'arraybuffer', headers: { Accept: 'text/csv,*/*' } }),
  exportarTopClientes:   (params) => http.get('/estadisticas/exportar-top-clientes',   { params, responseType: 'arraybuffer', headers: { Accept: 'text/csv,*/*' } }),
};

// ── Admin ─────────────────────────────────────────────────────────────────────
export const sucursales = {
  list:    ()        => http.get('/sucursales'),
  store:   (data)    => http.post('/sucursales', data),
  update:  (id, d)   => http.put(`/sucursales/${id}`, d),
  toggle:  (id)      => http.get(`/sucursales/${id}/toggle`),
  destroy: (id)      => http.delete(`/sucursales/${id}`),
};

export const users = {
  list:    (params)           => http.get('/users', { params }),
  store:   (data)             => http.post('/users', data),
  update:  (id, d)            => http.put(`/users/${id}`, d),
  destroy: (id)               => http.delete(`/users/${id}`),
  acces:   (uid, sid, estado) => http.get(`/users/${uid}/${sid}/${estado}/acces`),
  simulate: (roleId)           => http.post('/users/simulate-role', { role_id: roleId }),
  stopSimulate: ()             => http.post('/users/stop-simulate'),
};

export const roles = {
  list:    ()        => http.get('/roles'),
  store:   (data)    => http.post('/roles', data),
  update:  (id, d)   => http.put(`/roles/${id}`, d),
  destroy: (id)      => http.delete(`/roles/${id}`),
};

export const marcas = {
  list:    ()        => http.get('/marcas'),
  store:   (data)    => http.post('/marcas', data),
  update:  (id, d)   => http.put(`/marcas/${id}`, d),
  toggle:  (id)      => http.get(`/marcas/${id}/toggle`),
};

export const industrias = {
  list:    ()        => http.get('/industrias'),
  store:   (data)    => http.post('/industrias', data),
  update:  (id, d)   => http.put(`/industrias/${id}`, d),
  toggle:  (id)      => http.get(`/industrias/${id}/toggle`),
};

export const medios = {
  list:    ()        => http.get('/medios'),
  store:   (data)    => http.post('/medios', data),
  update:  (id, d)   => http.put(`/medios/${id}`, d),
  toggle:  (id)      => http.get(`/medios/${id}/toggle`),
  // Los catálogos se activan/desactivan con toggle ON/OFF; no hay DELETE en el backend.
};

export const empresas = {
  list:    ()        => http.get('/empresas'),
  store:   (data)    => http.post('/empresas', data),
  update:  (id, d)   => http.put(`/empresas/${id}`, d),
  toggle:  (id)      => http.get(`/empresas/${id}/toggle`),
  destroy: (id)      => http.delete(`/empresas/${id}`),
};

export const localidades = {
  list:    ()        => http.get('/localidades'),
  store:   (data)    => http.post('/localidades', data),
  update:  (id, d)   => http.put(`/localidades/${id}`, d),
  toggle:  (id)      => http.get(`/localidades/${id}/toggle`),
  // Los catálogos se activan/desactivan con toggle ON/OFF; no hay DELETE en el backend.
};

export const profile = {
  update: (data) => http.put('/profile', data),
};

export default http;
