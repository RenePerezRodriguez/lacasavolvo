/**
 * @fileoverview Pantallas de pedidos internos entre sucursales: listado y detalle.
 */

import React, { useState, useEffect, useRef } from 'react';
import { useListData, useColumnVisibility } from '../lib/hooks.js';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, StatusBadge, Card, KPI, Empty, PageHead, Pager, PageSizeSelector, DataTable, PdfButton, ProductSearchInput, QtyStepper, CopyableCode } from '../lib/components.jsx';
import { PedidoFormModal } from './forms.jsx';
import { openPdf, pedidos as pedidosApi } from '../services/api.js';

/**
 * Listado paginado de pedidos internos entre sucursales con KPIs y búsqueda.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function Pedidos({ onNav, sucursalId, effectivePermissions }) {
  const [estado, setEstado]         = useState("TODOS");
  const [q, setQ]                   = useState("");
  const [fechaDesde, setFechaDesde] = useState("");
  const [fechaHasta, setFechaHasta] = useState("");
  const [skip, setSkip]             = useState(0);
  const [pageSize, setPageSize]     = useState(15);
  const [formOpen, setFormOpen]     = useState(false);
  const [sort, setSort]             = useState({ col: 'id', dir: 'desc' });
  const { hiddenCols, toggleCol, visibleCols, showCols, setShowCols } = useColumnVisibility('pedidos', ['origen']);
  const canCreate = (effectivePermissions || []).some(p => p === 'pedidos.create');

  const { items: pedidos, total, kpis, loading, reload } = useListData(
    pedidosApi.list, pedidosApi.kpis,
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
    { key: 'id', title: '#', width: 80, sortable: true, render: p => <span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{p.id}</span> },
    { key: 'fecha', title: 'Fecha', width: 110, sortable: true, render: p => <span className="num">{p.fecha}</span> },
    { key: 'sucursal', title: 'Sucursal', width: 130, render: p => <Badge tone="neutral" outline>{p.sucursal}</Badge> },
    { key: 'observacion', title: 'Observación', render: p => <span style={{maxWidth: 320, wordBreak: 'break-word', whiteSpace: 'normal', lineHeight: 1.4}}>{p.observacion || "—"}</span> },
    { key: 'origen', title: 'Origen', width: 120, defaultHidden: true, render: p => <Badge tone="neutral" outline>{p.origen || p.sucursal || '—'}</Badge> },
    { key: 'estado', title: 'Estado', width: 120, sortable: true, render: p => <StatusBadge value={p.estado}/> },
    { key: 'actions', title: 'Acciones', width: 100, align: 'right', render: p => (
      <div className="actions">
        {p.estado === 'PROFORMA' ? (
          <button className="icon-btn" title="Editar pedido" onClick={e=>{e.stopPropagation(); onNav({ name: 'pedido-detail', id: p.id, pData: p });}}><Icon name="fa-pen" style={{fontSize:11, color: "var(--accent)"}}/></button>
        ) : (
          <button className="icon-btn" title="Ver detalle" onClick={e=>{e.stopPropagation(); onNav({ name: 'pedido-detail', id: p.id, pData: p });}}><Icon name="fa-eye" style={{fontSize:11}}/></button>
        )}
        <PdfButton iconOnly onPdf={() => openPdf(`/pedidos/${p.id}/pdf`)} />
      </div>
    )}
  ];

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Pedidos" sub="Solicitudes de clientes en proceso"
        actions={canCreate ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => setFormOpen(true)}>Nuevo pedido</Button> : null}
      />
      {formOpen && <PedidoFormModal onClose={() => setFormOpen(false)} onSaved={(newP) => { setFormOpen(false); setSkip(0); reload(); if(newP) onNav({ name: 'pedido-detail', id: newP.id, pData: newP }); }}/>}
      <div className="grid-4">
        <KPI label="Pedidos" value={kpis?.total ?? "—"}/>
        <KPI label="Proforma" value={kpis?.proforma ?? "—"} icon="fa-clock"/>
        <KPI label="Válidos" value={kpis?.valido ?? "—"} icon="fa-check-circle"/>
        <KPI label="Anulados" value={kpis?.anulado ?? "—"} icon="fa-circle-xmark" deltaTone="down"/>
      </div>
      <div className="card">
        <div style={{padding: 14, display:"flex", gap:10, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div style={{flex:1, minWidth: 220}}>
            <div className="filter-label">Búsqueda</div>
            <div className="input-group">
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:12}}/></span>
              <input className="input" placeholder="Buscar por #ID, observación…" value={q} onChange={(e)=>{setQ(e.target.value); setSkip(0);}}/>
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
              {["TODOS","PROFORMA","VALIDO","ANULADO"].map(e => (
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
            data={pedidos}
            sortCol={sort.col}
            sortDir={sort.dir}
            onSort={(col, dir) => setSort({ col, dir })}
            onRowClick={p => onNav({ name: 'pedido-detail', id: p.id, pData: p })}
            columns={visibleCols(cols)}
          />
        )}
        <Pager from={skip + 1} to={Math.min(skip + pageSize, total)} total={total} page={page} pages={pages} onPage={(p) => setSkip((p - 1) * pageSize)}/>
      </div>
    </div>
  );
}

/**
 * Detalle de pedido interno entre sucursales.
 * @param {object} props
 * @param {number} props.pedidoId - ID del pedido.
 * @param {object} [props.pedidoData] - Datos precargados desde el listado.
 * @param {function(string|object): void} props.onNav - Navegación.
 * @returns {JSX.Element}
 */
export function PedidoDetail({ pedidoId, pedidoData, onNav }) {
  const [detalles, setDetalles] = useState([]);
  const [loading, setLoading]   = useState(true);
  const [saving, setSaving]     = useState(false);
  const [p, setP]               = useState(pedidoData ?? null);
  const [error, setError]           = useState(null);
  const [obsEdit, setObsEdit]       = useState(null);

  useEffect(() => {
    setLoading(true);
    Promise.all([
      pedidosApi.show(pedidoId).then(r => { setP(prev => ({ ...prev, ...r.data })); }),
      pedidosApi.detalles(pedidoId).then(r => setDetalles(r.data ?? [])),
    ]).catch(logger.error).finally(() => setLoading(false));
  }, [pedidoId]);


  async function reload() { const r = await pedidosApi.detalles(pedidoId); setDetalles(r.data ?? []); }

  async function addItem(prod) {
    setError(null); setSaving(true);
    try {
      const res = await pedidosApi.agregarItem({ pedido_id: pedidoId, producto_id: prod.id, cantidad: 1 });
      if (res.data?.duplicado) { setError('Este producto ya está en el pedido'); setSaving(false); return; }
      await reload();
    } catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al agregar producto'); }
    finally { setSaving(false); }
  }

  async function updateCant(item, newCant) {
    if (newCant < 1) return; setSaving(true);
    try { await pedidosApi.updateItem({ registro: item.id, cantidad: newCant }); await reload(); }
    finally { setSaving(false); }
  }

  async function removeItem(item) {
    setSaving(true);
    try { await pedidosApi.deleteItem(item.id); await reload(); }
    finally { setSaving(false); }
  }

  async function handleValidar() {
    if (!window.confirm('¿Validar este pedido?')) return;
    setSaving(true); setError(null);
    try { await pedidosApi.validar(pedidoId); if (p) setP({...p, estado:'VALIDO'}); }
    catch { setError('Error al validar'); }
    finally { setSaving(false); }
  }

  async function handleAnular() {
    if (!window.confirm('¿Anular este pedido?')) return;
    setSaving(true);
    try { await pedidosApi.destroy(pedidoId); onNav('pedidos'); }
    finally { setSaving(false); }
  }

  async function saveObs() {
    if (obsEdit === null || obsEdit === (p?.observacion ?? '')) return;
    try { await pedidosApi.updateEncabezado({ pedido_id: pedidoId, observacion: obsEdit }); setP(prev => ({...prev, observacion: obsEdit})); }
    catch { setError('Error al guardar observación'); }
  }

  const estado = p?.estado ?? '—';

  if (loading) return <div style={{display:'grid',placeItems:'center',height:300}}><Icon name="fa-spinner fa-spin" style={{fontSize:24,color:'var(--soft)'}}/></div>;

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title={`Pedido #${pedidoId}`} sub={`${p?.sucursal??'—'} · ${p?.fecha??'—'}`}
        actions={<>
          <Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={()=>onNav('pedidos')}>Volver</Button>
          <PdfButton onPdf={() => openPdf(`/pedidos/${pedidoId}/pdf`)} />
          {estado === 'PROFORMA' && <Button variant="ghost" icon="fa-ban" size="sm" style={{color:"var(--danger)"}} disabled={saving} onClick={handleAnular}>Anular</Button>}
        </>}
      />
      {error && <div style={{padding:"10px 14px",background:"var(--danger-soft)",border:"1px solid rgba(220,38,38,.25)",borderRadius:"var(--r-md)",fontSize:13,color:"var(--danger)",display:"flex",gap:8,alignItems:"center"}}><Icon name="fa-circle-exclamation" style={{fontSize:12,flexShrink:0}}/><span>{error}</span></div>}
      <div className="grid-12">
        <div className="stack" style={{"--gap":"16px"}}>
          {estado === 'PROFORMA' && (
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
                <span style={{fontSize:13,fontWeight:700,color:"var(--ink)"}}>Productos del pedido</span>
                <Badge tone="neutral">{detalles.length}</Badge>
                {saving && <Icon name="fa-spinner fa-spin" style={{fontSize:12,color:"var(--soft)"}}/>}
              </div>
              <StatusBadge value={estado}/>
            </div>
            {detalles.length === 0 ? <Empty text="Sin productos" icon="fa-clipboard-list"/> : (
              // Detalle desglosado en columnas (ID · Código · Descripción · Marca) como en
              // Productos — observación de QA: antes iba todo amontonado en una sola celda y
              // el "ID" mostrado era el de la línea, no el del producto. El código es copiable.
              <table className="tbl">
                <thead><tr>
                  <th style={{width:64}}>ID</th>
                  <th style={{width:150}}>Código</th>
                  <th>Descripción</th>
                  <th style={{width:120}}>Marca</th>
                  <th className="center" style={{width:estado==='PROFORMA'?150:80}}>Cantidad</th>
                  {estado === 'PROFORMA' && <th style={{width:40}}></th>}
                </tr></thead>
                <tbody>
                  {detalles.map(it => (
                    <tr key={it.id}>
                      <td><span className="mono" style={{fontSize:11,fontWeight:600,color:"var(--soft)"}}>#{it.producto_id ?? it.id}</span></td>
                      <td><CopyableCode code={it.codigo} codeStyle={{fontSize:11,fontWeight:700,color:"var(--accent)"}}/></td>
                      <td><span style={{fontSize:13,fontWeight:600,color:"var(--ink)"}}>{it.descripcion}</span></td>
                      <td><span style={{fontSize:11.5,color:"var(--accent)",fontWeight:600}}>{it.marca || '—'}</span></td>
                      <td className="center">
                        {estado === 'PROFORMA'
                          ? <QtyStepper value={it.cantidad} onChange={(n)=>updateCant(it,n)} disabled={saving}/>
                          : <span className="mono tabular" style={{fontWeight:700}}>{it.cantidad}</span>}
                      </td>
                      {estado === 'PROFORMA' && <td><button className="icon-btn danger" disabled={saving} onClick={()=>removeItem(it)}><Icon name="fa-trash" style={{fontSize:11}}/></button></td>}
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
              {estado === 'PROFORMA' && (
                <>
                  <div className="field">
                    <label className="label" style={{fontSize:11,marginBottom:4,display:'block',color:'var(--soft)'}}>Observación</label>
                    {/* maxLength=191: coincide con el cap del backend (columna varchar(191))
                        para que el usuario no tipee de más y reciba un 422 al guardar. */}
                    <textarea className="input" rows={3} placeholder="Notas del pedido…" maxLength={191}
                      value={obsEdit ?? (p?.observacion ?? '')}
                      onChange={e => setObsEdit(e.target.value)}
                      onBlur={saveObs}
                      style={{resize:'vertical',fontSize:12,lineHeight:1.5}}/>
                  </div>
                  <Button variant="secondary" size="md"
                    icon={saving ? "fa-spinner fa-spin" : "fa-save"}
                    onClick={async ()=>{ await saveObs(); }}
                    style={{width:"100%"}}>
                    {saving ? "Guardando…" : "Guardar cambios"}
                  </Button>
                  <Button variant="accent" size="lg" icon="fa-check"
                    disabled={detalles.length===0||saving} onClick={handleValidar} style={{width:"100%"}}>
                    {saving ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Procesando…</> : 'Validar pedido'}
                  </Button>
                </>
              )}
            </div>
          </Card>
          <Card title="Resumen">
            <div className="stack" style={{"--gap":"10px"}}>
              {[{label:"N° Pedido",value:`#${pedidoId}`},{label:"Sucursal",value:p?.sucursal??'—'},{label:"Fecha",value:p?.fecha??'—'},{label:"Estado",value:estado},...(p?.observacion?[{label:"Observación",value:p.observacion}]:[])].map(r => (
                <div key={r.label} className="row" style={{justifyContent:"space-between",fontSize:12}}>
                  <span style={{color:"var(--soft)"}}>{r.label}</span>
                  <span style={{fontWeight:600,color:"var(--ink)"}}>{r.value}</span>
                </div>
              ))}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}
