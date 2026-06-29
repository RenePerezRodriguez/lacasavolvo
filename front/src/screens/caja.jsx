/**
 * @fileoverview Caja — réplica de la estructura del legacy en el diseño nuevo.
 * El landing (`Caja`) es la **Lista de Cierres** (N°/Sucursal/Fecha[A]/Fecha[C]/Apertura/
 * Ingreso/Egreso/Efectivo/Usuario + 👁), con "Movimientos" y "Última Apertura". El 👁 y
 * "Última Apertura" abren `CajaVista` ("CAJA [VISTA]"): panel de totales + pestañas
 * General/Compras/Ventas/Efectivos, con operación (gasto/inserción/cerrar) si la apertura
 * está abierta, o Imprimir/Eliminar si es un cierre.
 */

import React, { useState, useEffect, useCallback } from 'react';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, Card, Empty, PageHead, Pager, useToast } from '../lib/components.jsx';
import { caja as cajaApi, apiErrorMsg } from '../services/api.js';
import { claseLabel } from '../lib/clase.js';

/** Formatea un número como bolivianos con 2 decimales para display. */
const bs = (n) => `Bs ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
const hoyISO = () => new Date().toISOString().slice(0, 10);

/**
 * Modal para abrir la caja del día (monto inicial). Réplica del flujo de apertura del legacy.
 * @param {object} props
 * @param {function(): void} props.onClose
 * @param {function(): void} props.onOpened - Tras abrir con éxito.
 * @returns {JSX.Element}
 */
function AbrirCajaModal({ onClose, onOpened }) {
  const [monto, setMonto] = useState('');
  const [saving, setSaving] = useState(false);
  const submit = async () => {
    if (!monto) return;
    setSaving(true);
    try { await cajaApi.apertura({ monto: parseFloat(monto) }); onOpened?.(); }
    catch (e) { alert(apiErrorMsg(e, 'Error al abrir caja')); logger.error(e); }
    finally { setSaving(false); }
  };
  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: 420 }}>
        <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h3 style={{ fontSize: 15 }}>Abrir caja</h3>
          <button className="icon-btn" onClick={onClose} aria-label="Cerrar"><Icon name="fa-xmark" /></button>
        </div>
        <div className="stack" style={{ padding: 18, '--gap': '14px' }}>
          <p style={{ fontSize: 13, color: 'var(--soft)', margin: 0 }}>Monto inicial de efectivo para abrir la caja del día.</p>
          <div className="field">
            <label className="label">Monto inicial (Bs.)</label>
            <input className="input mono" type="number" min="0" step="0.01" value={monto} onChange={e => setMonto(e.target.value)} placeholder="0.00" style={{ fontSize: 20, textAlign: 'right', fontWeight: 700 }} autoFocus />
          </div>
          <Button variant="accent" icon="fa-lock-open" style={{ width: '100%' }} disabled={saving || !monto} onClick={submit}>
            {saving ? <><Icon name="fa-spinner fa-spin" style={{ marginRight: 6 }} />Abriendo…</> : 'Abrir caja'}
          </Button>
        </div>
      </div>
    </div>
  );
}

/**
 * Modal para registrar un ingreso (INSERCIÓN) o egreso (GASTO) en la apertura activa.
 * @param {object} props
 * @param {'INGRESO'|'EGRESO'} props.tipo
 * @param {function(): void} props.onClose
 * @param {function(): void} props.onSaved
 * @returns {JSX.Element}
 */
function MovimientoModal({ tipo, onClose, onSaved }) {
  const esIng = tipo === 'INGRESO';
  const [monto, setMonto] = useState('');
  const [desc, setDesc] = useState('');
  const [fecha, setFecha] = useState(hoyISO);
  const [saving, setSaving] = useState(false);
  const submit = async () => {
    if (!monto || !desc) return;
    setSaving(true);
    try {
      if (esIng) await cajaApi.ingreso({ monto: parseFloat(monto), descripcion: desc, fecha });
      else await cajaApi.egreso({ monto: parseFloat(monto), descripcion: desc, fecha });
      onSaved?.();
    } catch (e) { alert(apiErrorMsg(e, 'Error al registrar')); logger.error(e); }
    finally { setSaving(false); }
  };
  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: 440 }}>
        <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h3 style={{ fontSize: 15 }}>{esIng ? 'Inserción (ingreso)' : 'Gasto (egreso)'}</h3>
          <button className="icon-btn" onClick={onClose} aria-label="Cerrar"><Icon name="fa-xmark" /></button>
        </div>
        <div className="stack" style={{ padding: 18, '--gap': '12px' }}>
          <div className="field"><label className="label">Monto (Bs.)</label>
            <input className="input mono" type="number" min="0" step="0.01" value={monto} onChange={e => setMonto(e.target.value)} placeholder="0.00" style={{ fontSize: 18, textAlign: 'right', fontWeight: 700 }} autoFocus /></div>
          <div className="field"><label className="label">Concepto</label>
            <input className="input" value={desc} onChange={e => setDesc(e.target.value)} placeholder="Descripción del movimiento" /></div>
          <div className="field"><label className="label">Fecha</label>
            <input className="input" type="date" value={fecha} max={hoyISO()} onChange={e => setFecha(e.target.value)} /></div>
          <Button variant={esIng ? 'accent' : 'danger'} icon="fa-check" style={{ width: '100%' }} disabled={saving || !monto || !desc || !fecha} onClick={submit}>
            {saving ? <><Icon name="fa-spinner fa-spin" style={{ marginRight: 6 }} />Registrando…</> : (esIng ? 'Registrar ingreso' : 'Registrar gasto')}
          </Button>
        </div>
      </div>
    </div>
  );
}

/**
 * Caja (landing) — Lista de Cierres, igual que el legacy. Cada fila abre `CajaVista`.
 * @param {object} props
 * @param {function(string|object): void} props.onNav
 * @param {number} props.sucursalId - Sucursal activa (re-fetch al cambiar).
 * @param {object} props.user
 * @param {string[]} props.effectivePermissions
 * @returns {JSX.Element}
 */
export function Caja({ onNav, sucursalId, user, effectivePermissions }) {
  const [rows, setRows]     = useState([]);
  const [total, setTotal]   = useState(0);
  const [skip, setSkip]     = useState(0);
  const [take, setTake]     = useState(10);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [kpis, setKpis]     = useState(null);
  const [abrir, setAbrir]   = useState(false);
  const canOperate = (effectivePermissions || []).some(p => p === 'caja.apertura' || p === 'caja.ingreso' || p === 'caja.egreso' || p === 'caja.cierre');

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([
      cajaApi.cierres({ skip, take, ...(search && { search }) }),
      cajaApi.kpis(),
    ]).then(([cRes, kRes]) => {
      setRows(cRes.data.data ?? []); setTotal(cRes.data.total ?? 0); setKpis(kRes.data);
    }).catch(logger.error).finally(() => setLoading(false));
  }, [skip, take, search, sucursalId]);

  useEffect(() => { load(); }, [load]);

  const irUltimaApertura = () => {
    if (kpis?.abierta && kpis.apertura_id) onNav({ name: 'caja-vista', id: kpis.apertura_id });
    else if (canOperate) setAbrir(true);
    else alert('No hay una apertura activa en esta sucursal.');
  };

  const page  = Math.floor(skip / take) + 1;
  const pages = Math.ceil(total / take) || 1;

  return (
    <div className="fade-up stack" style={{ '--gap': '20px' }}>
      <PageHead title="Caja" sub="Lista de cierres" diamond
        actions={<>
          <Button variant="secondary" icon="fa-right-left" size="sm" onClick={() => onNav('historial-caja')}>Movimientos</Button>
          <Button variant="accent" icon="fa-calendar-day" size="sm" onClick={irUltimaApertura}>Última apertura</Button>
        </>}
      />
      {abrir && <AbrirCajaModal onClose={() => setAbrir(false)} onOpened={() => { setAbrir(false); load(); }} />}

      <div className="card" style={{ padding: 0 }}>
        <div style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ fontSize: 13, fontWeight: 700 }}>Lista de Cierres</div>
          <div className="row" style={{ gap: 8 }}>
            <select className="input" aria-label="Por página" value={take} onChange={e => { setTake(+e.target.value); setSkip(0); }} style={{ width: 72, padding: '6px 8px' }}>
              {[10, 25, 50].map(n => <option key={n} value={n}>{n}</option>)}
            </select>
            <div className="input-group" style={{ minWidth: 200 }}>
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{ fontSize: 12 }} /></span>
              <input className="input" placeholder="Buscar (N° o fecha)…" value={search} onChange={e => { setSearch(e.target.value); setSkip(0); }} />
            </div>
          </div>
        </div>
        {loading ? (
          <div style={{ padding: 40, textAlign: 'center', color: 'var(--soft)' }}><Icon name="fa-spinner fa-spin" style={{ fontSize: 20 }} /></div>
        ) : (
          <table className="tbl">
            <thead><tr>
              <th style={{ width: 60 }}>N°</th>
              <th>Sucursal</th>
              <th style={{ width: 110 }}>Fecha [A]</th>
              <th style={{ width: 110 }}>Fecha [C]</th>
              <th className="right" style={{ width: 110 }}>Apertura</th>
              <th className="right" style={{ width: 110 }}>Ingreso</th>
              <th className="right" style={{ width: 110 }}>Egreso</th>
              <th className="right" style={{ width: 110 }}>Efectivo</th>
              <th>Usuario</th>
              <th style={{ width: 50 }}></th>
            </tr></thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} onClick={() => onNav({ name: 'caja-vista', id: r.apertura_id })} style={{ cursor: 'pointer' }}>
                  <td><span className="mono" style={{ fontWeight: 700, color: 'var(--ink)' }}>{r.id}</span></td>
                  <td className="strong">{r.sucursal}</td>
                  <td className="num">{r.fecha_apertura || '—'}</td>
                  <td className="num">{r.fecha_cierre}</td>
                  <td className="right mono tabular">{bs(r.apertura)}</td>
                  <td className="right mono tabular" style={{ color: 'var(--success)' }}>{bs(r.ingresos)}</td>
                  <td className="right mono tabular" style={{ color: 'var(--warning)' }}>{bs(r.egresos)}</td>
                  <td className="right mono tabular strong">{bs(r.efectivo)}</td>
                  <td className="text-soft">{r.usuario || '—'}</td>
                  <td className="right"><button className="icon-btn" title="Ver" onClick={e => { e.stopPropagation(); onNav({ name: 'caja-vista', id: r.apertura_id }); }}><Icon name="fa-eye" style={{ fontSize: 11 }} /></button></td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan="10"><Empty text="Sin cierres" icon="fa-cash-register" /></td></tr>}
            </tbody>
          </table>
        )}
        <Pager from={total === 0 ? 0 : skip + 1} to={Math.min(skip + take, total)} total={total} page={page} pages={pages} onPage={p => setSkip((p - 1) * take)} />
      </div>
    </div>
  );
}

/**
 * CajaVista — "CAJA [VISTA]" del legacy: panel de totales (Apertura/Ingresos/Egresos/Efectivo +
 * fechas + usuarios) y pestañas General/Compras/Ventas/Efectivos. Si la apertura está ABIERTA:
 * Gasto/Inserción/Cerrar + editar/borrar movimientos en Efectivos. Si es un CIERRE: Imprimir +
 * Eliminar (revertir, solo el último). Sirve para el 👁 y para "Última Apertura".
 * @param {object} props
 * @param {number} props.aperturaId
 * @param {function(string|object): void} props.onNav
 * @param {number} props.sucursalId
 * @param {object} props.user
 * @param {string[]} props.effectivePermissions
 * @returns {JSX.Element}
 */
export function CajaVista({ aperturaId, onNav, sucursalId, user, effectivePermissions }) {
  const toast = useToast();
  const [info, setInfo]         = useState(null);
  const [loading, setLoading]   = useState(true);
  const [tab, setTab]           = useState('general');
  const [rows, setRows]         = useState([]);
  const [tabLoading, setTabLoading] = useState(false);
  const [movModal, setMovModal] = useState(null); // 'INGRESO' | 'EGRESO' | null
  const [cerrando, setCerrando] = useState(false);
  const [saving, setSaving]     = useState(false);
  const [editId, setEditId]     = useState(null);
  const [editDesc, setEditDesc]   = useState('');
  const [editFecha, setEditFecha] = useState('');
  const [editMonto, setEditMonto] = useState('');
  const toInputDate = (s) => { const [d, m, y] = String(s).split('/'); return (y && m && d) ? `${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}` : ''; };
  const canOperate = (effectivePermissions || []).some(p => p === 'caja.apertura' || p === 'caja.ingreso' || p === 'caja.egreso' || p === 'caja.cierre');

  const loadInfo = useCallback(() => {
    setLoading(true);
    cajaApi.aperturaShow(aperturaId).then(r => setInfo(r.data)).catch(logger.error).finally(() => setLoading(false));
  }, [aperturaId]);
  useEffect(() => { loadInfo(); }, [loadInfo]);

  const loadTab = useCallback(() => {
    setTabLoading(true);
    const fn = tab === 'compras' ? cajaApi.compras : tab === 'ventas' ? cajaApi.ventasCaja : cajaApi.tranzas;
    fn(aperturaId).then(r => {
      let data = r.data.data ?? [];
      if (tab === 'efectivos') data = data.filter(t => t.editable); // solo ENT/SAL (manuales)
      setRows(data);
    }).catch(logger.error).finally(() => setTabLoading(false));
  }, [tab, aperturaId]);
  useEffect(() => { loadTab(); }, [loadTab]);

  const abierta = info?.cerrado === 'NO';

  const handleCerrar = async () => {
    setSaving(true);
    try { await cajaApi.cierre({}); toast('Caja cerrada', 'success'); onNav('caja'); }
    catch (e) { alert(apiErrorMsg(e, 'Error al cerrar')); logger.error(e); }
    finally { setSaving(false); setCerrando(false); }
  };
  const handleImprimir = async () => {
    if (!info?.cierre_id) return;
    try { const r = await cajaApi.cierrePdf(info.cierre_id); const url = URL.createObjectURL(r.data); window.open(url, '_blank'); setTimeout(() => URL.revokeObjectURL(url), 60000); }
    catch (e) { toast('No se pudo generar el PDF', 'error'); logger.error(e); }
  };
  const handleEliminar = async () => {
    if (!info?.cierre_id) return;
    if (!window.confirm(`¿Eliminar el cierre #${info.cierre_id}? Se revierte el cierre y se reabre la apertura.`)) return;
    setSaving(true);
    try { await cajaApi.revertirCierre({ cierre_id: info.cierre_id }); toast('Cierre eliminado', 'success'); onNav('caja'); }
    catch (e) { alert(apiErrorMsg(e, 'No se pudo eliminar')); logger.error(e); }
    finally { setSaving(false); }
  };
  const guardarEdit = async (id) => {
    if (!editDesc.trim()) return;
    setSaving(true);
    try {
      await cajaApi.updateTranza({ tranza_id: id, descripcion: editDesc, fecha: editFecha || undefined, monto: editMonto && parseFloat(editMonto) > 0 ? parseFloat(editMonto) : undefined });
      setEditId(null); loadInfo(); loadTab();
    } catch (e) { alert(apiErrorMsg(e, 'Error al actualizar')); logger.error(e); }
    finally { setSaving(false); }
  };
  const borrarTranza = async (m) => {
    if (!window.confirm(`¿Eliminar movimiento "${m.descripcion}"?`)) return;
    setSaving(true);
    try { await cajaApi.deleteTranza({ tranza_id: m.id }); loadInfo(); loadTab(); }
    catch (e) { alert(apiErrorMsg(e, 'Error al eliminar')); logger.error(e); }
    finally { setSaving(false); }
  };

  if (loading || !info) return (
    <div style={{ display: 'grid', placeItems: 'center', height: 300 }}><Icon name="fa-spinner fa-spin" style={{ fontSize: 24, color: 'var(--soft)' }} /></div>
  );

  const esMovTab = tab === 'general' || tab === 'efectivos';
  const efectivosEdit = tab === 'efectivos' && abierta && canOperate;
  const sumIng = esMovTab ? rows.reduce((a, m) => a + (m.ingreso || 0), 0) : 0;
  const sumEgr = esMovTab ? rows.reduce((a, m) => a + (m.egreso || 0), 0) : 0;
  const sumSub = !esMovTab ? rows.reduce((a, m) => a + (m.subtotal || 0), 0) : 0;

  const InfoRow = ({ label, value, color }) => (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, padding: '7px 0', borderBottom: '1px solid var(--line)' }}>
      <span style={{ fontSize: 11, color: 'var(--soft)', textTransform: 'uppercase', letterSpacing: '.03em' }}>{label}</span>
      <span className="mono tabular" style={{ fontSize: 13, fontWeight: 700, color: color || 'var(--ink)' }}>{value}</span>
    </div>
  );

  return (
    <div className="fade-up stack" style={{ '--gap': '20px' }}>
      <PageHead title={`Caja${info.sucursal ? ' — ' + info.sucursal : ''}`} sub={abierta ? 'Apertura activa' : `Cierre #${info.cierre_id ?? ''}`}
        actions={<>
          <Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={() => onNav('caja')}>Volver</Button>
          {abierta && canOperate && <>
            <Button variant="danger" icon="fa-arrow-up" size="sm" onClick={() => setMovModal('EGRESO')}>Gastos</Button>
            <Button variant="accent" icon="fa-arrow-down" size="sm" onClick={() => setMovModal('INGRESO')}>Inserción</Button>
            <Button variant="secondary" icon="fa-lock" size="sm" onClick={() => setCerrando(true)}>Cerrar</Button>
          </>}
          {!abierta && <>
            <Button variant="secondary" icon="fa-print" size="sm" onClick={handleImprimir}>Imprimir</Button>
            {info.es_ultimo_cierre && canOperate && <Button variant="danger" icon="fa-trash" size="sm" disabled={saving} onClick={handleEliminar}>Eliminar</Button>}
          </>}
        </>}
      />

      {cerrando && (
        <div style={{ padding: '16px 20px', background: 'var(--danger-soft)', border: '1px solid rgba(220,38,38,.3)', borderRadius: 'var(--r-md)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 13, color: 'var(--danger)', fontWeight: 600 }}>¿Confirmar cierre de caja? Esta acción no se puede deshacer fácilmente.</span>
          <div className="row" style={{ gap: 8 }}>
            <Button variant="ghost" size="sm" onClick={() => setCerrando(false)}>Cancelar</Button>
            <Button variant="danger" size="sm" disabled={saving} onClick={handleCerrar}>{saving ? <Icon name="fa-spinner fa-spin" /> : 'Confirmar cierre'}</Button>
          </div>
        </div>
      )}
      {movModal && <MovimientoModal tipo={movModal} onClose={() => setMovModal(null)} onSaved={() => { setMovModal(null); loadInfo(); loadTab(); }} />}

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(240px, 300px) 1fr', gap: 20, alignItems: 'start' }} className="caja-vista-grid">
        {/* Panel de totales (como la columna izquierda del legacy) */}
        <Card title="Resumen" pad>
          <div className="stack" style={{ '--gap': '0px' }}>
            <InfoRow label="Fecha apertura [A]" value={info.fecha_apertura || '—'} />
            {!abierta && <InfoRow label="Fecha cierre [C]" value={info.fecha_cierre || '—'} />}
            <InfoRow label="Apertura" value={bs(info.apertura)} />
            <InfoRow label="Ingresos" value={bs(info.ingresos)} color="var(--success)" />
            <InfoRow label="Egresos" value={bs(info.egresos)} color="var(--warning)" />
            <InfoRow label="Efectivo" value={bs(info.efectivo)} color={info.efectivo >= 0 ? 'var(--accent)' : 'var(--danger)'} />
            <InfoRow label="Usuario [A]" value={info.usuario_apertura || '—'} />
            {!abierta && <InfoRow label="Usuario [C]" value={info.usuario_cierre || '—'} />}
          </div>
          <div style={{ marginTop: 12 }}>
            <Badge tone={abierta ? 'success' : 'neutral'} dot>{abierta ? 'Abierta' : 'Cerrada'}</Badge>
          </div>
        </Card>

        {/* Pestañas + tabla */}
        <Card pad={false}>
          <div style={{ padding: '10px 14px', borderBottom: '1px solid var(--line)' }}>
            <div className="seg-tabs">
              {[['general', 'General'], ['compras', 'Compras'], ['ventas', 'Ventas'], ['efectivos', 'Efectivos']].map(([id, lbl]) => (
                <button key={id} className={`seg ${tab === id ? 'active' : ''}`} onClick={() => { setTab(id); setEditId(null); }}>{lbl}</button>
              ))}
            </div>
          </div>
          {tabLoading ? (
            <div style={{ padding: 40, textAlign: 'center', color: 'var(--soft)' }}><Icon name="fa-spinner fa-spin" style={{ fontSize: 18 }} /></div>
          ) : esMovTab ? (
            <table className="tbl">
              <thead><tr>
                <th style={{ width: 70 }}>Clase</th><th style={{ width: 70 }}>No</th><th style={{ width: 110 }}>Fecha</th><th>Descripción</th>
                <th className="right" style={{ width: 120 }}>Ingreso</th><th className="right" style={{ width: 120 }}>Egreso</th>
                {efectivosEdit && <th style={{ width: 70 }}></th>}
              </tr></thead>
              <tbody>
                {rows.map(m => {
                  const editing = editId === m.id;
                  return (
                    <tr key={m.id}>
                      <td><Badge tone="neutral" outline><span title={m.clase}>{claseLabel(m.clase)}</span></Badge></td>
                      <td className="mono text-soft">{m.registro || '—'}</td>
                      <td className="num">{editing ? <input className="input" type="date" value={editFecha} max={hoyISO()} onChange={e => setEditFecha(e.target.value)} style={{ fontSize: 11, padding: '4px 6px' }} /> : m.fecha}</td>
                      <td>{editing ? <input className="input" value={editDesc} onChange={e => setEditDesc(e.target.value)} style={{ fontSize: 12.5, padding: '4px 8px' }} autoFocus /> : m.descripcion}</td>
                      <td className="right mono tabular" style={{ color: 'var(--success)', fontWeight: m.ingreso > 0 ? 600 : 400 }}>
                        {editing && m.ingreso > 0 ? <input className="input mono" type="number" min="0.01" step="0.01" value={editMonto} onChange={e => setEditMonto(e.target.value)} style={{ textAlign: 'right', fontSize: 12, padding: '4px 6px' }} /> : (m.ingreso > 0 ? bs(m.ingreso) : '—')}
                      </td>
                      <td className="right mono tabular" style={{ color: 'var(--warning)', fontWeight: m.egreso > 0 ? 600 : 400 }}>
                        {editing && m.egreso > 0 ? <input className="input mono" type="number" min="0.01" step="0.01" value={editMonto} onChange={e => setEditMonto(e.target.value)} style={{ textAlign: 'right', fontSize: 12, padding: '4px 6px' }} /> : (m.egreso > 0 ? bs(m.egreso) : '—')}
                      </td>
                      {efectivosEdit && (
                        <td className="right"><div className="actions">
                          {editing ? <>
                            <button className="icon-btn" title="Guardar" disabled={saving} onClick={() => guardarEdit(m.id)}><Icon name="fa-check" style={{ fontSize: 10, color: 'var(--success)' }} /></button>
                            <button className="icon-btn" title="Cancelar" onClick={() => setEditId(null)}><Icon name="fa-xmark" style={{ fontSize: 10 }} /></button>
                          </> : <>
                            <button className="icon-btn" title="Editar" onClick={() => { setEditId(m.id); setEditDesc(m.descripcion); setEditFecha(toInputDate(m.fecha)); setEditMonto(String(m.ingreso > 0 ? m.ingreso : m.egreso)); }}><Icon name="fa-pen" style={{ fontSize: 10 }} /></button>
                            <button className="icon-btn danger" title="Eliminar" disabled={saving} onClick={() => borrarTranza(m)}><Icon name="fa-trash" style={{ fontSize: 10 }} /></button>
                          </>}
                        </div></td>
                      )}
                    </tr>
                  );
                })}
                {rows.length === 0 && <tr><td colSpan={efectivosEdit ? 7 : 6} style={{ padding: 30, textAlign: 'center', color: 'var(--soft)' }}>Sin movimientos</td></tr>}
              </tbody>
              {rows.length > 0 && (
                <tfoot><tr style={{ borderTop: '2px solid var(--line)', fontWeight: 700 }}>
                  <td colSpan="4" className="right">TOTALES</td>
                  <td className="right mono tabular" style={{ color: 'var(--success)' }}>{bs(sumIng)}</td>
                  <td className="right mono tabular" style={{ color: 'var(--warning)' }}>{bs(sumEgr)}</td>
                  {efectivosEdit && <td></td>}
                </tr></tfoot>
              )}
            </table>
          ) : (
            <table className="tbl">
              <thead><tr>
                <th style={{ width: 110 }}>Fecha</th><th style={{ width: 130 }}>Código</th><th>Descripción</th><th style={{ width: 100 }}>Marca</th>
                <th className="right" style={{ width: 100 }}>Costo</th><th className="right" style={{ width: 70 }}>Cnt</th><th className="right" style={{ width: 120 }}>Subtotal</th>
              </tr></thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={i}>
                    <td className="num">{r.fecha}</td>
                    <td><span className="mono" style={{ fontSize: 11, fontWeight: 700, color: 'var(--accent)' }}>{r.codigo}</span></td>
                    <td className="strong">{r.descripcion}</td>
                    <td className="text-soft">{r.marca}</td>
                    <td className="right mono tabular">{bs(r.costo)}</td>
                    <td className="right mono tabular">{r.cantidad}</td>
                    <td className="right mono tabular strong">{bs(r.subtotal)}</td>
                  </tr>
                ))}
                {rows.length === 0 && <tr><td colSpan="7" style={{ padding: 30, textAlign: 'center', color: 'var(--soft)' }}>Sin registros</td></tr>}
              </tbody>
              {rows.length > 0 && (
                <tfoot><tr style={{ borderTop: '2px solid var(--line)', fontWeight: 700 }}>
                  <td colSpan="6" className="right">TOTAL</td>
                  <td className="right mono tabular">{bs(sumSub)}</td>
                </tr></tfoot>
              )}
            </table>
          )}
        </Card>
      </div>
    </div>
  );
}
