import React, { useState, useEffect } from 'react';
import logger from '../../lib/logger.js';
import { Icon, Button, StatusBadge, Card, Empty, PageHead, PdfButton } from '../../lib/components.jsx';
import { ventas as ventasApi, openPdf } from '../../services/api.js';

/**
 * Detalle de venta: muestra encabezado, ítems, cobros, devoluciones y PDF.
 * Carga los datos desde la API si no se recibe `ventaData` precargada.
 * @param {object} props
 * @param {number} props.ventaId - ID de la venta.
 * @param {object} [props.ventaData] - Datos precargados desde el listado (evita un fetch extra).
 * @param {function(string|object): void} props.onNav - Navegación.
 * @returns {JSX.Element}
 */
export function VentaDetail({ ventaId, ventaData, onNav }) {
  const [detalles, setDetalles]     = useState([]);
  const [cobros, setCobros]         = useState([]);
  const [devoluciones, setDevs]     = useState([]);
  const [loading, setLoading]       = useState(true);
  const [saving, setSaving]         = useState(false);
  const [montoCobro, setMontoCobro] = useState('');
  const [showCobrar, setShowCobrar] = useState(false);
  const [v, setV]                   = useState(ventaData ?? null);
  const [showDev, setShowDev]       = useState(false);
  const [devProdId, setDevProdId]   = useState('');
  const [devCant, setDevCant]       = useState(1);
  const [devSearch, setDevSearch]   = useState('');
  const [error, setError]           = useState(null);

  useEffect(() => {
    setLoading(true);
    const calls = [ventasApi.detalles(ventaId), ventasApi.cobros(ventaId), ventasApi.devoluciones(ventaId)];
    if (!ventaData) calls.push(ventasApi.list({ search: String(ventaId), take: 5 }));
    Promise.all(calls)
      .then(([dRes, cRes, devRes, listRes]) => {
        setDetalles(dRes.data ?? []);
        setCobros(cRes.data ?? []);
        setDevs(devRes.data ?? []);
        if (listRes) {
          const found = (listRes.data.data ?? []).find(x => x.id == ventaId);
          if (found) setV(found);
        }
      })
      .catch(logger.error)
      .finally(() => setLoading(false));
  }, [ventaId]);

  const totalNum   = v ? (v.total_num ?? parseFloat(String(v.total).replace(/[^0-9.]/g, ''))) : 0;
  // monto_num viene del backend como float; el string "Bs. 1,234.56" no es parseable con parseFloat
  const cobradoNum = cobros.reduce((s, c) => s + (c.monto_num ?? 0), 0);
  // El saldo de la API es la fuente autoritativa: cubre CONTADO (saldo 0 = pagada)
  // y devoluciones aplicadas a cuenta. Fallback: total menos cobros registrados.
  const saldoNum   = v?.saldo != null ? Math.max(0, parseFloat(v.saldo)) : Math.max(0, totalNum - cobradoNum);
  const pagadoNum  = Math.max(0, totalNum - saldoNum);
  const pctPagado  = totalNum > 0 ? Math.min(100, (pagadoNum / totalNum) * 100) : 0;

  /** Re-lee el encabezado de la venta (saldo/pagado/estado) desde el listado. */
  async function refreshV() {
    try {
      const listRes = await ventasApi.list({ search: String(ventaId), take: 5 });
      const found = (listRes.data.data ?? []).find(x => x.id == ventaId);
      if (found) setV(found);
    } catch (e) { logger.error(e); }
  }

  /** Registra un cobro parcial o total de la venta a crédito y recarga el historial. */
  async function handleCobrar() {
    setError(null); setSaving(true);
    try {
      const monto = parseFloat(montoCobro);
      await ventasApi.cobrar({ venta_id: ventaId, monto });
      const cRes = await ventasApi.cobros(ventaId);
      setCobros(cRes.data ?? []);
      setMontoCobro(''); setShowCobrar(false);
      await refreshV(); // saldo/pagado autoritativos del server (sin matemática optimista)
    } catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al registrar el cobro'); }
    finally { setSaving(false); }
  }

  /** Anula la venta (restituye stock y apaga sus tranzas) y vuelve al listado. */
  async function handleAnular() {
    if (!window.confirm('¿Anular esta venta? El stock será restituido.')) return;
    setError(null); setSaving(true);
    try {
      await ventasApi.destroy(ventaId);
      onNav('ventas');
    } catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al anular la venta'); }
    finally { setSaving(false); }
  }

  /** Registra la devolución del producto seleccionado y recarga la lista. */
  async function handleDev() {
    if (!devProdId) return;
    setError(null); setSaving(true);
    try {
      await ventasApi.devItem({ venta_id: ventaId, producto_id: parseInt(devProdId), cantidad: parseInt(devCant) });
      const devRes = await ventasApi.devoluciones(ventaId);
      setDevs(devRes.data ?? []);
      await refreshV(); // la devolución cambia saldo/pagado en ventas a crédito
      setDevProdId(''); setDevCant(1); setDevSearch(''); setShowDev(false);
    } catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al registrar la devolución'); }
    finally { setSaving(false); }
  }

  const estado = v?.estado ?? '—';

  // Productos únicos de la venta: sumamos cantidades por producto para no mostrar
  // opciones repetidas ni subestimar el máximo si hubiera renglones duplicados (legacy).
  const devProductos = React.useMemo(() => {
    const m = new Map();
    for (const d of detalles) {
      const pid = String(d.producto_id ?? d.id);
      const prev = m.get(pid);
      if (prev) prev.cantidad = Number(prev.cantidad) + Number(d.cantidad || 0);
      else m.set(pid, { ...d, cantidad: Number(d.cantidad || 0) });
    }
    return Array.from(m.values());
  }, [detalles]);

  // Productos de la venta filtrados para la devolución. Se busca por código, descripción,
  // marca Y por ID (#635 o 635) — antes solo matcheaba código/descripción, y el ID no
  // servía (pedido de QA). El '#' inicial se ignora para que "#635" y "635" funcionen igual.
  const devFiltrados = React.useMemo(() => {
    const ql = devSearch.trim().toLowerCase().replace(/^#/, '');
    if (!ql) return devProductos;
    return devProductos.filter(d =>
      (d.codigo || '').toLowerCase().includes(ql) ||
      (d.descripcion || '').toLowerCase().includes(ql) ||
      (d.marca || '').toLowerCase().includes(ql) ||
      String(d.producto_id ?? d.id) === ql
    );
  }, [devProductos, devSearch]);

  // Datos del ítem seleccionado para devolver: costo unitario y cuánto queda por devolver
  // (cantidad vendida menos lo ya devuelto). Surface el límite antes de que el backend lo rechace.
  const devSel      = devProductos.find(d => String(d.producto_id ?? d.id) === String(devProdId));
  const devReturned = devoluciones.filter(d => String(d.producto_id) === String(devProdId)).reduce((s, d) => s + Number(d.cantidad || 0), 0);
  const devMax      = devSel ? Number(devSel.cantidad) - devReturned : 0;
  const devUnit     = devSel ? parseFloat(String(devSel.costo).replace(/[^0-9.]/g, '')) || 0 : 0;
  const devCantNum  = Number(devCant) || 0;
  const devInvalido = !devProdId || devCantNum < 1 || devCantNum > devMax;

  if (loading) return (
    <div style={{display:'grid', placeItems:'center', height:300}}>
      <Icon name="fa-spinner fa-spin" style={{fontSize:24, color:'var(--soft)'}}/>
    </div>
  );

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title={`Venta #${ventaId}`}
        sub={`${v?.cuenta ?? '—'} · ${v?.fecha ?? '—'} · ${v?.tipo ?? '—'}`}
        actions={
          <>
            <Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={() => onNav("ventas")}>Volver</Button>
            {estado === 'PROFORMA' && (
              <Button variant="accent" icon="fa-pen" size="sm" onClick={() => onNav({ name: 'venta-nueva', id: ventaId, vData: v })}>Editar Proforma</Button>
            )}
            <PdfButton onPdf={() => openPdf(`/ventas/${ventaId}/pdf`)} />
            {estado === 'VALIDO' && <Button variant="ghost" icon="fa-ban" size="sm" style={{color:"var(--danger)"}} disabled={saving} onClick={handleAnular}>Anular</Button>}
          </>
        }
      />

      {error && (
        <div style={{padding:"10px 14px", background:"var(--danger-soft)", border:"1px solid rgba(220,38,38,.25)", borderRadius:"var(--r-md)", fontSize:13, color:"var(--danger)", display:"flex", gap:8, alignItems:"center"}}>
          <Icon name="fa-circle-exclamation" style={{fontSize:12, flexShrink:0}}/><span>{error}</span>
        </div>
      )}

      <div className="grid-12">
        <div className="stack" style={{"--gap":"16px"}}>
          <Card pad={false}>
            <div style={{padding:28}}>
              <div className="row" style={{justifyContent:"space-between", alignItems:"flex-start", marginBottom:28}}>
                <div>
                  <div className="row" style={{gap:10}}>
                    <img src="/logo.png" alt="LCV" style={{width:44, height:44, objectFit:"contain"}}/>
                    <div>
                      <div style={{fontFamily:"var(--f-display)", fontSize:17, fontWeight:700, color:"var(--ink)"}}>LA CASA VOLVO</div>
                      <div style={{fontSize:10, color:"var(--soft)", letterSpacing:".08em", textTransform:"uppercase"}}>Repuestos para camiones</div>
                    </div>
                  </div>
                  <div style={{fontSize:12, color:"var(--soft)", marginTop:16, lineHeight:1.6}}>
                    NIT 1234568791 · Casa Matriz<br/>Bolivia
                  </div>
                </div>
                <div style={{textAlign:"right"}}>
                  <div style={{fontSize:11, fontWeight:700, color:"var(--soft)", letterSpacing:".06em", textTransform:"uppercase"}}>Venta</div>
                  <div className="mono" style={{fontFamily:"var(--f-display)", fontSize:32, fontWeight:700, color:"var(--ink)", lineHeight:1, marginTop:4}}>
                    #{ventaId}
                  </div>
                  <div style={{marginTop:12}}><StatusBadge value={estado}/></div>
                </div>
              </div>

              <div className="grid-3" style={{marginBottom:28, gap:12}}>
                <div>
                  <div style={{fontSize:10, fontWeight:700, color:"var(--soft)", letterSpacing:".06em", textTransform:"uppercase"}}>Cliente</div>
                  <div style={{fontSize:13, fontWeight:600, color:"var(--ink)", marginTop:4}}>{v?.cuenta ?? '—'}</div>
                </div>
                <div>
                  <div style={{fontSize:10, fontWeight:700, color:"var(--soft)", letterSpacing:".06em", textTransform:"uppercase"}}>Fecha</div>
                  <div style={{fontSize:13, fontWeight:600, color:"var(--ink)", marginTop:4}}>{v?.fecha ?? '—'}</div>
                  <div style={{fontSize:11, color:"var(--soft)", marginTop:2}}>{v?.tipo ?? '—'}</div>
                </div>
                <div>
                  <div style={{fontSize:10, fontWeight:700, color:"var(--soft)", letterSpacing:".06em", textTransform:"uppercase"}}>Estado pago</div>
                  <div style={{fontSize:13, fontWeight:600, color: estado !== 'VALIDO' ? "var(--soft)" : v?.pagado === 'PAGADO' ? "var(--success)" : "var(--warning)", marginTop:4}}>
                    {/* Una proforma aún no cobró nada (el ingreso entra al validar). */}
                    {estado === 'VALIDO' ? (v?.pagado ?? '—') : '—'}
                  </div>
                </div>
              </div>

              {/* Código y Marca en COLUMNAS propias (pedido de QA: antes iban amontonados
                  bajo la descripción). Los códigos OE.xxxx siguen dentro de la descripción
                  porque en la BD son texto libre del campo `descripcion`, no columnas. */}
              <table className="tbl minimal" style={{marginBottom:20}}>
                <thead>
                  <tr>
                    <th style={{width:30}}>#</th>
                    <th style={{width:140}}>Código</th>
                    <th>Descripción</th>
                    <th style={{width:110}}>Marca</th>
                    <th className="center" style={{width:64}}>Cant.</th>
                    <th className="right" style={{width:100}}>P. Unit.</th>
                    <th className="right" style={{width:120}}>Subtotal</th>
                  </tr>
                </thead>
                <tbody>
                  {detalles.length === 0 && (
                    <tr><td colSpan="7"><Empty text="Sin ítems" icon="fa-cubes"/></td></tr>
                  )}
                  {detalles.map((it, i) => (
                    <tr key={it.id}>
                      <td className="mono" style={{color:"var(--soft)"}}>{String(i+1).padStart(2,"0")}</td>
                      <td className="mono" style={{fontSize:11, whiteSpace:"nowrap"}}>
                        <span style={{fontWeight:700, color:"var(--accent)", display:"block"}}>{it.codigo || '—'}</span>
                        <span style={{fontSize:10, color:"var(--soft)"}}>#{it.producto_id ?? it.id}</span>
                      </td>
                      <td><div style={{fontWeight:600, color:"var(--ink)"}}>{it.descripcion}</div></td>
                      <td style={{fontSize:11, color:"var(--soft)"}}>{it.marca || '—'}</td>
                      <td className="center mono tabular">{it.cantidad}</td>
                      <td className="right mono tabular">Bs {parseFloat(it.costo).toFixed(2)}</td>
                      <td className="right mono tabular strong">{it.subtotal}</td>
                    </tr>
                  ))}
                </tbody>
              </table>

              <div className="row" style={{justifyContent:"flex-end", borderTop:"1px solid var(--line)", paddingTop:20}}>
                <div style={{width:300}}>
                  <div className="row" style={{justifyContent:"space-between", alignItems:"baseline"}}>
                    <span style={{fontSize:13, fontWeight:700, color:"var(--ink)", textTransform:"uppercase"}}>Total</span>
                    <span className="display tabular" style={{fontSize:28, fontWeight:700, color:"var(--ink)"}}>
                      {v?.total ?? `Bs ${totalNum.toFixed(2)}`}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </Card>

          {cobros.length > 0 && (
            <Card title="Historial de cobros" pad={false}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th style={{width:110}}>Fecha</th>
                    <th>Descripción</th>
                    <th className="right" style={{width:140}}>Monto</th>
                  </tr>
                </thead>
                <tbody>
                  {cobros.map(c => (
                    <tr key={c.id}>
                      <td className="mono" style={{fontSize:11, color:"var(--soft)"}}>{c.fecha}</td>
                      <td style={{fontSize:12, color:"var(--body)"}}>{c.descripcion}</td>
                      <td className="right mono tabular strong" style={{color:"var(--success)"}}>{c.monto}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          )}

        </div>

        <div className="stack" style={{"--gap":"16px"}}>
          {(estado === 'VALIDO' || devoluciones.length > 0) && (
            <Card title="Devoluciones" pad={false}>
              {devoluciones.length > 0 && (
                <table className="tbl">
                  <thead>
                    <tr>
                      <th style={{width:90}}>Fecha</th>
                      <th>Producto</th>
                      <th className="center" style={{width:55}}>Cant.</th>
                      <th className="right" style={{width:110}}>Monto</th>
                    </tr>
                  </thead>
                  <tbody>
                    {devoluciones.map(d => (
                      <tr key={d.id}>
                        <td className="mono" style={{fontSize:11, color:"var(--soft)"}}>{d.fecha}</td>
                        <td>
                          <span className="mono" style={{fontSize:11, fontWeight:700, color:"var(--accent)", display:"block"}}>#{d.producto_id ?? d.id} {d.codigo}</span>
                          <span style={{fontSize:11, color:"var(--body)"}}>{d.descripcion}</span>
                        </td>
                        <td className="center mono tabular">{d.cantidad}</td>
                        <td className="right mono tabular strong" style={{color:"var(--danger)"}}>{d.total ?? d.subtotal ?? d.monto}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              {estado === 'VALIDO' && (
                showDev ? (
                  <div style={{padding:14, borderTop: devoluciones.length > 0 ? '1px solid var(--line)' : undefined}}>
                    {/* Buscador SIEMPRE visible (antes solo aparecía con >4 ítems) + lista en
                        COLUMNAS clickeable en vez del <select> (pedido de QA: era incómodo). */}
                    <div className="field" style={{marginBottom:10}}>
                      <label className="label" style={{fontSize:10}}>Buscar producto a devolver — código, ID o descripción</label>
                      <input className="input" placeholder="Ej: 71-3921, #635 o COJINETE…" value={devSearch}
                        autoFocus onChange={e=>setDevSearch(e.target.value)} style={{fontSize:12}}/>
                    </div>
                    <div style={{maxHeight:210, overflowY:'auto', border:'1px solid var(--line)', borderRadius:'var(--r-md)'}}>
                      {devFiltrados.length === 0 ? (
                        <div style={{padding:14, fontSize:12, color:'var(--soft)', textAlign:'center'}}>Sin coincidencias</div>
                      ) : devFiltrados.map(d => {
                        const pid  = String(d.producto_id ?? d.id);
                        const sel  = pid === String(devProdId);
                        const ya   = devoluciones.filter(x => String(x.producto_id) === pid).reduce((s, x) => s + Number(x.cantidad || 0), 0);
                        const disp = Number(d.cantidad) - ya;
                        return (
                          <button key={pid} type="button" disabled={disp <= 0}
                            onClick={()=>{ setDevProdId(pid); setDevCant(1); }}
                            style={{display:'grid', gridTemplateColumns:'minmax(120px,auto) 1fr auto', gap:10, alignItems:'center',
                              width:'100%', textAlign:'left', padding:'8px 10px', border:'none', borderBottom:'1px solid var(--line)',
                              cursor: disp > 0 ? 'pointer' : 'not-allowed', background: sel ? 'var(--accent-soft)' : 'transparent', opacity: disp > 0 ? 1 : 0.5}}>
                            <span className="mono" style={{fontSize:11, fontWeight:700, color:'var(--accent)', whiteSpace:'nowrap'}}>#{pid} · {d.codigo || '—'}</span>
                            <span style={{fontSize:12, color:'var(--ink)', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap'}}>
                              {d.descripcion}{d.marca ? <span style={{color:'var(--soft)', marginLeft:6, fontSize:10}}>{d.marca}</span> : null}
                            </span>
                            <span className="mono" style={{fontSize:10.5, fontWeight:700, color: disp > 0 ? 'var(--ink)' : 'var(--danger)', whiteSpace:'nowrap'}}>{disp} disp.</span>
                          </button>
                        );
                      })}
                    </div>
                    <div style={{display:'flex', gap:8, flexWrap:'wrap', alignItems:'flex-end', marginTop:10}}>
                      <div className="field" style={{marginBottom:0, width:110}}>
                        <label className="label" style={{fontSize:10}}>Cantidad</label>
                        <input className="input mono" type="number" min={1} max={devMax || 1} value={devCant} disabled={!devProdId}
                          onChange={e=>setDevCant(e.target.value)} style={{textAlign:'center'}}/>
                      </div>
                      <Button variant="accent" size="sm" disabled={devInvalido||saving} onClick={handleDev}>Registrar devolución</Button>
                      <Button variant="ghost" size="sm" onClick={()=>{setShowDev(false);setDevProdId('');setDevCant(1);setDevSearch('');}}>Cancelar</Button>
                    </div>
                    {devSel && (
                      <div style={{marginTop:8, fontSize:11, color: devCantNum > devMax ? 'var(--danger)' : 'var(--soft)'}}>
                        Costo unitario <strong className="mono">Bs {devUnit.toFixed(2)}</strong> · Devolver hasta <strong className="mono">{devMax}</strong> de {devSel.cantidad}
                        {devCantNum > devMax && <span> · cantidad supera el límite</span>}
                        {devCantNum >= 1 && devCantNum <= devMax && <span> · reembolso <strong className="mono">Bs {(devUnit * devCantNum).toFixed(2)}</strong></span>}
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

          <Card title="Estado de pago">
            {estado !== 'VALIDO' ? (
              <div style={{textAlign:"center", padding:"20px 0", color:"var(--soft)"}}>
                <Icon name="fa-clock" style={{fontSize:20, marginBottom:8, display:"block"}}/>
                <div style={{fontSize:12, lineHeight:1.5}}>El estado de pago se define<br/>al validar la venta.</div>
              </div>
            ) : (
            <div style={{textAlign:"center", padding:"12px 0"}}>
              <div className="display tabular" style={{fontSize:32, fontWeight:700, color: saldoNum === 0 ? "var(--success)" : "var(--warning)"}}>
                Bs {pagadoNum.toFixed(2)}
              </div>
              <div style={{fontSize:11, color:"var(--soft)", marginTop:4}}>cobrado de Bs {totalNum.toFixed(2)}</div>
              <div style={{marginTop:12}}>
                <div className="bar">
                  <div className="fill" style={{width:`${pctPagado}%`, background: saldoNum === 0 ? "var(--success)" : "var(--warning)"}}/>
                </div>
              </div>
              <div className="row" style={{justifyContent:"space-between", marginTop:14, fontSize:12}}>
                <span style={{color:"var(--soft)"}}>Cobrado</span>
                <span className="mono tabular" style={{fontWeight:700}}>Bs {pagadoNum.toFixed(2)}</span>
              </div>
              <div className="row" style={{justifyContent:"space-between", marginTop:6, fontSize:12}}>
                <span style={{color:"var(--soft)"}}>Saldo</span>
                <span className="mono tabular" style={{fontWeight:700, color: saldoNum > 0 ? "var(--warning)" : "var(--soft)"}}>Bs {saldoNum.toFixed(2)}</span>
              </div>
            </div>
            )}

            {saldoNum > 0 && estado === 'VALIDO' && v?.tipo === 'CREDITO' && !showCobrar && (
              <Button variant="accent" icon="fa-money-bill-wave" style={{width:"100%", marginTop:12}} onClick={() => { setMontoCobro(saldoNum.toFixed(2)); setShowCobrar(true); }}>
                Registrar cobro
              </Button>
            )}

            {showCobrar && (
              <div style={{marginTop:12, paddingTop:12, borderTop:"1px solid var(--line)"}}>
                <div className="field" style={{marginBottom:8}}>
                  <label className="label">Monto a cobrar (Bs.)</label>
                  <input className="input mono" type="number" value={montoCobro}
                    onChange={e=>setMontoCobro(e.target.value)}
                    style={{fontSize:20, textAlign:"right", fontWeight:700}}/>
                </div>
                <div className="row" style={{gap:8}}>
                  <Button variant="ghost" size="sm" onClick={()=>setShowCobrar(false)}>Cancelar</Button>
                  <Button variant="accent" size="sm" disabled={saving||!montoCobro} onClick={handleCobrar} style={{flex:1}}>
                    {saving ? <Icon name="fa-spinner fa-spin"/> : "Confirmar"}
                  </Button>
                </div>
              </div>
            )}
          </Card>

          {/* "Resumen" redundante quitado: N°/Tipo/Fecha/Cliente/Estado ya están en la
              cabecera (PageHead + la factura con #venta y StatusBadge). */}
        </div>
      </div>
    </div>
  );
}
