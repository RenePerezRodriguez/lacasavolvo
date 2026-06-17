import React, { useState } from 'react';
import { Icon, Button, Badge, Card, KPI, DataTable } from '../../lib/components.jsx';
import { estadisticas as estadApi } from '../../services/api.js';
import logger from '../../lib/logger.js';
import { useSucursales, downloadBlob, CsvErrMsg, RotacionBar, CsvLoadingOverlay, PanelIntro } from './utils.jsx';

/**
 * Panel de Rotación POR SUCURSAL (turnover real, considera traslados).
 *
 * Responde la pregunta de la sucursal: de TODO lo que ENTRÓ en el período
 * (comprado localmente + recibido por traslados, neto de devoluciones), ¿cuánto
 * se vendió? A diferencia de "Rotación por compra" (lente global / por orden de
 * compra), acá los envíos entre sucursales se contemplan por construcción.
 */
export function RotacionSucursalPanel({ user }) {
  const hoy    = new Date().toISOString().slice(0,10);
  const hace90 = new Date(Date.now() - 90*24*60*60*1000).toISOString().slice(0,10);
  const sucursales = useSucursales();

  const [sucursal, setSucursal] = useState(user?.sucursal_id || "");
  const [desde, setDesde]       = useState(hace90);
  const [hasta, setHasta]       = useState(hoy);
  const [data, setData]         = useState([]);
  const [resumen, setResumen]   = useState(null);
  const [loading, setLoading]   = useState(false);
  const [calculated, setCalculated] = useState(false);
  const [errorMsg, setErrorMsg] = useState(null);
  const [csvErr, setCsvErr]     = useState(false);
  const [csvLoading, setCsvLoading] = useState(false);
  const [sort, setSort]         = useState({ col: null, dir: 'desc' });
  const [search, setSearch]     = useState("");

  const sortedData = React.useMemo(() => {
    let filtered = data;
    if (search.trim()) {
      const q = search.toLowerCase().trim();
      filtered = filtered.filter(r =>
        (r.codigo || '').toLowerCase().includes(q) ||
        (r.descripcion || '').toLowerCase().includes(q) ||
        (r.marca || '').toLowerCase().includes(q)
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

  const fmt = (v) => (v ?? 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const load = () => {
    if (!sucursal) { setErrorMsg('Seleccioná una sucursal.'); return; }
    setLoading(true); setCalculated(false); setErrorMsg(null);
    estadApi.rotacionSucursal({ rsDesde: desde, rsHasta: hasta, rsSucursal: sucursal })
      .then(r => {
        setData((r.data?.data ?? []).map(d => ({
          ...d,
          comprado: parseFloat(d.comprado ?? 0), recibido: parseFloat(d.recibido ?? 0),
          despachado: parseFloat(d.despachado ?? 0), disponible: parseFloat(d.disponible ?? 0),
          vendido: parseFloat(d.vendido ?? 0), rotacion: parseFloat(d.rotacion ?? 0),
          utilidad: parseFloat(d.utilidad ?? 0),
        })));
        setResumen(r.data?.resumen ?? null);
        setCalculated(true);
      })
      .catch(e => {
        logger.error('RotacionSucursal:', e);
        setErrorMsg(e?.response?.data?.error ?? e?.message ?? 'Error al calcular la rotación.');
      })
      .finally(() => setLoading(false));
  };

  const handleCsv = () => {
    if (!sucursal) { setCsvErr(true); setTimeout(() => setCsvErr(false), 5000); return; }
    setCsvErr(false); setCsvLoading(true);
    estadApi.exportarRotacionSucursal({ rsDesde: desde, rsHasta: hasta, rsSucursal: sucursal })
      .then(r => { downloadBlob(r.data, 'rotacion-sucursal.csv'); })
      .catch(e => { logger.error('CSV rotacion-sucursal:', e); setCsvErr(true); setTimeout(() => setCsvErr(false), 5000); })
      .finally(() => setCsvLoading(false));
  };

  return (
    <div className="stack" style={{"--gap":"16px"}}>
      <PanelIntro icon="fa-store" title="Rotación por sucursal"
        question="De todo lo que entró a una sucursal (comprado + recibido por traslados), ¿cuánto vendió?"
        purpose="Decisiones de inventario por sucursal: detectar mercadería estancada. Contempla los traslados entre sucursales."/>

      {/* ── ① Consulta: sucursal + fechas → API ── */}
      <div className="card" style={{padding: "12px 16px"}}>
        <div style={{display:"flex", alignItems:"center", gap:8, marginBottom:10}}>
          <span style={{fontSize:11, fontWeight:700, textTransform:"uppercase", letterSpacing:".05em", color:"var(--soft)"}}>① Consulta</span>
          <span style={{fontSize:10, color:"var(--soft)"}}>Una sucursal y período</span>
        </div>
        <div className="grid-filters" style={{marginBottom:0}}>
          <div className="field">
            <label className="label">Sucursal</label>
            <select className="input" aria-label="Sucursal" value={sucursal} onChange={e => setSucursal(e.target.value)}>
              <option value="">Elegí una sucursal…</option>
              {sucursales.map(s => <option key={s.id} value={s.id}>{s.nombre}</option>)}
            </select>
          </div>
          <div className="field"><label className="label" title="Fecha de entrada de la mercadería">Desde</label><input className="input" type="date" aria-label="Desde" value={desde} onChange={e => setDesde(e.target.value)}/></div>
          <div className="field"><label className="label" title="Fecha de entrada de la mercadería">Hasta</label><input className="input" type="date" aria-label="Hasta" value={hasta} onChange={e => setHasta(e.target.value)}/></div>
          <div style={{display:"flex", flexDirection:"column", gap:6, justifyContent:"flex-end"}}>
            <div style={{display:"flex", gap: 8}}>
              <Button variant="accent" icon="fa-calculator" onClick={load}>{loading ? "Calculando…" : "Calcular rotación"}</Button>
              <Button variant="secondary" icon={csvLoading ? "fa-spinner fa-spin" : "fa-download"} disabled={csvLoading} size="sm" onClick={handleCsv} title="Exportar a CSV (Excel)">{csvLoading ? "Exportando…" : "CSV"}</Button>
            </div>
            <CsvErrMsg show={csvErr}/>
          </div>
        </div>
      </div>

      {/* ── ② Búsqueda (client-side) ── */}
      <div className="card" style={{padding: "12px 16px"}}>
        <div style={{display:"flex", alignItems:"center", gap:8, marginBottom:10}}>
          <span style={{fontSize:11, fontWeight:700, textTransform:"uppercase", letterSpacing:".05em", color:"var(--soft)"}}>② Búsqueda</span>
          <span style={{fontSize:10, background:"var(--success-a15, #e6f9ed)", color:"var(--success)", padding:"1px 8px", borderRadius:99, fontWeight:600}}>al instante</span>
          <span style={{fontSize:10, color:"var(--soft)", marginLeft:"auto"}}>
            Entrada = comprado + recibido por traslado · Rotación = vendido ÷ (entrada − despachado)
          </span>
        </div>
        <div className="grid-filters" style={{marginBottom:0}}>
          <div className="field" style={{flex:1}}>
            <label className="label">Buscar</label>
            <input className="input" placeholder="Código, descripción, marca…" value={search} onChange={e => setSearch(e.target.value)}/>
          </div>
        </div>
      </div>

      <CsvLoadingOverlay show={csvLoading} />

      {errorMsg && !loading && (
        <div className="card" style={{padding:16, background:"var(--danger-soft)", border:"1px solid var(--danger)", color:"var(--danger)", display:"flex", alignItems:"center", gap:8}}>
          <Icon name="fa-triangle-exclamation" style={{fontSize:16}}/>
          <span style={{fontSize:13}}>{errorMsg}</span>
        </div>
      )}

      {loading && <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>}

      {calculated && !loading && (
        <>
          <div className="grid-4">
            <KPI label="Productos" value={resumen?.productos ?? sortedData.length} icon="fa-cubes" sub={resumen?.sucursal || undefined}/>
            <KPI label="Entrada total" value={(resumen?.entrada_total ?? 0).toLocaleString()} icon="fa-arrow-down-to-bracket" sub="Comprado + recibido"/>
            <KPI label="Vendido total" value={(resumen?.vendido_total ?? 0).toLocaleString()} icon="fa-cart-shopping"/>
            <KPI label="Rotación promedio" value={`${resumen?.rotacion_promedio ?? 0}%`} icon="fa-rotate" sub={`Utilidad Bs ${fmt(resumen?.utilidad_total)}`}/>
          </div>

          {sortedData.length === 0 ? (
            <div className="card" style={{padding:32, textAlign:"center", color:"var(--soft)"}}>
              Sin entradas de mercadería en esta sucursal para el período elegido.
            </div>
          ) : (
            <Card pad={false}>
              <div className="scroll-area" style={{overflowX: "auto"}}>
                <DataTable
                  data={sortedData}
                  sortCol={sort.col}
                  sortDir={sort.dir}
                  onSort={(col, dir) => setSort({ col, dir })}
                  columns={[
                    { key: 'codigo', title: 'Código', sortable: true, width: 120, tooltip: "Código interno del producto", render: r => <span className="mono" style={{fontSize:11, fontWeight:700, color:"var(--accent)"}}>{r.codigo}</span> },
                    { key: 'descripcion', title: 'Descripción', sortable: true, tooltip: "Descripción del producto", className: "strong truncate", render: r => <div style={{maxWidth: 260}}>{r.descripcion}</div> },
                    { key: 'marca', title: 'Marca', sortable: true, width: 110, tooltip: "Marca", render: r => <Badge tone="neutral" outline>{r.marca}</Badge> },
                    { key: 'comprado', title: 'Comprado', sortable: true, width: 90, tooltip: "Unidades compradas por esta sucursal en el período (neto de devoluciones)", className: "right mono tabular" },
                    { key: 'recibido', title: 'Recibido', sortable: true, width: 90, tooltip: "Unidades recibidas por traslado desde otras sucursales (neto de devoluciones)", className: "right mono tabular" },
                    { key: 'despachado', title: 'Despachado', sortable: true, width: 100, tooltip: "Unidades enviadas a otras sucursales (salen del stock, no son venta)", className: "right mono tabular", render: r => r.despachado > 0 ? <span style={{color:"var(--warning)"}}>{r.despachado}</span> : <span style={{color:"var(--soft)"}}>0</span> },
                    { key: 'vendido', title: 'Vendido', sortable: true, width: 90, tooltip: "Unidades vendidas en esta sucursal (neto de devoluciones)", className: "right mono tabular strong" },
                    { key: 'rotacion', title: '% Rotación', sortable: true, width: 140, tooltip: "Vendido ÷ (entrada − despachado). Qué tanto del inventario disponible se vendió.", render: r => <RotacionBar pct={r.rotacion ?? 0}/> },
                    { key: 'utilidad', title: 'Utilidad', sortable: true, width: 110, tooltip: "Ingreso por ventas − costo de compra (p_comp) de lo vendido", className: "right mono tabular", render: r => <span style={{color: (r.utilidad ?? 0) >= 0 ? "var(--success)" : "var(--danger)", fontWeight:700}}>Bs {fmt(r.utilidad)}</span> },
                  ]}
                />
              </div>
            </Card>
          )}
        </>
      )}
    </div>
  );
}
