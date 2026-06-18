/**
 * @fileoverview Pantallas de compras: listado y detalle con gestión de ítems,
 * validación (actualiza stock), pagos y anulación.
 */

import React, { useState, useEffect } from 'react';
import { useListData, useColumnVisibility } from '../lib/hooks.js';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, StatusBadge, Card, KPI, Empty, PageHead, Pager, PageSizeSelector, DataTable, PdfButton, ProductSearchInput, AccountSearchInput, QtyStepper } from '../lib/components.jsx';
import { CompraFormModal } from './forms.jsx';
import { openPdf, compras as comprasApi } from '../services/api.js';

/**
 * Listado paginado de compras con KPIs, búsqueda y detalle por proveedor.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function Compras({ onNav, sucursalId, user, effectivePermissions }) {
  const [estado, setEstado]         = useState("TODOS");
  const [pagado, setPagado]         = useState("TODOS");
  const [q, setQ]                   = useState("");
  const [fechaDesde, setFechaDesde] = useState("");
  const [fechaHasta, setFechaHasta] = useState("");
  const [skip, setSkip]             = useState(0);
  const [formOpen, setFormOpen]     = useState(false);
  const [pageSize, setPageSize]     = useState(15);
  const [sort, setSort]             = useState({ col: 'id', dir: 'desc' });
  const { hiddenCols, toggleCol, visibleCols, showCols, setShowCols } = useColumnVisibility('compras', ['sucursal']);
  const canCreate = (effectivePermissions || []).some(p => p === 'compras.create');
  // El KPI de monto validadas solo es visible para GERENTE y ADMIN (respeta rol simulado)
  const effectiveRole = user?.simulated_role_name || user?.role;
  const showMontoKpi  = ['ADMIN', 'GERENTE'].includes(effectiveRole);

  const { items: compras, total, kpis, loading, reload } = useListData(
    comprasApi.list, comprasApi.kpis,
    () => ({
      skip, take: pageSize,
      sort: sort.col, dir: sort.dir,
      ...(estado !== "TODOS" && { estado_filtro: estado }),
      ...(pagado !== "TODOS" && { pagado_filtro: pagado }),
      ...(q && { search: q }),
      ...(fechaDesde && { fecha_desde: fechaDesde }),
      ...(fechaHasta && { fecha_hasta: fechaHasta }),
    }),
    [estado, pagado, q, fechaDesde, fechaHasta, skip, pageSize, sort, sucursalId]
  );

  const columns = [
    { key: 'id', title: '#', sortable: true, width: 80, render: c => <span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{c.id}</span> },
    { key: 'fecha', title: 'Fecha', sortable: true, width: 110, className: 'num' },
    { key: 'cuenta', title: 'Proveedor', sortable: true, className: 'strong' },
    { key: 'tipo', title: 'Tipo', sortable: true, width: 90, render: c => <Badge tone={c.tipo === "CONTADO" ? "neutral" : "info"} outline>{c.tipo}</Badge> },
    { key: 'total', title: 'Total', sortable: true, width: 160, className: 'right mono tabular strong' },
    { key: 'pagado', title: 'Pago', sortable: true, width: 130, render: c =>
        // Una PROFORMA no pagó nada todavía (el egreso a caja recién sale al validar),
        // aunque el backend marque pagado='PAGADO' para las CONTADO desde su creación.
        c.estado !== "VALIDO"
          ? <span style={{color:"var(--soft)", fontSize:12}}>—</span>
          : c.pagado === "PAGADO"
            ? <span style={{color:"var(--success)", fontWeight:600, fontSize:12}}><Icon name="fa-check-circle" style={{marginRight:4, fontSize:10}}/>Pagado</span>
            : <span style={{color:"var(--warning)", fontWeight:600, fontSize:12}}>Por pagar</span> },
    { key: 'estado', title: 'Estado', sortable: true, width: 120, render: c => <StatusBadge value={c.estado}/> },
    { key: 'sucursal', title: 'Sucursal', width: 120, defaultHidden: true, render: c => <Badge tone="neutral" outline>{c.sucursal || '—'}</Badge> },
    { key: 'acciones', title: 'Acciones', width: 100, className: 'right', render: c => (
      <div className="actions" onClick={e=>e.stopPropagation()}>
        {c.estado === 'PROFORMA' ? (
          <button className="icon-btn" title="Editar compra" onClick={() => onNav({ name: 'compra-detail', id: c.id, cData: c })}><Icon name="fa-pen" style={{fontSize:11, color: "var(--accent)"}}/></button>
        ) : (
          <button className="icon-btn" title="Ver detalle" onClick={() => onNav({ name: 'compra-detail', id: c.id, cData: c })}><Icon name="fa-eye" style={{fontSize:11}}/></button>
        )}
        <PdfButton iconOnly onPdf={() => openPdf(`/compras/${c.id}/pdf`)} />
      </div>
    )}
  ];

  const page  = Math.floor(skip / pageSize) + 1;
  const pages = Math.ceil(total / pageSize);
  const handlePageSize = (n) => { setPageSize(n); setSkip(0); };

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Compras" sub="Órdenes a proveedores y cuentas por pagar"
        actions={canCreate ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => setFormOpen(true)}>Nueva compra</Button> : null}
      />
      {formOpen && <CompraFormModal onClose={() => setFormOpen(false)} onSaved={(newC) => { setSkip(0); setFormOpen(false); reload(); if(newC) onNav({ name: 'compra-detail', id: newC.id, cData: newC }); }}/>}
      <div className={showMontoKpi ? "grid-4" : "grid-3"}>
        <KPI label="Compras registradas" value={kpis?.total ?? "—"} icon="fa-credit-card"/>
        <KPI label="Proforma" value={kpis?.proforma ?? "—"} icon="fa-clock"/>
        <KPI label="Validadas" value={kpis?.valido ?? "—"} icon="fa-check-circle"/>
        {showMontoKpi && <KPI label="Monto validadas" value={kpis?.monto ?? "—"} icon="fa-coins"/>}
      </div>
      <div className="card">
        <div style={{padding: 14, display:"flex", gap:10, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div style={{flex:1, minWidth: 220}}>
            <div className="filter-label">Búsqueda</div>
            <div className="input-group">
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:12}}/></span>
              <input className="input" placeholder="Buscar por #ID, proveedor…" value={q} onChange={(e)=>{setQ(e.target.value); setSkip(0);}}/>
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
            <div className="filter-label">Pago</div>
            <div className="seg-tabs">
              {[["TODOS","Todos"],["PAGADO","Pagado"],["POR PAGAR","Pendiente"]].map(([v,l]) => (
                <button key={v} className={`seg ${pagado === v ? "active" : ""}`} onClick={()=>{setPagado(v); setSkip(0);}}>{l}</button>
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
              {columns.filter(c => !hiddenCols.has(c.key)).length}/{columns.length}
            </button>
            {showCols && (
              <div style={{position:"absolute",top:"100%",right:0,marginTop:4,background:"var(--surface)",border:"1px solid var(--line)",borderRadius:"var(--r-md)",boxShadow:"var(--sh-lg)",zIndex:20,padding:8,minWidth:180}}>
                {columns.filter(c => c.key !== 'acciones').map(c => (
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
            data={compras}
            columns={visibleCols(columns)}
            sortCol={sort.col}
            sortDir={sort.dir}
            onSort={(col, dir) => { setSort({ col, dir }); setSkip(0); }}
            onRowClick={(row) => onNav({ name: 'compra-detail', id: row.id, cData: row })}
          />
        )}
        <Pager from={skip + 1} to={Math.min(skip + pageSize, total)} total={total} page={page} pages={pages} onPage={(p) => setSkip((p - 1) * pageSize)}/>
      </div>
    </div>
  );
}

/**
 * Detalle de compra: encabezado, ítems, pagos y devoluciones.
 * @param {object} props
 * @param {number} props.compraId - ID de la compra.
 * @param {object} [props.compraData] - Datos precargados desde el listado.
 * @param {function(string|object): void} props.onNav - Navegación.
 * @returns {JSX.Element}
 */
export function CompraDetail({ compraId, compraData, onNav }) {
  const [detalles, setDetalles]     = useState([]);
  const [pagos, setPagos]           = useState([]);
  const [devs, setDevs]             = useState([]);
  const [loading, setLoading]       = useState(true);
  const [saving, setSaving]         = useState(false);
  const [c, setC]                   = useState(compraData ?? null);
  const [showPagar, setShowPagar]   = useState(false);
  const [montoPago, setMontoPago]   = useState('');
  const [error, setError]           = useState(null);
  const [showDev, setShowDev]       = useState(false);
  const [devProdId, setDevProdId]   = useState('');
  const [devCant, setDevCant]       = useState(1);

  // Edición de encabezado (Resumen) en PROFORMA
  const [editTipo, setEditTipo]           = useState('');
  const [editFecha, setEditFecha]         = useState('');
  const [editCuentaId, setEditCuentaId]   = useState(null);
  const [editCuentaNombre, setEditCuentaNombre] = useState('');
  const [editandoResumen, setEditandoResumen] = useState(false);

  // Inicializar campos de edición cuando se cargan los datos de la compra
  useEffect(() => {
    if (c) {
      setEditTipo(c.tipo ?? 'CONTADO');
      setEditFecha(c.fecha_raw ?? '');
      setEditCuentaId(c.cuenta_id ?? null);
      setEditCuentaNombre(c.cuenta ?? '');
    }
  }, [c?.id, c?.cuenta_id]);

  async function handleSaveResumen() {
    if (!editCuentaId) return;
    setSaving(true); setError(null);
    try {
      await comprasApi.updateEncabezado({
        compra_id: compraId,
        cuenta_id: editCuentaId,
        tipo: editTipo,
        fecha: editFecha,
      });
      await reloadHeader();
      setEditandoResumen(false);
    } catch (e) {
      setError(e?.response?.data?.message ?? 'Error al guardar cambios.');
    } finally { setSaving(false); }
  }

  useEffect(() => {
    setLoading(true);
    Promise.all([
      comprasApi.show(compraId).then(r => { setC(prev => ({ ...prev, ...r.data })); }),
      comprasApi.detalles(compraId),
      comprasApi.pagos(compraId),
      comprasApi.devoluciones(compraId),
    ])
      .then(([showRes, dRes, pRes, devRes]) => { setDetalles(dRes.data ?? []); setPagos(pRes.data ?? []); setDevs(devRes.data?.data ?? devRes.data ?? []); })
      .catch(logger.error)
      .finally(() => setLoading(false));
  }, [compraId]);


  async function reloadDetalles() { const r = await comprasApi.detalles(compraId); setDetalles(r.data ?? []); }
  async function reloadHeader() { const r = await comprasApi.show(compraId); setC(prev => ({ ...prev, ...r.data })); }

  /**
   * Agrega un producto a la compra PROFORMA y recarga detalles + encabezado.
   * @param {object} p - Producto seleccionado en ProductSearchInput.
   */
  async function addItem(p) {
    setError(null); setSaving(true);
    try { await comprasApi.agregarItem({ compra_id: compraId, producto_id: p.id, cantidad: 1 }); await Promise.all([reloadDetalles(), reloadHeader()]); }
    catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al agregar'); }
    finally { setSaving(false); }
  }

  async function updateCant(item, newCant) {
    if (newCant < 1) return; setSaving(true);
    try { await comprasApi.updateItem({ registro: item.id, costo: parseFloat(item.costo), cantidad: newCant }); await Promise.all([reloadDetalles(), reloadHeader()]); }
    finally { setSaving(false); }
  }
  async function updateCosto(item, newCosto) {
    const c = parseFloat(newCosto);
    if (isNaN(c) || c < 0) return;
    setSaving(true);
    try { await comprasApi.updateItem({ registro: item.id, costo: c, cantidad: item.cantidad }); await Promise.all([reloadDetalles(), reloadHeader()]); }
    finally { setSaving(false); }
  }
  async function removeItem(item) {
    setSaving(true);
    try { await comprasApi.deleteItem(item.id); await Promise.all([reloadDetalles(), reloadHeader()]); }
    finally { setSaving(false); }
  }

  async function handleValidar() {
    if (!window.confirm('¿Validar esta compra? El stock será actualizado.')) return;
    setError(null); setSaving(true);
    try { await comprasApi.validar(compraId); await Promise.all([reloadDetalles(), reloadHeader()]); }
    catch (e) { setError(e?.response?.data?.error ?? 'Error al validar'); }
    finally { setSaving(false); }
  }

  async function handlePagar() {
    const monto = parseFloat(montoPago);
    if (isNaN(monto) || monto <= 0) { setError('Ingresa un monto válido.'); return; }
    if (monto > saldoNum) { setError(`El monto (Bs ${monto.toFixed(2)}) supera el saldo pendiente (Bs ${saldoNum.toFixed(2)}).`); return; }
    setError(null); setSaving(true);
    try {
      await comprasApi.pagar({ compra_id: compraId, monto });
      await Promise.all([reloadDetalles(), reloadHeader()]);
      const pRes = await comprasApi.pagos(compraId); setPagos(pRes.data ?? []);
      setMontoPago(''); setShowPagar(false);
    } catch (e) {
      setError(e?.response?.data?.error ?? 'Error al registrar pago');
    } finally { setSaving(false); }
  }

  /** Anula la compra (restituye stock si estaba validada) y vuelve al listado. */
  async function handleAnular() {
    if (!window.confirm('¿Anular esta compra?')) return;
    setError(null); setSaving(true);
    try { await comprasApi.destroy(compraId); onNav('compras'); }
    catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al anular la compra'); }
    finally { setSaving(false); }
  }

  /** Registra una devolución al proveedor del producto seleccionado. */
  async function handleDev() {
    if (!devProdId) return;
    setError(null); setSaving(true);
    try {
      await comprasApi.devItem({ compra_id: compraId, producto_id: parseInt(devProdId), cantidad: parseInt(devCant) });
      const devRes = await comprasApi.devoluciones(compraId);
      setDevs(devRes.data?.data ?? devRes.data ?? []);
      await reloadHeader();
      setDevProdId(''); setDevCant(1); setShowDev(false);
    } catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al registrar la devolución'); }
    finally { setSaving(false); }
  }

  const estado    = c?.estado ?? '—';
  const totalNum  = detalles.reduce((s, d) => s + parseFloat(d.costo) * d.cantidad, 0);
  // monto_num viene del backend como float; el string "Bs. 1,234.56" no es parseable con parseFloat
  const pagadoNum = pagos.reduce((s, p) => s + (p.monto_num ?? 0), 0);
  const saldoNum  = c?.saldo !== undefined && c?.saldo !== null ? parseFloat(c.saldo) : Math.max(0, totalNum - pagadoNum);

  // Ítem seleccionado para devolver al proveedor: costo unitario y cuánto queda por devolver
  // (cantidad comprada menos lo ya devuelto). Surface el límite antes de que el backend lo rechace.
  const devSel      = detalles.find(d => String(d.producto_id ?? d.id) === String(devProdId));
  const devReturned = devs.filter(d => String(d.producto_id) === String(devProdId)).reduce((s, d) => s + Number(d.cantidad || 0), 0);
  const devMax      = devSel ? Number(devSel.cantidad) - devReturned : 0;
  const devUnit     = devSel ? parseFloat(String(devSel.costo).replace(/[^0-9.]/g, '')) || 0 : 0;
  const devCantNum  = Number(devCant) || 0;
  const devInvalido = !devProdId || devCantNum < 1 || devCantNum > devMax;

  if (loading) return <div style={{display:'grid',placeItems:'center',height:300}}><Icon name="fa-spinner fa-spin" style={{fontSize:24,color:'var(--soft)'}}/></div>;

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title={`Compra #${compraId}`} sub={`${c?.cuenta??'—'} · ${c?.fecha??'—'} · ${c?.tipo??'—'}`}
        actions={<>
          <Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={()=>onNav('compras')}>Volver</Button>
          <PdfButton onPdf={() => openPdf(`/compras/${compraId}/pdf`)} />
          {estado !== 'ANULADO' && <Button variant="ghost" icon="fa-ban" size="sm" style={{color:"var(--danger)"}} disabled={saving} onClick={handleAnular}>Anular</Button>}
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
                <ProductSearchInput onSelect={addItem} placeholder="Buscar producto para agregar…" priceField="p_comp" showStock={false} />
              </div>
            </Card>
            </div>
          )}
          <Card pad={false}>
            <div className="row" style={{padding:"12px 16px",borderBottom:"1px solid var(--line)",justifyContent:"space-between"}}>
              <div className="row" style={{gap:12}}>
                <span style={{fontSize:13,fontWeight:700,color:"var(--ink)"}}>Ítems de compra</span>
                <Badge tone="neutral">{detalles.length}</Badge>
                {saving && <Icon name="fa-spinner fa-spin" style={{fontSize:12,color:"var(--soft)"}}/>}
              </div>
              <StatusBadge value={estado}/>
            </div>
            {detalles.length === 0 ? <Empty text="Sin productos" icon="fa-cubes"/> : (
              <table className="tbl">
                <thead><tr>
                  <th>Producto</th>
                  <th className="center" style={{width:estado==='PROFORMA'?150:80}}>Cantidad</th>
                  <th className="right" style={{width:110}}>P. Costo</th>
                  <th className="right" style={{width:130}}>Subtotal</th>
                  {estado === 'PROFORMA' && <th style={{width:40}}></th>}
                </tr></thead>
                <tbody>
                  {detalles.map(it => (
                    <tr key={it.id}>
                      <td>
                        <div style={{fontSize:13,fontWeight:600,color:"var(--ink)"}}>{it.descripcion}</div>
                        <div className="row" style={{gap:6,marginTop:2}}>
                        <span className="mono" style={{fontSize:10.5,color:"var(--soft)"}}>#{it.producto_id ?? it.id} · {it.codigo}</span>
                          <span style={{fontSize:10.5,color:"var(--accent)",fontWeight:600}}>{it.marca}</span>
                        </div>
                      </td>
                      <td className="center">
                        {estado === 'PROFORMA'
                          ? <QtyStepper value={it.cantidad} onChange={(n)=>updateCant(it,n)} disabled={saving}/>
                          : <span className="mono tabular" style={{fontWeight:700}}>{it.cantidad}</span>}
                      </td>
                      <td className="right mono tabular" style={{fontSize:13}}>
                        {estado === 'PROFORMA' ? (
                          <input className="input mono" type="number" min="0" step="0.01"
                            defaultValue={parseFloat(it.costo)}
                            onBlur={e => updateCosto(it, e.target.value)}
                            style={{width:100,textAlign:"right",padding:"3px 6px",fontSize:12}}/>
                        ) : <>Bs {parseFloat(it.costo).toFixed(2)}</>}
                      </td>
                      <td className="right mono tabular strong" style={{fontSize:13,fontWeight:700}}>{it.subtotal}</td>
                      {estado === 'PROFORMA' && <td><button className="icon-btn danger" disabled={saving} onClick={()=>removeItem(it)}><Icon name="fa-trash" style={{fontSize:11}}/></button></td>}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>
          {pagos.length > 0 && (
            <Card title="Historial de pagos" pad={false}>
              <table className="tbl">
                <thead><tr><th style={{width:110}}>Fecha</th><th>Descripción</th><th className="right" style={{width:140}}>Monto</th></tr></thead>
                <tbody>
                  {pagos.map(p => (
                    <tr key={p.id}>
                      <td className="mono" style={{fontSize:11,color:"var(--soft)"}}>{p.fecha}</td>
                      <td style={{fontSize:12,color:"var(--body)"}}>{p.descripcion}</td>
                      <td className="right mono tabular strong" style={{color:"var(--danger)"}}>{p.monto}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          )}
        </div>
        <div className="stack" style={{"--gap":"16px"}}>
          {(estado === 'VALIDO' || devs.length > 0) && (
            <Card title="Devoluciones a proveedor" pad={false}>
              {devs.length > 0 && (
                <table className="tbl">
                  <thead><tr>
                    <th style={{width:110}}>Fecha</th><th style={{width:120}}>Código</th>
                    <th>Descripción</th><th className="center" style={{width:70}}>Cant.</th>
                    <th className="right" style={{width:120}}>Total</th>
                  </tr></thead>
                  <tbody>
                    {devs.map(d => (
                      <tr key={d.id}>
                        <td className="mono" style={{fontSize:11,color:"var(--soft)"}}>{d.fecha}</td>
                        <td><span className="mono" style={{fontSize:11,fontWeight:700,color:"var(--accent)"}}>#{d.producto_id ?? d.id} {d.codigo}</span></td>
                        <td style={{fontSize:12}}>{d.descripcion}</td>
                        <td className="center mono tabular">{d.cantidad}</td>
                        <td className="right mono tabular strong" style={{color:"var(--warning)"}}>{d.total ?? `Bs ${d.costo}`}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              {estado === 'VALIDO' && (
                showDev ? (
                  <div style={{padding:14, borderTop: devs.length > 0 ? '1px solid var(--line)' : undefined}}>
                    <div style={{display:'flex', gap:8, flexWrap:'wrap', alignItems:'flex-end'}}>
                      <div className="field" style={{marginBottom:0, flex:1, minWidth:160}}>
                        <label className="label" style={{fontSize:10}}>Producto a devolver</label>
                        <select className="input" value={devProdId} onChange={e=>{setDevProdId(e.target.value); setDevCant(1);}} style={{fontSize:12}}>
                          <option value="">Seleccionar…</option>
                          {detalles.map(d => <option key={d.producto_id ?? d.id} value={d.producto_id ?? d.id}>{d.descripcion}</option>)}
                        </select>
                      </div>
                      <div className="field" style={{marginBottom:0, width:90}}>
                        <label className="label" style={{fontSize:10}}>Cantidad</label>
                        <input className="input mono" type="number" min={1} max={devMax || 1} value={devCant} onChange={e=>setDevCant(e.target.value)} style={{textAlign:'center'}}/>
                      </div>
                      <Button variant="accent" size="sm" disabled={devInvalido||saving} onClick={handleDev}>Registrar</Button>
                      <Button variant="ghost" size="sm" onClick={()=>{setShowDev(false);setDevProdId('');setDevCant(1);}}>Cancelar</Button>
                    </div>
                    {devSel && (
                      <div style={{marginTop:8, fontSize:11, color: devCantNum > devMax ? 'var(--danger)' : 'var(--soft)'}}>
                        Costo unitario <strong className="mono">Bs {devUnit.toFixed(2)}</strong> · Devolver hasta <strong className="mono">{devMax}</strong> de {devSel.cantidad}
                        {devCantNum > devMax && <span> · cantidad supera el límite</span>}
                        {devCantNum >= 1 && devCantNum <= devMax && <span> · reintegro <strong className="mono">Bs {(devUnit * devCantNum).toFixed(2)}</strong></span>}
                      </div>
                    )}
                  </div>
                ) : (
                  <div style={{padding:'8px 14px'}}>
                    <Button variant="ghost" size="sm" icon="fa-rotate-left" onClick={()=>setShowDev(true)}>Registrar devolución</Button>
                  </div>
                )
              )}
            </Card>
          )}
          <Card title="Total">
            <div style={{textAlign:"center",padding:"12px 0"}}>
              <div className="display tabular" style={{fontSize:32,fontWeight:700,color:"var(--ink)"}}>Bs {totalNum.toFixed(2)}</div>
              {estado === 'VALIDO' && c?.acuenta > 0 && <div style={{fontSize:11,color:"var(--soft)",marginTop:4}}>Pagado: Bs {parseFloat(c.acuenta).toFixed(2)}</div>}
              {estado === 'VALIDO' && saldoNum > 0 && <div style={{fontSize:12,fontWeight:700,color:"var(--warning)",marginTop:6}}>Saldo: Bs {saldoNum.toFixed(2)}</div>}
            </div>
            {estado === 'PROFORMA' && (
              <Button variant="accent" size="lg" icon="fa-check" disabled={detalles.length===0||saving} onClick={handleValidar} style={{width:"100%",marginTop:12}}>
                {saving ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Procesando…</> : 'Validar compra'}
              </Button>
            )}
            {estado === 'VALIDO' && c?.tipo === 'CREDITO' && saldoNum > 0 && !showPagar && (
              <Button variant="accent" icon="fa-money-bill-wave" style={{width:"100%",marginTop:12}} onClick={()=>{setMontoPago(saldoNum.toFixed(2));setShowPagar(true);}}>
                Registrar pago
              </Button>
            )}
            {showPagar && (
              <div style={{marginTop:12,paddingTop:12,borderTop:"1px solid var(--line)"}}>
                <div className="field" style={{marginBottom:8}}>
                  <label className="label">Monto a pagar (Bs.)</label>
                  <input className="input mono" type="number" value={montoPago} onChange={e=>setMontoPago(e.target.value)} style={{fontSize:20,textAlign:"right",fontWeight:700}}/>
                </div>
                <div className="row" style={{gap:8}}>
                  <Button variant="ghost" size="sm" onClick={()=>setShowPagar(false)}>Cancelar</Button>
                  <Button variant="accent" size="sm" disabled={saving||!montoPago} onClick={handlePagar} style={{flex:1}}>
                    {saving ? <Icon name="fa-spinner fa-spin"/> : "Confirmar"}
                  </Button>
                </div>
              </div>
            )}
          </Card>
          <Card title="Resumen" head={estado === 'PROFORMA' && !editandoResumen ? (
            <Button variant="ghost" size="sm" icon="fa-pen" onClick={() => setEditandoResumen(true)}>Editar</Button>
          ) : null}>
            {editandoResumen ? (
              <div className="stack" style={{"--gap":"10px"}}>
                {/* N° Compra — no editable */}
                <div className="row" style={{justifyContent:"space-between",fontSize:12}}>
                  <span style={{color:"var(--soft)"}}>N° Compra</span>
                  <span style={{fontWeight:700,color:"var(--ink)"}}>#{compraId}</span>
                </div>
                {/* Tipo */}
                <div className="field" style={{marginBottom:0}}>
                  <label className="label" style={{fontSize:10}}>Tipo</label>
                  <div className="row" style={{gap:6}}>
                    {["CONTADO","CREDITO"].map(t => (
                      <button key={t} type="button" onClick={() => setEditTipo(t)}
                        style={{flex:1,padding:"8px",borderRadius:"var(--r-md)",border:editTipo===t?"2px solid var(--accent)":"2px solid var(--line)",background:editTipo===t?"var(--accent-a15)":"var(--surface)",color:editTipo===t?"var(--accent)":"var(--body)",fontSize:11,fontWeight:700,cursor:"pointer"}}>
                        {t}
                      </button>
                    ))}
                  </div>
                </div>
                {/* Fecha */}
                <div className="field" style={{marginBottom:0}}>
                  <label className="label" style={{fontSize:10}}>Fecha</label>
                  <input className="input" type="date" value={editFecha} onChange={e => setEditFecha(e.target.value)} style={{fontSize:12}}/>
                </div>
                {/* Proveedor */}
                <div className="field" style={{marginBottom:0}}>
                  <label className="label" style={{fontSize:10}}>Proveedor</label>
                  {editCuentaId ? (
                    <div className="row" style={{alignItems:"center",justifyContent:"space-between"}}>
                      <span style={{fontSize:13,fontWeight:600,color:"var(--ink)"}}>{editCuentaNombre}</span>
                      <Button variant="ghost" size="sm" onClick={() => { setEditCuentaId(null); setEditCuentaNombre(''); }} style={{fontSize:10}}><Icon name="fa-times" style={{fontSize:9}}/></Button>
                    </div>
                  ) : (
                    <AccountSearchInput
                      onSelect={(ct) => { setEditCuentaId(ct.id); setEditCuentaNombre(ct.nombre); }}
                      tipoFiltro="PROVEEDOR"
                      placeholder="Buscar proveedor…"
                      take={0}
                    />
                  )}
                </div>
                {/* Botones */}
                <div className="row" style={{gap:8,marginTop:4}}>
                  <Button variant="ghost" size="sm" onClick={() => setEditandoResumen(false)} disabled={saving}>Cancelar</Button>
                  <Button variant="accent" size="sm" onClick={handleSaveResumen} disabled={saving || !editCuentaId || !editFecha} style={{flex:1}}>
                    {saving ? <Icon name="fa-spinner fa-spin"/> : "Guardar"}
                  </Button>
                </div>
              </div>
            ) : (
              <div className="stack" style={{"--gap":"10px"}}>
                {[{label:"N° Compra",value:`#${compraId}`},{label:"Tipo",value:c?.tipo??'—'},{label:"Fecha",value:c?.fecha??'—'},{label:"Proveedor",value:c?.cuenta??'—'},{label:"Pago",value: estado === 'VALIDO' ? (c?.pagado??'—') : '—', pago: estado === 'VALIDO'}].map(r => (
                  <div key={r.label} className="row" style={{justifyContent:"space-between",fontSize:12}}>
                    <span style={{color:"var(--soft)"}}>{r.label}</span>
                    <span style={{fontWeight:600,color: r.pago ? (c?.pagado === 'PAGADO' ? "var(--success)" : "var(--warning)") : "var(--ink)"}}>{r.value}</span>
                  </div>
                ))}
                {c?.saldo > 0 && (
                  <div className="row" style={{justifyContent:"space-between",fontSize:12}}>
                    <span style={{color:"var(--soft)"}}>Saldo</span>
                    <span style={{fontWeight:700,color:"var(--danger)"}}>Bs {parseFloat(c.saldo).toFixed(2)}</span>
                  </div>
                )}
              </div>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
