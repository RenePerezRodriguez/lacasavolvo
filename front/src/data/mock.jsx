/* ═══════════════════════════════════════════════════════════════
   La Casa Volvo — sample data (alineado al esquema real de la BD)
   ═══════════════════════════════════════════════════════════════ */

/* ── SUCURSALES (5 — la BD soporta stock1..stock5) ────────── */
const SUCURSALES = [
  { id: 1, nombre: "CENTRAL",  alias: "CEN", color: "#182642" },
  { id: 2, nombre: "SUR",      alias: "SUR", color: "#0E5E3A" },
  { id: 3, nombre: "ESTE",     alias: "EST", color: "#4A1D8A" },
  { id: 4, nombre: "OESTE",    alias: "OES", color: "#8A3A0C" },
  { id: 5, nombre: "NORTE",    alias: "NTE", color: "#0b7ec2" },
];

/* ── ROLES reales (Spatie Permission, según PermissionsSeeder) ── */
const ROLES = [
  { id: 1, name: "ADMIN",      desc: "Acceso total al sistema (bypass total)" },
  { id: 2, name: "GERENTE",    desc: "Operaciones, finanzas y reportes" },
  { id: 3, name: "VENDEDOR",   desc: "Ventas, cotizaciones, productos, cuentas" },
  { id: 4, name: "CAJERO",     desc: "Caja, ventas, compras, productos" },
  { id: 5, name: "OPERADOR",   desc: "Pedidos, compras, envíos, almacén" },
  { id: 6, name: "SUSPENDIDO", desc: "Sin permisos (acceso bloqueado)" },
];

const USER = {
  id: 1,
  name: "Marcelina Condori",
  initials: "MC",
  role: "GERENTE",
  email: "mcondori@lacasavolvo.bo",
  sucursal_id: 1,
};

/* ── MEDIOS (tabla medios) ── */
const MEDIOS = [
  { id: 1, nombre: "CAMIÓN PROPIO" },
  { id: 2, nombre: "TRANS YARA" },
  { id: 3, nombre: "COOPERATIVA ANDINA" },
  { id: 4, nombre: "ENCOMIENDA" },
  { id: 5, nombre: "FLOTA EBA" },
];

/* ── MARCAS ── */
const MARCAS = [
  { id: 1, nombre: "VOLVO OEM" },
  { id: 2, nombre: "BOSCH" },
  { id: 3, nombre: "SCANIA" },
  { id: 4, nombre: "MERCEDES" },
  { id: 5, nombre: "PHILIPS" },
  { id: 6, nombre: "DONALDSON" },
];

/* ── INDUSTRIAS ── */
const INDUSTRIAS = [
  { id: 1, nombre: "Automotriz" },
  { id: 2, nombre: "Camiones pesados" },
  { id: 3, nombre: "Maquinaria" },
  { id: 4, nombre: "Logística" },
];

/* ── VENTAS ── */
const VENTAS = [
  { id: 1284, fecha: "12/05/2026", tipo: "CONTADO", cliente: "Toyosa S.A.",              total: 4280.50, pago: "PAGADO",     estado: "VALIDO",   saldo: 0 },
  { id: 1283, fecha: "12/05/2026", tipo: "CONTADO", cliente: "Imcruz Bolivia",            total: 1240.00, pago: "PAGADO",     estado: "VALIDO",   saldo: 0 },
  { id: 1282, fecha: "12/05/2026", tipo: "CREDITO", cliente: "Transporte Yara SRL",       total: 9870.75, pago: "POR COBRAR", estado: "VALIDO",   saldo: 9870.75 },
  { id: 1281, fecha: "11/05/2026", tipo: "CONTADO", cliente: "Auto Repuestos Bolívar",    total:  860.00, pago: "PAGADO",     estado: "VALIDO",   saldo: 0 },
  { id: 1280, fecha: "11/05/2026", tipo: "CONTADO", cliente: "Cooperativa Trans-Andes",   total: 2150.25, pago: "PAGADO",     estado: "PROFORMA", saldo: 2150.25 },
  { id: 1279, fecha: "11/05/2026", tipo: "CREDITO", cliente: "Flotas del Chaco S.A.",     total: 15420.00,pago: "POR COBRAR", estado: "VALIDO",   saldo: 15420 },
  { id: 1278, fecha: "10/05/2026", tipo: "CONTADO", cliente: "Mecánica Sucre",            total:  395.50, pago: "PAGADO",     estado: "VALIDO",   saldo: 0 },
  { id: 1277, fecha: "10/05/2026", tipo: "CONTADO", cliente: "Consumidor Final",          total:  120.00, pago: "PAGADO",     estado: "ANULADO",  saldo: 0 },
  { id: 1276, fecha: "10/05/2026", tipo: "CREDITO", cliente: "EBA Transportes",           total: 6240.00, pago: "POR COBRAR", estado: "VALIDO",   saldo: 4140 },
  { id: 1275, fecha: "09/05/2026", tipo: "CONTADO", cliente: "Camiones del Sur",          total: 1875.30, pago: "PAGADO",     estado: "VALIDO",   saldo: 0 },
];

/* ── PRODUCTOS (con stock1..stock5 según la BD) ── */
const PRODUCTOS = [
  { id: 12041, codigo: "VOL-FH-1241",  descripcion: "Filtro de aceite motor D13",       marca_id: 1, marca: "VOLVO OEM",  industria_id: 2, unidad: "Unidad", p_comp: 195.00,  p_norm: 285.00,  p_fact: 320.00, stock1: 24, stock2: 11, stock3: 4, stock4: 0, stock5: 7 },
  { id: 12042, codigo: "VOL-FH-1882",  descripcion: "Disco de freno trasero 410mm",     marca_id: 1, marca: "VOLVO OEM",  industria_id: 2, unidad: "Unidad", p_comp: 845.00,  p_norm: 1240.00, p_fact: 1395.00, stock1: 8,  stock2: 3,  stock3: 0, stock4: 2, stock5: 1 },
  { id: 12043, codigo: "SCN-X3-9921",  descripcion: "Bomba de inyección SCN R-series",  marca_id: 3, marca: "SCANIA",     industria_id: 2, unidad: "Unidad", p_comp: 2940.00, p_norm: 4280.00, p_fact: 4790.00, stock1: 2,  stock2: 0,  stock3: 1, stock4: 0, stock5: 0 },
  { id: 12044, codigo: "MB-AX-4471",   descripcion: "Junta de culata Actros 460",       marca_id: 4, marca: "MERCEDES",   industria_id: 2, unidad: "Unidad", p_comp: 670.00,  p_norm: 980.00,  p_fact: 1090.00, stock1: 15, stock2: 5,  stock3: 2, stock4: 3, stock5: 2 },
  { id: 12045, codigo: "VOL-FM-7782",  descripcion: "Amortiguador delantero FM",        marca_id: 1, marca: "VOLVO OEM",  industria_id: 2, unidad: "Par",    p_comp: 440.00,  p_norm: 645.50,  p_fact: 720.00, stock1: 0,  stock2: 0,  stock3: 0, stock4: 0, stock5: 0 },
  { id: 12046, codigo: "BSH-CK-2210",  descripcion: "Kit de bujías cerámicas x6",       marca_id: 2, marca: "BOSCH",      industria_id: 1, unidad: "Juego",  p_comp: 215.00,  p_norm: 312.00,  p_fact: 350.00, stock1: 42, stock2: 28, stock3: 18, stock4: 12, stock5: 9 },
  { id: 12047, codigo: "VOL-FH-3490",  descripcion: "Sensor MAP turbo D11/D13",         marca_id: 1, marca: "VOLVO OEM",  industria_id: 2, unidad: "Unidad", p_comp: 565.00,  p_norm: 825.00,  p_fact: 930.00, stock1: 6,  stock2: 4,  stock3: 1, stock4: 2, stock5: 3 },
  { id: 12048, codigo: "DON-AF-1145",  descripcion: "Filtro de aire Donaldson P10",     marca_id: 6, marca: "DONALDSON",  industria_id: 2, unidad: "Unidad", p_comp: 128.00,  p_norm: 187.00,  p_fact: 210.00, stock1: 31, stock2: 19, stock3: 8, stock4: 6, stock5: 4 },
  { id: 12049, codigo: "VOL-FH-2245",  descripcion: "Embrague monodisco 430mm",         marca_id: 1, marca: "VOLVO OEM",  industria_id: 2, unidad: "Unidad", p_comp: 2150.00, p_norm: 3140.00, p_fact: 3520.00, stock1: 3,  stock2: 1,  stock3: 0, stock4: 1, stock5: 0 },
  { id: 12050, codigo: "PHI-LX-9912",  descripcion: "Faro halógeno H7 alto rendimiento",marca_id: 5, marca: "PHILIPS",    industria_id: 1, unidad: "Unidad", p_comp: 62.00,   p_norm: 92.00,   p_fact: 110.00, stock1: 56, stock2: 33, stock3: 21, stock4: 14, stock5: 11 },
];

/* helper para sumar stock total */
export function totalStock(p) {
  return (p.stock1||0) + (p.stock2||0) + (p.stock3||0) + (p.stock4||0) + (p.stock5||0);
}

const COTIZACIONES = [
  { id: 542, fecha: "12/05/2026", cliente: "Toyosa S.A.",          items: 8,  total: 12480.00, estado: "VALIDO" },
  { id: 541, fecha: "11/05/2026", cliente: "Flotas del Chaco",     items: 15, total: 32140.50, estado: "VALIDO" },
  { id: 540, fecha: "10/05/2026", cliente: "Cooperativa Andes",    items: 4,  total:  4280.00, estado: "VALIDO" },
  { id: 539, fecha: "10/05/2026", cliente: "Mecánica Sucre",       items: 6,  total:  2150.00, estado: "ANULADO" },
  { id: 538, fecha: "08/05/2026", cliente: "Transporte Yara SRL",  items: 11, total:  9870.75, estado: "VALIDO" },
  { id: 537, fecha: "06/05/2026", cliente: "Imcruz Bolivia",       items: 3,  total:   980.00, estado: "PROFORMA" },
];

const TOP_PRODUCTOS = [
  { codigo: "VOL-FH-1241", descripcion: "Filtro de aceite D13",     uds: 142, importe: 40470 },
  { codigo: "DON-AF-1145", descripcion: "Filtro de aire Donaldson", uds: 98,  importe: 18326 },
  { codigo: "BSH-CK-2210", descripcion: "Kit de bujías cerámicas",  uds: 76,  importe: 23712 },
  { codigo: "VOL-FM-7782", descripcion: "Amortiguador delantero",   uds: 54,  importe: 34857 },
  { codigo: "PHI-LX-9912", descripcion: "Faro halógeno H7",         uds: 48,  importe: 4416 },
];

const POR_COBRAR = [
  { id: 1282, cliente: "Transporte Yara SRL",    fecha: "12/05/2026", saldo:  9870.75, dias: 0 },
  { id: 1279, cliente: "Flotas del Chaco S.A.",  fecha: "11/05/2026", saldo: 15420.00, dias: 1 },
  { id: 1276, cliente: "EBA Transportes",        fecha: "10/05/2026", saldo:  4140.00, dias: 2 },
  { id: 1268, cliente: "Cooperativa Trans-Andes",fecha: "02/05/2026", saldo:  2850.00, dias: 10 },
];

const POR_PAGAR = [
  { id: 882, proveedor: "Volvo Trucks Sudamérica", fecha: "11/05/2026", saldo: 24800, dias: 1 },
  { id: 881, proveedor: "Distribuidora Bosch BO",  fecha: "09/05/2026", saldo:  4150, dias: 3 },
  { id: 880, proveedor: "Donaldson Andina",        fecha: "05/05/2026", saldo:  8920, dias: 7 },
];

const CHART_MESES = [
  { m: "Jun",  v: 142000, c: 98000 },
  { m: "Jul",  v: 158000, c: 110500 },
  { m: "Ago",  v: 174500, c: 128000 },
  { m: "Sep",  v: 168000, c: 122000 },
  { m: "Oct",  v: 184500, c: 134000 },
  { m: "Nov",  v: 196000, c: 142000 },
  { m: "Dic",  v: 215000, c: 158000 },
  { m: "Ene",  v: 178000, c: 128500 },
  { m: "Feb",  v: 188500, c: 132000 },
  { m: "Mar",  v: 202000, c: 145000 },
  { m: "Abr",  v: 218500, c: 154500 },
  { m: "May",  v: 156000, c:  98500 },
];

/* ── CUENTAS (BD: id 1..5 son las sucursales = INTERNO; >5 son CLIENTE/PROVEEDOR/CLIE-PROV) ── */
const CLIENTES = [
  { id: 1, nombre: "Sucursal Central",         nit: "1234568791",  tipo: "INTERNO" },
  { id: 2, nombre: "Sucursal Sur",             nit: "1234568792",  tipo: "INTERNO" },
  { id: 3, nombre: "Sucursal Este",            nit: "1234568793",  tipo: "INTERNO" },
  { id: 4, nombre: "Sucursal Oeste",           nit: "1234568794",  tipo: "INTERNO" },
  { id: 5, nombre: "Sucursal Norte",           nit: "1234568795",  tipo: "INTERNO" },
  { id: 6, nombre: "Toyosa S.A.",              nit: "1023456020", tipo: "CLIENTE" },
  { id: 7, nombre: "Imcruz Bolivia",            nit: "1024580032", tipo: "CLIENTE" },
  { id: 8, nombre: "Transporte Yara SRL",       nit: "1099445001", tipo: "CLIENTE" },
  { id: 9, nombre: "Auto Repuestos Bolívar",    nit: "1056782021", tipo: "CLIENTE" },
  { id: 10, nombre: "Cooperativa Trans-Andes",  nit: "1145698008", tipo: "CLIENTE" },
  { id: 11, nombre: "Flotas del Chaco S.A.",    nit: "1156870049", tipo: "CLIE-PROV" },
  { id: 12, nombre: "Mecánica Sucre",           nit: "1078562003", tipo: "CLIENTE" },
  { id: 13, nombre: "Consumidor Final",         nit: "0",          tipo: "CLIENTE" },
];

const CAJA = {
  estado: "ABIERTA",
  apertura: { fecha: "13/05/2026 08:14", monto: 500.00, usuario: "Marcelina Condori" },
  movimientos: [
    { hora: "08:32", tipo: "VENTA",   ref: "#1284", monto:  4280.50, signo: "+" },
    { hora: "09:12", tipo: "VENTA",   ref: "#1283", monto:  1240.00, signo: "+" },
    { hora: "10:04", tipo: "COBRO",   ref: "#1276", monto:  2100.00, signo: "+" },
    { hora: "10:48", tipo: "EGRESO",  ref: "Combustible", monto:   180.00, signo: "-" },
    { hora: "11:25", tipo: "VENTA",   ref: "#1282", monto:  9870.75, signo: "+" },
    { hora: "12:14", tipo: "PAGO",    ref: "Proveedor Bosch", monto: 4150.00, signo: "-" },
    { hora: "13:01", tipo: "VENTA",   ref: "#1281", monto:   860.00, signo: "+" },
  ],
  total_ingresos: 18351.25,
  total_egresos: 4330.00,
};

const ACTIVIDAD = [
  { t: "Hace 4 min",  who: "Carlos R.",      what: "registró la venta",       ref: "#1284",         icon: "fa-cart-shopping", color: "var(--success)" },
  { t: "Hace 12 min", who: "Marcelina C.",   what: "aprobó la cotización",    ref: "#542 — Toyosa", icon: "fa-circle-check",  color: "var(--info)" },
  { t: "Hace 28 min", who: "Sistema",        what: "alerta de stock bajo en", ref: "VOL-FH-1241",   icon: "fa-triangle-exclamation", color: "var(--warning)" },
  { t: "Hace 1 h",    who: "Verónica G.",    what: "abrió caja con",          ref: "Bs 500.00",     icon: "fa-cash-register", color: "var(--success)" },
  { t: "Hace 2 h",    who: "Carlos R.",      what: "envió a Sucursal Sur",    ref: "Envío #214",    icon: "fa-truck",         color: "var(--soft)" },
];

const NAV = [
  { section: "PRINCIPAL", items: [
    { id: "dashboard",      label: "Dashboard",      icon: "fa-house" },
    { id: "estadisticas",   label: "Estadísticas",   icon: "fa-chart-bar" },
  ]},
  { section: "OPERACIONES", items: [
    { id: "ventas",         label: "Ventas",         icon: "fa-cart-shopping" },
    { id: "cotizaciones",   label: "Cotizaciones",   icon: "fa-file-invoice" },
    { id: "compras",        label: "Compras",        icon: "fa-credit-card" },
    { id: "pedidos",        label: "Pedidos",        icon: "fa-clipboard-list" },
    { id: "envios",         label: "Envíos",         icon: "fa-truck-fast" },
  ]},
  { section: "INVENTARIO", items: [
    { id: "productos",      label: "Productos",      icon: "fa-cubes" },
    { id: "ajustes",        label: "Ajustes stock",  icon: "fa-balance-scale" },
    { id: "cuentas",        label: "Cuentas",        icon: "fa-address-book" },
  ]},
  { section: "FINANZAS", items: [
    { id: "caja",           label: "Caja",           icon: "fa-cash-register" },
    { id: "historial-caja", label: "Historial",      icon: "fa-clock-rotate-left" },
  ]},
  { section: "DATOS RAÍZ", items: [
    { id: "marcas",         label: "Marcas",         icon: "fa-tag" },
    { id: "industrias",     label: "Industrias",     icon: "fa-industry" },
    { id: "medios",         label: "Medios",         icon: "fa-truck" },
    { id: "empresas",       label: "Empresas",       icon: "fa-building" },
    { id: "localidades",    label: "Localidades",    icon: "fa-map-pin" },
  ]},
  { section: "ADMINISTRACIÓN", items: [
    { id: "sucursales",     label: "Sucursales",     icon: "fa-flag" },
    { id: "usuarios",       label: "Usuarios",       icon: "fa-users" },
    { id: "roles",          label: "Roles",          icon: "fa-shield-halved" },
  ]},
];

/* Mock movement generator — returns recent in/out for a given product id */
export function movimientosFor(productoId) {
  const seed = productoId * 13 + 7;
  const cuentas = ["Toyosa S.A.", "Velacuss SRL", "Consumidor Final", "Flotas del Chaco", "Imcruz", "Trans Yara", "Cooperativa Andes"];
  const tipos = [
    { code: "VEN", label: "Venta",   dir: "out" },
    { code: "ENV", label: "Envío",   dir: "out" },
    { code: "COM", label: "Compra",  dir: "in"  },
    { code: "AJU", label: "Ajuste",  dir: "in"  },
    { code: "DEV", label: "Devol.",  dir: "in"  },
  ];
  const n = 8 + (seed % 6);
  const out = [];
  for (let i = 0; i < n; i++) {
    const r = ((seed * (i + 1) * 37) >>> 0);
    const t = tipos[r % tipos.length];
    const cant = 1 + (r % 8);
    const precio = 100 + (r % 1500);
    const day = 24 - (i * 2 + (r % 3));
    out.push({
      id: 5000 + ((r >> 4) % 9000),
      tipo: t.code,
      tipoLabel: t.label,
      dir: t.dir,
      fecha: `2026-${String(4 + ((r >> 8) % 2)).padStart(2,"0")}-${String(Math.max(1, day)).padStart(2,"0")}`,
      cuenta: cuentas[r % cuentas.length],
      precio: t.dir === "in" ? precio : null,
      ing: t.dir === "in" ? cant : null,
      egr: t.dir === "out" ? cant : null,
    });
  }
  return out.sort((a, b) => b.fecha.localeCompare(a.fecha));
}

export const LCV_DATA = {
  SUCURSALES, USER, VENTAS, PRODUCTOS, COTIZACIONES, TOP_PRODUCTOS,
  POR_COBRAR, POR_PAGAR, CHART_MESES, CLIENTES, CAJA, ACTIVIDAD, NAV,
  ROLES, MEDIOS, MARCAS, INDUSTRIAS,
  movimientosFor, totalStock,
};
