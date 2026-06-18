import React, { useState, useEffect, useRef } from 'react';
import { Icon, Button, Badge, Card, Empty, PageHead, ProductSearchInput, AccountSearchInput, QtyStepper } from '../../lib/components.jsx';
import { ventas as ventasApi } from '../../services/api.js';

/**
 * Pantalla de creación de venta estilo POS: buscador de productos, tabla de ítems,
 * control de descuentos y flujo validar → cobrar.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {function(number): void} props.onComplete - Se llama con el ID de la venta creada.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function VentaNueva({ onNav, onComplete, sucursalId, initialId, initialData }) {
  const today = new Date().toISOString().split('T')[0];
  const [ventaId, setVentaId]         = useState(initialId || null);
  const [detalles, setDetalles]       = useState([]);
  const [cliente, setCliente]         = useState(initialData?.cuenta_id ? { id: initialData.cuenta_id, nombre: initialData.cuenta, nit: initialData.nit } : null);
  const [tipo, setTipo]               = useState(initialData?.tipo || 'CONTADO');
  const [fecha, setFecha]             = useState(initialData?.fecha_raw || today);
  const [saving, setSaving]           = useState(false);
  const [creando, setCreando]         = useState(false);
  const [showCliente, setShowCliente] = useState(false);
  const [clienteSearchKey, setClienteSearchKey] = useState(0);
  const [negativos, setNegativos]     = useState([]);
  const [showPago, setShowPago]       = useState(false);
  const [montoPago, setMontoPago]     = useState('');
  const [error, setError]             = useState(null);
  const [highlightCliente, setHighlightCliente] = useState(false);
  const clienteCardRef = useRef(null);


  async function reloadDetalles(vid) {
    const r = await ventasApi.detalles(vid);
    setDetalles(r.data ?? []);
  }

  useEffect(() => {
    if (initialId) reloadDetalles(initialId);
  }, [initialId]);

  async function handleUpdateEnc(newC, newT, newF) {
    if (ventaId && newC) {
      try {
        await ventasApi.updateEncabezado({ venta_id: ventaId, cuenta_id: newC.id, tipo: newT, fecha: newF });
      } catch (e) {
        setError('Error al actualizar encabezado');
      }
    }
  }

  function doSetCliente(c) { setCliente(c); handleUpdateEnc(c, tipo, fecha); }
  function doSetTipo(t)    { setTipo(t); handleUpdateEnc(cliente, t, fecha); }
  function doSetFecha(f)   { setFecha(f); handleUpdateEnc(cliente, tipo, f); }

  async function ensureVenta() {
    if (ventaId) return ventaId;
    setCreando(true);
    try {
      const r = await ventasApi.store({ fecha, cuenta_id: cliente.id, tipo, sucursal_id: sucursalId });
      setVentaId(r.data.id);
      return r.data.id;
    } finally { setCreando(false); }
  }

  /**
   * Agrega un producto a la venta (creándola si aún no existe).
   * Limpia la alerta de stock insuficiente porque los ítems cambiaron.
   * @param {object} p - Producto seleccionado en ProductSearchInput.
   */
  async function addItem(p) {
    if (!cliente) { setError('Selecciona un cliente primero'); return; }
    setError(null); setNegativos([]);
    // Si el producto YA está en la venta, preguntar: sumar a la línea o crear una línea
    // nueva para venderlo a OTRO precio (Opción 1 aprobada por QA). Por defecto se suma.
    let nuevaLinea = false;
    if (detalles.some(d => d.producto_id === p.id)) {
      nuevaLinea = window.confirm(
        'Este producto ya está en la venta.\n\n' +
        'Aceptar = agregarlo como LÍNEA NUEVA (para venderlo a otro precio)\n' +
        'Cancelar = sumar la cantidad a la línea existente'
      );
    }
    setSaving(true);
    try {
      const vid = await ensureVenta();
      await ventasApi.agregarItem({ venta_id: vid, producto_id: p.id, cantidad: 1, nueva_linea: nuevaLinea });
      await reloadDetalles(vid);
    } catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al agregar producto'); }
    finally { setSaving(false); }
  }

  /**
   * Cambia la cantidad de un ítem. Limpia la alerta de stock (los ítems cambiaron).
   * @param {object} item - Detalle de venta.
   * @param {number} newCant - Nueva cantidad (mínimo 1).
   */
  async function updateCant(item, newCant) {
    if (newCant < 1 || !ventaId) return;
    setNegativos([]);
    setSaving(true);
    try {
      await ventasApi.updateItem({ registro: item.id, costo: parseFloat(item.costo), cantidad: newCant });
      await reloadDetalles(ventaId);
    } finally { setSaving(false); }
  }

  /**
   * Cambia el PRECIO unitario de un ítem (precio de venta, editable hasta la v2 — no hay
   * modalidad de descuentos todavía). Reusa updateItem con la cantidad actual. Ignora
   * valores no numéricos o negativos (el backend igual valida min:0).
   * @param {object} item - Detalle de venta.
   * @param {number|string} nuevoPrecio - Nuevo precio unitario.
   */
  async function updatePrecio(item, nuevoPrecio) {
    const precio = parseFloat(nuevoPrecio);
    if (!ventaId || isNaN(precio) || precio < 0) return;
    if (precio === parseFloat(item.costo)) return; // sin cambios → no llamamos a la API
    setNegativos([]);
    setSaving(true);
    try {
      await ventasApi.updateItem({ registro: item.id, costo: precio, cantidad: item.cantidad });
      await reloadDetalles(ventaId);
    } finally { setSaving(false); }
  }

  /**
   * Elimina un ítem de la venta. Limpia la alerta de stock (los ítems cambiaron).
   * @param {object} item - Detalle de venta.
   */
  async function removeItem(item) {
    if (!ventaId) return;
    setNegativos([]);
    setSaving(true);
    try {
      await ventasApi.deleteItem(item.id);
      await reloadDetalles(ventaId);
    } finally { setSaving(false); }
  }

  /**
   * Valida la venta tras chequear stock. Para CONTADO completa directo
   * (queda PAGADA y su ingreso ya entró a caja al validar); para CREDITO
   * abre el panel de cobro inicial opcional.
   */
  async function handleValidar() {
    if (!ventaId || detalles.length === 0) return;
    setError(null); setSaving(true);
    try {
      const negRes = await ventasApi.negativos({ venta_id: ventaId });
      if (negRes.data.negativo) { setNegativos(negRes.data.items); setSaving(false); return; }
      await ventasApi.validar(ventaId);
      if (tipo === 'CONTADO') {
        onComplete(ventaId);
      } else {
        setShowPago(true);
      }
    } catch (e) { setError(e?.response?.data?.error ?? 'Error al validar'); }
    finally { setSaving(false); }
  }

  /** Registra el cobro inicial de una venta a crédito y navega al detalle. */
  async function handleCobrar() {
    if (!ventaId) return;
    setError(null); setSaving(true);
    try {
      await ventasApi.cobrar({ venta_id: ventaId, monto: parseFloat(montoPago) });
      onComplete(ventaId);
    } catch (e) { setError(e?.response?.data?.error ?? e?.response?.data?.message ?? 'Error al registrar el cobro'); }
    finally { setSaving(false); }
  }

  /** Abre el buscador de cliente, forzando una key nueva para garantizar re-montaje limpio. */
  function openClienteSearch() {
    setShowCliente(true);
    setClienteSearchKey(k => k + 1);
  }

  /** Resalta el card de cliente cuando el usuario intenta agregar un producto sin haberlo seleccionado. */
  function handleProductClickWithoutCliente() {
    setHighlightCliente(true);
    openClienteSearch();
    clienteCardRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => setHighlightCliente(false), 2500);
  }

  const total = detalles.reduce((s, d) => s + parseFloat(d.costo) * d.cantidad, 0);

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title={ventaId ? `Nueva venta #${ventaId}` : "Nueva venta"}
        sub="Punto de venta — selecciona cliente, agrega productos y cobra"
        actions={
          <>
            <Button variant="ghost" icon="fa-arrow-left" size="sm" onClick={() => onNav("ventas")}>Volver</Button>
          </>
        }
      />

      {error && (
        <div style={{padding:"10px 14px", background:"var(--danger-soft)", border:"1px solid rgba(220,38,38,.25)", borderRadius:"var(--r-md)", fontSize:13, color:"var(--danger)", display:"flex", gap:8, alignItems:"center"}}>
          <Icon name="fa-circle-exclamation" style={{fontSize:12, flexShrink:0}}/><span>{error}</span>
        </div>
      )}

      {negativos.length > 0 && (
        <div style={{padding:"14px 18px", background:"var(--warning-soft)", border:"1px solid rgba(245,158,11,.35)", borderRadius:"var(--r-md)"}}>
          <div style={{fontSize:13, fontWeight:700, color:"var(--warning)", marginBottom:8}}>
            <Icon name="fa-triangle-exclamation" style={{marginRight:6}}/>Stock insuficiente en {negativos.length} producto(s)
          </div>
          {negativos.map(n => (
            <div key={n.id} style={{fontSize:12, color:"var(--body)", marginBottom:4}}>
              {n.codigo} — disponible: {n.stock} · pedido: {n.pedido}
            </div>
          ))}
          <Button variant="ghost" size="sm" style={{marginTop:8}} onClick={()=>setNegativos([])}>Entendido, ajustar cantidades</Button>
        </div>
      )}

      {showPago && (
        <div style={{padding:"20px", background:"var(--surface)", border:"2px solid var(--accent)", borderRadius:"var(--r-md)"}}>
          <div style={{fontSize:15, fontWeight:700, color:"var(--ink)", marginBottom:12}}>
            <Icon name="fa-money-bill-wave" style={{marginRight:8, color:"var(--success)"}}/>Venta a crédito validada — Cobro inicial (opcional)
          </div>
          <div style={{fontSize:13, color:"var(--soft)", marginBottom:12}}>Total: <strong>Bs {total.toFixed(2)}</strong></div>
          <div className="row" style={{gap:10}}>
            <input className="input mono" type="number" value={montoPago} onChange={e=>setMontoPago(e.target.value)}
              placeholder={total.toFixed(2)} style={{flex:1, fontSize:18, textAlign:"right", fontWeight:700}}/>
            <Button variant="accent" size="lg" disabled={saving || !montoPago} onClick={handleCobrar}>
              {saving ? <Icon name="fa-spinner fa-spin"/> : "Confirmar cobro"}
            </Button>
            <Button variant="ghost" size="sm" onClick={() => onComplete(ventaId)}>Omitir — todo a crédito</Button>
          </div>
        </div>
      )}

      <div className="pos-grid">
        <div className="stack" style={{"--gap":"16px"}}>
          {/* Buscador ANCLADO (sticky): se queda fijo arriba mientras se hace scroll de los
              ítems, para no tener que volver a subir cada vez que se agrega otro producto
              (pedido de QA). top≈86px = debajo del topbar (54) + crumb-bar (~32). */}
          <div style={{position:"sticky", top:86, zIndex:10, background:"var(--page)"}}>
          <Card pad={false}>
            <div style={{padding:16, position:"relative"}}
              onClick={() => { if (!cliente && !showPago) handleProductClickWithoutCliente(); }}>
              {!cliente && !showPago && (
                <div style={{position:"absolute", inset:0, zIndex:1, cursor:"pointer", borderRadius:"var(--r-md)"}}/>
              )}
              <ProductSearchInput
                onSelect={addItem}
                placeholder={cliente ? "Escanea código o busca producto…" : "① Primero selecciona un cliente →"}
                disabled={!cliente || saving || creando || showPago}
                bothPrices={true}
                showStock={true}
              />
            </div>
          </Card>
          </div>

          <Card pad={false}>
            <div className="row" style={{padding:"12px 16px", borderBottom:"1px solid var(--line)", justifyContent:"space-between"}}>
              <div className="row" style={{gap:12}}>
                <span style={{fontSize:13, fontWeight:700, color:"var(--ink)"}}>Productos</span>
                <Badge tone="neutral">{detalles.length} {detalles.length === 1 ? "ítem" : "ítems"}</Badge>
                {(saving || creando) && <Icon name="fa-spinner fa-spin" style={{fontSize:12, color:"var(--soft)"}}/>}
              </div>
            </div>
            {detalles.length === 0
              ? <Empty text="Aún no agregaste productos" icon="fa-barcode"/>
              : (
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th className="center" style={{width:150}}>Cantidad</th>
                    <th className="right" style={{width:170}}>Precio (editable)</th>
                    <th className="right" style={{width:130}}>Subtotal</th>
                    <th style={{width:40}}></th>
                  </tr>
                </thead>
                <tbody>
                  {detalles.map(it => (
                    <tr key={it.id}>
                      <td>
                        <div style={{fontSize:13, fontWeight:600, color:"var(--ink)"}}>{it.descripcion}</div>
                        <div className="row" style={{gap:6, marginTop:2}}>
                          <span className="mono" style={{fontSize:10.5, color:"var(--soft)"}}>#{it.producto_id ?? it.id} · {it.codigo}</span>
                          <span style={{fontSize:10.5, color:"var(--accent)", fontWeight:600}}>{it.marca}</span>
                        </div>
                      </td>
                      <td className="center">
                        <QtyStepper value={it.cantidad} onChange={(n) => updateCant(it, n)} disabled={saving}/>
                      </td>
                      <td className="right">
                        {/* Precio EDITABLE (pedido de QA: el precio de venta no debe ser
                            estático hasta la v2). Botones rápidos S/F (precio sin factura =
                            p_norm) y C/F (con factura = p_fact); además se puede tipear a mano.
                            key fuerza re-montaje del input cuando cambia el costo (botón S/F·C/F). */}
                        <div style={{display:"flex", flexDirection:"column", alignItems:"flex-end", gap:4}}>
                          <div className="input-group" style={{width:120}}>
                            <span className="lead-icon" style={{fontSize:10, color:"var(--soft)"}}>Bs</span>
                            <input
                              key={`p-${it.id}-${it.costo}`}
                              className="input mono tabular"
                              type="number" min="0" step="0.01"
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
                      </td>
                      <td className="right mono tabular strong" style={{fontSize:13, fontWeight:700}}>Bs {(parseFloat(it.costo)*it.cantidad).toFixed(2)}</td>
                      <td><button className="icon-btn danger" title="Eliminar ítem" disabled={saving} onClick={() => removeItem(it)}><Icon name="fa-trash" style={{fontSize:11}}/></button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>
        </div>

        <div className="stack" style={{"--gap":"16px"}}>
          <div ref={clienteCardRef} style={{
            borderRadius:"calc(var(--r-md) + 2px)",
            outline: highlightCliente ? "2px solid var(--accent)" : "2px solid transparent",
            outlineOffset:2,
            transition:"outline-color .2s",
          }}>
          <Card title="Cliente" head={
            <div className="row" style={{gap:6}}>
              {cliente && <button className="btn btn-ghost btn-sm danger" onClick={() => setCliente(null)}><Icon name="fa-times" style={{fontSize:10}}/>Quitar</button>}
              <button className="btn btn-ghost btn-sm" onClick={()=>{ if (showCliente) { setShowCliente(false); } else { openClienteSearch(); } }}>
                <Icon name="fa-search" style={{fontSize:10}}/>{cliente ? "Cambiar" : "Buscar"}
              </button>
            </div>
          }>
            {cliente ? (
              <div>
                <div className="row" style={{gap:12}}>
                  <div className="avatar lg" style={{background:"linear-gradient(135deg, var(--star), var(--navy))", color:"#fff", fontSize:15, fontWeight:700}}>
                    {cliente.nombre.substring(0,2).toUpperCase()}
                  </div>
                  <div className="grow">
                    <div style={{fontSize:14, fontWeight:700, color:"var(--ink)"}}>{cliente.nombre}</div>
                    {cliente.nit && <div className="mono" style={{fontSize:11, color:"var(--soft)", marginTop:2}}>NIT {cliente.nit}</div>}
                  </div>
                </div>
              </div>
            ) : (
              <div style={{textAlign:"center", padding:"12px 0 4px"}}>
                <div style={{width:40, height:40, borderRadius:20, background:"var(--accent-a15)", display:"inline-flex", alignItems:"center", justifyContent:"center", marginBottom:10}}>
                  <Icon name="fa-user" style={{fontSize:16, color:"var(--accent)"}}/>
                </div>
                <div style={{fontSize:13, fontWeight:700, color:"var(--ink)", marginBottom:4}}>Selecciona un cliente</div>
                <div style={{fontSize:11, color:"var(--soft)", marginBottom:14, lineHeight:1.5}}>
                  Primero elige el cliente antes<br/>de agregar productos
                </div>
                <Button variant="accent" size="sm" icon="fa-search" onClick={openClienteSearch}>
                  Buscar cliente
                </Button>
              </div>
            )}
            {showCliente && (
              <div key={clienteSearchKey} style={{marginTop:12, paddingTop:12, borderTop:"1px solid var(--line)"}}>
                <AccountSearchInput
                  onSelect={doSetCliente}
                  placeholder="Buscar cliente…"
                  showSinNombre={true}
                  take={5}
                  autoFocus={true}
                />
              </div>
            )}
          </Card>
          </div>

          <Card title="Forma de pago">
            <div className="stack" style={{"--gap":"12px"}}>
              <div className="row" style={{gap:8}}>
                {[["CONTADO","fa-money-bill"],["CREDITO","fa-clock"]].map(([t, ico]) => (
                  <button key={t} onClick={()=>doSetTipo(t)}
                    style={{flex:1, padding:"10px", borderRadius:"var(--r-md)", border: tipo===t ? "2px solid var(--accent)" : "2px solid var(--line)",
                      background: tipo===t ? "var(--accent-soft)" : "var(--surface)", color: tipo===t ? "var(--accent)" : "var(--body)", fontSize:12, fontWeight:700}}>
                    <Icon name={ico} style={{marginRight:6, fontSize:11}}/>{t}
                  </button>
                ))}
              </div>
              <div className="field">
                <label className="label">Fecha</label>
                <input className="input" type="date" value={fecha} onChange={e=>doSetFecha(e.target.value)}/>
              </div>
            </div>
          </Card>

          <Card pad={false}>
            <div style={{padding:20}}>
              <div className="row" style={{justifyContent:"space-between", marginBottom:8}}>
                <span style={{fontSize:12, color:"var(--soft)"}}>Subtotal ({detalles.length} ítems)</span>
                <span className="mono tabular" style={{fontSize:13}}>Bs {total.toFixed(2)}</span>
              </div>
              <div style={{height:1, background:"var(--line)", margin:"14px 0"}}></div>
              <div className="row" style={{justifyContent:"space-between", alignItems:"center"}}>
                <span style={{fontSize:13, fontWeight:700, color:"var(--ink)", textTransform:"uppercase", letterSpacing:".02em"}}>Total</span>
                <span className="display tabular" style={{fontSize:28, fontWeight:700, color:"var(--ink)"}}>Bs {total.toFixed(2)}</span>
              </div>
            </div>
            {!showPago && (
              <div style={{padding:12, borderTop:"1px solid var(--line)", background:"var(--alt)", display:"flex", gap:8, flexDirection:"column"}}>
                {detalles.length > 0 && !cliente && (
                  <div style={{padding:"8px 12px", background:"var(--warning-soft)", border:"1px solid rgba(245,158,11,.35)", borderRadius:"var(--r-md)", fontSize:12, color:"var(--warning)", display:"flex", gap:6, alignItems:"center"}}>
                    <Icon name="fa-triangle-exclamation" style={{fontSize:11}}/>Selecciona un cliente para continuar
                  </div>
                )}
                <Button variant="accent" size="lg" icon="fa-file-invoice"
                  disabled={detalles.length === 0 || saving || !cliente || showPago}
                  onClick={() => onComplete(ventaId)}>
                  Dejar como proforma
                </Button>
                <Button variant="secondary" size="md" icon="fa-check"
                  disabled={detalles.length === 0 || saving || !cliente || showPago}
                  onClick={handleValidar}>
                  {saving ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Procesando…</> : `Validar y cobrar (Bs ${total.toFixed(2)})`}
                </Button>
              </div>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
