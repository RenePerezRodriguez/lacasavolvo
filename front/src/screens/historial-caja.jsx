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

  const load = () => {
    setLoading(true);
    const params = { desde, hasta };
    const fn = tab === "tranzas"   ? cajaApi.historialTranzas
             : tab === "compras"   ? cajaApi.historialCompras
             : tab === "ventas"    ? cajaApi.historialVentas
             : tab === "efectivos" ? cajaApi.historialEfectivos
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
            {["tranzas","efectivos","compras","ventas","aperturas"].map(t => (
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
    </div>
  );
}
