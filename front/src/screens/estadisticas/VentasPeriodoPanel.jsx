import React, { useState } from 'react';
import { Icon, Button, Card, KPI, DataTable } from '../../lib/components.jsx';
import { estadisticas as estadApi } from '../../services/api.js';
import logger from '../../lib/logger.js';
import { useSucursales, downloadBlob, CsvErrMsg, CsvLoadingOverlay, PanelIntro } from './utils.jsx';

/**
 * Panel de Ventas por Período.
 * Solo Agrupación + Sucursal van a la API (cambian el SQL).
 * Fechas y búsqueda son client-side (instantáneo).
 */
export function VentasPeriodoPanel({ user }) {
  const hoy    = new Date().toISOString().slice(0,10);
  const hace12m = new Date(Date.now() - 365*24*3600*1000).toISOString().slice(0,10);
  const sucursales = useSucursales();
  const isAdmin = user?.role === 'ADMIN' || user?.role === 'GERENTE';
  const [sucursal, setSucursal] = useState(isAdmin ? "0" : (user?.sucursal_id || ""));
  // Default: últimos 12 meses agrupados por MES (antes "día" sobre todo el historial
  // generaba miles de puntos ilegibles).
  const [desde, setDesde]       = useState(hace12m);
  const [hasta, setHasta]       = useState(hoy);
  const [gran, setGran]         = useState("month");
  const [data, setData]         = useState([]);
  const [loading, setLoading]   = useState(false);
  const [calculated, setCalculated] = useState(false);
  const [csvErr, setCsvErr]     = useState(false);
  const [csvLoading, setCsvLoading] = useState(false);
  const [sort, setSort]         = useState({ col: null, dir: 'desc' });
  const [search, setSearch]     = useState("");

  const sortedData = React.useMemo(() => {
    let filtered = data;
    // Las fechas ya se filtran en el backend (por fecha real). Acá solo búsqueda libre.
    // Antes se filtraba comparando etiquetas "2025-01"/"2025-W23" contra "YYYY-MM-DD"
    // como strings, lo que rompía el filtro en granularidad semana/mes (bug).
    if (search.trim()) {
      const q = search.toLowerCase().trim();
      filtered = filtered.filter(d => (d.periodo || '').toLowerCase().includes(q) || String(d.ventas ?? '').includes(q) || String(d.total ?? '').includes(q));
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

  const maxPeriodTotal = Math.max(0, ...sortedData.map(d => d.total ?? 0));
  const totalVentas = sortedData.reduce((s, d) => s + (d.ventas ?? 0), 0);
  const totalMonto  = sortedData.reduce((s, d) => s + (d.total  ?? 0), 0);
  const promedio    = sortedData.length ? Math.round(totalMonto / sortedData.length) : 0;
  const best        = sortedData.length ? sortedData.reduce((max, d) => (d.total ?? 0) > (max.total ?? 0) ? d : max, sortedData[0]) : null;
  const chartData   = sortedData;

  const load = () => {
    setLoading(true); setCalculated(false);
    // Fechas, sucursal y agrupación van al backend (filtra por fecha real, no por etiqueta).
    estadApi.ventasPeriodo({ vpDesde: desde, vpHasta: hasta, vpGran: gran, vpSucursal: sucursal })
      .then(r => {
        setData((r.data?.data ?? r.data ?? []).map(d => ({
          ...d,
          periodo: d.dia ?? d.periodo ?? '',
          total: parseFloat(d.total ?? 0),
        })));
        setCalculated(true);
      })
      .catch(logger.error)
      .finally(() => setLoading(false));
  };

  const handleCsv = () => {
    setCsvErr(false); setCsvLoading(true);
    estadApi.exportarVentasPeriodo({ vpDesde: desde, vpHasta: hasta, vpGran: gran, vpSucursal: sucursal })
      .then(r => { downloadBlob(r.data, 'ventas-periodo.csv'); })
      .catch(e => { logger.error('CSV ventas-periodo:', e); setCsvErr(true); setTimeout(() => setCsvErr(false), 5000); })
      .finally(() => setCsvLoading(false));
  };

  return (
    <div className="stack" style={{"--gap":"16px"}}>
      <PanelIntro icon="fa-chart-line" title="Ventas por período"
        question="¿Cómo evolucionaron las ventas a lo largo del tiempo?"
        purpose="Ver la tendencia por día, semana o mes y detectar picos o caídas."/>

      {/* ── ① Consulta: sucursal + agrupación → API ── */}
      <div className="card" style={{padding: "12px 16px"}}>
        <div style={{display:"flex", alignItems:"center", gap:8, marginBottom:10}}>
          <span style={{fontSize:11, fontWeight:700, textTransform:"uppercase", letterSpacing:".05em", color:"var(--soft)"}}>① Consulta</span>
          <span style={{fontSize:10, color:"var(--soft)"}}>Sucursal, fechas y agrupación</span>
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
          <div className="field">
            <label className="label" title="Define cómo se agrupan las ventas: por día, semana calendario o mes">Agrupar por</label>
            <select className="input" aria-label="Granularidad" value={gran} onChange={e => setGran(e.target.value)}>
              <option value="day">Día</option>
              <option value="week">Semana</option>
              <option value="month">Mes</option>
            </select>
          </div>
          <div style={{display:"flex", flexDirection:"column", gap:6, justifyContent:"flex-end"}}>
            <div style={{display:"flex", gap: 8}}>
              <Button variant="accent" icon="fa-calculator" onClick={load}>{loading ? "Calculando…" : "Calcular ventas"}</Button>
              <Button variant="secondary" icon={csvLoading ? "fa-spinner fa-spin" : "fa-download"} disabled={csvLoading} size="sm" onClick={handleCsv} title="Exportar a CSV (Excel)">{csvLoading ? "Exportando…" : "CSV"}</Button>
            </div>
            <CsvErrMsg show={csvErr}/>
          </div>
        </div>
      </div>

      {/* ── ② Filtros rápidos: fechas + búsqueda (client-side) ── */}
      <div className="card" style={{padding: "12px 16px"}}>
        <div style={{display:"flex", alignItems:"center", gap:8, marginBottom:10}}>
          <span style={{fontSize:11, fontWeight:700, textTransform:"uppercase", letterSpacing:".05em", color:"var(--soft)"}}>② Filtros</span>
          <span style={{fontSize:10, background:"var(--success-a15, #e6f9ed)", color:"var(--success)", padding:"1px 8px", borderRadius:99, fontWeight:600}}>al instante</span>
          <span style={{fontSize:10, color:"var(--soft)", marginLeft:"auto"}}>Buscá sin recargar</span>
        </div>
        <div className="grid-filters" style={{marginBottom:0}}>
          <div className="field" style={{flex:1}}>
            <label className="label">Buscar</label>
            <input className="input" placeholder="Período, monto…" value={search} onChange={e => { setSearch(e.target.value); }}/>
          </div>
        </div>
      </div>

      <CsvLoadingOverlay show={csvLoading} />

      {loading && <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>}

      {calculated && !loading && (
        <>
          <div className="grid-4">
            <KPI label="Total ventas" value={totalVentas} icon="fa-cart-shopping" sub="Transacciones en el período"/>
            <KPI label="Monto total" prefix="Bs " value={totalMonto.toLocaleString()} icon="fa-coins"/>
            <KPI label="Promedio por período" prefix="Bs " value={promedio.toLocaleString()} icon="fa-chart-line" sub={`Por ${gran === "day" ? "día" : gran === "week" ? "semana" : "mes"}`}/>
            <KPI label="Mejor período" value={best?.periodo || "—"} icon="fa-trophy" sub={best ? `Bs ${(best.total ?? 0).toLocaleString()}` : undefined}/>
          </div>

          {sortedData.length > 0 && (
            <Card title={`Ventas por ${gran === "day" ? "día" : gran === "week" ? "semana" : "mes"}`} meta={`${sortedData.length} períodos`} pad={false}>
              <div style={{padding: 20}}><PeriodChart data={chartData}/></div>
            </Card>
          )}

          <Card pad={false}>
            <DataTable
              data={sortedData}
              sortCol={sort.col}
              sortDir={sort.dir}
              onSort={(col, dir) => setSort({ col, dir })}
              columns={[
                { key: 'periodo', title: 'Período', sortable: true, tooltip: "Período analizado", className: "strong" },
                { key: 'ventas', title: 'Ventas', sortable: true, width: 120, tooltip: "Cantidad de ventas (transacciones) registradas en este período", className: "right mono tabular" },
                { key: 'total', title: 'Total Bs', sortable: true, width: 160, tooltip: "Suma de los totales de todas las ventas del período en bolivianos", className: "right mono tabular strong", render: d => `Bs ${(d.total ?? 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2})}` },
                { key: 'bar', title: 'Comparativa', width: 220, tooltip: "Barra proporcional al período con mayor monto", render: d => <div className="bar" style={{height:6}}><div className="fill" style={{width:`${maxPeriodTotal ? ((d.total ?? 0) / maxPeriodTotal) * 100 : 0}%`, background:"var(--accent)"}}></div></div> }
              ]}
            />
          </Card>
        </>
      )}
    </div>
  );
}

export function PeriodChart({ data }) {
  const W = 700, H = 220, P = { top: 16, right: 16, bottom: 45, left: 50 };
  const innerW = W - P.left - P.right;
  const innerH = H - P.top - P.bottom;
  const max = Math.max(...data.map(d => d.total)) * 1.1;
  const stepX = innerW / Math.max(1, data.length - 1);
  const pts = data.map((d, i) => `${P.left + i * stepX},${P.top + innerH - (d.total / max) * innerH}`);
  const ticks = 4;
  return (
    <svg viewBox={`0 0 ${W} ${H}`} style={{width:"100%", height: "auto", overflow:"visible"}}>
      {Array.from({length: ticks + 1}, (_, i) => {
        const y = P.top + (innerH / ticks) * i;
        const val = Math.round(max - (max / ticks) * i);
        return (
          <g key={i}>
            <line x1={P.left} x2={P.left + innerW} y1={y} y2={y} stroke="var(--line-soft)" strokeDasharray={i === ticks ? "none" : "2 4"} />
            <text x={P.left - 8} y={y + 4} fontSize="10" textAnchor="end" fill="var(--soft)" fontFamily="var(--f-mono)">{(val/1000).toFixed(0)}k</text>
          </g>
        );
      })}
      <defs>
        <linearGradient id="periodGrad" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="var(--accent)" stopOpacity="0.3"/>
          <stop offset="100%" stopColor="var(--accent)" stopOpacity="0"/>
        </linearGradient>
      </defs>
      <polygon points={`${pts.join(" ")} ${P.left + innerW},${P.top + innerH} ${P.left},${P.top + innerH}`} fill="url(#periodGrad)" />
      <polyline points={pts.join(" ")} fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
      {data.map((d, i) => {
        const cx = P.left + i * stepX;
        const cy = H - P.bottom + 12;
        const show = data.length > 15 ? (i % 2 === 0 || i === data.length - 1) : true;
        return (
          <g key={i}>
            <circle cx={cx} cy={P.top + innerH - (d.total / max) * innerH} r="3" fill="var(--accent)" />
            {show && (
              <text x={cx} y={cy} fontSize="10" textAnchor="end" fill="var(--soft)" fontWeight="600" transform={`rotate(-45 ${cx} ${cy})`}>
                {d.periodo}
              </text>
            )}
          </g>
        );
      })}
    </svg>
  );
}
