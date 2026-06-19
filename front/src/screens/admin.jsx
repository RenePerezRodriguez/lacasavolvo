/**
 * @fileoverview Pantallas de administración: Sucursales, Usuarios, Roles, Perfil,
 * Marcas, Industrias, Medios, Empresas, Localidades.
 */

import React, { useState, useEffect, useCallback } from 'react';
import logger from '../lib/logger.js';
import { useColumnVisibility } from '../lib/hooks.js';
import { Icon, Button, Badge, Card, KPI, Empty, PageHead, Pager, PageSizeSelector, DataTable } from '../lib/components.jsx';
import { SucursalFormModal, UsuarioFormModal, RolFormModal, MedioFormModal, NombreFormModal } from './forms.jsx';
import { sucursales as sucursalesApi, users as usersApi, roles as rolesApi, marcas as marcasApi, industrias as industriasApi, medios as mediosApi, empresas as empresasApi, localidades as localidadesApi, profile as profileApi } from '../services/api.js';

const ROL_PERMISOS = {
  ADMIN:      { count: 17, areas: ["Bypass total (Gate::before)"] },
  GERENTE:    { count: 15, areas: ["Operaciones", "Inventario", "Finanzas", "Reportes", "Usuarios"] },
  VENDEDOR:   { count: 5,  areas: ["Productos", "Cuentas", "Ventas", "Cotizaciones", "Pedidos"] },
  CAJERO:     { count: 5,  areas: ["Productos", "Cuentas", "Ventas", "Compras", "Caja"] },
  OPERADOR:   { count: 5,  areas: ["Productos", "Cuentas", "Pedidos", "Compras", "Envíos"] },
  SUSPENDIDO: { count: 0,  areas: ["Sin acceso"] },
};

export function FilterRow({ children }) {
  return (
    <div className="card" style={{padding: 12}}>
      <div className="row" style={{gap: 10, flexWrap: "wrap"}}>{children}</div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════════════
   SUCURSALES
   ═══════════════════════════════════════════════════════════ */
export function Sucursales({ onNav, effectivePermissions }) {
  const [sucursales, setSucursales] = useState([]);
  const [loading, setLoading]       = useState(true);
  const [formOpen, setFormOpen]     = useState(false);
  const [editing, setEditing]       = useState(null);

  const canEdit = (effectivePermissions || []).some(p => p.startsWith('sucursales.') && (p.endsWith('.edit') || p.endsWith('.create')));

  const load = () => sucursalesApi.list().then(r => setSucursales(r.data ?? [])).catch(logger.error).finally(() => setLoading(false));
  useEffect(() => { load(); }, []);

  const activas   = sucursales.filter(s => s.estado === "ON").length;
  const inactivas = sucursales.filter(s => s.estado === "OFF").length;

  const handleToggle = async (s) => {
    const accion = s.estado === 'ON' ? 'desactivar' : 'activar';
    if (!window.confirm(`¿${accion[0].toUpperCase()+accion.slice(1)} "${s.nombre}"?`)) return;
    try {
      await sucursalesApi.toggle(s.id);
      load();
    } catch (e) {
      alert(e?.response?.data?.error || 'Error al cambiar estado');
      logger.error(e);
    }
  };

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Sucursales" sub="Red de tiendas y centros operativos" diamond
        actions={canEdit ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => { setEditing(null); setFormOpen(true); }}>Nueva sucursal</Button> : null}
      />
      {formOpen && <SucursalFormModal edit={editing} onClose={() => { setFormOpen(false); setEditing(null); }} onSaved={() => { setFormOpen(false); setEditing(null); load(); }}/>}
      <div className="grid-4">
        <KPI label="Sucursales" value={sucursales.length} icon="fa-flag"/>
        <KPI label="Activas" value={activas} icon="fa-circle-check"/>
        <KPI label="Inactivas" value={inactivas} icon="fa-circle-pause" deltaTone="down"/>
        <KPI label="Con supervisor" value={sucursales.filter(s => s.supervisor).length} icon="fa-user-tie"/>
      </div>
      {loading ? (
        <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
      ) : (
        <div className="grid-4">
          {sucursales.map(s => (
            <Card key={s.id} pad={false}>
              <div style={{padding: 18}}>
                <div className="row" style={{justifyContent:"space-between", alignItems:"flex-start", marginBottom: 12}}>
                  <div className="row" style={{gap: 10}}>
                    <div style={{width: 38, height: 38, borderRadius:"var(--r-md)", background:"var(--navy)", display:"grid", placeItems:"center"}}>
                      <Icon name="fa-flag" style={{color:"#fff", fontSize: 13}}/>
                    </div>
                    <div>
                      <div style={{fontSize: 16, fontWeight: 700, color:"var(--ink)"}}>{s.nombre}
                        {s.alias && <span className="mono" style={{fontSize: 10, color:"var(--soft)", fontWeight:600, marginLeft: 4}}>· {s.alias}</span>}
                      </div>
                      <div style={{fontSize: 11, color:"var(--soft)"}}>{s.supervisor || "—"}</div>
                    </div>
                  </div>
                  <Badge tone={s.estado === "ON" ? "success" : "danger"} dot>{s.estado === "ON" ? "Activa" : "Inactiva"}</Badge>
                </div>
                <div className="stack" style={{"--gap":"6px", fontSize: 12, color:"var(--soft)"}}>
                  <div className="row" style={{gap:8}}><Icon name="fa-phone" style={{fontSize:10, width:14, color:"var(--dust)"}}/><span className="mono">{s.telefono || "—"}</span></div>
                  <div className="row" style={{gap:8}}><Icon name="fa-location-dot" style={{fontSize:10, width:14, color:"var(--dust)"}}/><span>{s.direccion || "—"}</span></div>
                </div>
              </div>
              <div className="row" style={{borderTop:"1px solid var(--line)", padding:"10px 18px", background:"var(--alt)", justifyContent:"flex-end", gap:8}}>
                {canEdit && <Button variant="ghost" size="sm" icon="fa-edit" onClick={() => { setEditing(s); setFormOpen(true); }}>Editar</Button>}
                {canEdit && s.id !== 1 && (
                  <button className="icon-btn" onClick={() => handleToggle(s)} title={s.estado === "ON" ? "Desactivar" : "Activar"}>
                    <Icon name={s.estado === "ON" ? "fa-toggle-on" : "fa-toggle-off"} style={{fontSize:13, color: s.estado === "ON" ? "var(--success)" : "var(--soft)"}}/>
                  </button>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

/* ═══════════════════════════════════════════════════════════
   USUARIOS
   ═══════════════════════════════════════════════════════════ */
/**
 * Pantalla de Usuarios: lista del equipo con acceso por sucursal, alta/edición
 * (UsuarioFormModal) y suspender/reactivar. Las acciones se habilitan según
 * `canEdit` (requiere permiso `users.edit`/`users.create`); ADMIN y GERENTE lo tienen.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {string[]} props.effectivePermissions - Permisos efectivos (reales o simulados).
 * @returns {JSX.Element}
 */
export function Usuarios({ onNav, effectivePermissions }) {
  const [usuarios, setUsuarios] = useState([]);
  const [total, setTotal]       = useState(0);
  const [loading, setLoading]   = useState(true);
  const [q, setQ]               = useState("");
  const [skip, setSkip]         = useState(0);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing]   = useState(null);
  const [pageSize, setPageSize] = useState(15);

  const [sort, setSort]         = useState({ col: 'name', dir: 'asc' });
  const { hiddenCols, toggleCol, visibleCols, showCols, setShowCols } = useColumnVisibility('usuarios', []);

  // El permiso vive bajo el namespace `users.*` (seeder / rutas / ROUTE_PERMISSION), NO `usuarios.*`.
  // Con el prefijo equivocado, canEdit era SIEMPRE false → ni GERENTE ni ADMIN veían las acciones
  // (Nuevo usuario / Editar / Suspender). Debe quedar `users.`, igual que las pantallas hermanas
  // (sucursales. / roles. / marcas.). Restaura la gestión de usuarios sin tocar permisos del backend.
  const canEdit = (effectivePermissions || []).some(p => p.startsWith('users.') && (p.endsWith('.edit') || p.endsWith('.create')));

  const load = useCallback(() => {
    setLoading(true);
    usersApi.list({ skip, take: pageSize, ...(q && { search: q }), sort: sort.col, dir: sort.dir })
      .then(r => { setUsuarios(r.data.data ?? []); setTotal(r.data.total ?? 0); })
      .catch(logger.error)
      .finally(() => setLoading(false));
  }, [skip, q, pageSize, sort]);

  useEffect(() => { load(); }, [load]);

  const handleSuspend = async (u) => {
    const esSuspension = u.role !== 'SUSPENDIDO';
    const accion = esSuspension ? 'suspender' : 'reactivar';
    if (!window.confirm(`¿${accion[0].toUpperCase()+accion.slice(1)} a "${u.name}"?`)) return;
    try {
      if (esSuspension) {
        await usersApi.destroy(u.id);
      } else {
        // Reactivar: asignar rol VENDEDOR por defecto, el admin puede editarlo después
        await usersApi.update(u.id, { name: u.name, sucursal_id: u.sucursal_id || 1, role: 'VENDEDOR', email: u.email });
      }
      load();
    } catch (e) {
      alert(e?.response?.data?.error || 'Error al cambiar estado');
      logger.error(e);
    }
  };
  const activos     = usuarios.filter(u => u.role !== "SUSPENDIDO").length;
  const suspendidos = usuarios.filter(u => u.role === "SUSPENDIDO").length;
  const admins      = usuarios.filter(u => u.role === "ADMIN" || u.role === "GERENTE").length;
  const page  = Math.floor(skip / pageSize) + 1;
  const pages = Math.ceil(total / pageSize);
  const handlePageSize = (n) => { setPageSize(n); setSkip(0); };

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Usuarios" sub="Equipo y acceso por sucursal" diamond
        actions={canEdit ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => { setEditing(null); setFormOpen(true); }}>Nuevo usuario</Button> : null}
      />
      {formOpen && <UsuarioFormModal edit={editing} onClose={() => { setFormOpen(false); setEditing(null); }} onSaved={() => { setFormOpen(false); setEditing(null); load(); }}/>}
      <div className="grid-4">
        <KPI label="Total usuarios" value={total} icon="fa-users"/>
        <KPI label="Activos" value={activos} icon="fa-circle-check"/>
        <KPI label="Suspendidos" value={suspendidos} icon="fa-circle-pause"/>
        <KPI label="Administradores" value={admins} icon="fa-shield-halved"/>
      </div>
      <div className="card">
        <div style={{padding: 14, display:"flex", gap:10, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div style={{flex:1, minWidth: 220}}>
            <div className="filter-label">Búsqueda</div>
            <div className="input-group">
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:12}}/></span>
              <input className="input" placeholder="Buscar usuario o email…" value={q} onChange={e=>{setQ(e.target.value); setSkip(0);}}/>
            </div>
          </div>
          <div>
            <div className="filter-label">Pág.</div>
            <PageSizeSelector value={pageSize} onChange={handlePageSize}/>
          </div>
          <div style={{position:"relative"}}>
            <div className="filter-label">Columnas</div>
            <button className="btn btn-ghost btn-sm" title="Mostrar u ocultar columnas" aria-label="Mostrar u ocultar columnas" onClick={() => setShowCols(!showCols)} style={{whiteSpace:"nowrap"}}>
              <Icon name="fa-columns" style={{fontSize:10,marginRight:4}}/>
            </button>
          </div>
        </div>
        {loading ? (
          <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
        ) : (
          <DataTable
            data={usuarios}
            sortCol={sort.col}
            sortDir={sort.dir}
            onSort={(col, dir) => { setSort({ col, dir }); setSkip(0); }}
            columns={visibleCols([
              { key: 'avatar', title: '', width: 50, render: u => <div className="avatar sm">{u.name.split(" ").map(p=>p[0]).join("").substring(0,2).toUpperCase()}</div> },
              { key: 'name', title: 'Usuario', sortable: true, render: u => <span className="strong">{u.name}{u.id === 1 && <Badge tone="warning" outline style={{marginLeft:6,fontSize:9}}>Sistema</Badge>}</span> },
              { key: 'email', title: 'Email', sortable: true, render: u => <span className="mono" style={{fontSize: 11.5, color:"var(--soft)"}}>{u.email}</span> },
              { key: 'role', title: 'Rol', width: 120, render: u => <Badge tone={u.role === "ADMIN" || u.role === "GERENTE" ? "info" : u.role === "CAJERO" ? "warning" : u.role === "VENDEDOR" ? "success" : "neutral"} outline>{u.role || "—"}</Badge> },
              { key: 'sucursal', title: 'Sucursales', width: 180, render: u => {
                const activas = (u.accesos || []).filter(a => a.estado === 'ON');
                if (activas.length === 0) return <span className="text-soft" style={{fontSize:11}}>—</span>;
                return (
                  <div className="row" style={{gap:4, flexWrap:"wrap"}}>
                    {activas.map(a => (
                      <span key={a.sucursal_id} style={{fontSize:10, fontWeight:700, padding:"2px 6px", borderRadius:"var(--r-full)", background: a.sucursal_id === u.sucursal_id ? "var(--accent-soft)" : "var(--muted)", color: a.sucursal_id === u.sucursal_id ? "var(--accent)" : "var(--soft)", whiteSpace:"nowrap"}}>
                        {a.alias || a.nombre}
                      </span>
                    ))}
                  </div>
                );
              }},
              { key: 'estado', title: 'Estado', width: 90, render: u => <Badge tone={u.role === "SUSPENDIDO" ? "danger" : "success"} dot>{u.role === "SUSPENDIDO" ? "Suspendido" : "Activo"}</Badge> },
              {
                key: 'actions', title: 'Acciones', width: 100, align: 'right',
                render: u => (
                  <div className="actions">
                    {canEdit && <button className="icon-btn" title="Editar" onClick={() => { setEditing(u); setFormOpen(true); }}><Icon name="fa-edit" style={{fontSize:11}}/></button>}
                    {canEdit && u.id !== 1 && (
                      <button className="icon-btn" title={u.role === "SUSPENDIDO" ? "Reactivar" : "Suspender"} onClick={() => handleSuspend(u)}>
                        <Icon name={u.role === "SUSPENDIDO" ? "fa-toggle-off" : "fa-toggle-on"} style={{fontSize:13, color: u.role === "SUSPENDIDO" ? "var(--soft)" : "var(--success)"}}/>
                      </button>
                    )}
                  </div>
                )
              }
            ])}
          />
        )}
        <Pager from={skip+1} to={Math.min(skip+pageSize,total)} total={total} page={page} pages={pages} onPage={p=>setSkip((p-1)*pageSize)}/>
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════════════
   ROLES
   ═══════════════════════════════════════════════════════════ */
export function Roles({ onNav, user, effectivePermissions }) {
  const [rolesList, setRolesList] = useState([]);
  const [loading, setLoading]     = useState(true);
  const [formOpen, setFormOpen]   = useState(false);
  const [editing, setEditing]     = useState(null);

  const canEdit = (effectivePermissions || []).some(p => p.startsWith('roles.') && (p.endsWith('.edit') || p.endsWith('.create')));
  // Rol REAL del usuario (no el simulado): `roles` siempre refleja el rol
  // asignado, a diferencia de `role` que puede ser el simulado. Solo un ADMIN
  // real puede simular el rol ADMIN (espejo del guard del backend que evita la
  // escalada de un GERENTE a ADMIN vía el simulador).
  const realRole = (user?.roles && user.roles[0]) || user?.role;
  const puedeSimularAdmin = realRole === 'ADMIN';

  const load = () => rolesApi.list().then(r => setRolesList(r.data ?? [])).finally(() => setLoading(false));
  useEffect(() => { load(); }, []);

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Roles y permisos" sub="Definiciones de acceso por rol (Spatie)" diamond
        actions={canEdit ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => { setEditing(null); setFormOpen(true); }}>Nuevo rol</Button> : null}
      />
      {formOpen && <RolFormModal edit={editing} onClose={() => { setFormOpen(false); setEditing(null); }} onSaved={() => { setFormOpen(false); setEditing(null); load(); }}/>}
      <div className="grid-4">
        <KPI label="Roles definidos" value={rolesList.length} icon="fa-shield-halved"/>
        <KPI label="Permisos activos" value={rolesList.reduce((sum, r) => sum + (r.permissions_count || 0), 0)} icon="fa-key"/>
        <KPI label="Con usuarios" value={rolesList.filter(r => r.users_count > 0).length} icon="fa-users"/>
        <KPI label="Custom" value={rolesList.filter(r => !['ADMIN','SUSPENDIDO','GERENTE','VENDEDOR','CAJERO','OPERADOR'].includes(r.name)).length} icon="fa-wand-magic-sparkles"/>
      </div>
      {loading ? (
        <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
      ) : (
        <div className="grid-3">
          {rolesList.map(r => {
            const perms = r.permissions || [];
            const isAdmin = r.name === "ADMIN";
            const isSuspendido = r.name === "SUSPENDIDO";
            return (
              <Card key={r.id} pad={false}>
                <div style={{padding: 18}}>
                  <div className="row" style={{justifyContent:"space-between", alignItems:"flex-start", marginBottom: 12}}>
                    <div className="row" style={{gap: 10}}>
                      <div style={{width: 38, height: 38, borderRadius:"var(--r-md)", background: isAdmin ? "var(--ink)" : isSuspendido ? "var(--danger)" : "var(--muted)", color: (isAdmin || isSuspendido) ? "var(--surface)" : "var(--soft)", display:"grid", placeItems:"center"}}>
                        <Icon name={isSuspendido ? "fa-ban" : "fa-shield-halved"}/>
                      </div>
                      <div>
                        <div style={{fontSize: 16, fontWeight: 700, color:"var(--ink)"}}>{r.name}</div>
                        <div style={{fontSize: 11, color:"var(--soft)"}}>{isAdmin ? "Acceso total — Gate::before" : isSuspendido ? "Sin acceso al sistema" : `${r.permissions_count || perms.length} permisos`}</div>
                      </div>
                    </div>
                    {isAdmin && <Badge tone="warning" outline>Sistema</Badge>}
                    {isSuspendido && <Badge tone="danger" outline>Bloqueado</Badge>}
                  </div>
                  <div className="row" style={{gap: 6, flexWrap:"wrap", marginBottom: 14}}>
                    {(isAdmin ? ['Acceso total — bypass'] : perms).map((p, i) => (
                      <span key={i} style={{fontSize: 10, fontWeight: 700, padding: "3px 8px", borderRadius:"var(--r-full)", background:"var(--accent-soft)", color: "var(--accent)", letterSpacing:".02em"}}>{typeof p === 'string' ? p : p.name || p}</span>
                    ))}
                  </div>
                </div>
                <div className="row" style={{borderTop:"1px solid var(--line)", padding:"12px 18px", background:"var(--alt)", justifyContent:"space-between"}}>
                  <div>
                    {/* --dust como TEXTO (10px bold) falla contraste AA; --soft sí pasa (verificado loop 23). */}
                    <div style={{fontSize:10, fontWeight:700, color:"var(--soft)", letterSpacing:".06em", textTransform:"uppercase"}}>Permisos</div>
                    <div className="mono tabular" style={{fontSize: 15, fontWeight: 700, color:"var(--ink)", marginTop:2}}>{r.permissions_count || 0}</div>
                  </div>
                  <div className="row" style={{gap:6}}>
                    {/* Un no-ADMIN no puede simular ADMIN (evita escalada al bypass total). */}
                    {(!isAdmin || puedeSimularAdmin) && (
                    <Button variant="ghost" size="sm" icon="fa-mask" onClick={async () => {
                      if (!window.confirm(`¿Simular rol "${r.name}"? Verás el sistema como un usuario con este rol.`)) return;
                      try {
                        await usersApi.simulate(r.id);
                        alert(`✓ Simulando "${r.name}". Recarga la página para ver los cambios.`);
                        window.location.reload();
                      } catch(e) { alert(e?.response?.data?.error || 'Error al simular'); }
                    }}>Simular</Button>
                    )}
                    {canEdit && <Button variant="ghost" size="sm" icon="fa-edit" onClick={() => { setEditing(r); setFormOpen(true); }}>Editar</Button>}
                  </div>
                </div>
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}

/* ═══════════════════════════════════════════════════════════
   PERFIL
   ═══════════════════════════════════════════════════════════ */
export function Perfil({ onNav, user }) {
  const U = user || {};
  const initials = (U.name || "").split(" ").map(p=>p[0]).join("").substring(0,2).toUpperCase();
  const [tab, setTab] = useState("info");
  const [form, setForm] = useState({ name: U.name || '', email: U.email || '' });
  const [pwForm, setPwForm] = useState({ password: '', password_confirmation: '' });
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState(null);
  const set = k => e => setForm(f => ({...f, [k]: e.target.value}));
  const setPw = k => e => setPwForm(f => ({...f, [k]: e.target.value}));

  async function handleSaveInfo() {
    setSaving(true); setMsg(null);
    try {
      await profileApi.update({ name: form.name, email: form.email });
      setMsg({ ok: true, text: 'Datos actualizados correctamente.' });
    } catch { setMsg({ ok: false, text: 'Error al guardar los datos.' }); }
    finally { setSaving(false); }
  }

  async function handleSavePassword() {
    if (!pwForm.password) return;
    setSaving(true); setMsg(null);
    try {
      await profileApi.update({ name: form.name, email: form.email, ...pwForm });
      setPwForm({ password: '', password_confirmation: '' });
      setMsg({ ok: true, text: 'Contraseña actualizada correctamente.' });
    } catch { setMsg({ ok: false, text: 'Error al actualizar la contraseña.' }); }
    finally { setSaving(false); }
  }

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Mi perfil" sub="Información personal, sesión y preferencias"/>
      <div className="grid-12">
        <div className="stack" style={{"--gap":"16px"}}>
          <Card pad={false}>
            <div className="utabs" style={{padding: "0 18px"}}>
              {[
                { id: "info",    label: "Datos personales", icon: "fa-user" },
                { id: "seguridad", label: "Seguridad",       icon: "fa-lock" },
                { id: "sesiones",  label: "Sesiones activas", icon: "fa-clock-rotate-left" },
              ].map(t => (
                <button key={t.id} className={`t ${tab === t.id ? "active" : ""}`} onClick={() => setTab(t.id)}>
                  <Icon name={t.icon} style={{fontSize:11, marginRight: 6}}/>{t.label}
                </button>
              ))}
            </div>
            <div style={{padding: 24}}>
              {msg && (
                <div style={{padding:"10px 14px", marginBottom:12, background: msg.ok ? "rgba(34,197,94,.08)" : "var(--danger-soft)", border:`1px solid ${msg.ok?"rgba(34,197,94,.3)":"rgba(220,38,38,.25)"}`, borderRadius:"var(--r-md)", fontSize:13, color: msg.ok ? "var(--success)" : "var(--danger)"}}>
                  <Icon name={msg.ok ? "fa-circle-check" : "fa-circle-exclamation"} style={{marginRight:6}}/>{msg.text}
                </div>
              )}
              {tab === "info" && (
                <div className="grid-2" style={{gap: 16}}>
                  <div className="field"><label className="label">Nombre completo</label><input className="input" value={form.name} onChange={set('name')}/></div>
                  <div className="field"><label className="label">Correo</label><input className="input" value={form.email} onChange={set('email')} type="email"/></div>
                  <div className="field"><label className="label">Rol</label><input className="input" defaultValue={U.role} disabled/></div>
                  <div className="field"><label className="label">Sucursal</label><input className="input" defaultValue={U.sucursal?.nombre || U.sucursal_id} disabled/></div>
                  <div style={{gridColumn:"span 2", display:"flex", justifyContent:"flex-end", gap:8, paddingTop: 12, borderTop:"1px solid var(--line)"}}>
                    <Button variant="secondary" onClick={() => setForm({ name: U.name||'', email: U.email||'' })}>Descartar</Button>
                    <Button variant="accent" icon="fa-check" disabled={saving} onClick={handleSaveInfo}>
                      {saving ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Guardando…</> : "Guardar cambios"}
                    </Button>
                  </div>
                </div>
              )}
              {tab === "seguridad" && (
                <div className="stack" style={{"--gap":"18px", maxWidth: 460}}>
                  <div className="field"><label className="label">Nueva contraseña</label><input className="input" type="password" value={pwForm.password} onChange={setPw('password')} placeholder="Mínimo 8 caracteres"/></div>
                  <div className="field"><label className="label">Confirmar nueva contraseña</label><input className="input" type="password" value={pwForm.password_confirmation} onChange={setPw('password_confirmation')}/></div>
                  <div style={{display:"flex", justifyContent:"flex-end", gap:8}}>
                    <Button variant="accent" icon="fa-lock" disabled={saving || !pwForm.password} onClick={handleSavePassword}>
                      {saving ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Guardando…</> : "Actualizar contraseña"}
                    </Button>
                  </div>
                </div>
              )}
              {tab === "sesiones" && (
                <div className="stack" style={{"--gap":"10px"}}>
                  {[
                    { device: "Chrome · Windows 11", loc: "Sucursal Central · 192.168.1.42", when: "Activa ahora", current: true },
                    { device: "Safari · iPhone 15",  loc: "Móvil 4G",                       when: "Hace 2 horas", current: false },
                    { device: "Edge · Windows 10",   loc: "Sucursal Sur · 192.168.4.10",     when: "Ayer · 18:42", current: false },
                  ].map((s, i) => (
                    <div key={i} className="row" style={{padding:"14px 16px", border:"1px solid var(--line)", borderRadius:"var(--r-md)", gap: 14}}>
                      <div style={{width: 36, height: 36, borderRadius:"var(--r-md)", background:"var(--muted)", color:"var(--soft)", display:"grid", placeItems:"center"}}>
                        <Icon name={s.device.includes("iPhone") ? "fa-mobile-screen" : "fa-display"}/>
                      </div>
                      <div className="grow">
                        <div className="row" style={{gap: 8}}>
                          <span style={{fontSize: 13, fontWeight: 600, color:"var(--ink)"}}>{s.device}</span>
                          {s.current && <Badge tone="success" dot>Esta sesión</Badge>}
                        </div>
                        <div style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>{s.loc} · {s.when}</div>
                      </div>
                      {!s.current && <Button variant="ghost" size="sm" icon="fa-power-off" style={{color:"var(--danger)"}}>Cerrar</Button>}
                    </div>
                  ))}
                </div>
              )}
            </div>
          </Card>
        </div>

        <div className="stack" style={{"--gap":"16px"}}>
          <Card>
            <div style={{textAlign:"center", padding:"12px 0"}}>
              <div className="avatar lg" style={{width: 80, height: 80, fontSize: 24, borderRadius:"var(--r-xl)", margin:"0 auto"}}>{initials}</div>
              <div style={{fontSize: 16, fontWeight: 700, color:"var(--ink)", marginTop: 14}}>{U.name}</div>
              <div style={{fontSize: 11, color:"var(--soft)", letterSpacing:".08em", textTransform:"uppercase", fontWeight: 700, marginTop: 2}}>{U.role}</div>
              <div style={{marginTop: 14, padding: 10, background:"var(--alt)", borderRadius:"var(--r-md)", fontSize:11, color:"var(--soft)"}}>
                <Icon name="fa-envelope" style={{marginRight:6, color:"var(--dust)"}}/>{U.email}
              </div>
            </div>
          </Card>
          <Card title="Resumen del mes" pad={false}>
            {[
              { label: "Ventas registradas", value: 124 },
              { label: "Cotizaciones",       value: 42 },
              { label: "Caja abierta",       value: "21 días" },
              { label: "Último cierre",      value: "Hoy 14:32" },
            ].map((s, i) => (
              <div key={i} className="row" style={{padding:"10px 18px", borderBottom: i === 3 ? 0 : "1px solid var(--line-soft)", justifyContent:"space-between"}}>
                <span style={{fontSize:12, color:"var(--soft)"}}>{s.label}</span>
                <span className="mono tabular" style={{fontSize: 13, fontWeight: 700, color:"var(--ink)"}}>{s.value}</span>
              </div>
            ))}
          </Card>
        </div>
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════════════
   DATOS RAÍZ — Marcas / Industrias / Medios / Empresas / Localidades
   ═══════════════════════════════════════════════════════════ */
export function SimpleCrudScreen({ title, sub, icon, items, loading, formComponent, label, onEdit, onToggle, canEdit }) {
  const [q, setQ] = useState("");
  const [estadoFiltro, setEstadoFiltro] = useState("TODOS");
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [sortCol, setSortCol] = useState('id');
  const [sortDir, setSortDir] = useState('asc');
  const FormComp = formComponent;

  const filtered = items
    // Busca por nombre O por id (antes solo nombre → no se podía buscar por #id)
    .filter(i => {
      const ql = q.trim().toLowerCase();
      return !ql || (i.nombre || '').toLowerCase().includes(ql) || String(i.id).includes(ql.replace(/^#/, ''));
    })
    .filter(i => estadoFiltro === "TODOS" || (estadoFiltro === "ON" ? (!i.estado || i.estado === "ON") : i.estado === "OFF"))
    .sort((a, b) => {
      const va = a[sortCol] ?? '';
      const vb = b[sortCol] ?? '';
      const cmp = typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb));
      return sortDir === 'desc' ? -cmp : cmp;
    });

  const handleSort = (col, dir) => { setSortCol(col); setSortDir(dir); };

  const handleToggle = (item) => {
    const accion = (!item.estado || item.estado === 'ON') ? 'desactivar' : 'activar';
    if (!window.confirm(`¿${accion[0].toUpperCase()+accion.slice(1)} "${item.nombre}"?`)) return;
    onToggle(item.id);
  };

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title={title} sub={sub}
        actions={canEdit ? <>
          <Button variant="accent" icon="fa-plus" size="sm" onClick={() => { setEditing(null); setFormOpen(true); }}>{`Nueva ${label.toLowerCase()}`}</Button>
        </> : null}
      />
      {formOpen && <FormComp edit={editing} onClose={() => { setFormOpen(false); setEditing(null); }} onSaved={() => { setFormOpen(false); setEditing(null); if (onEdit) onEdit(); }}/>}
      <div className="grid-4">
        <KPI label={`Total ${label.toLowerCase()}s`} value={items.length} icon={icon}/>
        <KPI label="Activas" value={items.filter(i => !i.estado || i.estado === "ON").length} icon="fa-circle-check"/>
        <KPI label="Inactivas" value={items.filter(i => i.estado === "OFF").length} icon="fa-circle-pause"/>
        {(() => {
          const reciente = [...items].sort((a,b) => b.id - a.id)[0];
          return (
            <div className="card card-pad" style={{display:"flex", flexDirection:"column", justifyContent:"center"}}>
              <div style={{fontSize:11, fontWeight:700, color:"var(--soft)", letterSpacing:".04em", textTransform:"uppercase", marginBottom:6}}>
                <Icon name={icon} style={{marginRight:6, fontSize:11}}/>Más reciente
              </div>
              <div style={{fontSize:14, fontWeight:700, color:"var(--ink)", lineHeight:1.3, wordBreak:"break-word"}}>
                {reciente?.nombre || '—'}
              </div>
              <div style={{fontSize:11, color:"var(--soft)", marginTop:4}}>
                #{reciente?.id ?? '—'}
              </div>
            </div>
          );
        })()}
      </div>
      <div className="card">
        <div style={{padding: "12px 16px", display:"flex", gap:10, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div style={{flex:1, minWidth:200, maxWidth:360}}>
            <div className="filter-label">Búsqueda</div>
            <div className="input-group">
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:12}}/></span>
              <input className="input" placeholder={`Buscar ${label.toLowerCase()}…`} value={q} onChange={(e)=>setQ(e.target.value)}/>
            </div>
          </div>
          <div>
            <div className="filter-label">Estado</div>
            <div className="seg-tabs">
              {["TODOS","ON","OFF"].map(t => (
                <button key={t} className={`seg ${estadoFiltro === t ? "active" : ""}`} onClick={() => setEstadoFiltro(t)}>
                  {t === "TODOS" ? "Todos" : t === "ON" ? "Activas" : "Inactivas"}
                </button>
              ))}
            </div>
          </div>
        </div>
        {loading ? (
          <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
        ) : (
          <DataTable
            data={filtered}
            sortCol={sortCol}
            sortDir={sortDir}
            onSort={handleSort}
            columns={[
              { key: 'id', title: 'ID', width: 60, sortable: true, render: i => <span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>{i.id}</span> },
              { key: 'nombre', title: 'Nombre', sortable: true, className: 'strong', render: i => <span title={i.nombre}>{i.nombre}</span> },
              { key: 'estado', title: 'Estado', width: 110, render: i => <Badge tone={(!i.estado || i.estado === "ON") ? "success" : "danger"} dot style={{whiteSpace:"nowrap"}}>{(!i.estado || i.estado === "ON") ? "Activa" : "Inactiva"}</Badge> },
              {
                key: 'actions', title: '', width: 80, align: 'right',
                render: i => (
                  <div className="actions">
                    {/* aria-label/title en los icon-buttons → nombre accesible (axe button-name). */}
                    {canEdit && <button className="icon-btn" title={`Editar ${label || 'registro'}`} aria-label={`Editar ${label || 'registro'}`} onClick={() => { setEditing(i); setFormOpen(true); }}><Icon name="fa-edit" style={{fontSize:11}}/></button>}
                    {canEdit && onToggle && (() => {
                      const activo = (!i.estado || i.estado === "ON");
                      const accion = `${activo ? "Desactivar" : "Activar"} ${label || 'registro'}`;
                      return <button className="icon-btn" title={accion} aria-label={accion} onClick={() => handleToggle(i)}><Icon name={activo ? "fa-toggle-on" : "fa-toggle-off"} style={{fontSize:11}}/></button>;
                    })()}
                  </div>
                )
              }
            ]}
          />
        )}
      </div>
    </div>
  );
}

export function Marcas({ onNav, effectivePermissions }) {
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(true);
  const load = () => marcasApi.list().then(r => setItems(r.data ?? [])).finally(() => setLoading(false));
  useEffect(() => { load(); }, []);
  const handleSave   = async (edit, data) => { if (edit) await marcasApi.update(edit.id, data); else await marcasApi.store(data); load(); };
  const handleToggle = async (id) => { await marcasApi.toggle(id); load(); };
  const canEdit = (effectivePermissions || []).some(p => p.startsWith('marcas.') && (p.endsWith('.edit') || p.endsWith('.create')));
  return <SimpleCrudScreen title="Marcas" sub="Catálogo de marcas de productos (tabla marcas)"
    icon="fa-tag" label="Marca" items={items} loading={loading}
    onEdit={load} onToggle={handleToggle} canEdit={canEdit}
    formComponent={(props) => <NombreFormModal {...props} label="marca" icon="fa-tag" onSave={(d) => handleSave(props.edit, d)}/>}
  />;
}

export function Industrias({ onNav, effectivePermissions }) {
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(true);
  const load = () => industriasApi.list().then(r => setItems(r.data ?? [])).finally(() => setLoading(false));
  useEffect(() => { load(); }, []);
  const handleSave   = async (edit, data) => { if (edit) await industriasApi.update(edit.id, data); else await industriasApi.store(data); load(); };
  const handleToggle = async (id) => { await industriasApi.toggle(id); load(); };
  const canEdit = (effectivePermissions || []).some(p => p.startsWith('industrias.') && (p.endsWith('.edit') || p.endsWith('.create')));
  return <SimpleCrudScreen title="Industrias" sub="Sectores de industria (tabla industrias)"
    icon="fa-industry" label="Industria" items={items} loading={loading}
    onEdit={load} onToggle={handleToggle} canEdit={canEdit}
    formComponent={(props) => <NombreFormModal {...props} label="industria" icon="fa-industry" onSave={(d) => handleSave(props.edit, d)}/>}
  />;
}

export function Medios({ onNav, effectivePermissions }) {
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(true);
  const load = () => mediosApi.list().then(r => setItems(r.data ?? [])).finally(() => setLoading(false));
  useEffect(() => { load(); }, []);
  const handleSave   = async (edit, data) => { if (edit) await mediosApi.update(edit.id, data); else await mediosApi.store(data); load(); };
  const handleToggle = async (id) => { await mediosApi.toggle(id); load(); };
  const canEdit = (effectivePermissions || []).some(p => p.startsWith('medios.') && (p.endsWith('.edit') || p.endsWith('.create')));
  return <SimpleCrudScreen title="Medios de transporte" sub="Empresas de transporte para envíos (tabla medios)"
    icon="fa-truck" label="Medio" items={items} loading={loading}
    onEdit={load} onToggle={handleToggle} canEdit={canEdit}
    formComponent={(props) => <MedioFormModal {...props} onSave={(d) => handleSave(props.edit, d)}/>}
  />;
}

export function Empresas({ onNav, effectivePermissions }) {
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(true);
  const load = () => empresasApi.list().then(r => setItems(r.data ?? [])).finally(() => setLoading(false));
  useEffect(() => { load(); }, []);
  const handleSave   = async (edit, data) => { if (edit) await empresasApi.update(edit.id, data); else await empresasApi.store(data); load(); };
  const handleToggle = async (id) => { await empresasApi.toggle(id); load(); };
  const canEdit = (effectivePermissions || []).some(p => p.startsWith('empresas.') && (p.endsWith('.edit') || p.endsWith('.create')));
  return <SimpleCrudScreen title="Empresas" sub="Grupos empresariales de cuentas (tabla empresas)"
    icon="fa-building" label="Empresa" items={items} loading={loading} onEdit={load} onToggle={handleToggle} canEdit={canEdit}
    formComponent={(props) => <NombreFormModal {...props} label="empresa" icon="fa-building" onSave={(d) => handleSave(props.edit, d)}/>}
  />;
}

export function Localidades({ onNav, effectivePermissions }) {
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(true);
  const load = () => localidadesApi.list().then(r => setItems(r.data ?? [])).finally(() => setLoading(false));
  useEffect(() => { load(); }, []);
  const handleSave   = async (edit, data) => { if (edit) await localidadesApi.update(edit.id, data); else await localidadesApi.store(data); load(); };
  const handleToggle = async (id) => { await localidadesApi.toggle(id); load(); };
  const canEdit = (effectivePermissions || []).some(p => p.startsWith('localidades.') && (p.endsWith('.edit') || p.endsWith('.create')));
  return <SimpleCrudScreen title="Localidades" sub="Ciudades y regiones de Bolivia (tabla localidades)"
    icon="fa-map-pin" label="Localidad" items={items} loading={loading} onEdit={load} onToggle={handleToggle} canEdit={canEdit}
    formComponent={(props) => <NombreFormModal {...props} label="localidad" icon="fa-map-pin" onSave={(d) => handleSave(props.edit, d)}/>}
  />;
}
