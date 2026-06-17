/**
 * @fileoverview Layout principal de la aplicación: Sidebar, Topbar, UserMenu,
 * breadcrumbs y AppLayout (que orquesta sidebar + topbar + modales globales).
 */

import React, { useState, useEffect, useRef } from 'react';
import { LCV_DATA } from '../../data/mock.jsx';
import { filterNav } from '../roles.js';
import { BrandMark, Icon } from './primitives.jsx';
import { SearchModal } from './search.jsx';
import { ProductQuickViewModal, MovimientosModal } from './modals.jsx';


/**
 * Barra lateral de navegación con logo, links de módulos y footer de usuario.
 * Filtra los ítems de nav según los permisos efectivos del usuario (RBAC).
 * @param {object} props
 * @param {string} props.current - ID de ruta activa para resaltar el link correspondiente.
 * @param {function(string): void} props.onNav - Callback de navegación.
 * @param {boolean} props.collapsed - Si está colapsada solo muestra íconos.
 * @param {boolean} props.sidebarLight - Aplica tema claro en lugar del navy oscuro.
 * @param {object} props.user - Objeto usuario con name y role.
 * @param {function(): void} props.onLogout - Callback para cerrar sesión.
 * @param {string[]} props.effectivePermissions - Permisos activos (reales o simulados).
 * @param {boolean} props.isAdmin - Si es ADMIN (Gate::before, acceso total).
 * @returns {JSX.Element}
 */
export function Sidebar({ current, onNav, collapsed, mobileOpen, sidebarLight, user, onLogout, effectivePermissions, isAdmin }) {
  const initials = user?.name
    ? user.name.split(" ").map(w => w[0]).join("").slice(0, 2).toUpperCase()
    : "?";
  const navSections = filterNav(LCV_DATA.NAV, effectivePermissions, isAdmin, user?.role);
  return (
    <aside className={`sidebar ${sidebarLight ? "light-mode" : ""} ${mobileOpen ? "open" : ""}`}>
      <button className="sidebar-brand" onClick={() => onNav("dashboard")} style={{textAlign:"left",position:"relative",overflow:"hidden"}}>
        <div style={{position:"absolute",width:90,height:90,background:"rgba(255,255,255,.03)",borderRadius:6,transform:"rotate(45deg)",top:-40,right:-40,pointerEvents:"none"}}/>
        <div style={{position:"absolute",width:55,height:55,background:"rgba(255,255,255,.05)",borderRadius:4,transform:"rotate(45deg)",top:-5,right:-5,pointerEvents:"none"}}/>
        <BrandMark size={40} variant={sidebarLight ? "light" : "dark"} />
        <div className="text">
          <span className="name">LA CASA VOLVO</span>
          <span className="sub">Sistema de gestión</span>
        </div>
      </button>

      <nav className="sidebar-nav">
        {navSections.map((sect) => (
          <div key={sect.section}>
            <div className="sb-section">{sect.section}</div>
            {sect.items.map((it) => (
              <button
                key={it.id}
                className={`sb-link ${current === it.id ? "active" : ""}`}
                onClick={() => onNav(it.id)}
                title={collapsed ? it.label : undefined}
              >
                <span className="icon"><Icon name={it.icon} /></span>
                <span>{it.label}</span>
                {it.badge ? <span className="badge">{it.badge}</span> : null}
              </button>
            ))}
          </div>
        ))}
      </nav>

      <div className="sidebar-footer">
        <div className="ava">{initials}</div>
        <div className="who">
          <div className="nm">{user?.name || "..."}</div>
          <div className="rl">{user?.role || ""}</div>
        </div>
        <button className="more" title="Cerrar sesión" onClick={onLogout}>
          <Icon name="fa-right-from-bracket" />
        </button>
      </div>
    </aside>
  );
}


/* ─── Topbar / Header ─── */
/**
 * Paleta de colores de acento por sucursal. Cada color se aplica como `--accent`
 * (fondo de `.btn-accent` con texto blanco), por lo que todos deben cumplir
 * WCAG AA (contraste >= 4.5:1 con #fff). Se oscurecieron green/amber/purple/blue
 * respecto de la paleta original (que daba 2.08–4.40:1) manteniendo el matiz:
 *   blue #0b7ec2→#0a6fa8 (5.45) · green #22c55e→#177a3c (5.41) ·
 *   violet #6b64b0 (5.15, sin cambio) · amber #f0a500→#a35a00 (5.22) ·
 *   purple #8b5cf6→#7c3aed (5.70).
 * @type {string[]}
 */
export const SUC_COLORS = ['#0a6fa8','#177a3c','#6b64b0','#a35a00','#7c3aed'];


/**
 * Barra superior de la aplicación: toggle sidebar, buscador ⌘K, selector de sucursal,
 * reloj, toggle dark mode y menú de usuario.
 * @param {object} props
 * @param {function(): void} props.onToggleSidebar - Colapsa/expande el sidebar.
 * @param {function(): void} props.onSearch - Abre el modal de búsqueda.
 * @param {boolean} props.dark - Estado actual del tema oscuro.
 * @param {function(): void} props.onToggleDark - Alterna dark mode.
 * @param {number} props.sucursalId - ID de la sucursal activa.
 * @param {function(number): void} props.onSelectSucursal - Cambia la sucursal activa.
 * @param {Array<{id: number, nombre: string}>} props.sucursales - Lista de sucursales disponibles.
 * @param {function(string): void} props.onNav - Callback de navegación.
 * @param {string|null} props.simulatedRole - Rol simulado activo (null si no hay simulación).
 * @param {function(string|null): void} props.onStopSimulation - Detiene o cambia la simulación de rol.
 * @param {object} props.user - Objeto usuario.
 * @param {function(): void} props.onLogout - Callback para cerrar sesión.
 * @returns {JSX.Element}
 */
export function Topbar({ onToggleSidebar, onMobileMenu, onSearch, dark, onToggleDark, sucursalId, onSelectSucursal, sucursales, onNav, simulatedRole, rolesData, onStopSimulation, user, onLogout, isAdmin, canSimulate, accesos }) {
  const [clock, setClock] = useState("");
  const [sucursalOpen, setSucursalOpen] = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  const [notifOpen, setNotifOpen] = useState(false);
  const sucRef = useRef(null);
  const userRef = useRef(null);
  const notifRef = useRef(null);

  useEffect(() => {
    const tick = () => setClock(new Date().toLocaleTimeString("es-BO", { hour: "2-digit", minute: "2-digit" }));
    tick();
    const t = setInterval(tick, 30000);
    return () => clearInterval(t);
  }, []);

  // Close popovers on outside click
  useEffect(() => {
    const onClick = (e) => {
      if (sucRef.current && !sucRef.current.contains(e.target)) setSucursalOpen(false);
      if (userRef.current && !userRef.current.contains(e.target)) setUserOpen(false);
      if (notifRef.current && !notifRef.current.contains(e.target)) setNotifOpen(false);
    };
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, []);

  const sucsWithColor = (sucursales || []).map((s, i) => ({...s, color: s.color ?? SUC_COLORS[i % SUC_COLORS.length]}));
  // Filtrar sucursales: solo las accesibles
  const accessibleSucs = accesos && accesos.length > 0
    ? sucsWithColor.filter(s => accesos.some(a => a.sucursal_id === s.id))
    : sucsWithColor;
  const suc = sucsWithColor.find(s => s.id === sucursalId) || sucsWithColor[0] || { nombre: '...', color: 'var(--soft)', alias: '?' };
  const U = user || {};

  return (
    <header className="topbar" style={{borderBottom: '3px solid var(--accent)'}}>
      <button className="iconbtn hamburger" onClick={onMobileMenu} title="Menú" style={{display:'none'}}>
        <Icon name="fa-bars" />
      </button>
      <button className="iconbtn hide-tablet" onClick={onToggleSidebar} title="Menú">
        <Icon name="fa-bars-staggered" />
      </button>

      <button className="searchbar" onClick={onSearch}>
        <Icon name="fa-magnifying-glass" />
        <span className="searchbar-text">Buscar productos, ventas, clientes…</span>
        <span className="kbd">⌘ K</span>
      </button>

      {/* Sucursal dropdown */}
      <div ref={sucRef} style={{position: "relative"}}>
        <button className="sucursal-chip" onClick={() => setSucursalOpen(o => !o)}>
          <span className="dot" style={{background: suc.color}} />
          <Icon name="fa-location-dot" style={{fontSize: 10, color:"var(--soft)"}}/>
          <span>{suc.nombre}</span>
          <Icon name="fa-chevron-down" style={{fontSize: 9, color:"var(--soft)", transition:"transform .15s", transform: sucursalOpen ? "rotate(180deg)" : "none"}}/>
        </button>
        {sucursalOpen && (
          <div className="popover" style={{minWidth: 220, right: "auto", left: 0}}>
            <div className="popover-header">
              <span style={{fontSize: 10, fontWeight: 700, color:"var(--dust)", letterSpacing:".08em", textTransform:"uppercase"}}>Cambiar sucursal</span>
            </div>
            <div className="popover-list">
              {accessibleSucs.map(s => {
                const active = s.id === sucursalId;
                return (
                  <button key={s.id}
                    onClick={() => { onSelectSucursal(s.id); setSucursalOpen(false); }}
                    className={`popover-item ${active ? "active" : ""}`}>
                    <span className="dot" style={{width: 8, height: 8, borderRadius: "50%", background: s.color, flexShrink: 0}}/>
                    <span style={{flex:1, textAlign:"left", fontSize: 13, fontWeight: 600}}>{s.nombre}</span>
                    {active && <Icon name="fa-check" style={{fontSize: 11, color: "var(--accent)"}}/>}
                  </button>
                );
              })}
            </div>
          </div>
        )}
      </div>

      <span style={{flex: 1}}></span>

      <span className="mono clock-display" style={{fontSize: 12, color: "var(--soft)"}}>{clock}</span>

      {simulatedRole && (
        <span className="sim-badge" title="Estás simulando un rol — clic en tu avatar para volver">
          <Icon name="fa-mask" style={{fontSize: 9, marginRight: 5}}/>
          {simulatedRole}
        </span>
      )}

      <div ref={notifRef} style={{position: "relative"}}>
        <button className="iconbtn" title="Notificaciones" onClick={() => setNotifOpen(o => !o)}>
          <Icon name="fa-bell" />
          <span className="dot"></span>
        </button>
        {notifOpen && (
          <div className="popover" style={{position:"absolute", top:"100%", right:0, marginTop:6, zIndex:100}}>
            <div style={{padding:"16px 24px", fontSize:13, color:"var(--soft)", whiteSpace:"nowrap"}}>
              <Icon name="fa-bell" style={{marginRight:8, color:"var(--accent)"}}/>
              Próximamente
            </div>
          </div>
        )}
      </div>

      <button className="iconbtn" onClick={onToggleDark} title="Tema">
        <Icon name={dark ? "fa-sun" : "fa-moon"} />
      </button>

      {/* User dropdown */}
      <div ref={userRef} style={{position: "relative"}}>
        <button className="user-trigger" onClick={() => setUserOpen(o => !o)}>
          <div className="avatar sm">{U.name ? U.name.split(" ").map(w=>w[0]).join("").slice(0,2).toUpperCase() : "?"}</div>
          <span className="user-name">{U.name ? U.name.split(" ")[0] : "..."}</span>
          <Icon name="fa-chevron-down" style={{fontSize: 9, color:"var(--soft)"}}/>
        </button>
        {userOpen && (
          <UserMenu user={U} role={simulatedRole} sucursalId={sucursalId}
            sucursales={accessibleSucs}
            rolesList={rolesData}
            isAdmin={isAdmin}
            canSimulate={canSimulate}
            accesos={accesos}
            onSelectSucursal={(id) => { onSelectSucursal(id); }}
            onSimulate={(role) => { onStopSimulation(role); setUserOpen(false); }}
            onStopSim={() => { onStopSimulation(null); setUserOpen(false); }}
            onNav={(r) => { setUserOpen(false); onNav(r); }}
            onLogout={onLogout} />
        )}
      </div>
    </header>
  );
}


/**
 * Popover de usuario: muestra datos del usuario, sucursales, selector de rol simulado y logout.
 * Se renderiza desde Topbar cuando el usuario hace click en su avatar.
 * @param {object} props
 * @param {object} props.user - Datos del usuario (name, email, role).
 * @param {string|null} props.role - Rol simulado activo.
 * @param {number} props.sucursalId - ID de la sucursal activa.
 * @param {Array} props.sucursales - Lista de sucursales con color.
 * @param {function(number): void} props.onSelectSucursal - Cambia sucursal activa.
 * @param {function(string): void} props.onSimulate - Activa simulación de un rol.
 * @param {function(): void} props.onStopSim - Detiene la simulación de rol.
 * @param {function(string): void} props.onNav - Navega a una ruta.
 * @param {function(): void} props.onLogout - Cierra sesión.
 * @returns {JSX.Element}
 */
export function UserMenu({ user, role, sucursalId, sucursales, rolesList, onSelectSucursal, onSimulate, onStopSim, onNav, onLogout, isAdmin, canSimulate, accesos }) {
  // Filtrar sucursales: solo las que el usuario tiene habilitadas
  const accessibleSucursales = accesos && accesos.length > 0
    ? sucursales.filter(s => accesos.some(a => a.sucursal_id === s.id))
    : sucursales;

  return (
    <div className="popover user-popover">
      <div className="user-popover-header">
        <div className="avatar lg" style={{background: "linear-gradient(135deg, rgba(255,255,255,.25), rgba(255,255,255,.08))", border: "1px solid rgba(255,255,255,.2)"}}>
          {user.initials}
        </div>
        <div style={{minWidth: 0, flex: 1}}>
          <div className="truncate" style={{color:"#fff", fontSize: 14, fontWeight: 700}}>{user.name}</div>
          <div style={{fontSize: 10, fontWeight: 700, letterSpacing: ".08em", textTransform: "uppercase", color: "rgba(255,255,255,.55)", marginTop: 3}}>
            {role ? <><Icon name="fa-mask" style={{marginRight: 4, fontSize: 9}}/>SIMULANDO {role}</> : (user.role || "USUARIO")}
          </div>
          <div className="truncate" style={{fontSize: 11, color: "rgba(255,255,255,.4)", marginTop: 2}}>{user.email}</div>
        </div>
      </div>

      {role && (
        <div style={{padding: "8px 14px", background:"var(--warning-soft)", borderBottom: "1px solid var(--line)", display:"flex", alignItems:"center", gap:8}}>
          <Icon name="fa-triangle-exclamation" style={{color:"var(--warning)", fontSize: 11}}/>
          <span style={{flex:1, fontSize: 11, color:"var(--warning)", fontWeight:600}}>Simulando como <strong>{role}</strong></span>
          <button onClick={onStopSim} className="link-sm">Detener</button>
        </div>
      )}

      <div className="popover-section">
        <span className="popover-section-label">Sucursal activa</span>
        <div className="suc-chips">
          {accessibleSucursales.map(s => {
            const active = s.id === sucursalId;
            return (
              <button key={s.id} onClick={() => onSelectSucursal(s.id)}
                className={`suc-chip-pill ${active ? "active" : ""}`}>
                <span className="dot" style={{background: s.color}}/>
                {s.nombre}
              </button>
            );
          })}
        </div>
      </div>

      <button className="popover-item" onClick={() => onNav("perfil")} style={{borderBottom: "1px solid var(--line)"}}>
        <Icon name="fa-circle-user" style={{color:"var(--soft)", fontSize: 13}}/>
        <span style={{flex:1, textAlign:"left", fontSize: 13, fontWeight: 500}}>Mi perfil</span>
        <Icon name="fa-chevron-right" style={{fontSize: 10, color: "var(--dust)"}}/>
      </button>

      {canSimulate && (
      <div className="popover-section">
        <span className="popover-section-label">Simular como</span>
        {role && (
          <button onClick={() => onSimulate(null)} className="role-chip" style={{background:"var(--danger-soft)", color:"var(--danger)", borderColor:"transparent", width:"100%", justifyContent:"center", marginBottom: 6}}>
            <Icon name="fa-rotate-left" style={{fontSize: 10}}/>
            Volver a mi rol ({user.roles?.[0] || user.role})
          </button>
        )}
        <div className="role-grid">
          {(rolesList || []).filter(r => r.name !== 'SUSPENDIDO' && (role ? r.name !== role : r.name !== user.role)).map(r => (
            <button key={r.id} onClick={() => onSimulate(r.name)} className="role-chip" title={r.desc}>
              <Icon name="fa-mask" style={{fontSize: 9, color: "var(--dust)"}}/>
              {r.name}
            </button>
          ))}
        </div>
      </div>
      )}

      <button className="popover-item logout" onClick={onLogout}>
        <Icon name="fa-right-from-bracket" style={{fontSize: 13}}/>
        <span style={{flex:1, textAlign:"left", fontSize: 13, fontWeight: 600}}>Cerrar sesión</span>
      </button>
    </div>
  );
}


/**
 * Barra de breadcrumbs. No se renderiza si hay menos de 2 crumbs.
 * @param {object} props
 * @param {string[]} props.crumbs - Lista de etiquetas de ruta (ej: ["Ventas", "Detalle #123"]).
 * @param {function(string): void} props.onNav - Callback de navegación.
 * @returns {JSX.Element|null}
 */
export function CrumbBar({ crumbs, onNav }) {
  if (!crumbs || crumbs.length <= 1) return null;
  return (
    <div className="crumb-bar">
      <Icon name="fa-folder-open" style={{fontSize: 10, color: "var(--dust)", marginRight: 2}}/>
      {crumbs.map((c, i) => (
        <React.Fragment key={i}>
          {i > 0 && <span className="crumb-sep"><Icon name="fa-chevron-right" style={{fontSize:9}}/></span>}
          <span className={i === crumbs.length - 1 ? "crumb-here" : "crumb"}>{c}</span>
        </React.Fragment>
      ))}
    </div>
  );
}


/**
 * Layout principal de la aplicación. Combina Sidebar + Topbar + contenido principal.
 * Gestiona el estado de colapso del sidebar, el modal de búsqueda ⌘K y los
 * modales de quick-view y movimientos de producto.
 * @param {object} props
 * @param {React.ReactNode} props.children - Pantalla activa a renderizar en el área de contenido.
 * @param {string} props.current - Ruta activa para highlight en sidebar.
 * @param {function} props.onNav - Callback de navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @param {function} props.onSelectSucursal - Cambia sucursal activa.
 * @param {Array} props.sucursales - Lista de sucursales.
 * @param {string|null} props.simulatedRole - Rol simulado activo.
 * @param {function} props.onSimulate - Activa/desactiva simulación de rol.
 * @param {string[]} props.effectivePermissions - Permisos efectivos (reales o simulados).
 * @param {boolean} props.isAdmin - Si el usuario es ADMIN.
 * @param {string[]} [props.crumbs] - Breadcrumbs opcionales.
 * @param {boolean} props.dark - Estado del tema oscuro.
 * @param {function} props.onToggleDark - Alterna dark mode.
 * @param {"normal"|"compact"|"spacious"} props.density - Densidad visual de la UI.
 * @param {boolean} props.sidebarLight - Sidebar en tema claro.
 * @param {object} props.user - Objeto usuario.
 * @param {function} props.onLogout - Callback de logout.
 * @returns {JSX.Element}
 */
export function AppLayout({ children, current, onNav, sucursalId, onSelectSucursal, sucursales, simulatedRole, rolesData, onSimulate, effectivePermissions, isAdmin, canSimulate, contentKey, crumbs, dark, onToggleDark, density, sidebarLight, user, onLogout }) {
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [quickProduct, setQuickProduct] = useState(null);
  const [movsProduct, setMovsProduct] = useState(null);

  // Cerrar sidebar móvil al navegar
  const handleNav = (route) => { setMobileOpen(false); onNav(route); };

  useEffect(() => {
    const onKey = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        setSearchOpen(true);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  const dVal = density === "compact" ? 0.85 : density === "spacious" ? 1.15 : 1;

  return (
    <div className={`app-shell ${collapsed ? "collapsed" : ""} ${mobileOpen ? "sidebar-open" : ""}`} style={{"--d": dVal}}>
      {/* Backdrop móvil */}
      <div className={`sidebar-backdrop ${mobileOpen ? "show" : ""}`} onClick={() => setMobileOpen(false)} />

      <Sidebar current={current} onNav={handleNav} collapsed={collapsed} mobileOpen={mobileOpen} sidebarLight={sidebarLight} user={user} onLogout={onLogout} effectivePermissions={effectivePermissions} isAdmin={isAdmin} />
      <div className="main-col">
        <Topbar
          onToggleSidebar={() => setCollapsed(c => !c)}
          onMobileMenu={() => setMobileOpen(o => !o)}
          onSearch={() => setSearchOpen(true)}
          dark={dark}
          onToggleDark={onToggleDark}
          sucursalId={sucursalId}
          onSelectSucursal={onSelectSucursal}
          sucursales={sucursales}
          simulatedRole={simulatedRole}
          rolesData={rolesData}
          onStopSimulation={onSimulate}
          onNav={onNav}
          isAdmin={isAdmin}
          canSimulate={canSimulate}
          accesos={user?.accesos ?? []}
          user={user}
          onLogout={onLogout}
        />
        <CrumbBar crumbs={crumbs} onNav={onNav} />
        <main className="content">{children}</main>
      </div>
      {searchOpen && (
        <SearchModal
          onClose={() => setSearchOpen(false)}
          onNav={onNav}
          onProductClick={(p) => setQuickProduct(p)}
          effectivePermissions={effectivePermissions}
          isAdmin={isAdmin}
        />
      )}
      {quickProduct && !movsProduct && (
        <ProductQuickViewModal
          product={quickProduct}
          onClose={() => setQuickProduct(null)}
          onMovimientos={() => setMovsProduct(quickProduct)}
          onNav={onNav}
        />
      )}
      {movsProduct && (
        <MovimientosModal
          product={movsProduct}
          onClose={() => setMovsProduct(null)}
        />
      )}
    </div>
  );
}
