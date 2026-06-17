import React, { useState } from 'react';
import { Icon, Button, Badge, Card, KPI, DataTable } from '../../lib/components.jsx';
import { estadisticas as estadApi } from '../../services/api.js';
import logger from '../../lib/logger.js';
import { useSucursales, downloadBlob, CsvErrMsg, CsvLoadingOverlay, PanelIntro } from './utils.jsx';

/**
 * Panel de Top Productos.
 * Carga única con rango amplio (2018→hoy) y topN máximo.
 * Filtros de fecha, sucursal y búsqueda son client-side.
 * Métrica y topN requieren recarga (afectan SQL).
 */
export function TopProductosPanel({ user }) {
  const hoy    = new Date().toISOString().slice(0,10);
  const sucursales = useSucursales();
  const isAdmin = user?.role === 'ADMIN' || user?.role === 'GERENTE';

  const [sucursal, setSucursal] = useState(isAdmin ? "0" : (user?.sucursal_id || ""));
  const [desde, setDesde]       = useState('2018-01-01');
  const [hasta, setHasta]       = useState(hoy);
  const [metric, setMetric]     = useState("unidades");
  const [topN, setTopN]         = useState(100);
  const [data, setData]         = useState([]);
  const [loading, setLoading]   = useState(false);
  const [calculated, setCalculated] = useState(false);
  const [csvErr, setCsvErr]     = useState(false);
  const [csvLoading, setCsvLoading] = useState(false);
  const [sort, setSort]         = useState({ col: null, dir: 'desc' });
  const [search, setSearch]     = useState("");

  // Re-rankeo client-side según métrica seleccionada + topN
  const rankedData = React.useMemo(() => {
    const sorted = [...data].sort((a, b) => (b[metric] ?? 0) - (a[metric] ?? 0));
    return sorted.slice(0, topN).map((d, i) => ({ ...d, rank: i + 1 }));
  }, [data, metric, topN]);

  const sortedData = React.useMemo(() => {
    let filtered = rankedData;
    if (search.trim()) {
      const q = search.toLowerCase().trim();
      filtered = filtered.filter(p =>
        (p.codigo || '').toLowerCase().includes(q) ||
        (p.descripcion || '').toLowerCase().includes(q) ||
        (p.marca || '').toLowerCase().includes(q)
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
  }, [rankedData, sort, search]);

  const maxMetric = Math.max(0, ...sortedData.map(d => d[metric] ?? 0));
  const totalU = sortedData.reduce((s, d) => s + (d.unidades ?? 0), 0);
  const totalM = sortedData.reduce((s, d) => s + (d.monto    ?? 0), 0);
  const top1   = sortedData[0];

  const load = (metricArg) => {
    const met = metricArg ?? metric;
    setLoading(true); setCalculated(false);
    // Trae el Top 100 ordenado por la MÉTRICA elegida. Antes pedía siempre por monto,
    // así el "top por unidades" omitía productos baratos de alto volumen (bug).
    estadApi.topProductos({ tpDesde: desde, tpHasta: hasta, tpMet: met, take: 100, tpSucursal: sucursal })
      .then(r => {
        setData((r.data?.data ?? r.data ?? []).map(p => ({
          ...p,
          unidades: parseInt(p.total_vendido ?? p.unidades ?? 0),
          monto:    parseFloat(p.total_monto ?? p.monto ?? 0),
        })));
        setCalculated(true);
      })
      .catch(logger.error)
      .finally(() => setLoading(false));
  };

  const handleCsv = () => {
    setCsvErr(false); setCsvLoading(true);
    estadApi.exportarTopProductos({ tpDesde: desde, tpHasta: hasta, tpMet: metric, tpSucursal: sucursal })
      .then(r => { downloadBlob(r.data, 'top-productos.csv'); })
      .catch(e => { logger.error('CSV top-productos:', e); setCsvErr(true); setTimeout(() => setCsvErr(false), 5000); })
      .finally(() => setCsvLoading(false));
  };

  return (
    <div className="stack" style={{"--gap":"16px"}}>
      <PanelIntro icon="fa-trophy" title="Top productos"
        question="¿Qué productos se venden más?"
        purpose="Foco comercial y reposición: ranking por unidades vendidas o por monto facturado."/>

      {/* ── ① Consulta: sucursal + fechas → API ── */}
      <div className="card" style={{padding: "12px 16px"}}>
        <div style={{display:"flex", alignItems:"center", gap:8, marginBottom:10}}>
          <span style={{fontSize:11, fontWeight:700, textTransform:"uppercase", letterSpacing:".05em", color:"var(--soft)"}}>① Consulta</span>
          <span style={{fontSize:10, color:"var(--soft)"}}>Sucursal y período</span>
        </div>
        <div className="grid-filters" style={{marginBottom:0}}>
          <div className="field">
            <label className="label">Sucursal</label>
            <select className="input" aria-label="Sucursal" value={sucursal} onChange={e => setSucursal(e.target.value)}>
              {isAdmin && <option value="0">Toda la red</option>}
              {sucursales.map(s => <option key={s.id} value={s.id}>{s.nombre}</option>)}
            </select>
          </div>
          <div className="field"><label className="label">Desde</label><input className="input" type="date" aria-label="Desde" value={desde} onChange={e => setDesde(e.target.value)}/></div>
          <div className="field"><label className="label">Hasta</label><input className="input" type="date" aria-label="Hasta" value={hasta} onChange={e => setHasta(e.target.value)}/></div>
          <div style={{display:"flex", flexDirection:"column", gap:6, justifyContent:"flex-end"}}>
            <div style={{display:"flex", gap: 8}}>
              <Button variant="accent" icon="fa-calculator" onClick={() => load()}>{loading ? "Calculando…" : "Calcular ranking"}</Button>
              <Button variant="secondary" icon={csvLoading ? "fa-spinner fa-spin" : "fa-download"} disabled={csvLoading} size="sm" onClick={handleCsv} title="Exportar a CSV (Excel)">{csvLoading ? "Exportando…" : "CSV"}</Button>
            </div>
            <CsvErrMsg show={csvErr}/>
          </div>
        </div>
      </div>

      {/* ── ② Filtros rápidos: métrica + topN + búsqueda (client-side) ── */}
      <div className="card" style={{padding: "12px 16px"}}>
        <div style={{display:"flex", alignItems:"center", gap:8, marginBottom:10}}>
          <span style={{fontSize:11, fontWeight:700, textTransform:"uppercase", letterSpacing:".05em", color:"var(--soft)"}}>② Filtros</span>
          <span style={{fontSize:10, background:"var(--success-a15, #e6f9ed)", color:"var(--success)", padding:"1px 8px", borderRadius:99, fontWeight:600}}>al instante</span>
          <span style={{fontSize:10, color:"var(--soft)", marginLeft:"auto"}}>Cambiá orden y top sin recargar</span>
        </div>
        <div className="grid-filters" style={{marginBottom:0}}>
          <div className="field">
            <label className="label" title="Criterio de ordenamiento del ranking (recalcula el Top 100 en el servidor)">Ordenar por</label>
            <select className="input" aria-label="Métrica" value={metric} onChange={e => { const v = e.target.value; setMetric(v); if (calculated) load(v); }}>
              <option value="unidades">Unidades vendidas</option>
              <option value="monto">Monto Bs</option>
            </select>
          </div>
          <div className="field">
            <label className="label" title="Cuántos productos mostrar en el ranking">Top N</label>
            <select className="input" aria-label="Cantidad a mostrar" value={topN} onChange={e => setTopN(Number(e.target.value))}>
              <option value={10}>Top 10</option>
              <option value={25}>Top 25</option>
              <option value={50}>Top 50</option>
              <option value={100}>Top 100</option>
            </select>
          </div>
          <div className="field" style={{flex:1}}>
            <label className="label">Buscar</label>
            <input className="input" placeholder="Código, descripción, marca…" value={search} onChange={e => { setSearch(e.target.value); }}/>
          </div>
        </div>
      </div>

      <CsvLoadingOverlay show={csvLoading} />

      {loading && <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>}

      {calculated && !loading && (
        <>
          <div className="grid-4">
            <KPI label="Productos distintos" value={sortedData.length} icon="fa-cubes" sub={`Top ${topN} del período`}/>
            <KPI label="Unidades vendidas" value={totalU.toLocaleString()} icon="fa-boxes-stacked" sub="Total acumulado"/>
            <KPI label="Monto total" prefix="Bs " value={totalM.toLocaleString()} icon="fa-coins"/>
            <KPI label="Top producto" value={top1?.codigo || "—"} icon="fa-trophy" sub={top1?.descripcion ? top1.descripcion.substring(0,30) : undefined}/>
          </div>
          <Card pad={false}>
            <DataTable
              data={sortedData}
              sortCol={sort.col}
              sortDir={sort.dir}
              onSort={(col, dir) => setSort({ col, dir })}
              columns={[
                { key: 'rank', title: 'Pos.', sortable: true, width: 50, tooltip: "Posición en el ranking", render: p => <div style={{width:26, height:26, borderRadius:"var(--r-sm)", display:"grid", placeItems:"center", background: p.rank===1?"var(--ink)":p.rank===2?"var(--aster)":p.rank===3?"var(--warning)":"var(--muted)", color: p.rank<=3?"#fff":"var(--soft)", fontFamily:"var(--f-display)", fontWeight:700, fontSize:12}}>{p.rank}</div> },
                { key: 'codigo', title: 'Código', sortable: true, width: 130, tooltip: "Código interno del producto en el sistema", render: p => <span className="mono" style={{fontSize:11, fontWeight:700, color:"var(--accent)"}}>{p.codigo}</span> },
                { key: 'descripcion', title: 'Descripción', sortable: true, tooltip: "Descripción del producto", className: "strong" },
                { key: 'marca', title: 'Marca', sortable: true, width: 120, tooltip: "Marca del fabricante", render: p => <Badge tone="neutral" outline>{p.marca}</Badge> },
                { key: 'unidades', title: 'Unidades', sortable: true, width: 100, tooltip: "Total de unidades vendidas en el período seleccionado", className: "right mono tabular strong" },
                { key: 'monto', title: 'Monto Bs', sortable: true, width: 130, tooltip: "Monto total facturado por este producto en el período", className: "right mono tabular", render: p => `Bs ${(p.monto ?? 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2})}` },
                { key: 'bar', title: metric === "unidades" ? "Unidades" : "Monto", width: 180, tooltip: `Barra proporcional al ${metric === "unidades" ? "mayor número de unidades" : "mayor monto"} del ranking`, render: p => <div className="bar" style={{height:6}}><div className="fill" style={{width:`${maxMetric ? ((p[metric] ?? 0) / maxMetric) * 100 : 0}%`, background:"var(--accent)"}}></div></div> }
              ]}
            />
          </Card>
        </>
      )}
    </div>
  );
}
