/**
 * @fileoverview Pantalla de cotizaciones con KPIs, conversión a venta y detalle editable.
 */

import React, { useState, useEffect, useRef } from 'react';
import { useListData, useColumnVisibility, filterDetalles, recalcSubtotal } from '../lib/hooks.js';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, StatusBadge, Card, KPI, Empty, PageHead, Pager, PageSizeSelector, DataTable, PdfButton, ProductSearchInput, QtyStepper, DocHeader, RowFilterInput } from '../lib/components.jsx';
import { CotizacionFormModal, EncabezadoModal } from './forms.jsx';
import { openPdf, cotizaciones as cotizApi } from '../services/api.js';

/**
 * Etiquetas de estado propias de cotizaciones: una cotización ES una PROFORMA (un
 * presupuesto), no una venta. En la BD el estado es VALIDO (no existe un estado
 * "proforma"/"borrador" — verificado contra prod y la base legacy), pero "VALIDO"
 * confundía al equipo (parecía venta cerrada), así que el activo se MUESTRA como
 * "PROFORMA". Al convertirse en venta pasa a CONVERTIDA; si se cancela, ANULADA.
 * El valor real en la BD/filtros sigue siendo VALIDO/ANULADO/CONVERTIDA.
 */
const COTIZ_ESTADO_LABEL = { VALIDO: 'PROFORMA', ANULADO: 'ANULADA', CONVERTIDA: 'CONVERTIDA' };

/**
 * Listado paginado de cotizaciones con KPIs y opción de convertir a venta.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function Cotizaciones({ onNav, sucursalId, user, effectivePermissions }) {
  const [estado, setEstado]         = useState("TODOS");
  const [q, setQ]                   = useState("");
  const [fechaDesde, setFechaDesde] = useState("");
  const [fechaHasta, setFechaHasta] = useState("");
  const [skip, setSkip]             = useState(0);
  const [pageSize, setPageSize]     = useState(15);
  const [formOpen, setFormOpen]     = useState(false);
  const [converting, setConverting] = useState(null);
  const [sort, setSort]             = useState({ col: 'id', dir: 'desc' });
  const { hiddenCols, toggleCol, visibleCols, showCols, setShowCols } = useColumnVisibility('cotizaciones', ['sucursal']);
  const canCreate = (effectivePermissions || []).some(p => p === 'cotizaciones.create');
  // El KPI de monto total solo es visible para GERENTE y ADMIN (respeta rol simulado)
  const effectiveRole = user?.simulated_role_name || user?.role;
  const showMontoKpi  = ['ADMIN', 'GERENTE'].includes(effectiveRole);

  const { items: cotizaciones, total, kpis, loading, reload } = useListData(
    cotizApi.list, cotizApi.kpis,
    () => ({
      skip, take: pageSize,
      sort: sort.col, dir: sort.dir,
      ...(estado !== "TODOS" && { estado_filtro: estado }),
      ...(q && { search: q }),
      ...(fechaDesde && { fecha_desde: fechaDesde }),
      ...(fechaHasta && { fecha_hasta: fechaHasta }),
    }),
    [estado, q, fechaDesde, fechaHasta, skip, pageSize, sort, sucursalId]
  );

  const handlePageSize = (n) => { setPageSize(n); setSkip(0); };

  async function handleConvertir(c) {
    if (!window.confirm(`¿Convertir cotización #${c.id} a venta? Se creará una venta PROFORMA con los mismos ítems.`)) return;
    setConverting(c.id);
    try {
      const r = await cotizApi.venta(c.id);
      onNav({ name: 'venta-nueva', id: r.data.id, vData: r.data });
    } catch (e) { logger.error(e); }
    finally { setConverting(null); }
  }

  const page  = Math.floor(skip / pageSize) + 1;
  const pages = Math.ceil(total / pageSize);

  const cols = [
    { key: 'id', title: '#', width: 90, sortable: true, render: c => <span className="mono" style={{fontSize:12, fontWeight:700, color:"var(--accent)"}}>#{c.id}</span> },
    { key: 'fecha', title: 'Fecha', width: 120, sortable: true, render: c => <span className="num">{c.fecha}</span> },
    { key: 'cuenta', title: 'Cliente', sortable: true, render: c => <span className="strong">{c.cuenta}</span> },
    { key: 'total', title: 'Total', width: 160, align: 'right', sortable: true, render: c => <span className="mono tabular strong">{c.total}</span> },
    { key: 'estado', title: 'Estado', width: 120, sortable: true, render: c => <StatusBadge value={c.estado} label={COTIZ_ESTADO_LABEL[c.estado]}/> },
    { key: 'sucursal', title: 'Sucursal', width: 120, defaultHidden: true, render: c => <Badge tone="neutral" outline>{c.sucursal || '—'}</Badge> },
    { key: 'actions', title: 'Acciones', width: 120, align: 'right', render: c => (
      <div className="actions">
        <button className="icon-btn" title="Convertir a venta" disabled={converting === c.id || c.estado !== 'VALIDO'} onClick={e=>{e.stopPropagation(); handleConvertir(c);}}>
          {converting === c.id ? <Icon name="fa-spinner fa-spin" style={{fontSize:11}}/> : <Icon name="fa-arrow-right-arrow-left" style={{fontSize:11}}/>}
        </button>
        {c.estado === 'VALIDO' ? (
          <button className="icon-btn" title="Editar cotización" onClick={e=>{e.stopPropagation(); onNav({ name: 'cotizacion-detail', id: c.id, cData: c });}}><Icon name="fa-pen" style={{fontSize:11, color: "var(--accent)"}}/></button>
        ) : (
          <button className="icon-btn" title="Ver detalle" onClick={e=>{e.stopPropagation(); onNav({ name: 'cotizacion-detail', id: c.id, cData: c });}}><Icon name="fa-eye" style={{fontSize:11}}/></button>
        )}
        <PdfButton iconOnly onPdf={() => openPdf(`/cotizaciones/${c.id}/pdf`)} />
      </div>
    )}
  ];

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Cotizaciones" sub="Proformas con seguimiento de validez y conversión"
        actions={canCreate ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => setFormOpen(true)}>Nueva cotización</Button> : null}
      />
      {formOpen && <CotizacionFormModal onClose={() => setFormOpen(false)} onSaved={(newCot) => { setFormOpen(false); setSkip(0); reload(); if(newCot) onNav({ name: 'cotizacion-detail', id: newCot.id, cData: newCot }); }}/>}

      <div className={showMontoKpi ? "grid-4" : "grid-3"}>
        <KPI label="Cotizaciones" value={kpis?.total ?? "—"} />
        <KPI label="Válidas" value={kpis?.valido ?? "—"} />
        <KPI label="Anuladas" value={kpis?.anulado ?? "—"} />
        {showMontoKpi && <KPI label="Monto total" value={kpis?.monto ?? "—"} />}
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
              {["TODOS","VALIDO","ANULADO"].map(e => (
                <button key={e} className={`seg ${estado === e ? "active" : ""}`} onClick={()=>{setEstado(e); setSkip(0);}}>
                  {e === "TODOS" ? "Todos" : (COTIZ_ESTADO_LABEL[e]?.[0] ?? e[0]) + (COTIZ_ESTADO_LABEL[e]?.slice(1).toLowerCase() ?? e.slice(1).toLowerCase())}
                </button>
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
              {cols.filter(c => !hiddenCols.has(c.key)).length}/{cols.length}
            </button>
            {showCols && (
              <div style={{position:"absolute",top:"100%",right:0,marginTop:4,background:"var(--surface)",border:"1px solid var(--line)",borderRadius:"var(--r-md)",boxShadow:"var(--sh-lg)",zIndex:20,padding:8,minWidth:180}}>
                {cols.filter(c => c.key !== 'acciones').map(c => (
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
          <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
        ) : (
          <DataTable
            data={cotizaciones}
            sortCol={sort.col}
            sortDir={sort.dir}
            onSort={(col, dir) => setSort({ col, dir })}
            onRowClick={c => onNav({ name: 'cotizacion-detail', id: c.id, cData: c })}
            columns={visibleCols(cols)}
          />
        )}
        <Pager from={skip + 1} to={Math.min(skip + pageSize, total)} total={total} page={page} pages={pages} onPage={(p) => setSkip((p - 1) * pageSize)}/>
      </div>
    </div>
  );
}

/**
 * Detalle de cotización: ítems editables, conversión a venta y anulación.
 * @param {object} props
 * @param {number} props.cotizacionId - ID de la cotización.
 * @param {object} [props.cotizacionData] - Datos precargados desde el listado.
 * @param {function(string|object): void} props.onNav - Navegación.
 * @returns {JSX.Element}
 */
export function CotizacionDetail({ cotizacionId, cotizacionData, onNav }) {
  const [detalles, setDetalles]     = useState([]);
  // Filtro SOLO-visual de los renglones ya agregados (no toca el total, que suma `detalles`).
  const [filtroItems, setFiltroItems] = useState('');
  const itemsVisibles = filterDetalles(detalles, filtroItems);
  const [loading, setLoading]       = useState(true);
  const [saving, setSaving]         = useState(false);
  // Guardado de ítems NO bloqueante: total optimista al instante, persistencia detrás.
  const [savingItem, setSavingItem] = useState(false);
  const inflight                    = useRef(0);
  const [converting, setConverting] = useState(false);
  const [c, setC]                   = useState(cotizacionData ?? null);
  const [error, setError]           = useState(null);
  const [showEditEnc, setShowEditEnc] = useState(false); // modal editar encabezado

  useEffect(() => {
    setLoading(true);
    Promise.all([
      cotizApi.show(cotizacionId).then(r => { setC(prev => ({ ...prev, ...r.data })); }),
      cotizApi.detalles(cotizacionId).then(r => setDetalles(r.data ?? [])),
    ]).catch(logger.error).finally(() => setLoading(false));
  }, [cotizacionId]);


  async function reload() { const r = await cotizApi.detalles(cotizacionId); setDetalles(r.data ?? []); }
  /** Recarga solo el encabezado (cliente/fecha/observación) tras editarlo. */
  async function reloadHeader() { const r = await cotizApi.show(cotizacionId); setC(prev => ({ ...prev, ...r.data })); }

  /**
   * Agrega un producto a la cotización y recarga los detalles.
   * @param {object} prod - Producto seleccionado en ProductSearchInput.
   */
  async function addItem(prod) {
    setError(null); setSaving(true);
    try { await cotizApi.agregarItem({ cotizacion_id: cotizacionId, producto_id: prod.id, cantidad: 1 }); await reload(); }
    catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al agregar producto'); }
    finally { setSaving(false); }
  }

  /**
   * Aplica un cambio de precio/cantidad de forma OPTIMISTA (el total se recalcula al instante,
   * sin esperar el round-trip — como el legacy) y lo persiste en segundo plano. El endpoint de
   * cotizaciones recibe el precio como `precio`; en updateCant no se reenvía (conserva el del
   * registro). Reconcilia con el server al terminar la última operación en vuelo; ante error revierte.
   * @param {object} item - Renglón de cotización.
   * @param {{costo?: number, cantidad?: number}} patch - Campo editado (`costo` = nuevo precio).
   */
  function saveItem(item, patch) {
    setDetalles(prev => prev.map(d => d.id === item.id ? recalcSubtotal(d, patch) : d)); // optimista
    const cantidad = patch.cantidad != null ? patch.cantidad : item.cantidad;
    const payload  = patch.costo != null
      ? { registro: item.id, cantidad, precio: patch.costo }
      : { registro: item.id, cantidad };
    inflight.current++; setSavingItem(true);
    cotizApi.updateItem(payload)
      .then(() => { if (inflight.current === 1) return reload(); })
      .catch(e => { setError(e?.response?.data?.error ?? 'Error al actualizar el ítem'); return reload(); })
      .finally(() => { inflight.current--; if (inflight.current === 0) setSavingItem(false); });
  }
  function updateCant(item, newCant) {
    if (newCant < 1 || newCant === item.cantidad) return;
    saveItem(item, { cantidad: newCant });
  }

  /**
   * Cambia el PRECIO unitario de un ítem de la cotización (editable hasta la v2, igual que
   * en ventas — pedido de QA).
   * @param {object} item - Detalle de cotización.
   * @param {number|string} nuevoPrecio - Nuevo precio unitario.
   */
  function updatePrecio(item, nuevoPrecio) {
    const precio = parseFloat(nuevoPrecio);
    if (isNaN(precio) || precio < 0 || precio === parseFloat(item.costo)) return;
    saveItem(item, { costo: precio });
  }

  async function removeItem(item) {
    setSaving(true);
    try { await cotizApi.deleteItem(item.id); await reload(); }
    finally { setSaving(false); }
  }

  async function handleConvertir() {
    if (!window.confirm(`¿Convertir cotización #${cotizacionId} a venta?`)) return;
    setConverting(true); setError(null);
    try {
      const r = await cotizApi.venta(cotizacionId);
      onNav({ name: 'venta-nueva', id: r.data.id, vData: r.data });
    } catch { setError('Error al convertir a venta'); }
    finally { setConverting(false); }
  }

  async function handleAnular() {
    if (!window.confirm('¿Anular esta cotización?')) return;
    setSaving(true);
    try { await cotizApi.destroy(cotizacionId); onNav('cotizaciones'); }
    finally { setSaving(false); }
  }

  const [descuento, setDescuento]   = useState(0);
  const [descuentoDirty, setDescuentoDirty] = useState(false);
  const descuentoTimer = useRef(null);

  useEffect(() => { if (c?.descuento !== undefined) { setDescuento(parseFloat(c.descuento) || 0); setDescuentoDirty(false); } }, [c?.descuento]);

  const estado   = c?.estado ?? '—';
  const editable = estado === 'VALIDO';
  // Suma de los SUBTOTALES guardados (preservan la precisión del precio tipeado),
  // no `costo × cantidad` sobre el costo truncado a 2 decimales.
  const montoNum = detalles.reduce((s, d) => s + (d.subtotal_num != null ? parseFloat(d.subtotal_num) : parseFloat(d.costo ?? 0) * d.cantidad), 0);
  const totalNum = Math.max(0, montoNum - descuento);

  async function guardarDescuento(d) {
    if (!c?.cuenta_id || !c?.fecha_raw) {
      setError('No se puede guardar: faltan datos de la cotización. Recarga la página.');
      return;
    }
    setError(null); setSaving(true);
    try {
      await cotizApi.updateEncabezado({
        cotizacion_id: cotizacionId,
        cuenta_id: c.cuenta_id,
        fecha: c.fecha_raw,
        descuento: d,
      });
      setDescuentoDirty(false);
    } catch (e) {
      setError('Error al guardar descuento: ' + (e?.response?.data?.error || e.message));
    }
    finally { setSaving(false); }
  }

  function handleDescuento(val) {
    const d = parseFloat(val) || 0;
    setDescuento(d);
    setDescuentoDirty(true);
    if (descuentoTimer.current) clearTimeout(descuentoTimer.current);
    descuentoTimer.current = setTimeout(() => guardarDescuento(d), 800);
  }

  async function handleGuardar() {
    await guardarDescuento(descuento);
    await reload();
  }

  if (loading) return <div style={{display:'grid',placeItems:'center',height:300}}><Icon name="fa-spinner fa-spin" style={{fontSize:24,color:'var(--soft)'}}/></div>;

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title={`Cotización #${cotizacionId}`} sub={`${c?.cuenta??'—'} · ${c?.fecha??'—'}`}
        actions={<>
          <Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={()=>onNav('cotizaciones')}>Volver</Button>
          {editable && <Button variant="secondary" icon="fa-pen" size="sm" onClick={()=>setShowEditEnc(true)}>Editar encabezado</Button>}
          <PdfButton onPdf={() => openPdf(`/cotizaciones/${cotizacionId}/pdf`)} />
          {editable && <Button variant="ghost" icon="fa-ban" size="sm" style={{color:"var(--danger)"}} disabled={saving} onClick={handleAnular}>Anular</Button>}
        </>}
      />
      {showEditEnc && c && (
        <EncabezadoModal docLabel="Cotización" docId={c.id} cuentaLabel="Cliente"
          initial={{ cuenta_id:c.cuenta_id, cuenta:c.cuenta, nit:c.nit, fecha_raw:c.fecha_raw, observacion:c.observacion }}
          showObservacion obsPlaceholder="Nombre/teléfono del cliente, términos, vigencia…" searchProps={{ take:5 }}
          onClose={()=>setShowEditEnc(false)}
          onSubmit={async (v) => { await cotizApi.updateEncabezado({ cotizacion_id:c.id, cuenta_id:v.cuenta_id, fecha:v.fecha, observacion:v.observacion, descuento:c.descuento ?? 0 }); await reloadHeader(); }}/>
      )}
      {error && <div style={{padding:"10px 14px",background:"var(--danger-soft)",border:"1px solid rgba(220,38,38,.25)",borderRadius:"var(--r-md)",fontSize:13,color:"var(--danger)",display:"flex",gap:8,alignItems:"center"}}><Icon name="fa-circle-exclamation" style={{fontSize:12,flexShrink:0}}/><span>{error}</span></div>}

      {/* Encabezado / intro arriba (pedido de QA): el cliente y la observación van como cabecera
          del documento ANTES del detalle de productos, no en el panel de la derecha. */}
      <DocHeader
        title="Cotización"
        subtitle="Datos del cliente y la proforma"
        fields={[
          { label: "N° Cotización", value: `#${cotizacionId}` },
          { label: "Cliente", value: c?.cuenta ?? '—' },
          { label: "Fecha", value: c?.fecha ?? '—' },
        ]}
        observacion={c?.observacion ?? ''}
        status={<StatusBadge value={estado} label={COTIZ_ESTADO_LABEL[estado]}/>}
      />

      <div className="grid-12">
        <div className="stack" style={{"--gap":"16px"}}>
          {editable && (
            // Buscador ANCLADO (sticky) — se mantiene fijo al hacer scroll de los ítems (QA).
            <div style={{position:"sticky", top:86, zIndex:10, background:"var(--page)"}}>
            <Card pad={false}>
              <div style={{padding:16}}>
                <ProductSearchInput onSelect={addItem} placeholder="Buscar producto para agregar…" />
              </div>
            </Card>
            </div>
          )}
          <Card pad={false}>
            <div className="row" style={{padding:"12px 16px",borderBottom:"1px solid var(--line)",justifyContent:"space-between"}}>
              <div className="row" style={{gap:12}}>
                <span style={{fontSize:13,fontWeight:700,color:"var(--ink)"}}>Productos de la cotización</span>
                <Badge tone="neutral">{detalles.length}</Badge>
                {(saving || savingItem) && <Icon name="fa-spinner fa-spin" style={{fontSize:12,color:"var(--soft)"}}/>}
              </div>
              {detalles.length > 0 && <RowFilterInput value={filtroItems} onChange={setFiltroItems} count={itemsVisibles.length} total={detalles.length}/>}
            </div>
            {detalles.length === 0 ? <Empty text="Sin productos" icon="fa-file-invoice"/>
              : itemsVisibles.length === 0 ? <Empty text="Sin coincidencias en los productos agregados" icon="fa-filter"/> : (
              <table className="tbl">
                <thead><tr>
                  <th>Producto</th>
                  <th className="center" style={{width:editable?150:80}}>Cantidad</th>
                  <th className="right" style={{width:editable?170:120}}>{editable ? 'Precio (editable)' : 'Precio'}</th>
                  <th className="right" style={{width:130}}>Subtotal</th>
                  {editable && <th style={{width:40}}></th>}
                </tr></thead>
                <tbody>
                  {itemsVisibles.map(it => (
                    <tr key={it.id}>
                      <td>
                        <div style={{fontSize:13,fontWeight:600,color:"var(--ink)"}}>{it.descripcion}</div>
                        <div className="row" style={{gap:6,marginTop:2}}>
                        <span className="mono" style={{fontSize:10.5,color:"var(--soft)"}}>#{it.producto_id ?? it.id} · {it.codigo}</span>
                          <span style={{fontSize:10.5,color:"var(--accent)",fontWeight:600}}>{it.marca}</span>
                        </div>
                      </td>
                      <td className="center">
                        {editable
                          ? <QtyStepper value={it.cantidad} onChange={(n)=>updateCant(it,n)} disabled={saving}/>
                          : <span className="mono tabular" style={{fontWeight:700}}>{it.cantidad}</span>}
                      </td>
                      <td className="right mono tabular">
                        {editable ? (
                          <div style={{display:"flex", flexDirection:"column", alignItems:"flex-end", gap:4}}>
                            <div className="input-group" style={{width:120}}>
                              <span className="lead-icon" style={{fontSize:10, color:"var(--soft)"}}>Bs</span>
                              <input
                                key={`cp-${it.id}-${it.costo}`}
                                className="input mono tabular"
                                type="number" min="0" step="any"
                                defaultValue={parseFloat(it.costo).toFixed(2)}
                                disabled={saving}
                                onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
                                onBlur={(e) => updatePrecio(it, e.target.value)}
                                style={{textAlign:"right", fontSize:13, padding:"6px 8px 6px 28px"}}
                                title="Precio unitario (editable)"
                              />
                            </div>
                            {(it.p_norm != null || it.p_fact != null) && (
                              <div className="row" style={{gap:4}}>
                                <button type="button" disabled={saving} title={`Sin factura: Bs ${Number(it.p_norm).toFixed(2)}`}
                                  onClick={() => updatePrecio(it, it.p_norm)}
                                  className="btn btn-ghost" style={{padding:"1px 6px", fontSize:10, fontWeight:700, height:"auto"}}>S/F</button>
                                <button type="button" disabled={saving} title={`Con factura: Bs ${Number(it.p_fact).toFixed(2)}`}
                                  onClick={() => updatePrecio(it, it.p_fact)}
                                  className="btn btn-ghost" style={{padding:"1px 6px", fontSize:10, fontWeight:700, height:"auto"}}>C/F</button>
                              </div>
                            )}
                          </div>
                        ) : `Bs ${parseFloat(it.costo).toFixed(2)}`}
                      </td>
                      <td className="right mono tabular strong">Bs {Number(it.subtotal_num ?? parseFloat(it.costo ?? 0) * it.cantidad).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                      {editable && <td><button className="icon-btn danger" disabled={saving} onClick={()=>removeItem(it)}><Icon name="fa-trash" style={{fontSize:11}}/></button></td>}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>
        </div>
        <div className="stack" style={{"--gap":"16px"}}>
          <Card title="Acciones">
            <div className="stack" style={{"--gap":"10px"}}>
              {editable && (
                <>
                  <div className="field">
                    <label className="label">Descuento</label>
                    <div className="input-group">
                      <span className="lead-icon" style={{fontWeight:700}}>Bs</span>
                      <input className="input mono" type="number" min="0" step="0.01"
                        value={descuento || ''} placeholder="0.00"
                        onChange={e => handleDescuento(e.target.value)}
                        style={{textAlign:"right",fontSize:14,fontWeight:600}}/>
                    </div>
                  </div>
                  <Button variant="secondary" size="md"
                    icon={saving ? "fa-spinner fa-spin" : (descuentoDirty ? "fa-floppy-disk" : "fa-check")}
                    disabled={saving || !descuentoDirty}
                    onClick={handleGuardar}
                    style={{width:"100%"}}>
                    {saving ? "Guardando…" : descuentoDirty ? "Guardar cambios" : "Guardado"}
                  </Button>
                  <Button variant="accent" size="lg" icon="fa-arrow-right-arrow-left"
                    disabled={detalles.length===0||converting} onClick={handleConvertir} style={{width:"100%"}}>
                    {converting ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Procesando…</> : 'Convertir a venta'}
                  </Button>
                </>
              )}
            </div>
          </Card>
          <Card title="Resumen">
            <div className="stack" style={{"--gap":"10px"}}>
              <div className="row" style={{justifyContent:"space-between",fontSize:12,alignItems:"center"}}>
                <span style={{color:"var(--soft)"}}>Subtotal</span>
                <span style={{fontWeight:600,color:"var(--ink)"}}>Bs {montoNum.toFixed(2)}</span>
              </div>
              <div className="row" style={{justifyContent:"space-between",fontSize:12,alignItems:"center"}}>
                <span style={{color:"var(--soft)"}}>Descuento</span>
                <span style={{fontWeight:600,color: descuento > 0 ? "var(--danger)" : "var(--ink)"}}>Bs {descuento.toFixed(2)}</span>
              </div>
              <div style={{height:1,background:"var(--line)",margin:"4px 0"}}></div>
              <div className="row" style={{justifyContent:"space-between",fontSize:13}}>
                <span style={{fontWeight:700,color:"var(--ink)"}}>Total</span>
                <span style={{fontWeight:700,color:"var(--ink)"}}>Bs {totalNum.toFixed(2)}</span>
              </div>
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}
