/**
 * @fileoverview Modales de formulario de La Casa Volvo.
 * Cada modal encapsula el formulario de creación/edición de una entidad,
 * llama a la API correspondiente y notifica al padre vía `onSaved`.
 * Todos usan `FormModal` como shell genérico (overlay, header, footer con Guardar/Cancelar).
 */

import React, { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { Icon, Button, Card, useToast, AccountSearchInput, ComboSelect } from '../lib/components.jsx';
import logger from '../lib/logger.js';
import { compras as comprasApi, pedidos as pedidosApi, envios as enviosApi, cuentas as cuentasApi, cotizaciones as cotizApi, medios as mediosApi, sucursales as sucursalesApi, marcas as marcasApi, industrias as industriasApi, productos as prodApi, users as usersApi, roles as rolesApi } from '../services/api.js';

// Mensaje de error inline bajo un campo requerido
function FieldErr({ msg }) {
  if (!msg) return null;
  return (
    <span style={{fontSize:11,color:"var(--danger)",marginTop:3,display:"flex",alignItems:"center",gap:3}}>
      <i className="fa-solid fa-circle-exclamation" style={{fontSize:9,flexShrink:0}}/>
      {msg}
    </span>
  );
}

// Estilo de borde rojo cuando hay error en un campo
const errStyle = (e) => e ? {borderColor:"var(--danger)",boxShadow:"0 0 0 2px rgba(220,38,38,.1)"} : {};

// Asterisco de campo requerido
const R = () => <span style={{color:"var(--danger)",marginLeft:2}}>*</span>;

/**
 * Shell genérico de modal con overlay, header, contenido y footer (Guardar + Cancelar).
 * Cierra con Escape. Todos los modales de formulario lo usan como contenedor.
 */
export function FormModal({ title, subtitle, icon = "fa-plus", onClose, onSubmit, submitLabel = "Guardar", maxWidth = 540, children, footerExtra }) {
  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);
  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()} style={{maxWidth, display:"flex", flexDirection:"column", maxHeight:"86vh"}}>
        <div style={{padding:"14px 18px", borderBottom:"1px solid var(--line)", display:"flex", alignItems:"center", justifyContent:"space-between", flexShrink:0, background:"linear-gradient(135deg,rgba(24,38,66,.04) 0%,transparent 60%)", position:"relative", overflow:"hidden"}}>
          <div style={{position:"absolute",width:72,height:72,background:"rgba(11,126,194,.06)",borderRadius:7,transform:"rotate(45deg)",top:-26,right:-22,pointerEvents:"none"}}/>
          <div style={{position:"absolute",width:44,height:44,background:"rgba(11,126,194,.09)",borderRadius:4,transform:"rotate(45deg)",top:4,right:14,pointerEvents:"none"}}/>
          <div style={{display:"flex", alignItems:"center", gap: 10, position:"relative", zIndex:1}}>
            <span style={{width:30, height:30, borderRadius:"var(--r-sm)", background:"var(--accent-soft)", color:"var(--accent)", display:"grid", placeItems:"center"}}>
              <Icon name={icon} style={{fontSize: 12}}/>
            </span>
            <div>
              <h3 style={{fontSize: 15}}>{title}</h3>
              {subtitle && <div style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>{subtitle}</div>}
            </div>
          </div>
          <button className="icon-btn" onClick={onClose} aria-label="Cerrar"><Icon name="fa-xmark"/></button>
        </div>
        <div className="scroll-area" style={{padding: 20, flex: 1, minHeight: 0}}>
          {children}
        </div>
        <div style={{padding: 12, borderTop:"1px solid var(--line)", background:"var(--alt)", display:"flex", gap: 8, justifyContent:"space-between", flexShrink:0}}>
          <div>{footerExtra}</div>
          <div style={{display:"flex", gap:8}}>
            <Button variant="secondary" onClick={onClose}>Cancelar</Button>
            <Button variant="accent" icon="fa-check" onClick={onSubmit}>{submitLabel}</Button>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ═══════════ COMPRA ═══════════ */
export function CompraFormModal({ onClose, onSaved }) {
  const toast = useToast();
  const [proveedor, setProveedor]       = useState(null);
  const [showProv, setShowProv]         = useState(false);
  const [tipo, setTipo]                 = useState("CONTADO");
  const [saving, setSaving]             = useState(false);
  const [errors, setErrors]             = useState({});
  const fechaRef = useRef();

  const handleSubmit = async () => {
    const e = {};
    if (!proveedor?.id) e.proveedor = 'Selecciona un proveedor';
    if (Object.keys(e).length > 0) { setErrors(e); return; }
    setErrors({});
    setSaving(true);
    try {
      const res = await comprasApi.store({ fecha: fechaRef.current.value, cuenta_id: proveedor.id, tipo });
      toast('Compra creada correctamente', 'success');
      onSaved && onSaved(res.data);
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al crear la compra', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title="Nueva compra" subtitle="Registra una orden a proveedor" icon="fa-credit-card"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Creando…" : "Crear proforma"}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div>
          <Card title={<>Proveedor <R/></>} head={
            <div className="row" style={{gap:6}}>
              {proveedor && <button type="button" className="btn btn-ghost btn-sm danger" onClick={() => setProveedor(null)}><Icon name="fa-times" style={{fontSize:10}}/>Quitar</button>}
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setShowProv(!showProv)}>
                <Icon name="fa-search" style={{fontSize:10}}/>{proveedor ? "Cambiar" : "Buscar"}
              </button>
            </div>
          }>
            {proveedor ? (
              <div>
                <div style={{fontSize:14, fontWeight:700, color:"var(--ink)"}}>{proveedor.nombre}</div>
                {proveedor.nit && <div className="mono" style={{fontSize:11, color:"var(--soft)", marginTop:2}}>NIT {proveedor.nit}</div>}
              </div>
            ) : (
              <div style={{color: errors.proveedor ? "var(--danger)" : "var(--soft)", fontSize:12, textAlign:"center", padding:"8px 0"}}>Sin proveedor seleccionado</div>
            )}
            {showProv && (
              <div style={{marginTop:12, paddingTop:12, borderTop:"1px solid var(--line)"}}>
                <AccountSearchInput
                  onSelect={(c) => { setProveedor(c); setShowProv(false); setErrors(prev => ({...prev, proveedor:''})); }}
                  tipoFiltro="PROVEEDOR"
                  placeholder="Buscar proveedor…"
                  take={5}
                  autoFocus={true}
                />
              </div>
            )}
          </Card>
          <FieldErr msg={errors.proveedor}/>
        </div>
        <div className="field">
          <label className="label">Fecha</label>
          <input className="input" type="date" ref={fechaRef} defaultValue={new Date().toISOString().slice(0,10)}/>
        </div>
        <div className="field"><label className="label">Tipo</label>
          <div className="row" style={{gap: 8}}>
            {["CONTADO","CREDITO"].map(t => (
              <button key={t} type="button" onClick={()=>setTipo(t)}
                style={{flex:1, padding:"10px", borderRadius:"var(--r-md)", border: tipo === t ? "2px solid var(--accent)" : "2px solid var(--line)", background: tipo === t ? "var(--accent-soft)" : "var(--surface)", color: tipo === t ? "var(--accent)" : "var(--body)", fontSize:12, fontWeight:700}}>
                <Icon name={t === "CONTADO" ? "fa-money-bill" : "fa-clock"} style={{marginRight:6, fontSize: 11}}/>{t}
              </button>
            ))}
          </div>
        </div>
        <div style={{padding: 14, background:"var(--info-soft)", border:"1px solid rgba(3,105,161,.2)", borderRadius:"var(--r-md)", fontSize:12, color:"var(--info)"}}>
          <Icon name="fa-info-circle" style={{marginRight: 6}}/>Los productos y montos se agregan en el detalle después de crear la compra.
        </div>
      </div>
    </FormModal>
  );
}

/* ═══════════ PEDIDO ═══════════ */
export function PedidoFormModal({ onClose, onSaved }) {
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const obsRef = useRef();

  const handleSubmit = async () => {
    setSaving(true);
    try {
      const res = await pedidosApi.store({ observacion: obsRef.current.value });
      toast('Pedido creado correctamente', 'success');
      onSaved && onSaved(res.data);
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al crear el pedido', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title="Nuevo pedido" subtitle="Pedido interno de almacén" icon="fa-clipboard-list"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Creando…" : "Crear pedido"}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div className="field"><label className="label">Observación <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
          <textarea className="input" rows="4" maxLength={191} ref={obsRef} placeholder="Notas del pedido, productos requeridos, urgencia…"></textarea>
        </div>
        <div style={{padding: 14, background:"var(--info-soft)", border:"1px solid rgba(3,105,161,.2)", borderRadius:"var(--r-md)", fontSize:12, color:"var(--info)"}}>
          <Icon name="fa-info-circle" style={{marginRight: 6}}/>Los productos del pedido se agregan en el detalle después de crear el pedido.
        </div>
      </div>
    </FormModal>
  );
}

/* ═══════════ ENVÍO ═══════════ */
export function EnvioFormModal({ onClose, onSaved, sucursalId }) {
  const toast = useToast();
  const [destinos, setDestinos]     = useState([]);
  const [mediosList, setMediosList] = useState([]);
  const [saving, setSaving]         = useState(false);
  const [errors, setErrors]         = useState({});
  // Medio controlado (ComboSelect con búsqueda por tipeo) en vez de <select> nativo (pedido de QA).
  const [medioId, setMedioId]       = useState('');
  const cuentaRef = useRef();
  const fechaRef  = useRef();
  const montoRef  = useRef();
  const pagadoRef = useRef();

  useEffect(() => {
    Promise.all([sucursalesApi.list(), mediosApi.list()])
      .then(([sRes, mRes]) => {
        setDestinos((sRes.data ?? []).filter(s => s.id !== sucursalId));
        setMediosList(mRes.data ?? []);
      })
      .catch(logger.error);
  }, []);

  const handleSubmit = async () => {
    const e = {};
    if (destinos.length === 0) { e.destino = 'Aún se están cargando los destinos'; }
    // El medio es obligatorio (columna NOT NULL): se valida acá para dar un mensaje
    // claro en vez de un 500 del backend.
    if (!medioId) { e.medio = 'Selecciona un medio de transporte'; }
    if (Object.keys(e).length > 0) { setErrors(e); return; }
    setErrors({});
    setSaving(true);
    try {
      const res = await enviosApi.store({
        fecha:     fechaRef.current.value,
        cuenta_id: cuentaRef.current.value,
        medio_id:  medioId,
        monto:     montoRef.current.value || 0,
        pagado:    pagadoRef.current.value,
      });
      toast('Envío creado correctamente', 'success');
      onSaved && onSaved(res.data);
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al crear el envío', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title="Nuevo envío" subtitle="Movimiento de stock entre sucursales" icon="fa-truck-fast"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Creando…" : "Crear envío"} maxWidth={580}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div className="field">
          <label className="label">Destino (sucursal) <R/></label>
          <select className="input" ref={cuentaRef} style={errStyle(errors.destino)}>
            {destinos.length === 0
              ? <option value="">Cargando…</option>
              : destinos.map(s => <option key={s.id} value={s.id}>{s.nombre}</option>)}
          </select>
          <FieldErr msg={errors.destino}/>
        </div>
        <div className="grid-2">
          <div className="field"><label className="label">Fecha</label>
            <input className="input" type="date" ref={fechaRef} defaultValue={new Date().toISOString().slice(0,10)}/>
          </div>
          <div className="field"><label className="label">Medio de transporte <R/></label>
            <ComboSelect options={mediosList} value={medioId} invalid={!!errors.medio}
              placeholder="Escribí para buscar…"
              onChange={(id) => { setMedioId(id); if (errors.medio) setErrors(p => ({...p, medio: ''})); }}/>
            <FieldErr msg={errors.medio}/>
          </div>
        </div>
        <div className="grid-2">
          <div className="field"><label className="label">Monto (Bs) <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input mono tabular" ref={montoRef} placeholder="0.00" defaultValue="0.00"/>
            <span style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>Costo del envío (0 si es transporte propio)</span>
          </div>
          {/* Tipo de pago del flete (faltaba — pedido de QA): PAGADO cobra el flete en el
              ORIGEN al despachar; POR PAGAR lo cobra en el DESTINO al recibir. */}
          <div className="field"><label className="label">Tipo de pago</label>
            <select className="input" ref={pagadoRef} defaultValue="PAGADO">
              <option value="PAGADO">Pagado</option>
              <option value="POR PAGAR">Por pagar</option>
            </select>
            <span style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>Quién paga el flete (origen / destino)</span>
          </div>
        </div>
      </div>
    </FormModal>
  );
}

/**
 * Modal para EDITAR EL ENCABEZADO de un envío en PROFORMA (pedido de QA: el legacy tiene
 * "EDITANDO ENCABEZADO" pero el sistema nuevo solo dejaba editar los productos). Permite
 * cambiar destino / fecha / medio de transporte / monto / tipo de pago. El backend
 * (`/envios/update-encabezado`) ya exige origen + PROFORMA (abort_if 403).
 * @param {object} props
 * @param {object} props.envio - Envío actual (cuenta_id, fecha_raw, medio_id, monto_num, pagado, sucursal_id).
 * @param {function(): void} props.onClose
 * @param {function(): void} props.onSaved
 * @returns {JSX.Element}
 */
export function EnvioEncabezadoModal({ envio, onClose, onSaved }) {
  const toast = useToast();
  const [destinos, setDestinos]     = useState([]);
  const [mediosList, setMediosList] = useState([]);
  const [saving, setSaving]         = useState(false);
  const [errors, setErrors]         = useState({});
  // Destino y medio son selects CONTROLADOS inicializados al valor guardado: sus
  // opciones se cargan async y un `defaultValue` (no controlado) se reseteaba a la
  // primera opción al llegar las opciones → el encabezado perdía el destino/medio
  // guardado (bug reportado: un envío a Tarija aparecía como Montero al editar).
  const [cuentaId, setCuentaId] = useState(String(envio.cuenta_id ?? ''));
  const [medioId,  setMedioId]  = useState(String(envio.medio_id ?? ''));
  const fechaRef  = useRef();
  const montoRef  = useRef();
  const pagadoRef = useRef();
  const obsRef    = useRef();

  useEffect(() => {
    Promise.all([sucursalesApi.list(), mediosApi.list()])
      .then(([sRes, mRes]) => {
        // El destino no puede ser el propio origen del envío.
        setDestinos((sRes.data ?? []).filter(s => s.id !== envio.sucursal_id));
        setMediosList(mRes.data ?? []);
      })
      .catch(logger.error);
  }, []);

  const handleSubmit = async () => {
    if (!medioId) { setErrors({ medio: 'Selecciona un medio de transporte' }); return; }
    setErrors({});
    setSaving(true);
    try {
      await enviosApi.updateEncabezado({
        envio_id:  envio.id,
        cuenta_id: cuentaId,
        fecha:     fechaRef.current.value,
        medio_id:  medioId,
        monto:     montoRef.current.value || 0,
        pagado:    pagadoRef.current.value,
        observacion: obsRef.current.value,
      });
      toast('Encabezado actualizado', 'success');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al actualizar el encabezado', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title="Editar encabezado" subtitle={`Envío #${envio.id}`} icon="fa-pen"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Guardando…" : "Guardar cambios"} maxWidth={580}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div className="field">
          <label className="label">Destino (sucursal) <R/></label>
          <select className="input" value={cuentaId} onChange={e => setCuentaId(e.target.value)}>
            {/* La opción del valor guardado se mantiene aunque la lista aún cargue,
                para que el select controlado nunca quede sin opción que lo respalde. */}
            {destinos.length === 0
              ? <option value={cuentaId}>Cargando…</option>
              : destinos.map(s => <option key={s.id} value={s.id}>{s.nombre}</option>)}
          </select>
        </div>
        <div className="grid-2">
          <div className="field"><label className="label">Fecha</label>
            <input className="input" type="date" ref={fechaRef} defaultValue={envio.fecha_raw}/>
          </div>
          <div className="field"><label className="label">Medio de transporte <R/></label>
            <ComboSelect options={mediosList} value={medioId} invalid={!!errors.medio}
              placeholder="Escribí para buscar…"
              onChange={(id) => { setMedioId(id); if (errors.medio) setErrors({}); }}/>
            <FieldErr msg={errors.medio}/>
          </div>
        </div>
        <div className="grid-2">
          <div className="field"><label className="label">Monto (Bs) <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input mono tabular" ref={montoRef} placeholder="0.00" defaultValue={Number(envio.monto_num ?? 0).toFixed(2)}/>
          </div>
          <div className="field"><label className="label">Tipo de pago</label>
            <select className="input" ref={pagadoRef} defaultValue={envio.pagado ?? 'PAGADO'}>
              <option value="PAGADO">Pagado</option>
              <option value="POR PAGAR">Por pagar</option>
            </select>
          </div>
        </div>
        <div className="field"><label className="label">Observación <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
          {/* El legacy guardaba y mostraba notas del envío (ej. "LLEGO DE SANTA CRUZ"); el
              sistema nuevo las había perdido. Se vuelve a poder ver/editar (regresión de QA). */}
          <textarea className="input" rows="2" maxLength={191} ref={obsRef} defaultValue={envio.observacion ?? ''} placeholder="Nota del envío (ej. transporte, referencia…)"></textarea>
        </div>
      </div>
    </FormModal>
  );
}

/* ═══════════ COTIZACIÓN ═══════════ */
export function CotizacionFormModal({ onClose, onSaved }) {
  const toast = useToast();
  const [cliente, setCliente]         = useState(null);
  const [showCliente, setShowCliente] = useState(false);
  const [saving, setSaving]           = useState(false);
  const [errors, setErrors]           = useState({});
  const fechaRef = useRef();
  const obsRef   = useRef();

  const handleSubmit = async () => {
    const e = {};
    if (!cliente?.id) e.cliente = 'Selecciona un cliente';
    if (Object.keys(e).length > 0) { setErrors(e); return; }
    setErrors({});
    setSaving(true);
    try {
      const res = await cotizApi.store({ fecha: fechaRef.current.value, cuenta_id: cliente.id, observacion: obsRef.current.value });
      toast('Cotización creada correctamente', 'success');
      onSaved && onSaved(res.data);
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al crear la cotización', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title="Nueva cotización" subtitle="Proforma para un cliente" icon="fa-file-invoice"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Creando…" : "Crear cotización"}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div>
          <Card title={<>Cliente <R/></>} head={
            <div className="row" style={{gap:6}}>
              {cliente && <button type="button" className="btn btn-ghost btn-sm danger" onClick={() => setCliente(null)}><Icon name="fa-times" style={{fontSize:10}}/>Quitar</button>}
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setShowCliente(!showCliente)}>
                <Icon name="fa-search" style={{fontSize:10}}/>{cliente ? "Cambiar" : "Buscar"}
              </button>
            </div>
          }>
            {cliente ? (
              <div>
                <div style={{fontSize:14, fontWeight:700, color:"var(--ink)"}}>{cliente.nombre}</div>
                {cliente.nit && <div className="mono" style={{fontSize:11, color:"var(--soft)", marginTop:2}}>NIT {cliente.nit}</div>}
              </div>
            ) : (
              <div style={{color: errors.cliente ? "var(--danger)" : "var(--soft)", fontSize:12, textAlign:"center", padding:"8px 0"}}>Sin cliente seleccionado</div>
            )}
            {showCliente && (
              <div style={{marginTop:12, paddingTop:12, borderTop:"1px solid var(--line)"}}>
                <AccountSearchInput
                  onSelect={(c) => { setCliente(c); setShowCliente(false); setErrors(prev => ({...prev, cliente:''})); }}
                  placeholder="Buscar cliente…"
                  showSinNombre={true}
                  take={5}
                  autoFocus={true}
                />
              </div>
            )}
          </Card>
          <FieldErr msg={errors.cliente}/>
        </div>
        <div className="field"><label className="label">Fecha</label>
          <input className="input" type="date" ref={fechaRef} defaultValue={new Date().toISOString().slice(0,10)}/>
        </div>
        <div className="field"><label className="label">Observación <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
          <textarea className="input" rows="3" maxLength={191} ref={obsRef} placeholder="Términos, condiciones, vigencia…"></textarea>
        </div>
        <div style={{padding: 14, background:"var(--info-soft)", border:"1px solid rgba(3,105,161,.2)", borderRadius:"var(--r-md)", fontSize:12, color:"var(--info)"}}>
          <Icon name="fa-info-circle" style={{marginRight: 6}}/>Después de crear, podrás agregar los productos a cotizar.
        </div>
      </div>
    </FormModal>
  );
}

/**
 * Modal para EDITAR el encabezado de una cotización existente: cliente, fecha y
 * observación. El sistema legacy tenía esta edición (y la observación, donde se
 * anotaban nombre/teléfono del cliente cuando la cuenta era genérica "SIN NOMBRE");
 * el sistema nuevo la había perdido (regresión de QA). El `descuento` actual se
 * REENVÍA para no resetearlo (el backend lo pone en 0 si no llega).
 *
 * @param {object} props
 * @param {object} props.cotizacion - Cotización a editar (id, cuenta_id, cuenta, fecha_raw, observacion, descuento).
 * @param {function(): void} props.onClose - Cierra el modal.
 * @param {function(): void} [props.onSaved] - Callback tras guardar (para recargar el encabezado).
 * @returns {JSX.Element}
 */
export function CotizacionEncabezadoModal({ cotizacion, onClose, onSaved }) {
  const toast = useToast();
  const [cliente, setCliente] = useState(
    cotizacion.cuenta_id ? { id: cotizacion.cuenta_id, nombre: cotizacion.cuenta, nit: cotizacion.nit } : null
  );
  const [showCliente, setShowCliente] = useState(false);
  const [saving, setSaving]           = useState(false);
  const [errors, setErrors]           = useState({});
  const fechaRef = useRef();
  const obsRef   = useRef();

  const handleSubmit = async () => {
    if (!cliente?.id) { setErrors({ cliente: 'Selecciona un cliente' }); return; }
    setErrors({});
    setSaving(true);
    try {
      await cotizApi.updateEncabezado({
        cotizacion_id: cotizacion.id,
        cuenta_id:     cliente.id,
        fecha:         fechaRef.current.value,
        observacion:   obsRef.current.value,
        descuento:     cotizacion.descuento ?? 0,  // preservar el descuento actual
      });
      toast('Encabezado actualizado', 'success');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      const d = err?.response?.data;
      toast(d?.error || d?.message || 'Error al actualizar el encabezado', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title="Editar encabezado" subtitle={`Cotización #${cotizacion.id}`} icon="fa-pen"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Guardando…" : "Guardar cambios"}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div>
          <Card title={<>Cliente <R/></>} head={
            <div className="row" style={{gap:6}}>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setShowCliente(!showCliente)}>
                <Icon name="fa-search" style={{fontSize:10}}/>{cliente ? "Cambiar" : "Buscar"}
              </button>
            </div>
          }>
            {cliente ? (
              <div>
                <div style={{fontSize:14, fontWeight:700, color:"var(--ink)"}}>{cliente.nombre}</div>
                {cliente.nit && <div className="mono" style={{fontSize:11, color:"var(--soft)", marginTop:2}}>NIT {cliente.nit}</div>}
              </div>
            ) : (
              <div style={{color: errors.cliente ? "var(--danger)" : "var(--soft)", fontSize:12, textAlign:"center", padding:"8px 0"}}>Sin cliente seleccionado</div>
            )}
            {showCliente && (
              <div style={{marginTop:12, paddingTop:12, borderTop:"1px solid var(--line)"}}>
                <AccountSearchInput
                  onSelect={(c) => { setCliente(c); setShowCliente(false); setErrors(prev => ({...prev, cliente:''})); }}
                  placeholder="Buscar cliente…"
                  showSinNombre={true}
                  take={5}
                  autoFocus={true}
                />
              </div>
            )}
          </Card>
          <FieldErr msg={errors.cliente}/>
        </div>
        <div className="field"><label className="label">Fecha</label>
          <input className="input" type="date" ref={fechaRef} defaultValue={cotizacion.fecha_raw}/>
        </div>
        <div className="field"><label className="label">Observación <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
          <textarea className="input" rows="3" maxLength={191} ref={obsRef} defaultValue={cotizacion.observacion ?? ''} placeholder="Nombre/teléfono del cliente, términos, vigencia…"></textarea>
        </div>
      </div>
    </FormModal>
  );
}

/* ═══════════ PRODUCTO ═══════════ */
export function ProductoFormModal({ onClose, onSaved, edit }) {
  const toast = useToast();
  const [tab, setTab]       = useState("basico");
  const [saving, setSaving] = useState(false);
  const [marcas, setMarcas] = useState([]);
  const [industrias, setIndustrias] = useState([]);
  const [errors, setErrors] = useState({});
  const codigoRef = useRef(null);
  const descRef   = useRef(null);
  const [form, setForm] = useState({
    codigo:       edit?.codigo       || '',
    descripcion:  edit?.descripcion  || '',
    unidad:       edit?.unidad       || 'Unidad',
    p_comp:       edit?.p_comp       || '',
    p_norm:       edit?.p_norm       || '',
    p_fact:       edit?.p_fact       || '',
    marca_id:     '',
    industria_id: '',
  });

  const set = (k) => (e) => {
    const val = e.target.value;
    setForm(f => ({ ...f, [k]: val }));
    if (errors[k]) setErrors(prev => ({...prev, [k]: ''}));
  };

  useEffect(() => {
    Promise.all([marcasApi.list(), industriasApi.list()])
      .then(([mRes, iRes]) => {
        const ms = mRes.data ?? [];
        const is = iRes.data ?? [];
        setMarcas(ms);
        setIndustrias(is);
        if (edit) {
          const m = ms.find(x => x.nombre === edit.marca);
          const i = is.find(x => x.nombre === edit.industria);
          setForm(f => ({ ...f, marca_id: m?.id || '', industria_id: i?.id || '' }));
        } else {
          setForm(f => ({ ...f, marca_id: ms[0]?.id || '', industria_id: is[0]?.id || '' }));
        }
      })
      .catch(logger.error);
  }, []);

  const handleSubmit = async () => {
    const e = {};
    if (!form.codigo.trim())      e.codigo      = 'El código es requerido';
    if (!form.descripcion.trim()) e.descripcion = 'La descripción es requerida';
    if (Object.keys(e).length > 0) {
      setErrors(e);
      if (e.codigo || e.descripcion) setTab('basico');
      toast(Object.values(e).join(' · '), 'error');
      setTimeout(() => {
        if (e.codigo)      codigoRef.current?.focus();
        else if (e.descripcion) descRef.current?.focus();
      }, 50);
      return;
    }
    setErrors({});
    setSaving(true);
    try {
      const data = { ...form, marca_id: +form.marca_id, industria_id: +form.industria_id };
      if (edit) await prodApi.update(edit.id, data);
      else await prodApi.store(data);
      toast(edit ? 'Producto actualizado' : 'Producto creado correctamente', 'success');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      // Mostrar el MOTIVO REAL: Laravel 422 trae los errores por campo en `errors`
      // (antes siempre salía el genérico "Error al guardar" y no se sabía qué corregir).
      const d = err?.response?.data;
      const motivo = d?.errors ? Object.values(d.errors).flat()[0] : (d?.error || d?.message);
      toast(motivo || 'Error al guardar el producto', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title={edit ? "Editar producto" : "Nuevo producto"} subtitle="Datos del repuesto y precios" icon="fa-cube"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Guardando…" : (edit ? "Guardar cambios" : "Crear producto")} maxWidth={620}>
      <div className="utabs" style={{marginBottom: 18, gap:18}}>
        {[
          { id: "basico",  label: "Datos básicos", icon: "fa-circle-info" },
          { id: "precios", label: "Precios",       icon: "fa-tag" },
        ].map(t => (
          <button key={t.id} className={`t ${tab === t.id ? "active" : ""}`} onClick={() => setTab(t.id)}>
            <Icon name={t.icon} style={{fontSize:11, marginRight: 6}}/>{t.label}
            {t.id === 'basico' && (errors.codigo || errors.descripcion) &&
              <span style={{width:7,height:7,borderRadius:'50%',background:"var(--danger)",display:"inline-block",marginLeft:6,verticalAlign:"middle"}}/>
            }
          </button>
        ))}
      </div>

      {tab === "basico" && (
        <div className="stack" style={{"--gap":"14px"}}>
          <div className="field">
            <label className="label">Código <R/></label>
            <input ref={codigoRef} className="input mono" placeholder="VOL-FH-1241" value={form.codigo} onChange={set('codigo')}
              style={errStyle(errors.codigo)}/>
            <FieldErr msg={errors.codigo}/>
          </div>
          <div className="field">
            <label className="label">Descripción <R/></label>
            <input ref={descRef} className="input" placeholder="Ej: Filtro de aceite motor D13" value={form.descripcion} onChange={set('descripcion')}
              style={errStyle(errors.descripcion)}/>
            <FieldErr msg={errors.descripcion}/>
          </div>
          <div className="grid-2">
            <div className="field"><label className="label">Marca</label>
              <select className="input" value={form.marca_id} onChange={set('marca_id')}>
                {marcas.map(m => <option key={m.id} value={m.id}>{m.nombre}</option>)}
              </select>
            </div>
            <div className="field"><label className="label">Industria</label>
              <select className="input" value={form.industria_id} onChange={set('industria_id')}>
                {industrias.map(i => <option key={i.id} value={i.id}>{i.nombre}</option>)}
              </select>
            </div>
          </div>
          <div className="field"><label className="label">Unidad de medida</label>
            <select className="input" value={form.unidad} onChange={set('unidad')}>
              {["Unidad","Par","Juego","Litro","Kilo","Metro"].map(u => <option key={u}>{u}</option>)}
            </select>
          </div>
        </div>
      )}

      {tab === "precios" && (
        <div className="stack" style={{"--gap":"14px"}}>
          <div style={{fontSize: 12, color:"var(--soft)", marginBottom: 4}}>Tres niveles de precio según el manual del sistema:</div>
          <div className="field"><label className="label" style={{color:"var(--warning)"}}>Precio compra (p_comp) <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input mono tabular" placeholder="0.00" type="number" step="0.01" value={form.p_comp} onChange={set('p_comp')}/>
            <span style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>Costo de adquisición</span>
          </div>
          <div className="field"><label className="label" style={{color:"var(--success)"}}>Precio normal (p_norm) <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input mono tabular" placeholder="0.00" type="number" step="0.01" value={form.p_norm} onChange={set('p_norm')}/>
            <span style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>Precio de venta al público sin factura</span>
          </div>
          <div className="field"><label className="label" style={{color:"var(--accent)"}}>Precio factura (p_fact) <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input mono tabular" placeholder="0.00" type="number" step="0.01" value={form.p_fact} onChange={set('p_fact')}/>
            <span style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>Precio con factura emitida</span>
          </div>
        </div>
      )}
    </FormModal>
  );
}

/* ═══════════ CUENTA ═══════════ */
export function CuentaFormModal({ onClose, onSaved, edit }) {
  const toast = useToast();
  const [tipo, setTipo]   = useState(edit?.tipo || "CLIENTE");
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const nombreRef   = useRef();
  const nitRef      = useRef();
  const telefonoRef = useRef();
  const emailRef    = useRef();
  const direccionRef = useRef();

  const handleSubmit = async () => {
    const e = {};
    if (!nombreRef.current?.value?.trim()) e.nombre = 'El nombre es requerido';
    if (Object.keys(e).length > 0) { setErrors(e); return; }
    setErrors({});
    setSaving(true);
    try {
      const data = { nombre: nombreRef.current.value, tipo, nit: nitRef.current.value, telefono: telefonoRef.current.value, email: emailRef.current.value, direccion: direccionRef.current.value };
      if (edit) await cuentasApi.update(edit.id, data);
      else await cuentasApi.store(data);
      toast(edit ? 'Cuenta actualizada' : 'Cuenta creada correctamente', 'success');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al guardar la cuenta', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title={edit ? "Editar cuenta" : "Nueva cuenta"} subtitle="Cliente, proveedor o ambos" icon="fa-address-book"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Guardando…" : edit ? "Guardar" : "Crear cuenta"}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div className="field"><label className="label">Tipo de cuenta</label>
          <div className="grid-2" style={{gap: 8}}>
            {[
              { v: "CLIENTE",   label: "Cliente" },
              { v: "PROVEEDOR", label: "Proveedor" },
              { v: "CLIE-PROV", label: "Cliente y Proveedor" },
            ].map(opt => (
              <button key={opt.v} type="button" onClick={()=>setTipo(opt.v)}
                style={{padding:"10px", borderRadius:"var(--r-md)", border: tipo === opt.v ? "2px solid var(--accent)" : "2px solid var(--line)", background: tipo === opt.v ? "var(--accent-soft)" : "var(--surface)", color: tipo === opt.v ? "var(--accent)" : "var(--body)", fontSize:12, fontWeight:700}}>
                {opt.label}
              </button>
            ))}
          </div>
        </div>
        <div className="field">
          <label className="label">Nombre / Razón social <R/></label>
          <input className="input" ref={nombreRef} placeholder="Ej: Toyosa S.A." defaultValue={edit?.nombre}
            onChange={() => errors.nombre && setErrors(e => ({...e, nombre:''}))}
            style={errStyle(errors.nombre)}/>
          <FieldErr msg={errors.nombre}/>
        </div>
        <div className="grid-2">
          <div className="field"><label className="label">NIT / CI <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input mono" ref={nitRef} placeholder="1023456020" defaultValue={edit?.nit}/>
          </div>
          <div className="field"><label className="label">Teléfono <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input mono" ref={telefonoRef} placeholder="+591 7xxxxxxx" defaultValue={edit?.telefono}/>
          </div>
        </div>
        <div className="field"><label className="label">Email <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
          <input className="input" ref={emailRef} type="email" placeholder="contacto@empresa.bo" defaultValue={edit?.email}/>
        </div>
        <div className="field"><label className="label">Dirección <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
          <input className="input" ref={direccionRef} placeholder="Av., calle, número, ciudad" defaultValue={edit?.direccion}/>
        </div>
      </div>
    </FormModal>
  );
}

/* ═══════════ SUCURSAL ═══════════ */
export function SucursalFormModal({ onClose, onSaved, edit }) {
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const nombreRef     = useRef();
  const aliasRef      = useRef();
  const nitRef        = useRef();
  const supervisorRef = useRef();
  const telefonoRef   = useRef();
  const emailRef      = useRef();
  const direccionRef  = useRef();
  const estadoRef     = useRef();

  const handleSubmit = async () => {
    const e = {};
    if (!nombreRef.current?.value?.trim()) e.nombre = 'El nombre de la sucursal es requerido';
    if (Object.keys(e).length > 0) { setErrors(e); return; }
    setErrors({});
    setSaving(true);
    try {
      const data = {
        nombre:     nombreRef.current.value,
        alias:      aliasRef.current.value,
        nit:        nitRef.current.value,
        supervisor: supervisorRef.current.value,
        telefono:   telefonoRef.current.value,
        email:      emailRef.current.value,
        direccion:  direccionRef.current.value,
        estado:     estadoRef.current.checked ? 'ON' : 'OFF',
      };
      if (edit) await sucursalesApi.update(edit.id, data);
      else await sucursalesApi.store(data);
      toast(edit ? 'Sucursal actualizada' : 'Sucursal creada correctamente', 'success');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al guardar la sucursal', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title={edit ? "Editar sucursal" : "Nueva sucursal"} subtitle="Punto de venta / centro operativo" icon="fa-flag"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Guardando…" : (edit ? "Guardar" : "Crear sucursal")}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div className="grid-2">
          <div className="field">
            <label className="label">Nombre <R/></label>
            <input className="input" placeholder="Ej: SUR" defaultValue={edit?.nombre} ref={nombreRef}
              onChange={() => errors.nombre && setErrors(e => ({...e, nombre:''}))}
              style={errStyle(errors.nombre)}/>
            <FieldErr msg={errors.nombre}/>
          </div>
          <div className="field"><label className="label">Alias <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input mono" placeholder="SUR" defaultValue={edit?.alias} maxLength="8" style={{textTransform:"uppercase"}} ref={aliasRef}/>
            <span style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>Abreviación corta para badges</span>
          </div>
        </div>
        <div className="field"><label className="label">NIT <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
          <input className="input mono" placeholder="1234568791" defaultValue={edit?.nit} ref={nitRef}/>
        </div>
        <div className="field"><label className="label">Supervisor / Encargado <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
          <input className="input" placeholder="Nombre completo del responsable" defaultValue={edit?.supervisor || edit?.responsable} ref={supervisorRef}/>
        </div>
        <div className="grid-2">
          <div className="field"><label className="label">Teléfono <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input mono" placeholder="+591 7xxxxxxx" defaultValue={edit?.telefono} ref={telefonoRef}/>
          </div>
          <div className="field"><label className="label">Email <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
            <input className="input" type="email" placeholder="sucursal@lacasavolvo.bo" defaultValue={edit?.email} ref={emailRef}/>
          </div>
        </div>
        <div className="field"><label className="label">Dirección <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(opcional)</span></label>
          <input className="input" placeholder="Av., calle, referencia, ciudad" defaultValue={edit?.direccion} ref={direccionRef}/>
        </div>
        <div className="row" style={{gap: 8}}>
          <input type="checkbox" id="suc-active" defaultChecked={edit ? edit.estado === "ON" : true} style={{accentColor:"var(--accent)"}} ref={estadoRef}/>
          <label htmlFor="suc-active" style={{fontSize: 13, color:"var(--body)"}}>Sucursal activa (estado ON)</label>
        </div>
      </div>
    </FormModal>
  );
}

/* ═══════════ USUARIO ═══════════ */
const ROLES_SISTEMA = [
  { name: "ADMIN",      desc: "Acceso total — bypass Gate::before" },
  { name: "GERENTE",    desc: "Operaciones, inventario, finanzas, usuarios" },
  { name: "VENDEDOR",   desc: "Productos, cuentas, ventas, cotizaciones" },
  { name: "CAJERO",     desc: "Productos, cuentas, ventas, compras, caja" },
  { name: "OPERADOR",   desc: "Productos, cuentas, pedidos, compras, envíos" },
  { name: "SUSPENDIDO", desc: "Sin acceso al sistema" },
];

export function UsuarioFormModal({ onClose, onSaved, edit }) {
  const toast = useToast();
  const [tab, setTab]       = useState("info");
  const [saving, setSaving] = useState(false);
  const [sucursales, setSucursales] = useState([]);
  const [rolesList, setRolesList] = useState(ROLES_SISTEMA);
  // En edición se cargan los accesos reales (estado ON) del usuario;
  // en creación arranca vacío y la sucursal predeterminada se agrega al guardar.
  const [accesos, setAccesos] = useState(() => {
    if (edit?.accesos?.length) return edit.accesos.filter(a => a.estado === 'ON').map(a => a.sucursal_id);
    return edit?.sucursal_id ? [edit.sucursal_id] : [];
  });
  const [errors, setErrors] = useState({});
  const [form, setForm] = useState({
    name:            edit?.name     || '',
    email:           edit?.email    || '',
    password:        '',
    password_confirmation: '',
    role:            edit?.role     || 'VENDEDOR',
    sucursal_id:     edit?.sucursal_id || 1,
  });

  useEffect(() => {
    sucursalesApi.list().then(r => {
      const list = r.data ?? [];
      setSucursales(list);
      if (!edit && list.length > 0) setForm(f => ({ ...f, sucursal_id: list[0].id }));
    }).catch(() => {});
    rolesApi.list().then(r => {
      const apiRoles = (r.data || []).map(rl => ({ name: rl.name, desc: `${rl.permissions_count} permisos` }));
      const merged = [...ROLES_SISTEMA];
      apiRoles.forEach(ar => { if (!merged.find(m => m.name === ar.name)) merged.push(ar); });
      setRolesList(merged);
    }).catch(() => {});
  }, []);

  const set = (k) => (e) => {
    const val = e.target.value;
    setForm(f => ({ ...f, [k]: val }));
    if (errors[k]) setErrors(prev => ({...prev, [k]: ''}));
  };

  const toggleAcceso = async (id) => {
    const tiene = accesos.includes(id);
    if (edit) {
      try { await usersApi.acces(edit.id, id, tiene ? 'OFF' : 'ON'); }
      catch { return; }
    }
    setAccesos(a => tiene ? a.filter(x => x !== id) : [...a, id]);
  };

  const handleSubmit = async () => {
    const e = {};
    if (!form.name.trim()) e.name = 'El nombre es requerido';
    if (!form.email.trim()) e.email = 'El email es requerido';
    if (!edit && !form.password) e.password = 'La contraseña es requerida';
    if (form.password && form.password !== form.password_confirmation) e.password_confirmation = 'Las contraseñas no coinciden';
    if (Object.keys(e).length > 0) {
      setErrors(e);
      // Navegar al tab con el primer error
      if (e.name || e.email) setTab('info');
      else if (e.password || e.password_confirmation) setTab('acceso');
      return;
    }
    setErrors({});
    setSaving(true);
    try {
      if (edit) {
        const data = { name: form.name, email: form.email, sucursal_id: +form.sucursal_id, role: form.role };
        if (form.password) { data.password = form.password; data.password_confirmation = form.password_confirmation; }
        await usersApi.update(edit.id, data);
      } else {
        // En creación se envían los accesos marcados en el tab Sucursales;
        // el backend siempre deja ON la sucursal predeterminada.
        await usersApi.store({ name: form.name, email: form.email, password: form.password, password_confirmation: form.password_confirmation, sucursal_id: +form.sucursal_id, role: form.role, accesos });
      }
      toast(edit ? 'Usuario actualizado' : 'Usuario creado correctamente', 'success');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      const msg = err?.response?.data?.errors
        ? Object.values(err.response.data.errors).flat().join(' · ')
        : (err?.response?.data?.error || 'Error al guardar el usuario');
      toast(msg, 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title={edit ? "Editar usuario" : "Nuevo usuario"} subtitle="Acceso al sistema" icon="fa-user-plus"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Guardando…" : (edit ? "Guardar" : "Crear usuario")} maxWidth={580}>
      <div className="utabs" style={{marginBottom: 18, gap: 18}}>
        {[
          { id: "info",       label: "Datos",         icon: "fa-user" },
          { id: "acceso",     label: "Rol y acceso",  icon: "fa-shield-halved" },
          { id: "sucursales", label: "Sucursales",    icon: "fa-flag" },
        ].map(t => (
          <button key={t.id} className={`t ${tab === t.id ? "active" : ""}`} onClick={() => setTab(t.id)}>
            <Icon name={t.icon} style={{fontSize:11, marginRight: 6}}/>{t.label}
            {t.id === 'info' && (errors.name || errors.email) &&
              <span style={{width:7,height:7,borderRadius:'50%',background:"var(--danger)",display:"inline-block",marginLeft:6,verticalAlign:"middle"}}/>
            }
            {t.id === 'acceso' && (errors.password || errors.password_confirmation) &&
              <span style={{width:7,height:7,borderRadius:'50%',background:"var(--danger)",display:"inline-block",marginLeft:6,verticalAlign:"middle"}}/>
            }
          </button>
        ))}
      </div>

      {tab === "info" && (
        <div className="stack" style={{"--gap":"14px"}}>
          <div className="field">
            <label className="label">Nombre completo <R/></label>
            <input className="input" placeholder="Ej: Marcelina Condori" value={form.name} onChange={set('name')}
              style={errStyle(errors.name)}/>
            <FieldErr msg={errors.name}/>
          </div>
          {!edit && (
            <div className="field">
              <label className="label">Email corporativo <R/></label>
              <input className="input" type="email" placeholder="usuario@lacasavolvo.bo" value={form.email} onChange={set('email')}
                style={errStyle(errors.email)}/>
              <FieldErr msg={errors.email}/>
            </div>
          )}
          {edit && (
            <div className="field">
              <label className="label">Email <R/></label>
              <input className="input" type="email" value={form.email} onChange={set('email')}
                style={errStyle(errors.email)}/>
              <FieldErr msg={errors.email}/>
            </div>
          )}
        </div>
      )}

      {tab === "acceso" && (
        <div className="stack" style={{"--gap":"14px"}}>
          <div className="field"><label className="label">Rol (Spatie)</label>
            <div className="role-grid" style={{gridTemplateColumns:"repeat(2,1fr)"}}>
              {rolesList.map(r => (
                <label key={r.name} style={{display:"flex", gap:10, padding:"10px 12px", border:"1px solid", borderColor: form.role === r.name ? "var(--accent)" : "var(--line)", borderRadius:"var(--r-md)", cursor:"pointer", background: form.role === r.name ? "var(--accent-soft)" : "var(--surface)"}}>
                  <input type="radio" name="rol" checked={form.role === r.name} onChange={() => setForm(f => ({...f, role: r.name}))} style={{accentColor:"var(--accent)"}}/>
                  <div>
                    <div style={{fontSize: 12.5, fontWeight: 700, color:"var(--ink)"}}>{r.name}</div>
                    <div style={{fontSize: 10.5, color:"var(--soft)", marginTop: 2}}>{r.desc}</div>
                  </div>
                </label>
              ))}
            </div>
            <span style={{fontSize: 11, color:"var(--soft)", marginTop: 4}}>El rol determina los permisos y el nivel de acceso del usuario.</span>
          </div>
          <div className="field"><label className="label">Sucursal predeterminada</label>
            <select className="input" value={form.sucursal_id} onChange={set('sucursal_id')}>
              {sucursales.map(s => <option key={s.id} value={s.id}>{s.nombre}</option>)}
            </select>
            <span style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>Campo <code>users.sucursal_id</code> — al iniciar sesión</span>
          </div>
          <div className="field">
            <label className="label">Contraseña {edit ? <span style={{fontSize:10,color:"var(--soft)",fontWeight:400}}>(dejar vacío para no cambiar)</span> : <R/>}</label>
            <input className="input" type="password" placeholder="Mínimo 8 caracteres" value={form.password} onChange={set('password')}
              style={errStyle(errors.password)}/>
            <FieldErr msg={errors.password}/>
          </div>
          <div className="field">
            <label className="label">Confirmar contraseña {!edit && <R/>}</label>
            <input className="input" type="password" value={form.password_confirmation} onChange={set('password_confirmation')}
              style={errStyle(errors.password_confirmation)}/>
            <FieldErr msg={errors.password_confirmation}/>
          </div>
        </div>
      )}

      {tab === "sucursales" && (
        <div className="stack" style={{"--gap":"10px"}}>
          <div style={{fontSize: 12, color:"var(--soft)", marginBottom: 4}}>
            Sucursales donde el usuario puede operar:
          </div>
          {sucursales.map(s => {
            // La sucursal predeterminada siempre debe tener acceso: se muestra
            // obligatoria y no se puede desactivar (evita dejar al usuario sin
            // acceso a su propia sucursal de inicio).
            const isDefault = s.id === +form.sucursal_id;
            const has = accesos.includes(s.id) || isDefault;
            return (
              <button key={s.id} type="button" disabled={isDefault}
                onClick={() => { if (!isDefault) toggleAcceso(s.id); }}
                style={{display:"flex", alignItems:"center", gap: 12, padding:"12px 14px", border:"1px solid", borderColor: has ? "rgba(22,163,74,.35)" : "var(--line)", borderRadius:"var(--r-md)", background: has ? "var(--success-soft)" : "var(--surface)", textAlign:"left", cursor: isDefault ? "default" : "pointer", opacity: isDefault ? 0.85 : 1}}>
                <div style={{width: 32, height: 32, borderRadius:"var(--r-sm)", background:"var(--accent)", display:"grid", placeItems:"center"}}>
                  <Icon name="fa-flag" style={{color:"#fff", fontSize: 11}}/>
                </div>
                <div className="grow">
                  <div style={{fontSize: 13, fontWeight: 700, color:"var(--ink)", display:"flex", alignItems:"center", gap:6}}>
                    {s.nombre}
                    {isDefault && <span className="badge" style={{fontSize:9, padding:"1px 6px", background:"var(--accent-soft)", color:"var(--accent)"}}>Predeterminada</span>}
                  </div>
                  <div style={{fontSize: 11, color:"var(--soft)"}}>Sucursal #{s.id} · acceso {has ? "ON" : "OFF"}{isDefault ? " · obligatorio" : ""}</div>
                </div>
                <Icon name={has ? "fa-circle-check" : "fa-circle"} style={{color: has ? "var(--success)" : "var(--dust)", fontSize: 18}}/>
              </button>
            );
          })}
        </div>
      )}
    </FormModal>
  );
}

/* ═══════════ ROL ═══════════ */
export function RolFormModal({ onClose, onSaved, edit }) {
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const [perms, setPerms] = useState({});
  const [loading, setLoading] = useState(!!edit);
  const [errors, setErrors] = useState({});
  const nameRef = useRef();
  const editName = edit?.name || '';
  const editId   = edit?.id;

  const MATRIZ = [
    { modulo: 'Home',          base: 'home',        acciones: ['index'] },
    { modulo: 'Sucursales',    base: 'sucursales',  acciones: ['index','show','create','edit','destroy'] },
    { modulo: 'Roles',         base: 'roles',       acciones: ['index','show','create','edit','destroy'] },
    { modulo: 'Usuarios',      base: 'users',       acciones: ['index','show','edit','destroy'] },
    { modulo: 'Marcas',        base: 'marcas',      acciones: ['index','show','create','edit','destroy'] },
    { modulo: 'Industrias',    base: 'industrias',  acciones: ['index','show','create','edit','destroy'] },
    { modulo: 'Empresas',      base: 'empresas',    acciones: ['index','show','create','edit','destroy'] },
    { modulo: 'Localidades',   base: 'localidades', acciones: ['index','show','create','edit','destroy'] },
    { modulo: 'Medios',        base: 'medios',      acciones: ['index','show','create','edit','destroy'] },
    { modulo: 'Productos',     base: 'productos',   acciones: ['index','show','create','edit','destroy','ajustes','ajustepositivo','ajustenegativo','ajustedelete'] },
    { modulo: 'Cuentas',       base: 'cuentas',     acciones: ['index','show','create','edit','destroy'] },
    { modulo: 'Pedidos',       base: 'pedidos',     acciones: ['index','show','create','edit','destroy','print'] },
    { modulo: 'Compras',       base: 'compras',     acciones: ['index','show','create','edit','destroy','print'] },
    { modulo: 'Ventas',        base: 'ventas',      acciones: ['index','show','create','edit','destroy','print'] },
    { modulo: 'Envíos',        base: 'envios',      acciones: ['index','show','create','edit','destroy','print'] },
    { modulo: 'Caja',          base: 'caja',        acciones: ['index','show','cierre','destroy','print'] },
    { modulo: 'Perfil',        base: 'perfil',      acciones: ['index','edit'] },
    { modulo: 'Cotizaciones',  base: 'cotizaciones',acciones: ['index','show','create','edit','destroy','print'] },
    { modulo: 'Estadísticas',  base: 'estadisticas',acciones: ['index'] },
  ];

  const ACC_LABEL = {
    index:'INDEX', show:'VER', create:'CREAR', edit:'EDITAR', destroy:'ELIMINAR',
    print:'IMPRIMIR', ajustes:'AJUSTES', ajustepositivo:'AJUSTE +', ajustenegativo:'AJUSTE −',
    ajustedelete:'ELIM.AJUSTE', cierre:'CERRAR',
  };

  useEffect(() => {
    if (!edit) return;
    if (edit.permissions) {
      const map = {};
      edit.permissions.forEach(p => { map[typeof p === 'string' ? p : p.name] = true; });
      setPerms(map);
      setLoading(false);
      return;
    }
    rolesApi.list().then(r => {
      const rol = (r.data || []).find(rl => rl.name === editName);
      if (rol?.permissions) {
        const map = {};
        rol.permissions.forEach(p => { map[typeof p === 'string' ? p : p.name] = true; });
        setPerms(map);
      }
      setLoading(false);
    }).catch(() => setLoading(false));
  }, [edit]);

  const toggle = (permName) => setPerms(p => ({ ...p, [permName]: !p[permName] }));

  const handleSubmit = async () => {
    const e = {};
    if (!editId && !nameRef.current?.value?.trim()) e.name = 'El nombre del rol es requerido';
    if (Object.keys(e).length > 0) { setErrors(e); return; }
    setErrors({});
    setSaving(true);
    try {
      const permList = Object.entries(perms).filter(([,v]) => v).map(([k]) => k);
      if (editId) {
        await rolesApi.update(editId, { name: editName, permissions: permList });
      } else {
        await rolesApi.store({ name: nameRef.current.value, permissions: permList });
      }
      toast(editId ? 'Permisos actualizados' : 'Rol creado correctamente', 'success');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al guardar el rol', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title={editId ? `Editar rol · ${editName}` : "Nuevo rol"} subtitle="Matriz de permisos granular (Spatie)" icon="fa-shield-halved"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Guardando…" : (editId ? "Guardar permisos" : "Crear rol")} maxWidth={780}>
      <div className="stack" style={{"--gap":"8px"}}>
        {!edit && (
          <div className="field" style={{marginBottom:8}}>
            <label className="label">Nombre del rol <R/></label>
            <input className="input" ref={nameRef} placeholder="Ej: SUPERVISOR" style={{textTransform:"uppercase", ...errStyle(errors.name)}}
              onChange={() => errors.name && setErrors(e => ({...e, name:''}))}/>
            <FieldErr msg={errors.name}/>
          </div>
        )}
        {editName === "ADMIN" && (
          <div style={{padding:"10px 14px", background:"var(--info-soft)", border:"1px solid rgba(3,105,161,.2)", borderRadius:"var(--r-md)", fontSize:12, color:"var(--info)", marginBottom:4}}>
            <Icon name="fa-info-circle" style={{marginRight:6}}/>ADMIN tiene bypass total vía <code>Gate::before</code>. La matriz inferior es solo referencia.
          </div>
        )}
        {loading ? (
          <div style={{padding:60, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
        ) : (
          <div style={{maxHeight:"55vh", overflowY:"auto", border:"1px solid var(--line)", borderRadius:"var(--r-md)"}}>
            <table style={{width:"100%", borderCollapse:"collapse", fontSize:11}}>
              <thead>
                <tr style={{background:"var(--alt)", position:"sticky", top:0, zIndex:1}}>
                  <th style={{padding:"8px 12px", textAlign:"left", fontWeight:700, color:"var(--ink)", borderBottom:"2px solid var(--line)", minWidth:130}}>Módulo</th>
                  {['INDEX','VER','CREAR','EDITAR','ELIM','IMPR','AJUS','AJ+','AJ−','EL.AJ','CERR'].map(h => (
                    <th key={h} style={{padding:"4px 1px", textAlign:"center", fontWeight:700, fontSize:8.5, color:"var(--soft)", borderBottom:"2px solid var(--line)", width:36, letterSpacing:".02em"}} title={h}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {MATRIZ.map((m, mi) => (
                  <tr key={mi} style={{borderBottom:"1px solid var(--line-soft)", background: mi % 2 === 0 ? "transparent" : "rgba(0,0,0,.015)", transition:"background .1s"}}>
                    <td style={{padding:"5px 10px", fontWeight:600, color:"var(--ink)", fontSize:11, whiteSpace:"nowrap"}}>
                      <label style={{cursor:"pointer", display:"flex", alignItems:"center", gap:4, fontSize:10, color:"var(--soft)", fontWeight:400}}
                        onClick={() => {
                          const nuevos = {...perms};
                          const todosMarcados = m.acciones.every(a => nuevos[`${m.base}.${a}`]);
                          m.acciones.forEach(a => { nuevos[`${m.base}.${a}`] = !todosMarcados; });
                          setPerms(nuevos);
                        }}>
                        <input type="checkbox" checked={m.acciones.every(a => perms[`${m.base}.${a}`])} readOnly
                          style={{accentColor:"var(--accent)", width:12, height:12, pointerEvents:"none"}}/>
                      </label>
                      {m.modulo}
                    </td>
                    {['index','show','create','edit','destroy','print','ajustes','ajustepositivo','ajustenegativo','ajustedelete','cierre'].map(acc => {
                      const exists = m.acciones.includes(acc);
                      const permName = `${m.base}.${acc}`;
                      return (
                        <td key={acc} style={{textAlign:"center", padding:"1px"}}>
                          {exists ? (
                            <input type="checkbox" checked={!!perms[permName]} onChange={() => toggle(permName)}
                              style={{accentColor:"var(--accent)", cursor:"pointer", width:13, height:13}}/>
                          ) : <span style={{color:"var(--line)", fontSize:9}}>·</span>}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </FormModal>
  );
}

/* ═══════════ MEDIO ═══════════ */
export function MedioFormModal({ onClose, onSaved, edit, onSave }) {
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const nameRef   = useRef();
  const activeRef = useRef();

  const handleSubmit = async () => {
    const e = {};
    if (!nameRef.current?.value?.trim()) e.nombre = 'El nombre es requerido';
    if (Object.keys(e).length > 0) { setErrors(e); return; }
    setErrors({});
    setSaving(true);
    try {
      if (onSave) await onSave({ nombre: nameRef.current.value, estado: activeRef.current.checked ? 'ON' : 'OFF' });
      toast(edit ? 'Medio actualizado' : 'Medio creado correctamente', 'success');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al guardar', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title={edit ? "Editar medio" : "Nuevo medio de transporte"} icon="fa-truck"
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Guardando…" : "Guardar"}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div className="field">
          <label className="label">Nombre <R/></label>
          <input className="input" ref={nameRef} placeholder="Ej: TRANS YARA" style={{textTransform:"uppercase", ...errStyle(errors.nombre)}} defaultValue={edit?.nombre}
            onChange={() => errors.nombre && setErrors(e => ({...e, nombre:''}))}/>
          <FieldErr msg={errors.nombre}/>
        </div>
        <div className="row" style={{gap: 8}}>
          <input type="checkbox" id="medio-active" ref={activeRef} defaultChecked={edit ? edit.estado === "ON" : true} style={{accentColor:"var(--accent)"}}/>
          <label htmlFor="medio-active" style={{fontSize: 13, color:"var(--body)"}}>Estado activo (ON)</label>
        </div>
      </div>
    </FormModal>
  );
}

/* ═══════════ MARCA / INDUSTRIA (genérico) ═══════════ */
export function NombreFormModal({ onClose, onSaved, edit, label, icon, onSave }) {
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const nameRef   = useRef();
  const activeRef = useRef();

  const handleSubmit = async () => {
    const e = {};
    if (!nameRef.current?.value?.trim()) e.nombre = `El nombre es requerido`;
    if (Object.keys(e).length > 0) { setErrors(e); return; }
    setErrors({});
    setSaving(true);
    try {
      if (onSave) await onSave({ nombre: nameRef.current.value, estado: activeRef.current.checked ? 'ON' : 'OFF' });
      toast(edit ? `${label} actualizada` : `${label} creada correctamente`, 'success');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      toast(err?.response?.data?.error || 'Error al guardar', 'error');
      logger.error(err);
    }
    finally { setSaving(false); }
  };

  return (
    <FormModal title={edit ? `Editar ${label.toLowerCase()}` : `Nueva ${label.toLowerCase()}`} icon={icon}
      onClose={onClose} onSubmit={handleSubmit} submitLabel={saving ? "Guardando…" : "Guardar"}>
      <div className="stack" style={{"--gap":"14px"}}>
        <div className="field">
          <label className="label">Nombre <R/></label>
          <input className="input" ref={nameRef} placeholder={`Nombre de la ${label.toLowerCase()}`} defaultValue={edit?.nombre}
            style={errStyle(errors.nombre)}
            onChange={() => errors.nombre && setErrors(e => ({...e, nombre:''}))}/>
          <FieldErr msg={errors.nombre}/>
        </div>
        <div className="row" style={{gap: 8}}>
          <input type="checkbox" id="gen-active" ref={activeRef} defaultChecked={edit ? edit.estado === "ON" : true} style={{accentColor:"var(--accent)"}}/>
          <label htmlFor="gen-active" style={{fontSize: 13, color:"var(--body)"}}>Estado activo (ON)</label>
        </div>
      </div>
    </FormModal>
  );
}

Object.assign(window, {
  FormModal,
  CompraFormModal, PedidoFormModal, EnvioFormModal, EnvioEncabezadoModal,
  CotizacionFormModal, CotizacionEncabezadoModal, ProductoFormModal, CuentaFormModal,
  SucursalFormModal, UsuarioFormModal, RolFormModal,
  MedioFormModal, NombreFormModal,
});
