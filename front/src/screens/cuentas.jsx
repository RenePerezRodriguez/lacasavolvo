/**
 * @fileoverview Pantalla de cuentas: clientes, proveedores y libro de saldos.
 */

import React, { useState, useEffect } from 'react';
import { useListData, useColumnVisibility } from '../lib/hooks.js';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, StatusBadge, Card, KPI, Empty, PageHead, Pager, PageSizeSelector, DataTable } from '../lib/components.jsx';
import { CuentaFormModal } from './forms.jsx';
import { cuentas as cuentasApi } from '../services/api.js';

/**
 * Listado paginado de cuentas (clientes/proveedores) con KPIs, búsqueda y CRUD.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function Cuentas({ onNav, sucursalId, user, effectivePermissions }) {
  const [tipo, setTipo]         = useState("TODOS");
  const [q, setQ]               = useState("");
  const [skip, setSkip]         = useState(0);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing]   = useState(null);
  const [pageSize, setPageSize] = useState(15);
  const [sort, setSort]         = useState({ col: 'nombre', dir: 'asc' });
  const { hiddenCols, toggleCol, visibleCols, showCols, setShowCols } = useColumnVisibility('cuentas', ['email','direccion']);
  const canEdit = (effectivePermissions || []).some(p => p.startsWith('cuentas.') && (p.endsWith('.edit') || p.endsWith('.create')));

  const { items: cuentas, total, kpis, loading, reload } = useListData(
    cuentasApi.list, cuentasApi.kpis,
    () => ({ skip, take: pageSize, sort: sort.col, dir: sort.dir, todos: 1, ...(tipo !== "TODOS" && { tipo }), ...(q && { search: q }) }),
    [tipo, q, skip, pageSize, sort, sucursalId]
  );

  const columns = [
    { key: 'avatar', title: '', width: 50, render: c => <div className="avatar sm" style={{background: c.tipo === "PROVEEDOR" ? "linear-gradient(135deg, var(--warning), #d97706)" : c.tipo === "CLIE-PROV" ? "linear-gradient(135deg, var(--accent), var(--success))" : undefined}}>{c.nombre.substring(0,2).toUpperCase()}</div> },
    { key: 'nombre', title: 'Nombre', sortable: true, className: 'strong' },
    { key: 'nit', title: 'NIT', sortable: true, width: 120, className: 'mono', render: c => <span style={{fontSize:11, color:"var(--soft)"}}>{c.nit || "—"}</span> },
    { key: 'telefono', title: 'Teléfono', sortable: true, width: 130, className: 'mono', render: c => <span style={{fontSize:11, color:"var(--soft)"}}>{c.telefono || "—"}</span> },
    { key: 'direccion', title: 'Dirección', sortable: true, width: 160, render: c => <span style={{fontSize:11, color:"var(--soft)"}}>{c.direccion || "—"}</span> },
    { key: 'departamento', title: 'Depto.', sortable: true, width: 110, render: c => <span style={{fontSize:11, color:"var(--soft)"}}>{c.departamento || "—"}</span> },
    { key: 'tipo', title: 'Tipo', sortable: true, width: 120, render: c => {
        const tipoTone = c.tipo === "PROVEEDOR" ? "warning" : c.tipo === "CLIE-PROV" ? "info" : "success";
        const tipoLabel = c.tipo === "CLIE-PROV" ? "Clie-Prov" : c.tipo[0] + c.tipo.slice(1).toLowerCase();
        return <Badge tone={tipoTone} dot>{tipoLabel}</Badge>;
    } },
    { key: 'saldo', title: 'Saldo', sortable: true, width: 120, className: 'right mono tabular', render: c => <span style={{color: c.saldo > 0 ? "var(--danger)" : "var(--soft)", fontWeight: c.saldo > 0 ? 700 : 400}}>Bs {c.saldo.toLocaleString(undefined,{minimumFractionDigits:2})}</span> },
    { key: 'acciones', title: 'Acciones', width: 90, className: 'right', render: c => (
      <div className="actions" onClick={e=>e.stopPropagation()}>
        <button className="icon-btn" title="Ver detalle" onClick={() => onNav({ name: 'cuenta-detail', id: c.id, cData: c })}><Icon name="fa-eye" style={{fontSize:11}}/></button>
        {canEdit && <button className="icon-btn" title="Editar" onClick={() => { setEditing(c); setFormOpen(true); }}><Icon name="fa-edit" style={{fontSize:11}}/></button>}
      </div>
    )}
  ];

  const page  = Math.floor(skip / pageSize) + 1;
  const pages = Math.ceil(total / pageSize);
  const handlePageSize = (n) => { setPageSize(n); setSkip(0); };

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Cuentas" sub="Clientes, proveedores y libro de saldos"
        actions={canEdit ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => { setEditing(null); setFormOpen(true); }}>Nueva cuenta</Button> : null}
      />
      {formOpen && <CuentaFormModal edit={editing} onClose={() => { setFormOpen(false); setEditing(null); }} onSaved={() => { setFormOpen(false); setEditing(null); setSkip(0); reload(); }}/>}
      <div className="grid-4">
        <KPI label="Clientes" value={kpis ? (kpis.clientes + kpis.dual) : "—"} icon="fa-users"/>
        <KPI label="Proveedores" value={kpis ? (kpis.proveedores + kpis.dual) : "—"} icon="fa-building"/>
        <KPI label="Con saldo" value={kpis?.con_saldo ?? "—"} icon="fa-clock" deltaTone="down"/>
        <KPI label="Saldo total" prefix="Bs " value={kpis ? Number(kpis.saldo_total).toLocaleString(undefined,{maximumFractionDigits:2}) : "—"} icon="fa-coins"/>
      </div>
      <div className="card">
        <div style={{padding: 14, display:"flex", gap:10, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div style={{flex:1, minWidth: 220}}>
            <div className="filter-label">Búsqueda</div>
            <div className="input-group">
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:12}}/></span>
              <input className="input" placeholder="Buscar por nombre o NIT…" value={q} onChange={(e)=>{setQ(e.target.value); setSkip(0);}}/>
            </div>
          </div>
          <div>
            <div className="filter-label">Tipo</div>
            <div className="seg-tabs">
              {["TODOS","CLIENTE","PROVEEDOR","CLIE-PROV"].map(t => (
                <button key={t} className={`seg ${tipo === t ? "active" : ""}`} onClick={()=>{setTipo(t); setSkip(0);}}>{t === "CLIE-PROV" ? "Ambos" : t[0]+t.slice(1).toLowerCase()}</button>
              ))}
            </div>
          </div>
          <div>
            <div className="filter-label">Pág.</div>
            <PageSizeSelector value={pageSize} onChange={handlePageSize}/>
          </div>
          <div style={{position:"relative"}}>
            <div className="filter-label">Columnas</div>
            <button className="btn btn-ghost btn-sm" onClick={() => setShowCols(!showCols)} style={{whiteSpace:"nowrap"}}>
              <Icon name="fa-columns" style={{fontSize:10,marginRight:4}}/>
              {columns.filter(c => !hiddenCols.has(c.key)).length}/{columns.length}
            </button>
            {showCols && (
              <div style={{position:"absolute",top:"100%",right:0,marginTop:4,background:"var(--surface)",border:"1px solid var(--line)",borderRadius:"var(--r-md)",boxShadow:"var(--sh-lg)",zIndex:20,padding:8,minWidth:200}}>
                {columns.filter(c => c.key !== 'acciones' && c.key !== '' && c.title).map(c => (
                  <label key={c.key} className="row" style={{gap:8,padding:"4px 8px",fontSize:11,cursor:"pointer",alignItems:"center"}}>
                    <input type="checkbox" checked={!hiddenCols.has(c.key)} onChange={() => toggleCol(c.key)} style={{margin:0}}/>
                    <span style={{fontWeight:500,color:"var(--ink)"}}>{c.title}</span>
                  </label>
                ))}
              </div>
            )}
          </div>
        </div>
        {loading ? (
          <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}>
            <Icon name="fa-spinner fa-spin" style={{fontSize:20}}/>
          </div>
        ) : (
          <DataTable
            data={cuentas}
            columns={visibleCols(columns)}
            sortCol={sort.col}
            sortDir={sort.dir}
            onSort={(col, dir) => { setSort({ col, dir }); setSkip(0); }}
            onRowClick={(row) => onNav({ name: 'cuenta-detail', id: row.id, cData: row })}
          />
        )}
        <Pager from={skip + 1} to={Math.min(skip + pageSize, total)} total={total} page={page} pages={pages} onPage={(p) => setSkip((p - 1) * pageSize)}/>
      </div>
    </div>
  );
}

/**
 * Detalle de cuenta: información + historial de compras, ventas, pagos y cobros.
 * @param {object} props
 * @param {number} props.cuentaId - ID de la cuenta.
 * @param {object} [props.cuentaData] - Datos precargados desde el listado.
 * @param {function(string|object): void} props.onNav - Navegación.
 * @returns {JSX.Element}
 */
export function CuentaDetail({ cuentaId, cuentaData, onNav }) {
  const [tab, setTab]       = useState("ventas");
  const [data, setData]     = useState({});
  const [loading, setLoading] = useState(true);
  const [c, setC]           = useState(cuentaData ?? null);

  useEffect(() => {
    setLoading(true);
    const calls = [
      cuentasApi.ventas(cuentaId),
      cuentasApi.compras(cuentaId),
      cuentasApi.cobros(cuentaId),
      cuentasApi.pagos(cuentaId),
    ];
    if (!c) calls.push(cuentasApi.show(cuentaId));
    Promise.all(calls)
      .then(([vRes, cRes, coRes, pRes, showRes]) => {
        setData({
          ventas:  vRes.data?.data  ?? vRes.data  ?? [],
          compras: cRes.data?.data  ?? cRes.data  ?? [],
          cobros:  coRes.data?.data ?? coRes.data ?? [],
          pagos:   pRes.data?.data  ?? pRes.data  ?? [],
        });
        if (showRes) setC(showRes.data);
      })
      .catch(logger.error)
      .finally(() => setLoading(false));
  }, [cuentaId]);

  const rows = data[tab] ?? [];
  const tipoTone = c?.tipo === "PROVEEDOR" ? "warning" : c?.tipo === "CLIE-PROV" ? "info" : "success";
  const tipoLabel = c?.tipo === "CLIE-PROV" ? "Cliente y Proveedor" : c?.tipo ? c.tipo[0] + c.tipo.slice(1).toLowerCase() : '—';

  if (loading) return <div style={{display:'grid',placeItems:'center',height:300}}><Icon name="fa-spinner fa-spin" style={{fontSize:24,color:'var(--soft)'}}/></div>;

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title={c?.nombre ?? `Cuenta #${cuentaId}`} sub={c?.nit ? `NIT: ${c.nit}` : 'Sin NIT registrado'}
        actions={<Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={()=>onNav('cuentas')}>Volver</Button>}
      />
      <div className="grid-12">
        <div className="stack" style={{"--gap":"16px"}}>
          <Card pad={false}>
            <div style={{padding:"8px 16px", borderBottom:"1px solid var(--line)"}}>
              <div className="seg-tabs">
                {["ventas","compras","cobros","pagos"].map(t => (
                  <button key={t} className={`seg ${tab===t?"active":""}`} onClick={()=>setTab(t)}>
                    {t[0].toUpperCase()+t.slice(1)}
                    <span style={{marginLeft:6, fontSize:10, background:"var(--muted)", borderRadius:10, padding:"1px 6px"}}>{(data[t]??[]).length}</span>
                  </button>
                ))}
              </div>
            </div>
            {rows.length === 0 ? <Empty text={`Sin ${tab} registrados`} icon="fa-inbox"/> : (
              tab === "ventas" || tab === "compras" ? (
                <table className="tbl">
                  <thead><tr>
                    <th style={{width:80}}>#</th>
                    <th style={{width:110}}>Fecha</th>
                    <th className="right" style={{width:150}}>Total</th>
                    <th style={{width:120}}>Estado</th>
                  </tr></thead>
                  <tbody>
                    {rows.map(r => (
                      <tr key={r.id} style={{cursor:"pointer"}} onClick={()=>onNav({ name: tab==="ventas"?'venta-detail':'compra-detail', id: r.id })}>
                        <td><span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{r.id}</span></td>
                        <td className="num">{r.fecha}</td>
                        <td className="right mono tabular strong">{r.total}</td>
                        <td><StatusBadge value={r.estado}/></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <table className="tbl">
                  <thead><tr>
                    <th style={{width:110}}>Fecha</th>
                    <th>Descripción</th>
                    <th className="right" style={{width:150}}>Monto</th>
                  </tr></thead>
                  <tbody>
                    {rows.map((r, i) => (
                      <tr key={i}>
                        <td className="num">{r.fecha}</td>
                        <td style={{fontSize:12, color:"var(--soft)"}}>{r.descripcion || '—'}</td>
                        <td className="right mono tabular strong" style={{color:"var(--success)"}}>{r.monto}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )
            )}
          </Card>
        </div>
        <div className="stack" style={{"--gap":"16px"}}>
          <Card title="Información">
            <div className="stack" style={{"--gap":"10px"}}>
              <div style={{textAlign:"center", marginBottom:8}}>
                <div className="avatar" style={{width:56,height:56,fontSize:20,margin:"0 auto",background: c?.tipo==="PROVEEDOR" ? "linear-gradient(135deg,var(--warning),#d97706)" : c?.tipo==="CLIE-PROV" ? "linear-gradient(135deg,var(--accent),var(--success))" : undefined}}>
                  {(c?.nombre||'').substring(0,2).toUpperCase()}
                </div>
                <div style={{marginTop:10,fontSize:15,fontWeight:700,color:"var(--ink)"}}>{c?.nombre}</div>
                <div style={{marginTop:4}}><Badge tone={tipoTone} dot>{tipoLabel}</Badge></div>
              </div>
              {[
                {label:"NIT",       value: c?.nit || '—'},
                {label:"Teléfono",  value: c?.telefono || '—'},
                {label:"Email",     value: c?.email || '—'},
                {label:"Dirección", value: c?.direccion || '—'},
                {label:"Saldo",     value: `Bs ${Number(c?.saldo??0).toLocaleString(undefined,{minimumFractionDigits:2})}`},
              ].map(r => (
                <div key={r.label} className="row" style={{justifyContent:"space-between",fontSize:12}}>
                  <span style={{color:"var(--soft)"}}>{r.label}</span>
                  <span style={{fontWeight:600,color: r.label==="Saldo" && (c?.saldo??0) > 0 ? "var(--danger)" : "var(--ink)"}}>{r.value}</span>
                </div>
              ))}
            </div>
          </Card>
          {c?.kpis && (
            <Card title="Resumen de actividad">
              <div className="grid-2" style={{gap:"10px", fontSize:12}}>
                <div><span style={{color:"var(--soft)"}}>Compras</span><br/><span style={{fontWeight:700}}>{c.kpis.compras_n}</span></div>
                <div><span style={{color:"var(--soft)"}}>Total comprado</span><br/><span style={{fontWeight:700}}>Bs {Number(c.kpis.compras_total).toLocaleString(undefined,{minimumFractionDigits:2})}</span></div>
                <div><span style={{color:"var(--soft)"}}>Ventas</span><br/><span style={{fontWeight:700}}>{c.kpis.ventas_n}</span></div>
                <div><span style={{color:"var(--soft)"}}>Total vendido</span><br/><span style={{fontWeight:700}}>Bs {Number(c.kpis.ventas_total).toLocaleString(undefined,{minimumFractionDigits:2})}</span></div>
                {c.kpis.compras_saldo > 0 && <div><span style={{color:"var(--soft)"}}>Saldo compras</span><br/><span style={{fontWeight:700, color:"var(--danger)"}}>Bs {Number(c.kpis.compras_saldo).toLocaleString(undefined,{minimumFractionDigits:2})}</span></div>}
                {c.kpis.ventas_saldo > 0 && <div><span style={{color:"var(--soft)"}}>Saldo ventas</span><br/><span style={{fontWeight:700, color:"var(--warning)"}}>Bs {Number(c.kpis.ventas_saldo).toLocaleString(undefined,{minimumFractionDigits:2})}</span></div>}
                {c.kpis.pagos_total > 0 && <div><span style={{color:"var(--soft)"}}>Total pagado</span><br/><span style={{fontWeight:700, color:"var(--success)"}}>Bs {Number(c.kpis.pagos_total).toLocaleString(undefined,{minimumFractionDigits:2})}</span></div>}
                {c.kpis.cobros_total > 0 && <div><span style={{color:"var(--soft)"}}>Total cobrado</span><br/><span style={{fontWeight:700, color:"var(--accent)"}}>Bs {Number(c.kpis.cobros_total).toLocaleString(undefined,{minimumFractionDigits:2})}</span></div>}
              </div>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
