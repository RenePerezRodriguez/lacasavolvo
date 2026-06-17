import React, { useState } from 'react';
import { Icon, Button, Badge, Card, KPI, DataTable } from '../../lib/components.jsx';
import { estadisticas as estadApi } from '../../services/api.js';
import logger from '../../lib/logger.js';
import { useSucursales, downloadBlob, CsvErrMsg, CsvLoadingOverlay, PanelIntro } from './utils.jsx';

/**
 * Panel de Top Clientes.
 * ① Consulta: sucursal, fechas, métrica y topN van a la API.
 * ② Búsqueda: filtrado client-side al instante.
 */
export function TopClientesPanel({ user }) {
  const hoy    = new Date().toISOString().slice(0,10);
  const sucursales = useSucursales();
  const isAdmin = user?.role === 'ADMIN' || user?.role === 'GERENTE';

  const [sucursal, setSucursal] = useState(isAdmin ? "0" : (user?.sucursal_id || ""));
  const [desde, setDesde]       = useState('2018-01-01');
  const [hasta, setHasta]       = useState(hoy);
  const [metric, setMetric]     = useState("monto");
  const [topN, setTopN]         = useState(25);
  const [data, setData]         = useState([]);
  const [loading, setLoading]   = useState(false);
  const [calculated, setCalculated] = useState(false);
  const [csvErr, setCsvErr]     = useState(false);
  const [csvLoading, setCsvLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState(null);
  const [sort, setSort]         = useState({ col: null, dir: 'desc' });
  const [search, setSearch]     = useState("");
  const [mostrador, setMostrador] = useState(null);

  // Re-rankeo client-side según métrica + topN
  const rankedData = React.useMemo(() => {
    const sorted = [...data].sort((a, b) => (b[metric] ?? 0) - (a[metric] ?? 0));
    return sorted.slice(0, topN).map((d, i) => ({ ...d, rank: i + 1 }));
  }, [data, metric, topN]);

  const sortedData = React.useMemo(() => {
    let filtered = rankedData;
    if (search.trim()) {
      const q = search.toLowerCase().trim();
      filtered = rankedData.filter(c =>
        (c.cliente || '').toLowerCase().includes(q) ||
        String(c.ventas ?? '').includes(q) ||
        String(c.monto ?? '').includes(q)
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
  const totalV = sortedData.reduce((s, d) => s + (d.ventas ?? 0), 0);
  const totalM = sortedData.reduce((s, d) => s + (d.monto  ?? 0), 0);
  const top1   = sortedData[0];

  const load = (metricArg) => {
    const met = metricArg ?? metric;
    setLoading(true); setCalculated(false); setErrorMsg(null);
    // Trae el Top 100 ordenado por la métrica elegida. El backend excluye la cuenta
    // de mostrador ("SIN NOMBRE") del ranking y la devuelve aparte en `mostrador`.
    estadApi.topClientes({ tcDesde: desde, tcHasta: hasta, tcMet: met, take: 100, tcSucursal: sucursal })
      .then(r => {
        const payload = r.data?.data ?? r.data ?? [];
        if (!Array.isArray(payload)) {
          throw new Error('Respuesta inesperada del servidor: ' + JSON.stringify(r.data));
        }
        setMostrador(r.data?.mostrador ?? null);
        setData(payload.map(c => ({
          ...c,
          cliente: c.cliente ?? '',
          ventas:  parseInt(c.ventas ?? 0),
          monto:   parseFloat(c.monto ?? 0),
          ticket:  parseFloat(c.ticket ?? 0),
        })));
        setCalculated(true);
      })
      .catch(e => {
        logger.error('TopClientes:', e);
        const msg = e?.response?.data?.message ?? e?.message ?? 'Error desconocido';
        setErrorMsg(msg);
      })
      .finally(() => setLoading(false));
  };

  const handleCsv = () => {
    setCsvErr(false); setCsvLoading(true);
    estadApi.exportarTopClientes({ tcDesde: desde, tcHasta: hasta, tcMet: metric, tcSucursal: sucursal })
      .then(r => { downloadBlob(r.data, 'top-clientes.csv'); })
      .catch(e => { logger.error('CSV top-clientes:', e); setCsvErr(true); setTimeout(() => setCsvErr(false), 5000); })
      .finally(() => setCsvLoading(false));
  };

  return (
    <div className="stack" style={{"--gap":"16px"}}>
      <PanelIntro icon="fa-users" title="Top clientes"
        question="¿Qué clientes compran más?"
        purpose="Identificar tus mejores clientes por monto o cantidad de compras (el mostrador se reporta aparte)."/>

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
              <option value="monto">Monto Bs</option>
              <option value="ventas">Cant. de compras</option>
            </select>
          </div>
          <div className="field">
            <label className="label" title="Cuántos clientes mostrar en el ranking">Top N</label>
            <select className="input" aria-label="Cantidad a mostrar" value={topN} onChange={e => setTopN(Number(e.target.value))}>
              <option value={10}>Top 10</option>
              <option value={25}>Top 25</option>
              <option value={50}>Top 50</option>
              <option value={100}>Top 100</option>
            </select>
          </div>
          <div className="field" style={{flex:1}}>
            <label className="label">Buscar</label>
            <input className="input" placeholder="Cliente, monto…" value={search} onChange={e => { setSearch(e.target.value); }}/>
          </div>
        </div>
      </div>

      <CsvLoadingOverlay show={csvLoading} />

      {loading && <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>}

      {errorMsg && !loading && (
        <div className="card" style={{padding:16, background:"var(--danger-soft)", border:"1px solid var(--danger)", color:"var(--danger)", display:"flex", alignItems:"center", gap:8}}>
          <Icon name="fa-triangle-exclamation" style={{fontSize:16}}/>
          <span style={{fontSize:13}}>{errorMsg}</span>
        </div>
      )}

      {calculated && !loading && (
        <>
          <div className="grid-4">
            <KPI label="Clientes en ranking" value={sortedData.length} icon="fa-users" sub={`Top ${topN} del período`}/>
            <KPI label="Total transacciones" value={totalV} icon="fa-cart-shopping" sub="Entre estos clientes"/>
            <KPI label="Monto total" prefix="Bs " value={totalM.toLocaleString()} icon="fa-coins"/>
            <KPI label="Mejor cliente" value={top1?.cliente || "—"} icon="fa-trophy" sub={top1?.cliente ? `Bs ${(top1.monto ?? 0).toLocaleString()}` : undefined}/>
          </div>
          {mostrador && mostrador.ventas > 0 && (
            <div className="card" style={{padding:"10px 14px", display:"flex", alignItems:"center", gap:10, fontSize:12.5, color:"var(--soft)"}}>
              <Icon name="fa-store" style={{color:"var(--accent)"}}/>
              <span><strong style={{color:"var(--ink)"}}>Mostrador (sin nombre):</strong> {mostrador.ventas.toLocaleString()} ventas · Bs {mostrador.monto.toLocaleString('es-BO',{minimumFractionDigits:2, maximumFractionDigits:2})} — <em>excluido del ranking para mostrar clientes reales.</em></span>
            </div>
          )}
          <Card pad={false}>
            <DataTable
              data={sortedData}
              sortCol={sort.col}
              sortDir={sort.dir}
              onSort={(col, dir) => setSort({ col, dir })}
              columns={[
                { key: 'rank', title: 'Pos.', sortable: true, width: 50, tooltip: "Posición en el ranking", render: p => <div style={{width:26, height:26, borderRadius:"var(--r-sm)", display:"grid", placeItems:"center", background: p.rank===1?"var(--ink)":p.rank===2?"var(--aster)":p.rank===3?"var(--warning)":"var(--muted)", color: p.rank<=3?"#fff":"var(--soft)", fontFamily:"var(--f-display)", fontWeight:700, fontSize:12}}>{p.rank}</div> },
                { key: 'cliente', title: 'Cliente / Razón Social', sortable: true, tooltip: "Nombre del cliente", className: "strong truncate", render: p => <div style={{maxWidth: 320}}>{p.cliente}</div> },
                { key: 'ventas', title: 'Compras', sortable: true, width: 100, tooltip: "Cantidad de compras que hizo el cliente en el período", className: "right mono tabular strong" },
                { key: 'ticket', title: 'Ticket prom.', sortable: true, width: 130, tooltip: "Monto promedio por cada compra", className: "right mono tabular text-soft", render: p => `Bs ${(p.ticket ?? 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2})}` },
                { key: 'monto', title: 'Monto total', sortable: true, width: 140, tooltip: "Monto total gastado por el cliente en el período", className: "right mono tabular strong", render: p => `Bs ${(p.monto ?? 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2})}` },
                { key: 'bar', title: metric === "monto" ? "Monto" : "Compras", width: 180, tooltip: `Barra proporcional al ${metric === "monto" ? "mayor monto" : "mayor número de compras"} del ranking`, render: p => <div className="bar" style={{height:6}}><div className="fill" style={{width:`${maxMetric ? ((p[metric] ?? 0) / maxMetric) * 100 : 0}%`, background:"var(--accent)"}}></div></div> }
              ]}
            />
          </Card>
        </>
      )}
    </div>
  );
}
