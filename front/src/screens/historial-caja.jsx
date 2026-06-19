/**
 * @fileoverview Historial de caja: aperturas pasadas con tranzas, compras y ventas.
 */

import React, { useState, useEffect } from 'react';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, KPI, Empty, PageHead } from '../lib/components.jsx';
import { caja as cajaApi } from '../services/api.js';
import { claseLabel } from '../lib/clase.js';

function AperturaDetalleModal({ apertura, onClose }) {
  const [tab, setTab]     = useState("tranzas");
  const [tranzas, setTranzas]   = useState([]);
  const [compras, setCompras]   = useState([]);
  const [ventas, setVentas]     = useState([]);
  const [loading, setLoading]   = useState(true);

  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    Promise.all([cajaApi.tranzas(apertura.id), cajaApi.compras(apertura.id), cajaApi.ventasCaja(apertura.id)])
      .then(([tRes, cRes, vRes]) => {
        setTranzas(tRes.data?.data ?? []);
        setCompras(cRes.data?.data ?? []);
        setVentas(vRes.data?.data ?? []);
      })
      .catch(logger.error)
      .finally(() => setLoading(false));
    return () => window.removeEventListener("keydown", onKey);
  }, [apertura.id]);

  const rows = tab === "tranzas" ? tranzas : tab === "compras" ? compras : ventas;

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{maxWidth: 900}}>
        <div style={{padding:"14px 18px", borderBottom:"1px solid var(--line)", display:"flex", alignItems:"center", justifyContent:"space-between"}}>
          <div>
            <h3 style={{fontSize:15}}>Apertura #{apertura.id} — {apertura.fecha}</h3>
            <div style={{fontSize:11, color:"var(--soft)", marginTop:2}}>Monto inicial: Bs {Number(apertura.monto).toLocaleString(undefined,{minimumFractionDigits:2})} · {apertura.cerrado ? "Cerrada" : "Abierta"}</div>
          </div>
          <button className="icon-btn" onClick={onClose} aria-label="Cerrar"><Icon name="fa-xmark"/></button>
        </div>
        <div style={{padding:"8px 16px", borderBottom:"1px solid var(--line)"}}>
          <div className="seg-tabs">
            {["tranzas","compras","ventas"].map(t => (
              <button key={t} className={`seg ${tab===t?"active":""}`} onClick={()=>setTab(t)}>
                {t[0].toUpperCase()+t.slice(1)}
              </button>
            ))}
          </div>
        </div>
        <div style={{maxHeight:"55vh", overflowY:"auto"}}>
          {loading ? (
            <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
          ) : tab === "tranzas" ? (
            <table className="tbl">
              <thead><tr>
                <th style={{width:70}}>#</th><th style={{width:110}}>Fecha</th>
                <th style={{width:80}}>Clase</th><th>Descripción</th>
                <th className="right" style={{width:130}}>Ingreso</th>
                <th className="right" style={{width:130}}>Egreso</th>
              </tr></thead>
              <tbody>
                {rows.map(r => (
                  <tr key={r.id}>
                    <td><span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{r.id}</span></td>
                    <td className="num">{r.fecha}</td>
                    <td><Badge tone="neutral" outline><span title={r.clase}>{claseLabel(r.clase)}</span></Badge></td>
                    <td>{r.descripcion}</td>
                    <td className="right mono tabular" style={{color:"var(--success)", fontWeight: r.ingreso > 0 ? 600 : 400}}>{r.ingreso > 0 ? `Bs ${Number(r.ingreso).toLocaleString(undefined,{minimumFractionDigits:2})}` : "—"}</td>
                    <td className="right mono tabular" style={{color:"var(--warning)", fontWeight: r.egreso > 0 ? 600 : 400}}>{r.egreso > 0 ? `Bs ${Number(r.egreso).toLocaleString(undefined,{minimumFractionDigits:2})}` : "—"}</td>
                  </tr>
                ))}
                {rows.length === 0 && <tr><td colSpan="6" style={{padding:30, textAlign:"center", color:"var(--soft)"}}>Sin movimientos</td></tr>}
              </tbody>
            </table>
          ) : (
            <table className="tbl">
              <thead><tr>
                <th style={{width:110}}>Fecha</th><th style={{width:130}}>Código</th>
                <th>Descripción</th><th style={{width:100}}>Marca</th>
                <th className="right" style={{width:100}}>Costo</th>
                <th className="right" style={{width:80}}>Cant.</th>
                <th className="right" style={{width:130}}>Subtotal</th>
              </tr></thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={i}>
                    <td className="num">{r.fecha}</td>
                    <td><span className="mono" style={{fontSize:11, fontWeight:700, color:"var(--accent)"}}>{r.codigo}</span></td>
                    <td className="strong">{r.descripcion}</td>
                    <td className="text-soft">{r.marca}</td>
                    <td className="right mono tabular">Bs {Number(r.costo).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                    <td className="right mono tabular">{r.cantidad}</td>
                    <td className="right mono tabular strong">Bs {Number(r.subtotal).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                  </tr>
                ))}
                {rows.length === 0 && <tr><td colSpan="7" style={{padding:30, textAlign:"center", color:"var(--soft)"}}>Sin registros</td></tr>}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * Detalle de un cierre (el "ojito" — réplica del legacy caja.show): panel resumen
 * (apertura/ingresos/egresos/efectivo + fechas + usuarios) + movimientos del período,
 * con botones Imprimir (PDF) y Eliminar (revertir el cierre — solo el último; el backend
 * deniega los demás, igual que el legacy).
 * @param {object} props
 * @param {object} props.cierre - Fila de cierre seleccionada (al menos { id }).
 * @param {function(): void} props.onClose - Cierra el modal.
 * @param {function(): void} [props.onDeleted] - Callback tras eliminar (recargar lista).
 * @returns {JSX.Element}
 */
function CierreDetalleModal({ cierre, onClose, onDeleted }) {
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    cajaApi.cierreDetalle(cierre.id)
      .then(r => setData(r.data))
      .catch(logger.error)
      .finally(() => setLoading(false));
    return () => window.removeEventListener("keydown", onKey);
  }, [cierre.id]);

  const bs = (n) => `Bs ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;

  const imprimir = async () => {
    try {
      const r = await cajaApi.cierrePdf(cierre.id);
      const url = URL.createObjectURL(r.data);
      window.open(url, '_blank');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) { alert('No se pudo generar el PDF.'); logger.error(e); }
  };

  const eliminar = async () => {
    if (!window.confirm(`¿Eliminar el cierre #${cierre.id}? Se revierte el cierre y se reabre la apertura.`)) return;
    setDeleting(true);
    try {
      await cajaApi.revertirCierre({ cierre_id: cierre.id });
      onDeleted?.();
      onClose();
    } catch (e) {
      alert(e?.response?.data?.error || 'No se pudo eliminar el cierre.');
      logger.error(e);
    } finally { setDeleting(false); }
  };

  const movs = data?.movimientos ?? [];
  const sumIng = movs.reduce((a, m) => a + (m.ingreso || 0), 0);
  const sumEgr = movs.reduce((a, m) => a + (m.egreso || 0), 0);

  const Resumen = ({ label, value, color }) => (
    <div style={{padding:"8px 10px", background:"var(--muted)", borderRadius:"var(--r-md)"}}>
      <div style={{fontSize:10, color:"var(--soft)", textTransform:"uppercase", letterSpacing:".04em"}}>{label}</div>
      <div className="mono tabular" style={{fontSize:14, fontWeight:700, color: color || "var(--ink)", marginTop:2}}>{value}</div>
    </div>
  );

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{maxWidth: 920}}>
        <div style={{padding:"14px 18px", borderBottom:"1px solid var(--line)", display:"flex", alignItems:"center", justifyContent:"space-between", gap:12}}>
          <div>
            <h3 style={{fontSize:15}}>Cierre #{cierre.id}</h3>
            <div style={{fontSize:11, color:"var(--soft)", marginTop:2}}>
              {data ? `Apertura ${data.fecha_apertura ?? "—"} → Cierre ${data.fecha_cierre ?? "—"}` : "Cargando…"}
            </div>
          </div>
          <div className="row" style={{gap:8}}>
            <Button variant="ghost" size="sm" icon="fa-print" onClick={imprimir} disabled={loading}>Imprimir</Button>
            <Button variant="danger" size="sm" icon="fa-trash" onClick={eliminar} disabled={loading || deleting}>
              {deleting ? "Eliminando…" : "Eliminar"}
            </Button>
            <button className="icon-btn" onClick={onClose} aria-label="Cerrar"><Icon name="fa-xmark"/></button>
          </div>
        </div>
        {loading ? (
          <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
        ) : (
          <>
            <div style={{padding:"14px 18px", borderBottom:"1px solid var(--line)"}}>
              <div className="grid-4" style={{gap:10}}>
                <Resumen label="Apertura" value={bs(data.apertura)} />
                <Resumen label="Ingresos" value={bs(data.ingresos)} color="var(--success)" />
                <Resumen label="Egresos"  value={bs(data.egresos)}  color="var(--warning)" />
                <Resumen label="Efectivo" value={bs(data.efectivo)} color="var(--accent)" />
              </div>
              <div className="row" style={{gap:18, flexWrap:"wrap", marginTop:10, fontSize:11.5, color:"var(--soft)"}}>
                <span>Usuario apertura: <strong style={{color:"var(--ink)"}}>{data.usuario_apertura || "—"}</strong></span>
                <span>Usuario cierre: <strong style={{color:"var(--ink)"}}>{data.usuario_cierre || "—"}</strong></span>
                {!data.es_ultimo && <span style={{color:"var(--warning)"}}><Icon name="fa-circle-info" style={{fontSize:10, marginRight:4}}/>Solo el último cierre puede eliminarse.</span>}
              </div>
            </div>
            <div style={{maxHeight:"50vh", overflowY:"auto"}}>
              <table className="tbl">
                <thead><tr>
                  <th style={{width:70}}>#</th><th style={{width:110}}>Fecha</th>
                  <th style={{width:80}}>Clase</th><th>Descripción</th>
                  <th className="right" style={{width:130}}>Ingreso</th>
                  <th className="right" style={{width:130}}>Egreso</th>
                </tr></thead>
                <tbody>
                  {movs.map(m => (
                    <tr key={m.id}>
                      <td><span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{m.id}</span></td>
                      <td className="num">{m.fecha}</td>
                      <td><Badge tone="neutral" outline><span title={m.clase}>{claseLabel(m.clase)}</span></Badge></td>
                      <td>{m.descripcion || m.cuenta}</td>
                      <td className="right mono tabular" style={{color:"var(--success)", fontWeight: m.ingreso > 0 ? 600 : 400}}>{m.ingreso > 0 ? bs(m.ingreso) : "—"}</td>
                      <td className="right mono tabular" style={{color:"var(--warning)", fontWeight: m.egreso > 0 ? 600 : 400}}>{m.egreso > 0 ? bs(m.egreso) : "—"}</td>
                    </tr>
                  ))}
                  {movs.length === 0 && <tr><td colSpan="6" style={{padding:30, textAlign:"center", color:"var(--soft)"}}>Sin movimientos</td></tr>}
                </tbody>
                {movs.length > 0 && (
                  <tfoot><tr style={{borderTop:"2px solid var(--line)", fontWeight:700}}>
                    <td colSpan="4" className="right">TOTALES</td>
                    <td className="right mono tabular" style={{color:"var(--success)"}}>{bs(sumIng)}</td>
                    <td className="right mono tabular" style={{color:"var(--warning)"}}>{bs(sumEgr)}</td>
                  </tr></tfoot>
                )}
              </table>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

/**
 * Historial de caja: lista de aperturas pasadas con sus tranzas, compras y ventas asociadas.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function HistorialCaja({ onNav, sucursalId }) {
  const hoy = new Date().toISOString().slice(0,10);
  const hace30 = new Date(Date.now() - 30*24*60*60*1000).toISOString().slice(0,10);
  const [tab, setTab]     = useState("tranzas");
  const [desde, setDesde] = useState(hace30);
  const [hasta, setHasta] = useState(hoy);
  const [data, setData]   = useState(null);
  const [loading, setLoading] = useState(false);
  const [selectedApertura, setSelectedApertura] = useState(null);
  const [selectedCierre, setSelectedCierre] = useState(null);

  const load = () => {
    setLoading(true);
    const params = { desde, hasta };
    const fn = tab === "tranzas"   ? cajaApi.historialTranzas
             : tab === "compras"   ? cajaApi.historialCompras
             : tab === "ventas"    ? cajaApi.historialVentas
             : tab === "efectivos" ? cajaApi.historialEfectivos
             : tab === "cierres"   ? cajaApi.cierres
             :                       cajaApi.aperturas;
    fn(params)
      .then(r => setData(r.data))
      .catch(logger.error)
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [tab, desde, hasta, sucursalId]);

  const rows = data?.data ?? [];
  const totalIngresos = data?.total_ingresos;
  const totalEgresos  = data?.total_egresos;

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Historial de caja" sub="Movimientos históricos por fecha"
        actions={<Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={() => onNav('caja')}>Volver a caja</Button>}
      />

      <div className="card" style={{padding: 16}}>
        <div className="row" style={{gap: 12, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div className="field" style={{marginBottom:0}}><label className="label">Desde</label>
            <input className="input" type="date" aria-label="Desde" value={desde} onChange={e=>{ setDesde(e.target.value); }}/>
          </div>
          <div className="field" style={{marginBottom:0}}><label className="label">Hasta</label>
            <input className="input" type="date" aria-label="Hasta" value={hasta} onChange={e=>{ setHasta(e.target.value); }}/>
          </div>
          <Button variant="accent" icon="fa-search" onClick={load}>Buscar</Button>
        </div>
      </div>

      {(tab === "tranzas" || tab === "efectivos") && totalIngresos !== undefined && (
        <div className="grid-4">
          <KPI label="Movimientos" value={rows.length} icon="fa-list"/>
          <KPI label="Total ingresos" value={`Bs ${Number(totalIngresos).toLocaleString(undefined,{minimumFractionDigits:2})}`} icon="fa-arrow-down"/>
          <KPI label="Total egresos" value={`Bs ${Number(totalEgresos).toLocaleString(undefined,{minimumFractionDigits:2})}`} icon="fa-arrow-up"/>
          <KPI label="Balance" value={`Bs ${(totalIngresos - totalEgresos).toLocaleString(undefined,{minimumFractionDigits:2})}`}/>
        </div>
      )}

      <div className="card" style={{padding:0}}>
        <div style={{padding: "12px 16px", borderBottom:"1px solid var(--line)"}}>
          <div className="seg-tabs">
            {["tranzas","efectivos","compras","ventas","cierres","aperturas"].map(t => (
              <button key={t} className={`seg ${tab===t?"active":""}`} onClick={()=>setTab(t)}>
                {t[0].toUpperCase()+t.slice(1)}
              </button>
            ))}
          </div>
        </div>
        {loading ? (
          <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
        ) : tab === "tranzas" || tab === "efectivos" ? (
          <table className="tbl">
            <thead><tr>
              <th style={{width:70}}>#</th><th style={{width:110}}>Fecha</th>
              <th style={{width:80}}>Clase</th><th>Descripción</th>
              <th className="right" style={{width:130}}>Ingreso</th>
              <th className="right" style={{width:130}}>Egreso</th>
            </tr></thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id}>
                  <td><span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{r.id}</span></td>
                  <td className="num">{r.fecha}</td>
                  <td><Badge tone="neutral" outline><span title={r.clase}>{claseLabel(r.clase)}</span></Badge></td>
                  <td>{r.descripcion}</td>
              <td className="right mono tabular" style={{color:"var(--success)", fontWeight: r.ingreso > 0 ? 600 : 400}}>{r.ingreso > 0 ? `Bs ${Number(r.ingreso).toLocaleString(undefined,{minimumFractionDigits:2})}` : "—"}</td>
              <td className="right mono tabular" style={{color:"var(--warning)", fontWeight: r.egreso > 0 ? 600 : 400}}>{r.egreso > 0 ? `Bs ${Number(r.egreso).toLocaleString(undefined,{minimumFractionDigits:2})}` : "—"}</td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan="6"><Empty text={`Sin ${tab} en este período`} icon="fa-cash-register"/></td></tr>}
            </tbody>
          </table>
        ) : tab === "cierres" ? (
          <table className="tbl">
            <thead><tr>
              <th style={{width:60}}>#</th>
              <th style={{width:110}}>Apertura</th>
              <th style={{width:110}}>Cierre</th>
              <th className="right" style={{width:120}}>Apertura</th>
              <th className="right" style={{width:120}}>Ingreso</th>
              <th className="right" style={{width:120}}>Egreso</th>
              <th className="right" style={{width:120}}>Efectivo</th>
              <th>Usuario</th>
              <th style={{width:60}}></th>
            </tr></thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} onClick={() => setSelectedCierre(r)} style={{cursor:"pointer"}}>
                  <td><span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{r.id}</span></td>
                  <td className="num">{r.fecha_apertura || "—"}</td>
                  <td className="num">{r.fecha_cierre}</td>
                  <td className="right mono tabular">Bs {Number(r.apertura).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                  <td className="right mono tabular" style={{color:"var(--success)"}}>Bs {Number(r.ingresos).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                  <td className="right mono tabular" style={{color:"var(--warning)"}}>Bs {Number(r.egresos).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                  <td className="right mono tabular strong">Bs {Number(r.efectivo).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                  <td className="text-soft">{r.usuario || "—"}</td>
                  <td className="right">
                    <button className="icon-btn" title="Ver detalle" onClick={e=>{e.stopPropagation(); setSelectedCierre(r);}}><Icon name="fa-eye" style={{fontSize:11}}/></button>
                  </td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan="9"><Empty text="Sin cierres en este período" icon="fa-cash-register"/></td></tr>}
            </tbody>
          </table>
        ) : tab === "aperturas" ? (
          <table className="tbl">
            <thead><tr>
              <th style={{width:70}}>#</th>
              <th style={{width:120}}>Fecha</th>
              <th className="right" style={{width:140}}>Monto inicial</th>
              <th style={{width:120}}>Estado</th>
              <th style={{width:80}}></th>
            </tr></thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} onClick={() => setSelectedApertura(r)} style={{cursor:"pointer"}}>
                  <td><span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{r.id}</span></td>
                  <td className="num">{r.fecha}</td>
                  <td className="right mono tabular strong">Bs {Number(r.monto).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                  <td>
                    {r.cerrado === 'SI'
                      ? <Badge tone="neutral" outline>Cerrada</Badge>
                      : <Badge tone="success" outline>Abierta</Badge>
                    }
                  </td>
                  <td className="right">
                    <button className="icon-btn" title="Ver detalle" onClick={e=>{e.stopPropagation(); setSelectedApertura(r);}}><Icon name="fa-eye" style={{fontSize:11}}/></button>
                  </td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan="5"><Empty text="Sin aperturas en este período" icon="fa-cash-register"/></td></tr>}
            </tbody>
          </table>
        ) : (
          <table className="tbl">
            <thead><tr>
              <th style={{width:110}}>Fecha</th><th style={{width:130}}>Código</th>
              <th>Descripción</th><th style={{width:100}}>Marca</th>
              <th className="right" style={{width:100}}>Costo</th>
              <th className="right" style={{width:80}}>Cant.</th>
              <th className="right" style={{width:130}}>Subtotal</th>
            </tr></thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={i}>
                  <td className="num">{r.fecha}</td>
                  <td><span className="mono" style={{fontSize:11, fontWeight:700, color:"var(--accent)"}}>{r.codigo}</span></td>
                  <td className="strong">{r.descripcion}</td>
                  <td className="text-soft">{r.marca}</td>
                  <td className="right mono tabular">Bs {Number(r.costo).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                  <td className="right mono tabular">{r.cantidad}</td>
                  <td className="right mono tabular strong">Bs {Number(r.subtotal).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan="7"><Empty text={`Sin ${tab} en este período`} icon="fa-box"/></td></tr>}
            </tbody>
          </table>
        )}
      </div>
      {selectedApertura && <AperturaDetalleModal apertura={selectedApertura} onClose={() => setSelectedApertura(null)} />}
      {selectedCierre && <CierreDetalleModal cierre={selectedCierre} onClose={() => setSelectedCierre(null)} onDeleted={load} />}
    </div>
  );
}
