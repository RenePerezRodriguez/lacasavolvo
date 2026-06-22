/**
 * @fileoverview Pantallas de envíos (remitos de despacho entre sucursales): listado y detalle.
 */

import React, { useState, useEffect } from 'react';
import { useListData, useColumnVisibility, filterDetalles } from '../lib/hooks.js';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, StatusBadge, Card, KPI, Empty, PageHead, Pager, PageSizeSelector, DataTable, PdfButton, ProductSearchInput, QtyStepper, DocHeader, RowFilterInput } from '../lib/components.jsx';
import { EnvioFormModal, EnvioEncabezadoModal } from './forms.jsx';
import { openPdf, envios as enviosApi } from '../services/api.js';

/**
 * Listado paginado de envíos (remitos de despacho) con KPIs y búsqueda.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function Envios({ onNav, sucursalId, effectivePermissions }) {
  const [estado, setEstado]         = useState("TODOS");
  const [q, setQ]                   = useState("");
  const [fechaDesde, setFechaDesde] = useState("");
  const [fechaHasta, setFechaHasta] = useState("");
  const [skip, setSkip]             = useState(0);
  const [pageSize, setPageSize]     = useState(15);
  const [formOpen, setFormOpen]     = useState(false);
  const [sort, setSort]             = useState({ col: 'id', dir: 'desc' });
  const { hiddenCols, toggleCol, visibleCols, showCols, setShowCols } = useColumnVisibility('envios', ['medio']);
  const canCreate = (effectivePermissions || []).some(p => p === 'envios.create');

  const { items: envios, total, kpis, loading, reload } = useListData(
    enviosApi.list, enviosApi.kpis,
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

  const page  = Math.floor(skip / pageSize) + 1;
  const pages = Math.ceil(total / pageSize);
  const handlePageSize = (n) => { setPageSize(n); setSkip(0); };

  const cols = [
    { key: 'id', title: '#', width: 70, sortable: true, render: e => <span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{e.id}</span> },
    { key: 'fecha', title: 'Fecha', width: 110, sortable: true, render: e => <span className="num">{e.fecha}</span> },
    { key: 'origen_destino', title: 'Origen → Destino', render: e => (
      <div className="row" style={{gap: 8}}>
        <Badge tone="neutral" outline>{e.origen}</Badge>
        <Icon name="fa-arrow-right" style={{fontSize: 9, color:"var(--dust)"}}/>
        <Badge tone="info" outline>{e.destino}</Badge>
      </div>
    )},
    { key: 'medio', title: 'Medio', defaultHidden: true, render: e => <span className="text-soft">{e.medio}</span> },
    { key: 'monto', title: 'Monto', width: 120, align: 'right', sortable: true, render: e => <span className="mono tabular">{e.monto}</span> },
    { key: 'estado', title: 'Estado', width: 120, sortable: true, render: e => <StatusBadge value={e.estado}/> },
    { key: 'actions', title: 'Acciones', width: 90, align: 'right', render: e => (
      <div className="actions">
        {/* Lápiz de edición solo si la sucursal es el ORIGEN de un envío en PROFORMA
            (puede_editar). El destino ve el ojo de solo-lectura. */}
        {e.puede_editar ? (
          <button className="icon-btn" title="Editar envío" onClick={ev=>{ev.stopPropagation(); onNav({ name: 'envio-detail', id: e.id, eData: e });}}><Icon name="fa-pen" style={{fontSize:11, color: "var(--accent)"}}/></button>
        ) : (
          <button className="icon-btn" title="Ver detalle" onClick={ev=>{ev.stopPropagation(); onNav({ name: 'envio-detail', id: e.id, eData: e });}}><Icon name="fa-eye" style={{fontSize:11}}/></button>
        )}
        <PdfButton iconOnly onPdf={() => openPdf(`/envios/${e.id}/pdf`)} />
      </div>
    )}
  ];

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Envíos" sub="Movimientos entre sucursales"
        actions={canCreate ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => setFormOpen(true)}>Nuevo envío</Button> : null}
      />
      {formOpen && <EnvioFormModal onClose={() => setFormOpen(false)} onSaved={(newE) => { setSkip(0); setFormOpen(false); reload(); if(newE) onNav({ name: 'envio-detail', id: newE.id, eData: newE }); }} sucursalId={sucursalId}/>}
      <div className="grid-4">
        <KPI label="Envíos" value={kpis?.total ?? "—"} icon="fa-truck-fast"/>
        <KPI label="Proforma" value={kpis?.proforma ?? "—"} icon="fa-clock"/>
        <KPI label="En tránsito" value={kpis?.enviado ?? "—"} icon="fa-route"/>
        <KPI label="Recibidos" value={kpis?.recibido ?? "—"} icon="fa-circle-check"/>
      </div>
      <div className="card">
        <div style={{padding: 14, display:"flex", gap:10, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div style={{flex:1, minWidth: 200}}>
            <div className="filter-label">Búsqueda</div>
            <div className="input-group">
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:12}}/></span>
              <input className="input" placeholder="Buscar por #ID…" value={q} onChange={(e)=>{setQ(e.target.value); setSkip(0);}}/>
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
              {["TODOS","PROFORMA","ENVIADO","RECIBIDO"].map(e => (
                <button key={e} className={`seg ${estado === e ? "active" : ""}`} onClick={()=>{setEstado(e); setSkip(0);}}>{e[0]+e.slice(1).toLowerCase()}</button>
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
              <div style={{position:"absolute",top:"100%",right:0,marginTop:4,background:"var(--surface)",border:"1px solid var(--line)",borderRadius:"var(--r-md)",boxShadow:"var(--sh-lg)",zIndex:20,padding:8,minWidth:200}}>
                {cols.filter(c => c.key !== 'actions').map(c => (
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
          <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}>
            <Icon name="fa-spinner fa-spin" style={{fontSize:20}}/>
          </div>
        ) : (
          <DataTable
            data={envios}
            sortCol={sort.col}
            sortDir={sort.dir}
            onSort={(col, dir) => setSort({ col, dir })}
            onRowClick={e => onNav({ name: 'envio-detail', id: e.id, eData: e })}
            columns={visibleCols(cols)}
          />
        )}
        <Pager from={skip + 1} to={Math.min(skip + pageSize, total)} total={total} page={page} pages={pages} onPage={(p) => setSkip((p - 1) * pageSize)}/>
      </div>
    </div>
  );
}

/**
 * Detalle de envío (remito de despacho): encabezado, ítems y devoluciones.
 * @param {object} props
 * @param {number} props.envioId - ID del envío.
 * @param {object} [props.envioData] - Datos precargados desde el listado.
 * @param {function(string|object): void} props.onNav - Navegación.
 * @returns {JSX.Element}
 */
export function EnvioDetail({ envioId, envioData, onNav }) {
  const [detalles, setDetalles]     = useState([]);
  // Filtro SOLO-visual de los renglones ya agregados (no toca cantidades ni el documento).
  const [filtroItems, setFiltroItems] = useState('');
  const itemsVisibles = filterDetalles(detalles, filtroItems);
  const [devs, setDevs]             = useState([]);
  const [loading, setLoading]       = useState(true);
  const [saving, setSaving]         = useState(false);
  const [e, setE]                   = useState(envioData ?? null);
  const [error, setError]           = useState(null);
  const [negativos, setNegativos]   = useState([]);
  const [devRow, setDevRow]         = useState(null);
  const [devCant, setDevCant]       = useState(1);
  const [showEditEnc, setShowEditEnc] = useState(false); // modal editar encabezado

  useEffect(() => {
    setLoading(true);
    Promise.all([
      enviosApi.show(envioId).then(r => { setE(prev => ({ ...prev, ...r.data })); }),
      enviosApi.detalles(envioId),
      enviosApi.devoluciones(envioId),
    ])
      .then(([showRes, dRes, devRes]) => { setDetalles(dRes.data ?? []); setDevs(devRes.data?.data ?? []); })
      .catch(logger.error)
      .finally(() => setLoading(false));
  }, [envioId]);


  async function reloadDetalles() { const r = await enviosApi.detalles(envioId); setDetalles(r.data ?? []); }
  async function reloadHeader() { const r = await enviosApi.show(envioId); setE(prev => ({ ...prev, ...r.data })); }

  async function addItem(prod) {
    setError(null); setSaving(true);
    try { await enviosApi.agregarItem({ envio_id: envioId, producto_id: prod.id, cantidad: 1 }); await Promise.all([reloadDetalles(), reloadHeader()]); }
    catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al agregar producto'); }
    finally { setSaving(false); }
  }

  async function updateCant(item, newCant) {
    if (newCant < 1) return; setSaving(true);
    try { await enviosApi.updateItem({ registro: item.id, cantidad: newCant }); await Promise.all([reloadDetalles(), reloadHeader()]); }
    finally { setSaving(false); }
  }

  async function removeItem(item) {
    setSaving(true);
    try { await enviosApi.deleteItem(item.id); await Promise.all([reloadDetalles(), reloadHeader()]); }
    finally { setSaving(false); }
  }

  async function handleEnviar() {
    if (!window.confirm('¿Confirmar despacho? El stock será descontado de esta sucursal.')) return;
    setSaving(true); setError(null);
    try {
      const negRes = await enviosApi.negativos({ envio_id: envioId });
      if (negRes.data.negativo) { setNegativos(negRes.data.items); setSaving(false); return; }
      setNegativos([]);
      await enviosApi.enviar(envioId);
      await reloadHeader();
    }
    catch { setError('Error al enviar'); }
    finally { setSaving(false); }
  }

  async function handleDevEnvio(item) {
    setSaving(true);
    try {
      const res = await enviosApi.devItem({ envio_id: envioId, registro: item.id, cantidad: parseInt(devCant) });
      if (!res.data.ok) {
        setError(res.data.lim ? 'La cantidad excede el máximo devolvible para este ítem' : 'No se puede devolver este ítem');
      } else {
        const devRes = await enviosApi.devoluciones(envioId);
        setDevs(devRes.data?.data ?? []);
      }
      setDevRow(null); setDevCant(1);
    } finally { setSaving(false); }
  }

  async function handleRecibir() {
    if (!window.confirm('¿Confirmar recepción? El stock será acreditado a esta sucursal.')) return;
    setSaving(true); setError(null);
    try { await enviosApi.recibir(envioId); await reloadHeader(); }
    catch { setError('Error al recibir'); }
    finally { setSaving(false); }
  }

  async function handleAnular() {
    // En PROFORMA no movió stock; ya despachado (ENVIADO/RECIBIDO) el backend revierte el
    // movimiento de stock al anular, así que se avisa (pedido de QA: anular envíos duplicados).
    const msg = (e?.estado ?? '') === 'PROFORMA'
      ? '¿Anular este envío?'
      : '¿Anular este envío despachado? Se revertirá el movimiento de stock entre sucursales.';
    if (!window.confirm(msg)) return;
    setSaving(true);
    try { await enviosApi.destroy(envioId); onNav('envios'); }
    catch (err) { alert(err?.response?.data?.error || 'No se pudo anular el envío.'); }
    finally { setSaving(false); }
  }

  const estado = e?.estado ?? '—';
  // Frontera de sucursal: un envío pertenece a su ORIGEN. Solo el origen edita/despacha/
  // anula la proforma; solo el DESTINO recibe y devuelve. El backend ya lo impone (403);
  // acá ocultamos los controles que no le competen a la sucursal (bug reportado por QA:
  // el destino veía los botones de edición de un envío ajeno).
  const esOrigen  = e?.es_origen ?? false;
  const esDestino = e?.es_destino ?? false;
  const editable  = estado === 'PROFORMA' && esOrigen;
  const soloLectura = !esOrigen && !esDestino;

  if (loading) return <div style={{display:'grid',placeItems:'center',height:300}}><Icon name="fa-spinner fa-spin" style={{fontSize:24,color:'var(--soft)'}}/></div>;

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title={`Envío #${envioId}`} sub={`${e?.origen??'—'} → ${e?.destino??'—'} · ${e?.fecha??'—'}`}
        actions={<>
          <Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={()=>onNav('envios')}>Volver</Button>
          <PdfButton onPdf={() => openPdf(`/envios/${envioId}/pdf`)} />
          {editable && <Button variant="secondary" icon="fa-pen" size="sm" onClick={() => setShowEditEnc(true)}>Editar encabezado</Button>}
          {/* Anular: disponible mientras no esté ya ANULADO y seas el ORIGEN (igual que el backend,
              que revierte el stock al anular un envío despachado). Antes solo salía en PROFORMA →
              no se podían anular envíos duplicados ya enviados (pedido de QA, como en el legacy). */}
          {estado !== 'ANULADO' && esOrigen && <Button variant="ghost" icon="fa-ban" size="sm" style={{color:"var(--danger)"}} disabled={saving} onClick={handleAnular}>Anular</Button>}
        </>}
      />
      {showEditEnc && e && (
        <EnvioEncabezadoModal envio={e} onClose={() => setShowEditEnc(false)} onSaved={reloadHeader}/>
      )}
      {estado === 'PROFORMA' && !esOrigen && (
        <div style={{padding:"10px 14px",background:"var(--alt)",border:"1px solid var(--line)",borderRadius:"var(--r-md)",fontSize:12.5,color:"var(--soft)",display:"flex",gap:8,alignItems:"center"}}>
          <Icon name="fa-lock" style={{fontSize:12,flexShrink:0}}/>
          <span>Solo lectura: este envío pertenece a la sucursal de origen ({e?.origen ?? '—'}). Solo el origen puede editarlo o despacharlo.</span>
        </div>
      )}
      {error && <div style={{padding:"10px 14px",background:"var(--danger-soft)",border:"1px solid rgba(220,38,38,.25)",borderRadius:"var(--r-md)",fontSize:13,color:"var(--danger)",display:"flex",gap:8,alignItems:"center"}}><Icon name="fa-circle-exclamation" style={{fontSize:12,flexShrink:0}}/><span>{error}</span></div>}
      {negativos.length > 0 && (
        <div style={{padding:"14px 18px", background:"var(--warning-soft)", border:"1px solid rgba(245,158,11,.35)", borderRadius:"var(--r-md)"}}>
          <div style={{fontSize:13, fontWeight:700, color:"var(--warning)", marginBottom:8}}>
            <Icon name="fa-triangle-exclamation" style={{marginRight:6}}/>Stock insuficiente en {negativos.length} producto(s)
          </div>
          {negativos.map(n => (
            <div key={n.id} style={{fontSize:12, color:"var(--body)", marginBottom:4}}>
              <span className="mono" style={{fontWeight:700, color:"var(--accent)"}}>{n.codigo}</span> {n.marca} — stock: <strong>{n.stock}</strong>, requerido: <strong>{n.pedido}</strong>
            </div>
          ))}
        </div>
      )}

      {/* Encabezado / intro arriba (mismo patrón que cotizaciones, pedido de René). */}
      <DocHeader
        title="Envío"
        subtitle="Movimiento de stock entre sucursales"
        fields={[
          { label: "N° Envío", value: `#${envioId}` },
          { label: "Origen", value: e?.origen ?? '—' },
          { label: "Destino", value: e?.destino ?? '—' },
          { label: "Medio", value: e?.medio ?? '—' },
          { label: "Fecha", value: e?.fecha ?? '—' },
          { label: "Monto", value: e?.monto ?? '—' },
        ]}
        observacion={e?.observacion ?? ''}
        status={<StatusBadge value={estado}/>}
      />

      <div className="grid-12">
        <div className="stack" style={{"--gap":"16px"}}>
          {editable && (
            // Buscador ANCLADO (sticky) — se mantiene fijo al hacer scroll de los ítems (QA).
            <div style={{position:"sticky", top:86, zIndex:10, background:"var(--page)"}}>
              <Card pad={false}>
                <div style={{padding:16}}>
                  <ProductSearchInput onSelect={addItem} placeholder="Buscar producto para agregar al envío…" />
                </div>
              </Card>
            </div>
          )}
          <Card pad={false}>
            <div className="row" style={{padding:"12px 16px",borderBottom:"1px solid var(--line)",justifyContent:"space-between"}}>
              <div className="row" style={{gap:12}}>
                <span style={{fontSize:13,fontWeight:700,color:"var(--ink)"}}>Ítems del envío</span>
                <Badge tone="neutral">{detalles.length}</Badge>
                {saving && <Icon name="fa-spinner fa-spin" style={{fontSize:12,color:"var(--soft)"}}/>}
              </div>
              {detalles.length > 0 && <RowFilterInput value={filtroItems} onChange={setFiltroItems} count={itemsVisibles.length} total={detalles.length}/>}
            </div>
            {detalles.length === 0 ? <Empty text="Sin productos" icon="fa-truck"/>
              : itemsVisibles.length === 0 ? <Empty text="Sin coincidencias en los productos agregados" icon="fa-filter"/> : (
              <table className="tbl">
                <thead><tr>
                  <th>Producto</th>
                  <th className="center" style={{width:editable?150:80}}>Cantidad</th>
                  {(editable || (estado === 'RECIBIDO' && esDestino)) && <th style={{width: estado==='RECIBIDO'?180:40}}></th>}
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
                      {editable && <td><button className="icon-btn danger" disabled={saving} onClick={()=>removeItem(it)}><Icon name="fa-trash" style={{fontSize:11}}/></button></td>}
                      {estado === 'RECIBIDO' && esDestino && (
                        <td>
                          {devRow?.id === it.id ? (
                            <div style={{display:'flex',gap:6,alignItems:'center'}}>
                              <input className="input mono" type="number" min={1} max={it.cantidad} value={devCant}
                                onChange={ev=>setDevCant(ev.target.value)} style={{width:60,textAlign:'center',padding:'4px 6px'}}/>
                              <button className="icon-btn" title="Confirmar" disabled={saving} onClick={()=>handleDevEnvio(it)}><Icon name="fa-check" style={{fontSize:10,color:"var(--success)"}}/></button>
                              <button className="icon-btn" title="Cancelar" onClick={()=>{setDevRow(null);setDevCant(1);}}><Icon name="fa-xmark" style={{fontSize:10}}/></button>
                            </div>
                          ) : (
                            <button className="icon-btn" title="Devolver" disabled={saving} onClick={()=>{setDevRow(it);setDevCant(1);}}>
                              <Icon name="fa-rotate-left" style={{fontSize:10}}/>
                            </button>
                          )}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>
          {devs.length > 0 && (
            <Card title="Devoluciones" pad={false}>
              <table className="tbl">
                <thead><tr><th style={{width:120}}>Código</th><th>Descripción</th><th className="center" style={{width:80}}>Cant.</th></tr></thead>
                <tbody>
                  {devs.map(d => (
                    <tr key={d.id}>
                      <td><span className="mono" style={{fontSize:11,fontWeight:700,color:"var(--accent)"}}>#{d.producto_id ?? d.id} {d.codigo}</span></td>
                      <td style={{fontSize:12}}>{d.descripcion}</td>
                      <td className="center mono tabular">{d.cantidad}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          )}
        </div>
        <div className="stack" style={{"--gap":"16px"}}>
          <Card title="Acciones">
            <div className="stack" style={{"--gap":"10px"}}>
              {editable && (
                <>
                  <Button variant="secondary" size="md"
                    icon={saving ? "fa-spinner fa-spin" : "fa-check"}
                    disabled={saving} style={{width:"100%"}}>
                    {saving ? "Guardando…" : "Guardado"}
                  </Button>
                  <Button variant="accent" size="lg" icon="fa-truck"
                    disabled={detalles.length===0||saving} onClick={handleEnviar} style={{width:"100%"}}>
                    {saving ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Procesando…</> : 'Confirmar despacho'}
                  </Button>
                </>
              )}
              {/* Recibir es acción del DESTINO (el backend valida cuenta_id === sucursal). */}
              {estado === 'ENVIADO' && esDestino && (
                <Button variant="accent" size="lg" icon="fa-circle-check" disabled={saving} onClick={handleRecibir} style={{width:"100%"}}>
                  {saving ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Procesando…</> : 'Confirmar recepción'}
                </Button>
              )}
              {/* Sin acciones disponibles para esta sucursal en este estado. */}
              {!editable && !(estado === 'ENVIADO' && esDestino) && (
                <div style={{fontSize:12, color:"var(--soft)", textAlign:"center", padding:"8px 0", lineHeight:1.5}}>
                  <Icon name="fa-eye" style={{marginRight:6}}/>
                  {estado === 'ENVIADO' ? 'En tránsito — la recepción la confirma la sucursal de destino.'
                    : estado === 'RECIBIDO' ? 'Envío recibido.'
                    : estado === 'ANULADO' ? 'Envío anulado.'
                    : 'Sin acciones disponibles para tu sucursal.'}
                </div>
              )}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}
