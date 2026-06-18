import React, { useState, useEffect } from 'react';
import { useTweaks, TweaksPanel } from './lib/tweaks.jsx';
import { AppLayout, SUC_COLORS, ToastProvider } from './lib/components.jsx';
import { LoginScreen, Dashboard } from './screens/main.jsx';
import { VentasIndex, VentaNueva, VentaDetail } from './screens/ventas.jsx';
import { Productos, ProductoDetail } from './screens/productos.jsx';
import { Cotizaciones, CotizacionDetail } from './screens/cotizaciones.jsx';
import { Caja } from './screens/caja.jsx';
import { Compras, CompraDetail } from './screens/compras.jsx';
import { Pedidos, PedidoDetail } from './screens/pedidos.jsx';
import { Envios, EnvioDetail } from './screens/envios.jsx';
import { Ajustes } from './screens/ajustes.jsx';
import { Cuentas, CuentaDetail } from './screens/cuentas.jsx';
import { HistorialCaja } from './screens/historial-caja.jsx';
import { Sucursales, Usuarios, Roles, Perfil, Marcas, Industrias, Medios, Empresas, Localidades } from './screens/admin.jsx';
import { Estadisticas } from './screens/estadisticas.jsx';
import { auth, sucursales as sucursalesApi, roles as rolesApi, users as usersApi } from './services/api.js';
import { canAccess } from './lib/roles.js';

const TWEAK_DEFAULTS = {
  dark: false,
  // Accent por defecto (pantalla de login, antes de cargar la sucursal del usuario).
  // Oscurecido de #0b7ec2 (4.40:1) a #0a6fa8 (5.45:1) para que el texto blanco de
  // .btn-accent cumpla WCAG AA. Coincide con --star en index.css y con SUC_COLORS[0].
  accent: '#0a6fa8',
  radius: 10,
  sidebarLight: false,
  density: 'normal',
  sucursalId: 1,
  simulatedRole: '',
};

function hexA(hex, a) {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r},${g},${b},${a})`;
}

function lighten(hex, pct) {
  const r = Math.min(255, parseInt(hex.slice(1, 3), 16) + Math.round(255 * pct));
  const g = Math.min(255, parseInt(hex.slice(3, 5), 16) + Math.round(255 * pct));
  const b = Math.min(255, parseInt(hex.slice(5, 7), 16) + Math.round(255 * pct));
  return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
}

export default function App() {
  const [tweaks, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [route, setRoute] = useState({ name: 'login' });
  const [user, setUser] = useState(null);
  const [sucursales, setSucursales] = useState([]);
  const [rolesData, setRolesData] = useState([]);
  const [authLoading, setAuthLoading] = useState(true);
  const [loginFading, setLoginFading] = useState(false);
  const [welcomeUser, setWelcomeUser] = useState(null);

  const { dark, accent, radius, sidebarLight, density, sucursalId, simulatedRole } = tweaks;

  useEffect(() => {
    if (user && route.name !== 'login') {
      sessionStorage.setItem('lcv_route', JSON.stringify(route));
    }
  }, [route, user]);

  // isAdmin: ADMIN tiene Gate::before en backend, sus permisos en BD son vacíos.
  // ¿Puede simular roles? Solo ADMIN y GERENTE (legacy: GERENTE tiene 83 permisos,
  // es un superusuario sin acceso a sucursales/usuarios/roles).
  const canSimulate = user?.roles?.some(r => r === 'ADMIN' || r === 'GERENTE') || false;

  // ¿El rol EFECTIVO (simulado o real) es ADMIN? Controla permisos y acceso.
  // También true cuando se simula ADMIN (perms vacíos en BD no sirven para filtrar).
  const isAdmin = simulatedRole === 'ADMIN' || (!simulatedRole && user?.role === 'ADMIN');

  // Permisos del rol simulado (de rolesData; fallback a user.permissions si no hay datos aún)
  const simulatedPerms = simulatedRole
    ? (rolesData.find(r => r.name === simulatedRole)?.permissions ?? user?.permissions ?? [])
    : [];

  const effectivePermissions = simulatedRole ? simulatedPerms : (user?.permissions ?? []);

  function loadCatalogos(userSucursalId) {
    sucursalesApi.list().then(sr => {
      const list = sr.data ?? [];
      setSucursales(list);
      // Sincronizar color de accent con la sucursal activa del usuario
      if (userSucursalId) {
        const idx = list.findIndex(s => s.id === userSucursalId);
        if (idx >= 0) setTweak('accent', SUC_COLORS[idx % SUC_COLORS.length]);
      }
    }).catch(() => {});
    rolesApi.list().then(rr => setRolesData(rr.data ?? [])).catch(() => {});
  }

  // Restore session from saved token
  useEffect(() => {
    const token = localStorage.getItem('lcv_token') || sessionStorage.getItem('lcv_token');
    if (!token) { setAuthLoading(false); return; }
    auth.me()
      .then(r => {
        const u = r.data;
        setUser(u);
        // Restaurar simulación desde BD (simulated_role_id persiste entre sesiones)
        if (u.simulated_role_name) setTweak('simulatedRole', u.simulated_role_name);
        else setTweak('simulatedRole', '');
        if (u.sucursal_id) setTweak('sucursalId', u.sucursal_id);
        const savedRoute = sessionStorage.getItem('lcv_route');
        if (savedRoute) {
          try { setRoute(JSON.parse(savedRoute)); } catch { setRoute({ name: 'dashboard' }); }
        } else {
          setRoute({ name: 'dashboard' });
        }
        loadCatalogos(u.sucursal_id);
      })
      .catch(() => { localStorage.removeItem('lcv_token'); sessionStorage.removeItem('lcv_token'); })
      .finally(() => setAuthLoading(false));
  }, []);

  useEffect(() => {
    const el = document.documentElement;
    el.style.setProperty('--accent', accent);
    el.style.setProperty('--accent-hover', lighten(accent, 0.12));
    el.style.setProperty('--accent-a15', hexA(accent, 0.15));
    el.style.setProperty('--accent-a30', hexA(accent, 0.30));
    el.style.setProperty('--radius', `${radius}px`);
    if (dark) el.classList.add('dark');
    else el.classList.remove('dark');
  }, [dark, accent, radius]);

  async function handleLogin(email, password, remember = true) {
    const r = await auth.login(email, password);
    const storage = remember ? localStorage : sessionStorage;
    storage.setItem('lcv_token', r.data.token);
    const u = r.data.user;

    setLoginFading(true);
    await new Promise(res => setTimeout(res, 350));

    setUser(u);
    setTweak('simulatedRole', '');
    if (u.sucursal_id) setTweak('sucursalId', u.sucursal_id);
    loadCatalogos(u.sucursal_id);
    setRoute({ name: 'dashboard' });
    setLoginFading(false);
    setWelcomeUser(u);
    setTimeout(() => setWelcomeUser(null), 1800);
  }

  async function handleLogout() {
    try { await usersApi.stopSimulate(); } catch {}
    try { await auth.logout(); } catch {}
    localStorage.removeItem('lcv_token');
    sessionStorage.removeItem('lcv_token');
    setTweak('simulatedRole', '');
    setUser(null);
    setRoute({ name: 'login' });
  }

  /**
   * Cambia la sucursal activa del usuario.
   *
   * ORDEN CRÍTICO: primero se actualiza el servidor (`switchSucursal` cambia
   * `users.sucursal_id`) y RECIÉN DESPUÉS se mueve el estado local `sucursalId`.
   * Las pantallas (Caja, Productos, Ventas…) se re-fetchean cuando cambia el prop
   * `sucursalId`, y el backend resuelve los datos por `Auth::user()->sucursal_id`.
   * Si se cambiara `sucursalId` ANTES del switch, el re-fetch saldría con la
   * sucursal vieja del servidor y mostraría los datos de la sucursal anterior
   * (bug reportado en Caja: al pasar a Tarija se veían los movimientos de Central).
   *
   * @param {number} id - ID de la sucursal destino (debe estar entre los accesos del usuario).
   * @returns {Promise<void>}
   */
  async function handleSelectSucursal(id) {
    const idx = sucursales.findIndex(s => s.id === id);
    try {
      const r = await auth.switchSucursal(id);   // 1) el servidor cambia users.sucursal_id
      setUser(r.data);
      if (idx >= 0) setTweak('accent', SUC_COLORS[idx % SUC_COLORS.length]);
      setTweak('sucursalId', id);                // 2) recién ahora se disparan los re-fetch
    } catch {
      // Sin acceso (o fallo de red): no se toca el estado local, así que no hay
      // nada que revertir; la sucursal y el color siguen como estaban.
    }
  }

  function onNav(r) {
    if (typeof r === 'string') setRoute({ name: r });
    else setRoute(r);
  }

  // Extrae el módulo raíz del route para iluminar el sidebar en subsecciones.
  // Ej: 'pedido-detail' → 'pedidos', 'cotizacion-detail' → 'cotizaciones'
  function navRoot(name) {
    const detailMap = {
      'venta-nueva':        'ventas',
      'venta-detail':       'ventas',
      'cotizacion-detail':  'cotizaciones',
      'pedido-detail':      'pedidos',
      'compra-detail':      'compras',
      'envio-detail':       'envios',
      'producto-detail':    'productos',
      'cuenta-detail':      'cuentas',
    };
    return detailMap[name] ?? name;
  }

  if (authLoading) {
    return (
      <div style={{ height: '100vh', background: 'var(--bg)', display: 'flex', overflow: 'hidden' }}>
        <div style={{ width: 220, background: 'var(--navy)', padding: '20px 16px', display: 'flex', flexDirection: 'column', gap: 10 }}>
          <div style={{ height: 44, borderRadius: 10, background: 'rgba(255,255,255,.12)', marginBottom: 8 }}/>
          {[80, 60, 60, 60, 60, 60, 60].map((w, i) => (
            <div key={i} style={{ height: 30, width: `${w}%`, borderRadius: 6, background: 'rgba(255,255,255,.07)' }}/>
          ))}
        </div>
        <div style={{ flex: 1, padding: 32, display: 'flex', flexDirection: 'column', gap: 20 }}>
          <div style={{ height: 36, width: 180, borderRadius: 8, background: 'var(--line)' }}/>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 16 }}>
            {[1,2,3,4].map(i => <div key={i} style={{ height: 88, borderRadius: 12, background: 'var(--line)' }}/>)}
          </div>
          <div style={{ height: 320, borderRadius: 12, background: 'var(--line)' }}/>
        </div>
      </div>
    );
  }

  if (!user) {
    return (
      <>
        <LoginScreen onLogin={handleLogin} fading={loginFading} />
        <TweaksPanel title="Prototipo" />
      </>
    );
  }

  function renderScreen() {
    const name = route.name;

    // Redirigir al dashboard si el usuario no tiene permiso para esta ruta
    if (!canAccess(name, effectivePermissions, isAdmin, user?.role)) return <Dashboard onNav={onNav} user={user} sucursalId={sucursalId} />;

    if (name === 'dashboard')      return <Dashboard onNav={onNav} user={user} sucursalId={sucursalId} effectivePermissions={effectivePermissions} isAdmin={isAdmin} />;
    if (name === 'estadisticas')   return <Estadisticas onNav={onNav} user={user} />;
    if (name === 'ventas')         return <VentasIndex onNav={onNav} onOpenVenta={(id, vData) => onNav({ name: 'venta-detail', id, vData })} sucursalId={sucursalId} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'venta-nueva')    return <VentaNueva onNav={onNav} onComplete={(id) => onNav({ name: 'venta-detail', id })} sucursalId={sucursalId} user={user} initialId={route.id} initialData={route.vData} />;
    if (name === 'venta-detail')   return <VentaDetail ventaId={route.id} ventaData={route.vData} onNav={onNav} user={user} />;
    if (name === 'cotizaciones')      return <Cotizaciones onNav={onNav} sucursalId={sucursalId} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'cotizacion-detail') return <CotizacionDetail cotizacionId={route.id} cotizacionData={route.cData} onNav={onNav} user={user} />;
    if (name === 'producto-detail')   return <ProductoDetail productoId={route.id} productoData={route.pData} onNav={onNav} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'cuenta-detail')     return <CuentaDetail cuentaId={route.id} cuentaData={route.cData} onNav={onNav} />;
    if (name === 'pedidos')        return <Pedidos onNav={onNav} sucursalId={sucursalId} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'compras')        return <Compras onNav={onNav} sucursalId={sucursalId} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'compra-detail')  return <CompraDetail compraId={route.id} compraData={route.cData} onNav={onNav} user={user} />;
    if (name === 'pedido-detail')  return <PedidoDetail pedidoId={route.id} pedidoData={route.pData} onNav={onNav} user={user} />;
    if (name === 'envio-detail')   return <EnvioDetail envioId={route.id} envioData={route.eData} onNav={onNav} user={user} />;
    if (name === 'empresas')       return <Empresas onNav={onNav} effectivePermissions={effectivePermissions} />;
    if (name === 'localidades')    return <Localidades onNav={onNav} effectivePermissions={effectivePermissions} />;
    if (name === 'envios')         return <Envios onNav={onNav} sucursalId={sucursalId} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'productos')      return <Productos onNav={onNav} sucursalId={sucursalId} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'ajustes')        return <Ajustes onNav={onNav} sucursalId={sucursalId} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'cuentas')        return <Cuentas onNav={onNav} sucursalId={sucursalId} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'caja')           return <Caja onNav={onNav} sucursalId={sucursalId} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'historial-caja') return <HistorialCaja onNav={onNav} sucursalId={sucursalId} user={user} />;
    if (name === 'marcas')         return <Marcas onNav={onNav} effectivePermissions={effectivePermissions} />;
    if (name === 'industrias')     return <Industrias onNav={onNav} effectivePermissions={effectivePermissions} />;
    if (name === 'medios')         return <Medios onNav={onNav} effectivePermissions={effectivePermissions} />;
    if (name === 'sucursales')     return <Sucursales onNav={onNav} effectivePermissions={effectivePermissions} />;
    if (name === 'usuarios')       return <Usuarios onNav={onNav} effectivePermissions={effectivePermissions} />;
    if (name === 'roles')          return <Roles onNav={onNav} user={user} effectivePermissions={effectivePermissions} />;
    if (name === 'perfil')         return <Perfil onNav={onNav} user={user} />;
    return <Dashboard onNav={onNav} user={user} sucursalId={sucursalId} />;
  }

  return (
    <ToastProvider>
    <>
      {welcomeUser && (
        <div style={{position:'fixed', inset:0, zIndex:9999, display:'grid', placeItems:'center', background:'rgba(24,38,66,.9)', backdropFilter:'blur(10px)', animation:'fade .25s'}}>
          <div style={{textAlign:'center', color:'#fff', animation:'pop .4s cubic-bezier(.2,.8,.3,1.1)'}}>
            <img src="assets/logo-white.svg" alt="" style={{width:68, height:68, objectFit:'contain', marginBottom:18, opacity:.95}}/>
            <div style={{fontSize:11, fontWeight:700, letterSpacing:'.14em', textTransform:'uppercase', opacity:.5, marginBottom:10}}>
              {welcomeUser.role}
            </div>
            <div style={{fontSize:34, fontWeight:700, letterSpacing:'-.02em'}}>
              ¡Bienvenido, {welcomeUser.name?.split(' ')[0]}!
            </div>
          </div>
        </div>
      )}
      <AppLayout
        current={navRoot(route.name)}
        onNav={onNav}
        user={user}
        onLogout={handleLogout}
        sucursalId={sucursalId}
        onSelectSucursal={handleSelectSucursal}
        sucursales={sucursales}
        simulatedRole={simulatedRole}
        rolesData={rolesData}
        onSimulate={async (role) => {
          if (role) {
            const roleObj = rolesData.find(r => r.name === role);
            if (roleObj) {
              try { await usersApi.simulate(roleObj.id); } catch {}
            }
          } else {
            try { await usersApi.stopSimulate(); } catch {}
          }
          // Recargar usuario SIEMPRE y sincronizar simulatedRole con la BD
          try {
            const meRes = await auth.me();
            setUser(meRes.data);
            // Sincronizar tweak con el estado real del backend
            setTweak('simulatedRole', meRes.data?.simulated_role_name || '');
          } catch {}
        }}
        effectivePermissions={effectivePermissions}
        isAdmin={isAdmin}
        canSimulate={canSimulate}
        dark={dark}
        onToggleDark={() => setTweak('dark', !dark)}
        density={density}
        sidebarLight={sidebarLight}
      >
        {renderScreen()}
      </AppLayout>
      <TweaksPanel title="Prototipo" />
    </>
    </ToastProvider>
  );
}
