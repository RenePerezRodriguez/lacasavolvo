/**
 * @fileoverview Pantalla de catálogo de productos con gestión de stock,
 * KPIs de inventario, formulario de creación/edición y detalle de movimientos.
 */

import React, { useState, useEffect } from 'react';
import { useListData, useColumnVisibility } from '../lib/hooks.js';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, Card, KPI, Empty, PageHead, Pager, PageSizeSelector, DataTable, CopyableCode } from '../lib/components.jsx';
import { ProductoFormModal } from './forms.jsx';
import { productos as prodApi } from '../services/api.js';

/**
 * Listado paginado de productos con KPIs de inventario, búsqueda y formulario de edición.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function Productos({ onNav, sucursalId, user, effectivePermissions }) {
  const [view, setView]             = useState("table");
  const [q, setQ]                   = useState("");
  const [stockFilter, setStockFilter] = useState("TODOS");
  const [formOpen, setFormOpen]     = useState(false);
  const [editing, setEditing]       = useState(null);
  const [skip, setSkip]             = useState(0);
  const [pageSize, setPageSize]     = useState(15);
  const [sort, setSort]             = useState({ col: 'id', dir: 'desc' });
  const { hiddenCols, toggleCol, visibleCols, showCols, setShowCols } = useColumnVisibility('productos', ['industria']);
  const canCreate = (effectivePermissions || []).some(p => p === 'productos.create');
  // "Ajustar stock" navega a la ruta `ajustes`, que exige `productos.ajustes`. Hay que
  // gatear el botón con ESE permiso (no con productos.create): un vendedor sin ajustes
  // hacía clic y el guard de ruta lo rebotaba al inicio (bug reportado).
  const canAdjust = (effectivePermissions || []).some(p => p === 'productos.ajustes');
  const effectiveRole = user?.simulated_role_name || user?.role;
  const showCosto = ['ADMIN', 'GERENTE'].includes(effectiveRole);

  const { items: productos, total, kpis, loading, reload } = useListData(
    prodApi.list, prodApi.kpis,
    () => ({ skip, take: pageSize, sort: sort.col, dir: sort.dir, ...(q && { search: q }) }),
    [q, skip, pageSize, sort, sucursalId]
  );

  const columns = [
    { key: 'id', title: 'ID', sortable: true, width: 70, render: p => <span className="mono" style={{fontSize:11, fontWeight:600, color:"var(--soft)"}}>#{p.id}</span> },
    // Código copiable (observación de QA: era texto/enlace y al clickear navegaba al detalle,
    // no se podía copiar para pegarlo en el buscador). CopyableCode frena la navegación de fila.
    { key: 'codigo', title: 'Código', sortable: true, width: 150, render: p => <CopyableCode code={p.codigo} codeStyle={{fontSize:11, fontWeight:700, color:"var(--accent)"}}/> },
    { key: 'descripcion', title: 'Descripción', sortable: true, className: 'strong' },
    { key: 'marca', title: 'Marca', sortable: true, width: 120, render: p => <Badge tone="neutral" outline>{p.marca}</Badge> },
    { key: 'industria', title: 'Industria', sortable: true, width: 120, render: p => <span style={{fontSize:12, color:"var(--soft)"}}>{p.industria}</span> },
    { key: 'p_norm', title: 'P. Normal', sortable: true, width: 110, className: 'right mono tabular', render: p => `Bs ${p.p_norm}` },
    { key: 'p_fact', title: 'P. Factura', sortable: true, width: 110, className: 'right mono tabular strong', render: p => `Bs ${p.p_fact}` },
    ...(showCosto ? [{
      key: 'costo', title: 'Costo', sortable: true, width: 110, className: 'right',
      render: p => p.p_comp > 0
        ? <span className="mono tabular" style={{fontWeight:600}}>Bs {Number(p.p_comp).toLocaleString(undefined,{minimumFractionDigits:2})}</span>
        : <span style={{color:"var(--soft)"}}>—</span>
    }] : []),
    { key: 'stock', title: 'Stock', sortable: true, width: sucursalId === 1 ? 180 : 80, className: 'center', render: p => {
        // Colores uniformes (observación de QA): > 0 negro, = 0 rojo. Sin escalón naranja
        // intermedio — había saldos > 0 que salían en rojo y confundían. El umbral "stock
        // bajo (≤5)" sigue vivo como KPI y filtro, pero no tiñe las cantidades por sucursal.
        const tone = (s) => s <= 0 ? "var(--danger)" : "var(--ink)";
        if (sucursalId === 1 && p.stocks && p.stocks.length > 1) {
          return (
            <div style={{display:"flex", gap:4, justifyContent:"center", flexWrap:"wrap"}}>
              {p.stocks.map(s => (
                <span key={s.alias} className="mono tabular" style={{fontSize:10, fontWeight:700, color:tone(s.stock), background:"var(--alt)", padding:"1px 5px", borderRadius:4}}>
                  {s.alias}:{s.stock}
                </span>
              ))}
            </div>
          );
        }
        return <span className="mono tabular" style={{fontWeight:700, color:tone(p.stock), fontSize:13}}>{p.stock}</span>;
      } 
    },
    { key: 'estado', title: 'Estado', width: 90, render: p => <Badge tone={p.estado === "ON" ? "success" : "neutral"} outline>{p.estado === "ON" ? "Activo" : "Desc."}</Badge> },
    { key: 'accion', title: 'Acción', width: 80, className: 'right', render: p => (
        <div className="actions" onClick={e=>e.stopPropagation()}>
          <button className="icon-btn" title="Ver detalle" onClick={() => onNav({ name: 'producto-detail', id: p.id, pData: p })}><Icon name="fa-eye" style={{fontSize:11}}/></button>
          {canCreate && <button className="icon-btn" title="Editar" onClick={() => { setEditing(p); setFormOpen(true); }}><Icon name="fa-edit" style={{fontSize:11}}/></button>}
        </div>
      )
    }
  ];

  const filtered = productos.filter(p => {
    if (stockFilter === "BAJO" && !(p.stock > 0 && p.stock <= 5)) return false;
    if (stockFilter === "AGOTADO" && p.stock > 0) return false;
    return true;
  });

  const page  = Math.floor(skip / pageSize) + 1;
  const pages = Math.ceil(total / pageSize);
  const handlePageSize = (n) => { setPageSize(n); setSkip(0); };

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Productos" sub="Catálogo, stock de tu sucursal y precios"
        actions={<>
          {canAdjust && <Button variant="secondary" icon="fa-balance-scale" size="sm" onClick={() => onNav("ajustes")}>Ajustar stock</Button>}
          {canCreate && <Button variant="accent" icon="fa-plus" size="sm" onClick={() => { setEditing(null); setFormOpen(true); }}>Nuevo producto</Button>}
        </>}
      />
      {formOpen && <ProductoFormModal edit={editing} onClose={() => { setFormOpen(false); setEditing(null); reload(); }}/>}

      {/* El valor de inventario solo viaja (no null) para quien puede ver costos; si no,
          se oculta la tarjeta (decisión de negocio: no mostrarlo a vendedores). */}
      <div className={kpis?.valor_inventario != null ? "grid-4" : "grid-3"}>
        <KPI label="Productos activos" value={kpis?.activos ?? "—"} />
        <KPI label="Stock bajo (≤5)" value={kpis?.stock_critico ?? "—"} icon="fa-triangle-exclamation" />
        <KPI label="Agotados" value={kpis?.sin_stock ?? "—"} icon="fa-circle-xmark" />
        {kpis?.valor_inventario != null && (
          <KPI label="Valor inventario" prefix="Bs " value={Number(kpis.valor_inventario).toLocaleString(undefined, {maximumFractionDigits:0})} />
        )}
      </div>

      <div className="card">
        <div className="row" style={{padding: 14, gap: 10, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div style={{flex:1, minWidth: 220}}>
            <div className="filter-label">Búsqueda</div>
            <div className="input-group">
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:12}}/></span>
              <input className="input" placeholder="Buscar por código, descripción o marca…" value={q} onChange={(e)=>{setQ(e.target.value); setSkip(0);}}/>
            </div>
          </div>
          <div>
            <div className="filter-label">Stock</div>
            <div className="seg-tabs">
              {["TODOS", "BAJO", "AGOTADO"].map(s => (
                <button key={s} className={`seg ${stockFilter === s ? "active" : ""}`} onClick={()=>setStockFilter(s)}>{s[0]+s.slice(1).toLowerCase()}</button>
              ))}
            </div>
          </div>
          <div>
            <div className="filter-label">Vista</div>
            <div className="seg-tabs">
              <button className={`seg ${view === "table" ? "active" : ""}`} onClick={()=>setView("table")} aria-label="Vista de tabla" aria-pressed={view === "table"} title="Vista de tabla"><Icon name="fa-table" style={{fontSize: 11}}/></button>
              <button className={`seg ${view === "grid" ? "active" : ""}`} onClick={()=>setView("grid")} aria-label="Vista de cuadrícula" aria-pressed={view === "grid"} title="Vista de cuadrícula"><Icon name="fa-grip" style={{fontSize: 11}}/></button>
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
                {columns.filter(c => c.key !== 'accion' && c.key !== 'Acción').map(c => (
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
          <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
        ) : view === "table" ? (
          <DataTable
            data={filtered}
            columns={visibleCols(columns)}
            sortCol={sort.col}
            sortDir={sort.dir}
            onSort={(col, dir) => { setSort({ col, dir }); setSkip(0); }}
            onRowClick={(row) => onNav({ name: 'producto-detail', id: row.id, pData: row })}
          />
        ) : (
          <div style={{padding: 14, display:"grid", gridTemplateColumns:"repeat(auto-fill, minmax(230px, 1fr))", gap: 12}}>
            {filtered.map(p => (
              <div key={p.id} onClick={() => { if (window.getSelection && String(window.getSelection()).length > 0) return; onNav({ name: 'producto-detail', id: p.id, pData: p }); }}
                onMouseEnter={(e)=>{e.currentTarget.style.borderColor="var(--accent)"; e.currentTarget.style.boxShadow="var(--sh-md)";}}
                onMouseLeave={(e)=>{e.currentTarget.style.borderColor="var(--line)"; e.currentTarget.style.boxShadow="";}}
                style={{border:"1px solid var(--line)", borderRadius:"var(--r-md)", background:"var(--surface)", cursor:"pointer",
                  transition:"border-color .15s, box-shadow .15s", display:"flex", flexDirection:"column"}}>
                {/* Cabecera: código + badge stock */}
                <div style={{padding:"9px 12px", display:"flex", justifyContent:"space-between", alignItems:"center",
                  borderBottom:"1px solid var(--line)"}}>
                  <span className="mono" style={{fontSize:10.5, fontWeight:700, color:"var(--accent)",
                    background:"var(--accent-soft)", padding:"2px 7px", borderRadius:4}}>{p.codigo}</span>
                  <Badge tone={p.stock === 0 ? "danger" : p.stock <= 5 ? "warning" : "success"} dot>
                    {p.stock === 0 ? "Agotado" : p.stock <= 5 ? "Bajo" : "Stock"}
                  </Badge>
                </div>
                {/* Cuerpo: descripción + marca/industria */}
                <div style={{padding:"10px 12px", flex:1}}>
                  <div style={{fontSize:13, fontWeight:700, color:"var(--ink)", lineHeight:1.35, marginBottom:5}}>
                    {p.descripcion}
                  </div>
                  <div style={{fontSize:11, color:"var(--soft)"}}>
                    {p.marca}{p.industria ? ` · ${p.industria}` : ''}
                  </div>
                </div>
                {/* Pie: AMBOS precios (con y sin factura) + costo (admin) + stock + acciones.
                    La tabla muestra P. Normal y P. Factura; el grid debe ser consistente
                    (antes solo mostraba un precio — observación de QA). */}
                <div style={{padding:"8px 12px", borderTop:"1px solid var(--line)",
                  display:"flex", justifyContent:"space-between", alignItems:"center"}}>
                  <div>
                    <div className="mono tabular" style={{fontSize:15, fontWeight:700, color:"var(--ink)"}}>
                      Bs {p.p_fact} <span style={{fontSize:9, fontWeight:600, color:"var(--soft)"}}>c/f</span>
                    </div>
                    <div className="mono tabular" style={{fontSize:11, color:"var(--soft)"}}>
                      Bs {p.p_norm} <span style={{fontSize:9, fontWeight:600}}>s/f</span>
                    </div>
                    {showCosto && p.p_comp > 0 && (
                      <div className="mono tabular" style={{fontSize:10, color:"var(--soft)"}}>costo Bs {p.p_comp}</div>
                    )}
                  </div>
                  <div style={{display:"flex", alignItems:"center", gap:6}}>
                    <span className="mono tabular" style={{fontSize:11, color:"var(--soft)"}}>{p.stock} uds</span>
                    <div className="actions" onClick={e => e.stopPropagation()}>
                      <button className="icon-btn" title="Ver detalle"
                        onClick={() => onNav({ name: 'producto-detail', id: p.id, pData: p })}>
                        <Icon name="fa-eye" style={{fontSize:10}}/>
                      </button>
                      {canCreate && (
                        <button className="icon-btn" title="Editar"
                          onClick={() => { setEditing(p); setFormOpen(true); }}>
                          <Icon name="fa-pen" style={{fontSize:10}}/>
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        {view === "table" && <Pager from={skip + 1} to={Math.min(skip + pageSize, total)} total={total} page={page} pages={pages} onPage={(p) => setSkip((p - 1) * pageSize)}/>}
      </div>
    </div>
  );
}

/**
 * Detalle de producto: info, stock por sucursal e historial de movimientos.
 * @param {object} props
 * @param {number} props.productoId - ID del producto.
 * @param {object} [props.productoData] - Datos precargados desde el listado.
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {object} [props.user] - Usuario autenticado (para control de permisos).
 * @param {string[]} [props.effectivePermissions] - Permisos efectivos del usuario.
 * @returns {JSX.Element}
 */
export function ProductoDetail({ productoId, productoData, onNav, user, effectivePermissions }) {
  const [movimientos, setMovimientos] = useState([]);
  const [loading, setLoading]         = useState(true);
  const [p, setP]                     = useState(productoData ?? null);
  const [formOpen, setFormOpen]       = useState(false);
  const canCreate = (effectivePermissions || []).includes('productos.create');
  // "Ajustar stock" navega a `ajustes` (exige productos.ajustes). Sin este gate, un vendedor
  // hacía clic y el guard de ruta lo mandaba al inicio (bug reportado).
  const canAdjust = (effectivePermissions || []).includes('productos.ajustes');

  useEffect(() => {
    setLoading(true);
    Promise.all([
      prodApi.show(productoId).then(r => { setP(prev => ({ ...prev, ...r.data })); }),
      prodApi.movimientos(productoId).then(r => setMovimientos(r.data?.data ?? r.data ?? [])),
    ]).catch(logger.error).finally(() => setLoading(false));
  }, [productoId]);

  if (loading) return <div style={{display:'grid',placeItems:'center',height:300}}><Icon name="fa-spinner fa-spin" style={{fontSize:24,color:'var(--soft)'}}/></div>;

  const stockTone = !p ? "var(--soft)" : p.stock === 0 ? "var(--danger)" : p.stock <= 5 ? "var(--warning)" : "var(--success)";

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead
        title={p ? p.descripcion : `Producto #${productoId}`}
        sub={p ? `#${p.id} · ${p.codigo} · ${p.marca}` : ''}
        actions={<>
          <Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={()=>onNav('productos')}>Volver</Button>
          {canAdjust && <Button variant="secondary" icon="fa-balance-scale" size="sm" onClick={()=>onNav('ajustes')}>Ajustar stock</Button>}
          {canCreate && <Button variant="secondary" icon="fa-pen" size="sm" onClick={() => setFormOpen(true)}>Editar</Button>}
        </>}
      />
      <div className="grid-12">
        <div className="stack" style={{"--gap":"16px"}}>
          <Card pad={false}>
            <div style={{padding:"12px 16px", borderBottom:"1px solid var(--line)"}}>
              <span style={{fontSize:13, fontWeight:700, color:"var(--ink)"}}>Historial de movimientos</span>
            </div>
            {movimientos.length === 0 ? <Empty text="Sin movimientos registrados" icon="fa-clock-rotate-left"/> : (
              <table className="tbl">
                <thead><tr>
                  <th style={{width:110}}>Fecha</th>
                  <th style={{width:110}}>Tipo</th>
                  <th>Referencia</th>
                  {/* Precio de referencia (el precio con el que se vendió/compró el ítem).
                      Siempre visible — observación de QA: faltaba la columna. El costo de
                      compra ya viene null del backend para quien no puede ver costos. */}
                  <th className="right" style={{width:110}}>Precio ref.</th>
                  <th className="right" style={{width:100}}>Cantidad</th>
                </tr></thead>
                <tbody>
                  {movimientos.map((m, i) => {
                    const esIngreso = m.ingreso && m.ingreso !== '-';
                    const cantidad = esIngreso ? parseInt(m.ingreso) : parseInt(m.egreso);
                    const pos = esIngreso || cantidad === 0;
                    return (
                      <tr key={i}>
                        <td className="num">{m.fecha}</td>
                        <td><Badge tone={pos ? "success" : "warning"} dot>{m.tipo}</Badge></td>
                        <td style={{fontSize:12, color:"var(--soft)"}}>
                          {m.nombre || '—'}
                          {m.registro ? <span className="mono" style={{marginLeft:6, fontSize:10, color:"var(--accent)"}}>#{m.registro}</span> : null}
                        </td>
                        <td className="right mono tabular" style={{fontSize:12, color:"var(--soft)"}}>
                          {m.costo != null && Number(m.costo) > 0 ? `Bs ${Number(m.costo).toLocaleString(undefined,{minimumFractionDigits:2})}` : '—'}
                        </td>
                        <td className="right mono tabular" style={{fontWeight:700, color: pos ? "var(--success)" : "var(--warning)"}}>
                          {pos ? '+' : '−'}{Math.abs(cantidad || 0)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </Card>
        </div>
        <div className="stack" style={{"--gap":"16px"}}>
          {p && (
            <Card title="Información">
              <div className="stack" style={{"--gap":"10px"}}>
                {[
                  {label:"ID",         value: `#${p.id}`},
                  {label:"Código",    value: p.codigo},
                  {label:"Marca",     value: p.marca},
                  {label:"Industria", value: p.industria},
                  {label:"P. Normal", value: `Bs ${p.p_norm}`},
                  {label:"P. Factura",value: `Bs ${p.p_fact}`},
                  // P. Compra (COSTO) solo a quien puede ver costos: el backend manda
                  // p_comp=null si no → ocultamos la fila (no mostramos "—" engañoso).
                  ...(p.p_comp != null ? [{label:"P. Compra", value: `Bs ${p.p_comp}`}] : []),
                  {label:"Estado",    value: p.estado === "ON" ? "Activo" : "Descontinuado"},
                ].map(r => (
                  <div key={r.label} className="row" style={{justifyContent:"space-between",fontSize:12}}>
                    <span style={{color:"var(--soft)"}}>{r.label}</span>
                    <span style={{fontWeight:600,color:"var(--ink)"}}>{r.value}</span>
                  </div>
                ))}
              </div>
            </Card>
          )}
          <Card title="Stock por sucursal">
            <div style={{textAlign:"center", padding:"8px 0"}}>
              <div className="mono tabular" style={{fontSize:40, fontWeight:700, color:stockTone}}>{p?.stock ?? '—'}</div>
              <div style={{fontSize:11, color:"var(--soft)", marginTop:4}}>unidades en tu sucursal</div>
            </div>
            {p?.stocks && p.stocks.length > 0 && (
              <div style={{display:"flex", justifyContent:"center", gap:8, flexWrap:"wrap", marginTop:8, paddingTop:12, borderTop:"1px solid var(--line)"}}>
                {p.stocks.map(s => (
                  <div key={s.id} style={{background:"var(--alt)", border:"1px solid var(--line)", borderRadius:"var(--r-md)", padding:"8px 14px", textAlign:"center", minWidth:64}}>
                    <span style={{fontSize:10, fontWeight:700, textTransform:"uppercase", letterSpacing:".06em", color:"var(--soft)", display:"block"}}>{s.alias}</span>
                    <span style={{fontSize:18, fontWeight:800, color:s.stock <= 0 ? "var(--danger)" : "var(--ink)", fontVariantNumeric:"tabular-nums"}}>{s.stock}</span>
                  </div>
                ))}
              </div>
            )}
          </Card>
        </div>
      </div>
      {formOpen && p && (
        <ProductoFormModal
          edit={p}
          onClose={() => setFormOpen(false)}
          onSaved={() => {
            prodApi.show(productoId).then(r => setP(prev => ({ ...prev, ...r.data })));
          }}
        />
      )}
    </div>
  );
}
