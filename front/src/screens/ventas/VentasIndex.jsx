import React, { useState } from 'react';
import { useListData, useColumnVisibility } from '../../lib/hooks.js';
import { Icon, Button, Badge, StatusBadge, KPI, PageHead, Pager, PageSizeSelector, DataTable, PdfButton } from '../../lib/components.jsx';
import { ventas as ventasApi, openPdf } from '../../services/api.js';

/**
 * Listado paginado de ventas con KPIs, filtros de estado y búsqueda.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {function(number, object): void} props.onOpenVenta - Abre el detalle de una venta.
 * @param {number} props.sucursalId - ID de sucursal activa (filtra resultados).
 * @param {object} props.user - Usuario autenticado (para control de visibilidad por rol).
 * @param {string[]} props.effectivePermissions - Permisos efectivos del usuario.
 * @returns {JSX.Element}
 */
export function VentasIndex({ onNav, onOpenVenta, sucursalId, user, effectivePermissions }) {
  const [estado, setEstado]         = useState("TODOS");
  const [pagado, setPagado]         = useState("TODOS");
  const [q, setQ]                   = useState("");
  const [fechaDesde, setFechaDesde] = useState("");
  const [fechaHasta, setFechaHasta] = useState("");
  const [skip, setSkip]             = useState(0);
  const [pageSize, setPageSize]     = useState(15);
  const [sort, setSort]             = useState({ col: 'id', dir: 'desc' });
  const { hiddenCols, toggleCol, visibleCols, showCols, setShowCols } = useColumnVisibility('ventas', ['sucursal']);
  const canCreate      = (effectivePermissions || []).some(p => p === 'ventas.create');
  const effectiveRole  = user?.simulated_role_name || user?.role;
  const showMontoKpi   = !['VENDEDOR', 'VENDEDOR DENNIS', 'VTARIJA'].includes(effectiveRole);

  const { items: ventas, total, kpis, loading } = useListData(
    ventasApi.list, ventasApi.kpis,
    () => ({
      sucursal_id: sucursalId, // Enviamos el sucursal_id al backend
      skip, take: pageSize,
      sort: sort.col, dir: sort.dir,
      ...(estado !== "TODOS" && { estado_filtro: estado }),
      ...(pagado !== "TODOS" && { pagado_filtro: pagado }),
      ...(q && { search: q }),
      ...(fechaDesde && { fecha_desde: fechaDesde }),
      ...(fechaHasta && { fecha_hasta: fechaHasta }),
    }),
    [estado, pagado, q, fechaDesde, fechaHasta, skip, pageSize, sort, sucursalId]
  );

  const columns = [
    { key: 'id', title: '#', sortable: true, width: 80, render: v => <span className="mono" style={{fontWeight:700, color:"var(--ink)", fontSize:12}}>#{v.id}</span> },
    { key: 'fecha', title: 'Fecha', sortable: true, width: 110, className: 'num' },
    { key: 'cuenta', title: 'Cliente', sortable: true, className: 'strong' },
    { key: 'tipo', title: 'Tipo', sortable: true, width: 90, render: v => <Badge tone={v.tipo === "CONTADO" ? "neutral" : "info"} outline>{v.tipo}</Badge> },
    { key: 'total', title: 'Total', sortable: true, width: 140, className: 'right num strong' },
    { key: 'pagado', title: 'Pago', sortable: true, width: 120, render: v =>
        // Una PROFORMA no cobró nada todavía (el ingreso a caja recién entra al validar),
        // aunque el backend marque pagado='PAGADO' para las CONTADO desde su creación.
        // No mostramos estado de pago hasta que la venta esté validada.
        v.estado !== "VALIDO"
          ? <span style={{color:"var(--soft)", fontSize:12}}>—</span>
          : v.pagado === "PAGADO"
            ? <span style={{color:"var(--success)", fontWeight:600, fontSize:12}}><Icon name="fa-check-circle" style={{marginRight:4, fontSize:10}}/>Pagado</span>
            : <span style={{color:"var(--warning)", fontWeight:600, fontSize:12}}>Por cobrar</span> },
    { key: 'estado', title: 'Estado', sortable: true, width: 120, render: v => <StatusBadge value={v.estado}/> },
    { key: 'sucursal', title: 'Sucursal', width: 120, defaultHidden: true, render: v => <Badge tone="neutral" outline>{v.sucursal || '—'}</Badge> },
    { key: 'acciones', title: 'Acciones', width: 100, className: 'right', render: v => (
      <div className="actions" onClick={e=>e.stopPropagation()}>
        {v.estado === 'PROFORMA' && (
          <button className="icon-btn" title="Editar Proforma" onClick={() => onNav({ name: 'venta-nueva', id: v.id, vData: v })}><Icon name="fa-pen" style={{fontSize:11, color: "var(--accent)"}}/></button>
        )}
        <button className="icon-btn" title="Ver detalle" onClick={() => onOpenVenta(v.id, v)}><Icon name="fa-eye" style={{fontSize:11}}/></button>
        <PdfButton iconOnly onPdf={() => openPdf(`/ventas/${v.id}/pdf`)} />
      </div>
    )}
  ];

  const page  = Math.floor(skip / pageSize) + 1;
  const pages = Math.ceil(total / pageSize);
  const handlePageSize = (n) => { setPageSize(n); setSkip(0); };

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Ventas"
        sub="Gestión completa de ventas, proformas y cuentas por cobrar"
        actions={canCreate ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => onNav("venta-nueva")}>Nueva venta</Button> : null}
      />

      {/* Grid auto-fit: acomoda 4 (vendedor) o 5 (con monto) tarjetas sin huecos y sigue
          colapsando a 1 columna en móvil. La tarjeta "Anuladas" es un contador clickable
          que filtra el listado a ANULADO (pedido de QA: faltaba el contador de anulaciones). */}
      <div style={{display:"grid", gap:16, gridTemplateColumns:"repeat(auto-fit, minmax(180px, 1fr))"}}>
        <KPI label="Ventas registradas" value={kpis?.total ?? "—"} />
        <KPI label="Proforma" value={kpis?.proforma ?? "—"} />
        <KPI label="Válidas" value={kpis?.valido ?? "—"} />
        <div role="button" tabIndex={0} style={{cursor:"pointer"}}
          title="Ver solo ventas anuladas"
          onClick={() => { setEstado("ANULADO"); setSkip(0); }}
          onKeyDown={(ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); setEstado("ANULADO"); setSkip(0); } }}>
          <KPI label="Anuladas" value={kpis?.anulado ?? "—"} icon="fa-ban" />
        </div>
        {showMontoKpi && <KPI label="Total válidas" value={kpis?.monto ?? "—"} />}
      </div>

      <div className="card">
        <div style={{padding: 14, display:"flex", gap:10, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div style={{flex:1, minWidth: 220}}>
            <div className="filter-label">Búsqueda</div>
            <div className="input-group">
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:12}}/></span>
              <input className="input" placeholder="Buscar por #ID, cliente…" value={q} onChange={(e)=>{setQ(e.target.value); setSkip(0);}}/>
            </div>
          </div>
          <div>
            <div className="filter-label">Período</div>
            <div className="row" style={{gap:6}}>
              <input className="input" type="date" value={fechaDesde} title="Desde" style={{width:140}} onChange={e=>{setFechaDesde(e.target.value); setSkip(0);}}/>
              <input className="input" type="date" value={fechaHasta} title="Hasta" style={{width:140}} onChange={e=>{setFechaHasta(e.target.value); setSkip(0);}}/>
            </div>
          </div>
          <div>
            <div className="filter-label">Estado</div>
            <div className="seg-tabs">
              {["TODOS", "PROFORMA", "VALIDO", "ANULADO"].map(e => (
                <button key={e} className={`seg ${estado === e ? "active" : ""}`} onClick={()=>{setEstado(e); setSkip(0);}}>{e[0] + e.slice(1).toLowerCase()}</button>
              ))}
            </div>
          </div>
          <div>
            <div className="filter-label">Pago</div>
            <div className="seg-tabs">
              {[["TODOS","Todos"],["PAGADO","Pagado"],["POR PAGAR","Pendiente"]].map(([v,l]) => (
                <button key={v} className={`seg ${pagado === v ? "active" : ""}`} onClick={()=>{setPagado(v); setSkip(0);}}>{l}</button>
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
              <div style={{position:"absolute",top:"100%",right:0,marginTop:4,background:"var(--surface)",border:"1px solid var(--line)",borderRadius:"var(--r-md)",boxShadow:"var(--sh-lg)",zIndex:20,padding:8,minWidth:180}}>
                {columns.filter(c => c.key !== 'acciones').map(c => (
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
            data={ventas}
            columns={visibleCols(columns)}
            sortCol={sort.col}
            sortDir={sort.dir}
            onSort={(col, dir) => { setSort({ col, dir }); setSkip(0); }}
            onRowClick={(row) => onOpenVenta(row.id, row)}
          />
        )}

        <Pager from={skip + 1} to={Math.min(skip + pageSize, total)} total={total} page={page} pages={pages}
          onPage={(p) => setSkip((p - 1) * pageSize)} />
      </div>
    </div>
  );
}
