/**
 * @fileoverview Pantalla de caja: apertura, movimientos, cierre y reporte PDF.
 */

import React, { useState, useEffect } from 'react';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, Card, KPI, Empty, PageHead, DataTable, PdfButton, useToast } from '../lib/components.jsx';
import { caja as cajaApi } from '../services/api.js';

/**
 * Mapa de clase de tranza → documento que la originó. Permite mostrar una
 * referencia clickeable ("Venta #123") en cada movimiento de caja para ubicar
 * el documento al instante. ENT/SAL (movimientos manuales) no tienen documento.
 */
const DOC_REF = {
  VEN:     { label: 'Venta',  route: 'venta-detail'  },
  COB:     { label: 'Venta',  route: 'venta-detail'  },
  'D-VEN': { label: 'Venta',  route: 'venta-detail'  },
  COM:     { label: 'Compra', route: 'compra-detail' },
  PAG:     { label: 'Compra', route: 'compra-detail' },
  'D-COM': { label: 'Compra', route: 'compra-detail' },
  ENV:     { label: 'Envío',  route: 'envio-detail'  },
  REC:     { label: 'Envío',  route: 'envio-detail'  },
};

/**
 * Pantalla de caja: KPIs de ingresos/egresos, apertura/cierre y registro de movimientos.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function Caja({ onNav, sucursalId, user, effectivePermissions }) {
  const toast = useToast();
  const [kpis, setKpis]       = useState(null);
  const [movs, setMovs]       = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving]   = useState(false);
  const [tipoMov, setTipoMov] = useState('INGRESO');
  const [monto, setMonto]     = useState('');
  const [desc, setDesc]       = useState('');
  const [montoApertura, setMontoApertura] = useState('');
  const [cerrandoConf, setCerrandoConf]   = useState(false);
  const [editingId, setEditingId]         = useState(null);
  const [editDesc, setEditDesc]           = useState('');
  const canOperate = (effectivePermissions || []).some(p => p === 'caja.apertura' || p === 'caja.ingreso' || p === 'caja.egreso' || p === 'caja.cierre');

  function cargar() {
    setLoading(true);
    Promise.all([cajaApi.kpis(), cajaApi.movimientos()])
      .then(([kRes, mRes]) => { setKpis(kRes.data); setMovs(mRes.data.data ?? []); })
      .catch(logger.error)
      .finally(() => setLoading(false));
  }

  useEffect(cargar, [sucursalId]);

  async function handleApertura() {
    if (!montoApertura) return;
    setSaving(true);
    try {
      await cajaApi.apertura({ monto: parseFloat(montoApertura) });
      setMontoApertura('');
      cargar();
    } catch (err) {
      alert(err?.response?.data?.error || 'Error al abrir caja');
      logger.error(err);
    } finally { setSaving(false); }
  }

  async function handleCierre() {
    setSaving(true);
    try {
      await cajaApi.cierre({});
      onNav('historial-caja');
    } catch (err) {
      alert(err?.response?.data?.error || 'Error al cerrar caja');
      logger.error(err);
    } finally {
      setSaving(false);
      setCerrandoConf(false);
    }
  }

  async function handleMovimiento() {
    if (!monto || !desc) return;
    setSaving(true);
    try {
      if (tipoMov === 'INGRESO') await cajaApi.ingreso({ monto: parseFloat(monto), descripcion: desc });
      else await cajaApi.egreso({ monto: parseFloat(monto), descripcion: desc });
      setMonto(''); setDesc('');
      cargar();
    } catch (err) {
      alert(err?.response?.data?.error || 'Error al registrar movimiento');
      logger.error(err);
    } finally { setSaving(false); }
  }

  async function handleUpdateTranza(id) {
    if (!editDesc.trim()) return;
    setSaving(true);
    try {
      await cajaApi.updateTranza({ tranza_id: id, descripcion: editDesc });
      setEditingId(null);
      cargar();
    } catch (err) {
      alert(err?.response?.data?.error || 'Error al actualizar');
      logger.error(err);
    } finally { setSaving(false); }
  }

  async function handleDeleteTranza(m) {
    if (!window.confirm(`¿Eliminar movimiento "${m.descripcion}"?`)) return;
    setSaving(true);
    try {
      await cajaApi.deleteTranza({ tranza_id: m.id });
      cargar();
    } catch (err) {
      alert(err?.response?.data?.error || 'Error al eliminar');
      logger.error(err);
    } finally { setSaving(false); }
  }

  /** Descarga el reporte PDF de caja del período visible. */
  async function handleReport() {
    try {
      const r = await cajaApi.report();
      const url = URL.createObjectURL(new Blob([r.data], { type: 'application/pdf' }));
      window.open(url, '_blank');
    } catch (e) {
      logger.error(e);
      toast(e?.response?.data?.error || 'Error al generar el reporte PDF', 'error');
    }
  }

  if (loading) return (
    <div style={{display:'grid', placeItems:'center', height:300}}>
      <Icon name="fa-spinner fa-spin" style={{fontSize:24, color:'var(--soft)'}}/>
    </div>
  );

  if (!kpis?.abierta) return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title="Caja" sub="La caja no tiene apertura hoy"
        actions={<Button variant="secondary" icon="fa-clock-rotate-left" size="sm" onClick={() => onNav("historial-caja")}>Historial</Button>}
      />
      {canOperate && (
      <div style={{maxWidth:420, margin:"0 auto"}}>
        <Card title="Abrir caja" pad>
          <div className="stack" style={{"--gap":"14px"}}>
            <p style={{fontSize:13, color:"var(--soft)", margin:0}}>
              Ingresa el monto inicial de efectivo para abrir la caja del día.
            </p>
            <div className="field">
              <label className="label">Monto inicial (Bs.)</label>
              <input className="input mono" type="number" min="0" step="0.01"
                value={montoApertura} onChange={e => setMontoApertura(e.target.value)}
                placeholder="0.00" style={{fontSize:20, textAlign:"right", fontWeight:700}}/>
            </div>
            <Button variant="accent" icon="fa-lock-open" style={{width:"100%"}} disabled={saving || !montoApertura} onClick={handleApertura}>
              {saving ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Abriendo…</> : "Abrir caja"}
            </Button>
          </div>
        </Card>
      </div>
      )}
    </div>
  );

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title="Caja" sub={`Apertura: Bs ${Number(kpis.apertura_monto).toLocaleString(undefined,{minimumFractionDigits:2})} · Saldo actual: Bs ${Number(kpis.saldo).toLocaleString(undefined,{minimumFractionDigits:2})}`}
        actions={canOperate ? <>
            <PdfButton onPdf={handleReport} />
            <Button variant="secondary" icon="fa-clock-rotate-left" size="sm" onClick={() => onNav("historial-caja")}>Historial</Button>
            <Button variant="danger" icon="fa-lock" size="sm" onClick={() => setCerrandoConf(true)}>Cerrar caja</Button>
          </> : <>
            <PdfButton onPdf={handleReport} />
            <Button variant="secondary" icon="fa-clock-rotate-left" size="sm" onClick={() => onNav("historial-caja")}>Historial</Button>
          </>}
      />

      {cerrandoConf && (
        <div style={{padding:"16px 20px", background:"var(--danger-soft)", border:"1px solid rgba(220,38,38,.3)", borderRadius:"var(--r-md)", display:"flex", justifyContent:"space-between", alignItems:"center"}}>
          <span style={{fontSize:13, color:"var(--danger)", fontWeight:600}}>¿Confirmar cierre de caja? Esta acción no se puede deshacer.</span>
          <div style={{display:"flex", gap:8}}>
            <Button variant="ghost" size="sm" onClick={() => setCerrandoConf(false)}>Cancelar</Button>
            <Button variant="danger" size="sm" disabled={saving} onClick={handleCierre}>
              {saving ? <Icon name="fa-spinner fa-spin"/> : "Confirmar cierre"}
            </Button>
          </div>
        </div>
      )}

      <div className="grid-4">
        <div className="card card-pad" style={{borderLeft:"4px solid var(--accent)"}}>
          <div style={{fontSize:11, fontWeight:700, color:"var(--soft)", letterSpacing:".06em", textTransform:"uppercase"}}>Saldo actual</div>
          <div className="display tabular" style={{fontSize:32, fontWeight:700, color:"var(--ink)", marginTop:8}}>Bs {Number(kpis.saldo).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
          <div style={{fontSize:11, color:"var(--soft)", marginTop:4}}>Apertura: Bs {Number(kpis.apertura_monto).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
        </div>
        <KPI label="Ingresos" icon="fa-arrow-down" prefix="Bs " value={Number(kpis.ingresos).toLocaleString(undefined,{minimumFractionDigits:2})} />
        <KPI label="Egresos"  icon="fa-arrow-up"  prefix="Bs " value={Number(kpis.egresos).toLocaleString(undefined,{minimumFractionDigits:2})}  />
        <KPI label="Movimientos" icon="fa-list" value={movs.length} />
      </div>

      <div className="grid-12">
        <Card title="Movimientos del día" meta={`${movs.length} registros`} pad={false}>
          {movs.length === 0
            ? <Empty text="Sin movimientos hoy" icon="fa-cash-register"/>
            : (
            <DataTable
              data={movs}
              columns={[
                { key: 'fecha', title: 'Fecha', width: 110, render: m => <span className="mono" style={{color:"var(--soft)", fontSize:11}}>{m.fecha}</span> },
                {
                  key: 'tipo', title: 'Tipo', width: 110,
                  render: m => (
                    <Badge tone={m.tipo === "INGRESO" ? "success" : "warning"} outline>
                      <Icon name={m.tipo === "INGRESO" ? "fa-arrow-down" : "fa-arrow-up"} style={{fontSize:9, marginRight:3}}/>{m.clase}
                    </Badge>
                  )
                },
                {
                  key: 'descripcion', title: 'Descripción / Cuenta',
                  render: m => {
                    const isEditing = editingId === m.id;
                    if (isEditing) return <input className="input" style={{fontSize:12.5, padding:"4px 8px"}} value={editDesc} onChange={e=>setEditDesc(e.target.value)} autoFocus/>;
                    const doc = DOC_REF[m.clase];
                    return (
                      <>
                        <div style={{fontSize:12.5, fontWeight:600, color:"var(--ink)"}}>{m.descripcion}</div>
                        <div className="row" style={{gap:8, alignItems:"center", marginTop:2}}>
                          {m.cuenta && <span style={{fontSize:11, color:"var(--soft)"}}>{m.cuenta}</span>}
                          {doc && m.registro > 0 && (
                            <button type="button" title={`Ver ${doc.label} #${m.registro}`}
                              onClick={() => onNav({ name: doc.route, id: m.registro })}
                              style={{display:"inline-flex", alignItems:"center", gap:3, padding:"1px 7px", fontSize:10.5,
                                fontWeight:700, color:"var(--accent)", background:"var(--accent-a15)", border:"none",
                                borderRadius:99, cursor:"pointer", whiteSpace:"nowrap"}}>
                              <Icon name="fa-up-right-from-square" style={{fontSize:8}}/>{doc.label} #{m.registro}
                            </button>
                          )}
                        </div>
                      </>
                    );
                  }
                },
                { key: 'ingreso', title: 'Ingreso', width: 140, align: 'right', render: m => m.ingreso > 0 ? <span className="mono tabular" style={{fontWeight:700, color:"var(--success)"}}>Bs {m.ingreso.toLocaleString(undefined,{minimumFractionDigits:2})}</span> : <span className="mono tabular" style={{color:"var(--soft)"}}>—</span> },
                { key: 'egreso', title: 'Egreso', width: 140, align: 'right', render: m => m.egreso > 0 ? <span className="mono tabular" style={{fontWeight:700, color:"var(--warning)"}}>Bs {m.egreso.toLocaleString(undefined,{minimumFractionDigits:2})}</span> : <span className="mono tabular" style={{color:"var(--soft)"}}>—</span> },
                {
                  key: 'actions', title: '', width: 70, align: 'right',
                  render: m => {
                    const editable = m.clase === 'ENT' || m.clase === 'SAL';
                    const isEditing = editingId === m.id;
                    if (!editable) return null;
                    return (
                      <div className="actions">
                        {isEditing ? (
                          <>
                            <button className="icon-btn" title="Guardar" disabled={saving} onClick={()=>handleUpdateTranza(m.id)}><Icon name="fa-check" style={{fontSize:10, color:"var(--success)"}}/></button>
                            <button className="icon-btn" title="Cancelar" onClick={()=>setEditingId(null)}><Icon name="fa-xmark" style={{fontSize:10}}/></button>
                          </>
                        ) : (
                          <>
                            <button className="icon-btn" title="Editar" onClick={()=>{setEditingId(m.id); setEditDesc(m.descripcion);}}><Icon name="fa-pen" style={{fontSize:10}}/></button>
                            <button className="icon-btn danger" title="Eliminar" disabled={saving} onClick={()=>handleDeleteTranza(m)}><Icon name="fa-trash" style={{fontSize:10}}/></button>
                          </>
                        )}
                      </div>
                    );
                  }
                }
              ]}
            />
          )}
        </Card>

        {canOperate && (
        <Card title="Registrar movimiento">
          <div className="stack" style={{"--gap":"12px"}}>
            <div className="grid-2" style={{gap:8}}>
              {["INGRESO","EGRESO"].map(t => (
                <button key={t} onClick={()=>setTipoMov(t)}
                  style={{padding:"10px", borderRadius:"var(--r-md)", border: tipoMov===t ? "2px solid var(--accent)" : "2px solid var(--line)",
                    background: tipoMov===t ? "var(--accent-soft)" : "var(--surface)", color: tipoMov===t ? "var(--accent)" : "var(--body)",
                    fontSize:12, fontWeight:700}}>
                  <Icon name={t==="INGRESO" ? "fa-arrow-down" : "fa-arrow-up"} style={{marginRight:6, color: t==="INGRESO" ? "var(--success)" : "var(--warning)"}}/>
                  {t[0]+t.slice(1).toLowerCase()}
                </button>
              ))}
            </div>
            <div className="field">
              <label className="label">Monto (Bs.)</label>
              <input className="input mono" type="number" min="0" step="0.01"
                value={monto} onChange={e=>setMonto(e.target.value)}
                placeholder="0.00" style={{fontSize:18, textAlign:"right", fontWeight:700}}/>
            </div>
            <div className="field">
              <label className="label">Concepto</label>
              <input className="input" value={desc} onChange={e=>setDesc(e.target.value)} placeholder="Descripción del movimiento"/>
            </div>
            <Button variant="accent" icon="fa-check" style={{width:"100%"}}
              disabled={saving || !monto || !desc} onClick={handleMovimiento}>
              {saving ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Registrando…</> : `Registrar ${tipoMov.toLowerCase()}`}
            </Button>
          </div>
        </Card>
        )}
      </div>
    </div>
  );
}
