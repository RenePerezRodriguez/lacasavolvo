import React, { useState, useEffect } from 'react';
import { Icon, Button, Badge, Card, KPI, Pager, PageSizeSelector, DataTable } from '../../lib/components.jsx';
import { estadisticas as estadApi, sucursales as sucursalesApi } from '../../services/api.js';
import logger from '../../lib/logger.js';
import { useSucursales, downloadBlob, CsvErrMsg, RotacionBar, MiniStat, CsvLoadingOverlay, PanelIntro } from './utils.jsx';

export function RotacionPanel({ user, onVerSucursal }) {
  const hoy    = new Date().toISOString().slice(0,10);
  const hace90 = new Date(Date.now() - 90*24*60*60*1000).toISOString().slice(0,10);
  const sucursales = useSucursales();
  const isAdmin = user?.role === 'ADMIN' || user?.role === 'GERENTE';

  const [sucursal, setSucursal] = useState(isAdmin ? "0" : (user?.sucursal_id || ""));
  // Default: últimos 90 días de compras (la variable existía pero no se usaba).
  // Para ver rotación histórica completa, basta cambiar "Compra desde".
  const [desde, setDesde]       = useState(hace90);
  const [hasta, setHasta]       = useState(hoy);
  const [corte, setCorte]       = useState(hoy);
  const [data, setData]         = useState([]);
  const [loading, setLoading]   = useState(false);
  const [calculated, setCalculated] = useState(false);
  const [selectedCompra, setSelectedCompra] = useState(null);
  const [skip, setSkip]         = useState(0);
  const [pageSize, setPageSize] = useState(15);
  const [csvErr, setCsvErr]     = useState(false);
  const [csvLoading, setCsvLoading] = useState(false);
  const [sort, setSort]         = useState({ col: null, dir: 'desc' });
  const [search, setSearch]     = useState("");

  /**
   * Filtrado client-side: solo búsqueda por texto.
   * Fechas y sucursal van directo a la API (① Cálculo).
   */
  const sortedData = React.useMemo(() => {
    let filtered = data;
    if (search.trim()) {
      const q = search.toLowerCase().trim();
      filtered = filtered.filter(r =>
        String(r.id || '').includes(q) ||
        (r.proveedor || '').toLowerCase().includes(q) ||
        (r.sucursal || '').toLowerCase().includes(q) ||
        (r.fecha || '').includes(q)
      );
    }
    if (!sort.col) return filtered;
    return [...filtered].sort((a, b) => {
      let aVal = a[sort.col];
      let bVal = b[sort.col];
      if (typeof aVal === 'string') aVal = aVal.toLowerCase();
      if (typeof bVal === 'string') bVal = bVal.toLowerCase();
      if (aVal < bVal) return sort.dir === 'asc' ? -1 : 1;
      if (aVal > bVal) return sort.dir === 'asc' ? 1 : -1;
      return 0;
    });
  }, [data, sort, search]);

  /**
   * Carga datos desde la API con los valores actuales de los filtros.
   * Fechas y sucursal van a la API; solo el buscador es client-side.
   */
  const load = () => {
    setLoading(true); setCalculated(false);
    estadApi.rotacion({ rotDesde: desde, rotHasta: hasta, rotCorte: corte, take: 100, rotSucursal: sucursal })
      .then(r => {
        const raw = r.data?.data ?? r.data ?? [];
        setData(raw.map((row, index) => ({
          ...row,
          id:            parseInt(String(row.compra_id ?? '').replace('#', '')) || 0,
          uid:           `rot-${row.compra_id}-${row.codigo}-${index}`,
          cant:          row.cantidad_comprada ?? 0,
          vend:          row.ventas ?? 0,
          rot:           row.rotacion ?? 0,
          costo_unit:    parseFloat(String(row.costo_unitario ?? '0').replace('Bs. ', '').replace(/,/g, '')) || 0,
          costo_total:   parseFloat(String(row.costo_total ?? '0').replace('Bs. ', '').replace(/,/g, '')) || 0,
          utilidad:      parseFloat(String(row.utilidad ?? '0').replace('Bs. ', '').replace(/,/g, '')) || 0,
          lineas:        row.lineas ?? 1,
        })));
        setCalculated(true);
        setSkip(0);
      })
      .catch(logger.error)
      .finally(() => setLoading(false));
  };

  const handleCsv = () => {
    setCsvErr(false); setCsvLoading(true);
    estadApi.exportarRotacion({ rotDesde: desde, rotHasta: hasta, rotCorte: corte, rotSucursal: sucursal })
      .then(r => { downloadBlob(r.data, 'rotacion.csv'); })
      .catch(e => { logger.error('CSV rotacion:', e); setCsvErr(true); setTimeout(() => setCsvErr(false), 5000); })
      .finally(() => setCsvLoading(false));
  };

  const page  = Math.floor(skip / pageSize) + 1;
  const pages = Math.ceil(sortedData.length / pageSize);
  const rows  = sortedData.slice(skip, skip + pageSize);

  // KPIs sobre datos filtrados (sortedData), no sobre data cruda
  const comprasUnicas = new Set(sortedData.map(r => r.id)).size;
  const totalCosto    = sortedData.reduce((s, r) => s + (r.costo_total ?? 0), 0);
  const totalUtilidad = sortedData.reduce((s, r) => s + (r.utilidad ?? 0), 0);
  const avgRot  = sortedData.length ? Math.round(sortedData.reduce((s, r) => s + (r.rot ?? 0), 0) / sortedData.length) : 0;
  const margen  = totalCosto ? Math.round((totalUtilidad / totalCosto) * 100) : 0;

  return (
    <div className="stack" style={{"--gap":"16px"}}>
      <PanelIntro icon="fa-rotate" title="Rotación por compra"
        question="De cada orden de compra, ¿cuánto de lo comprado se vendió?"
        purpose="Decisiones de compra: ver si lo que comprás realmente rota. Toda la red, o por la sucursal que hizo la compra."/>

      {/* ── ① Consulta: parámetros que van a la API ── */}
      <div className="card" style={{padding: "12px 16px"}}>
        <div style={{display:"flex", alignItems:"center", gap:8, marginBottom:10}}>
          <span style={{fontSize:11, fontWeight:700, textTransform:"uppercase", letterSpacing:".05em", color:"var(--soft)"}}>① Consulta</span>
          <span style={{fontSize:10, color:"var(--soft)"}}>Define el alcance de los datos a analizar</span>
        </div>
        <div className="grid-filters" style={{marginBottom:0}}>
          <div className="field">
            <label className="label" title="Filtra por la sucursal que HIZO la compra; mide cuánto vendió esa misma sucursal (no sigue traslados).">Sucursal de la compra</label>
            <select className="input" aria-label="Sucursal de la compra" value={sucursal} onChange={e => setSucursal(e.target.value)}>
              {isAdmin && <option value="0">Toda la red</option>}
              {sucursales.map(s => <option key={s.id} value={s.id}>{s.nombre}</option>)}
            </select>
          </div>
          <div className="field">
            <label className="label" title="Fecha de la compra">Compra desde</label>
            <input className="input" type="date" aria-label="Compra desde" value={desde} onChange={e => setDesde(e.target.value)}/>
          </div>
          <div className="field">
            <label className="label" title="Fecha de la compra">Compra hasta</label>
            <input className="input" type="date" aria-label="Compra hasta" value={hasta} onChange={e => setHasta(e.target.value)}/>
          </div>
          <div className="field">
            <label className="label" title="Las ventas posteriores a esta fecha no se cuentan.">Fecha de corte</label>
            <input className="input" type="date" aria-label="Fecha de corte" value={corte} onChange={e => setCorte(e.target.value)}/>
          </div>
          <div style={{display:"flex", flexDirection:"column", gap:6, justifyContent:"flex-end"}}>
            <div style={{display:"flex", gap: 8}}>
              <Button variant="accent" icon="fa-calculator" onClick={load}>{loading ? "Calculando…" : "Calcular rotación"}</Button>
              <Button variant="secondary" icon={csvLoading ? "fa-spinner fa-spin" : "fa-download"} disabled={csvLoading} size="sm" onClick={handleCsv} title="Exportar tabla a CSV (Excel)">{csvLoading ? "Exportando…" : "CSV"}</Button>
            </div>
            <CsvErrMsg show={csvErr}/>
          </div>
        </div>
      </div>

      {/* ── ② Filtro rápido (client-side, sin recargar) ── */}
      <div className="card" style={{padding: "12px 16px"}}>
        <div style={{display:"flex", alignItems:"center", gap:8, marginBottom:10}}>
          <span style={{fontSize:11, fontWeight:700, textTransform:"uppercase", letterSpacing:".05em", color:"var(--soft)"}}>② Búsqueda</span>
          <span style={{fontSize:10, background:"var(--success-a15, #e6f9ed)", color:"var(--success)", padding:"1px 8px", borderRadius:99, fontWeight:600}}>al instante</span>
        </div>
        <div className="grid-filters" style={{marginBottom:0}}>
          <div className="field" style={{flex:1}}>
            <label className="label">Buscar en resultados</label>
            <input className="input" placeholder="N° compra, proveedor, sucursal…" value={search} onChange={e => { setSearch(e.target.value); setSkip(0); }}/>
          </div>
        </div>
      </div>

      {sucursal && String(sucursal) !== "0" && (
        <div className="card" style={{padding:"10px 14px", display:"flex", alignItems:"center", gap:10, fontSize:12.5, color:"var(--soft)", background:"var(--accent-a15)"}}>
          <Icon name="fa-circle-info" style={{color:"var(--accent)"}}/>
          <span>
            Esta vista cuenta solo ventas <strong>en la misma sucursal de la compra</strong>. Para una rotación que considera los traslados entre sucursales, mirá{" "}
            {onVerSucursal
              ? <a onClick={onVerSucursal} style={{color:"var(--accent)", fontWeight:700, cursor:"pointer", textDecoration:"underline"}}>Rotación por sucursal</a>
              : <strong style={{color:"var(--accent)"}}>Rotación por sucursal</strong>}.
          </span>
        </div>
      )}

      <CsvLoadingOverlay show={csvLoading} />

      {loading && <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>}

      {calculated && !loading && (
        <>
          <div className="grid-4">
            <KPI label="Compras" value={comprasUnicas} icon="fa-list" sub={sortedData.length >= 100 ? "Máx. 100 líneas" : `${sortedData.length} líneas`}/>
            <KPI label="Costo total" prefix="Bs " value={totalCosto.toLocaleString()} icon="fa-credit-card"/>
            <KPI label="Utilidad" prefix="Bs " value={totalUtilidad.toLocaleString()} delta={margen} since="margen"/>
            <KPI label="Rotación promedio" value={`${avgRot}%`} icon="fa-rotate"/>
          </div>
          <Card pad={false}>
            <div className="scroll-area" style={{overflowX: "auto"}}>
              <DataTable
                data={rows}
                columns={[
                  { key: 'id', title: 'N° Compra', sortable: true, width: 80, tooltip: "Número de la compra registrada", render: r => <span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{r.id}</span> },
                  { key: 'fecha', title: 'Fecha', sortable: true, width: 110, className: 'num' },
                  { key: 'sucursal', title: 'Sucursal', sortable: true, width: 100, render: r => <Badge tone="neutral" outline>{r.sucursal}</Badge> },
                  { key: 'proveedor', title: 'Proveedor', sortable: true, className: 'strong truncate', render: r => <div style={{maxWidth: 220}}>{r.proveedor}</div> },
                  { key: 'cant', title: 'Cant.', sortable: true, width: 70, className: 'right mono tabular' },
                  { key: 'vend', title: 'Vend.', sortable: true, width: 70, className: 'right mono tabular strong' },
                  { key: 'rot', title: '% Rotación', sortable: true, width: 130, render: r => <RotacionBar pct={r.rot ?? 0}/> },
                  { key: 'costo_unit', title: 'Costo unit.', sortable: true, width: 100, className: 'right mono tabular', render: r => `Bs ${(r.costo_unit ?? 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2})}` },
                  { key: 'costo_total', title: 'Costo total', sortable: true, width: 110, className: 'right mono tabular', render: r => `Bs ${(r.costo_total ?? 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2})}` },
                  { key: 'utilidad', title: 'Utilidad', sortable: true, width: 100, className: 'right mono tabular', render: r => <span style={{color:"var(--success)", fontWeight:700}}>Bs {(r.utilidad ?? 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2})}</span> },
                  { key: 'dias', title: 'Días', sortable: true, width: 70, className: 'center mono tabular', render: r => <span style={{color: (r.dias ?? 0) > 30 ? "var(--warning)" : "var(--soft)"}}>{r.dias ?? "—"}d</span> },
                  { key: 'acciones', title: '', width: 50, className: 'right', render: r => <button className="icon-btn" title="Ver detalle de la compra" onClick={e=>{e.stopPropagation(); setSelectedCompra(r);}}><Icon name="fa-eye" style={{fontSize:11}}/></button> }
                ]}
                sortCol={sort.col}
                sortDir={sort.dir}
                onSort={(col, dir) => { setSort({ col, dir }); setSkip(0); }}
                onRowClick={(row) => setSelectedCompra(row)}
              />
            </div>
            <div style={{display:"flex",alignItems:"center",justifyContent:"space-between",flexWrap:"wrap",gap:8,padding:"0 8px"}}>
              <PageSizeSelector value={pageSize} onChange={n=>{setPageSize(n);setSkip(0);}}/>
              <Pager from={skip+1} to={Math.min(skip+pageSize,sortedData.length)} total={sortedData.length} page={page} pages={Math.max(1,pages)} onPage={p=>setSkip((p-1)*pageSize)}/>
            </div>
          </Card>
        </>
      )}

      {selectedCompra && <RotacionDetalleModal compra={selectedCompra} sucursal={sucursal} onClose={() => setSelectedCompra(null)} />}
    </div>
  );
}

export function RotacionDetalleModal({ compra, sucursal, onClose }) {
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    // Pasa la sucursal del panel para que el FIFO del detalle coincida con la fila de la lista.
    estadApi.rotacionDetalle(compra.id, { rotSucursal: sucursal })
      .then(r => setItems(r.data?.items ?? []))
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
    return () => window.removeEventListener("keydown", onKey);
  }, [compra.id, sucursal]);

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()} style={{maxWidth: 920}}>
        <div style={{padding: "14px 18px", borderBottom:"1px solid var(--line)", display:"flex", alignItems:"center", justifyContent:"space-between"}}>
          <div>
            <h3 style={{fontSize: 15}}>Detalle de compra #{compra.id}</h3>
            <div style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>{compra.proveedor} · {compra.fecha} · {compra.sucursal}</div>
          </div>
          <button className="icon-btn" onClick={onClose}><Icon name="fa-xmark"/></button>
        </div>
        <div style={{padding: 16, display:"grid", gridTemplateColumns:"repeat(4, 1fr)", gap: 12, borderBottom: "1px solid var(--line)"}}>
          <MiniStat label="Líneas" value={compra.lineas ?? 1}/>
          <MiniStat label="Comprado" value={compra.cant}/>
          <MiniStat label="Vendido" value={compra.vend}/>
          <MiniStat label="Rotación" value={`${compra.rot}%`} tone={compra.rot >= 60 ? "var(--success)" : compra.rot >= 30 ? "var(--warning)" : "var(--danger)"}/>
        </div>
        <div className="scroll-area" style={{maxHeight: "50vh"}}>
          {loading ? (
            <div style={{padding: 40, textAlign:"center", color:"var(--soft)"}}>
              <Icon name="fa-spinner fa-spin" style={{fontSize: 20}}/>
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th style={{width: 130}}>Código</th>
                  <th>Descripción</th>
                  <th style={{width: 110}}>Marca</th>
                  <th className="right" style={{width: 70}}>Comp.</th>
                  <th className="right" style={{width: 70}}>Vend.</th>
                  <th style={{width: 130}}>Rotación</th>
                  <th className="right" style={{width: 90}}>Utilidad</th>
                  <th style={{width: 100}}>1ª venta</th>
                  <th style={{width: 100}}>Últ. venta</th>
                </tr>
              </thead>
              <tbody>
                {items.map((it, i) => (
                  <tr key={i}>
                    <td><span className="mono" style={{fontSize: 11, fontWeight: 700, color: "var(--accent)"}}>#{it.producto_id ?? it.id} {it.codigo}</span></td>
                    <td className="strong">{it.descripcion}</td>
                    <td><Badge tone="neutral" outline>{it.marca}</Badge></td>
                    <td className="right mono tabular">{it.cantidad}</td>
                    <td className="right mono tabular strong">{it.vendidos}</td>
                    <td><RotacionBar pct={it.rotacion ?? 0}/></td>
                    <td className="right mono tabular" style={{color: "var(--success)", fontWeight: 700}}>Bs {(it.utilidad ?? 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                    <td className="mono" style={{color:"var(--soft)", fontSize: 11}}>{it.primera_venta || "—"}</td>
                    <td className="mono" style={{color:"var(--soft)", fontSize: 11}}>{it.ultima_venta || "—"}</td>
                  </tr>
                ))}
                {items.length === 0 && (
                  <tr><td colSpan="9" style={{padding:32, textAlign:"center", color:"var(--soft)"}}>Sin detalle disponible</td></tr>
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
