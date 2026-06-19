/**
 * @fileoverview Buscadores: modal global ⌘K (SearchModal), buscador de productos
 * para documentos (ProductSearchInput) y buscador de cuentas (AccountSearchInput).
 */

import React, { useState, useEffect, useLayoutEffect, useRef } from 'react';
import { productos as prodApi, cuentas as cuentasApi } from '../../services/api.js';
import { canQuickAction } from '../roles.js';
import { Icon, Button, Badge, CopyableCode } from './primitives.jsx';


/**
 * Modal de búsqueda global (⌘K). Busca productos en tiempo real con debounce de 280ms
 * y muestra acciones rápidas cuando no hay query activo.
 * @param {object} props
 * @param {function(): void} props.onClose - Cierra el modal.
 * @param {function(string): void} props.onNav - Navega a una ruta.
 * @param {function(object): void} props.onProductClick - Abre el quick-view de un producto.
 * @param {string[]} props.effectivePermissions - Permisos para filtrar las acciones rápidas.
 * @param {boolean} props.isAdmin - Si es ADMIN (Gate::before).
 * @returns {JSX.Element}
 */
export function SearchModal({ onClose, onNav, onProductClick, effectivePermissions, isAdmin }) {
  const [q, setQ] = useState("");
  const [results, setResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const inputRef = useRef(null);
  const timerRef = useRef(null);

  useEffect(() => {
    inputRef.current?.focus();
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  useEffect(() => {
    clearTimeout(timerRef.current);
    if (!q.trim()) { setResults([]); setSearching(false); return; }
    setSearching(true);
    timerRef.current = setTimeout(() => {
      prodApi.quicksearch(q.trim())
        .then(r => setResults((r.data?.data ?? r.data ?? []).slice(0, 6)))
        .catch(() => setResults([]))
        .finally(() => setSearching(false));
    }, 280);
    return () => clearTimeout(timerRef.current);
  }, [q]);

  // Ancho de ventana → layout responsivo del buscador. En pantallas chicas las 4 columnas
  // (id/código · descripción · stock · precio) no entran y se solapaban (QA); en `compact`
  // cada resultado se apila en tarjeta. Desktop usa un modal más ancho.
  const [vw, setVw] = useState(typeof window !== "undefined" ? window.innerWidth : 1200);
  useEffect(() => {
    const onResize = () => setVw(window.innerWidth);
    window.addEventListener("resize", onResize);
    return () => window.removeEventListener("resize", onResize);
  }, []);
  const compact = vw < 760;

  // Abre el producto SOLO si no hay texto seleccionado: así se puede seleccionar/copiar la
  // descripción (y demás) sin que el clic dispare la navegación (mismo criterio que la tabla).
  const openIfNoSelection = (p) => {
    if (typeof window !== "undefined" && window.getSelection && String(window.getSelection()).length > 0) return;
    onClose(); onProductClick(p);
  };

  const quickActions = [
    { label: "Nueva venta",      icon: "fa-cart-shopping",  route: "venta-nueva",   action: () => { onClose(); onNav("venta-nueva"); } },
    { label: "Nueva cotización", icon: "fa-file-invoice",   route: "cotizaciones",  action: () => { onClose(); onNav("cotizaciones"); } },
    { label: "Registrar compra", icon: "fa-credit-card",    route: "compras",       action: () => { onClose(); onNav("compras"); } },
    { label: "Abrir caja",       icon: "fa-cash-register",  route: "caja",          action: () => { onClose(); onNav("caja"); } },
  ].filter(a => canQuickAction(a.route, effectivePermissions, isAdmin));

  return (
    <div className="overlay" onClick={onClose}>
      {/* Modal ancho en desktop (las 4 columnas necesitan aire); en ≤600px el CSS lo hace
          full-screen. maxWidth solo aplica fuera de mobile. */}
      <div className="modal" onClick={(e) => e.stopPropagation()} style={compact ? undefined : {maxWidth: 880}}>
        <div style={{padding: "12px 16px", borderBottom: "1px solid var(--line)", display:"flex", alignItems:"center", gap: 10}}>
          <Icon name="fa-magnifying-glass" style={{color:"var(--soft)"}}/>
          <input
            ref={inputRef}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Buscar productos, ventas, clientes…"
            style={{flex:1, padding:"8px 0", fontSize: 15, background:"transparent", outline:"none", border:0, color:"var(--ink)"}}
          />
          <span className="kbd" style={{fontFamily:"var(--f-mono)", fontSize:10, background:"var(--muted)", padding:"3px 6px", borderRadius:4, color:"var(--soft)"}}>ESC</span>
        </div>
        <div className="scroll-area" style={{maxHeight: "60vh", padding: 8}}>
          {!q.trim() && (
            <>
              <div style={{padding: "8px 10px", fontSize: 10, fontWeight:700, color:"var(--dust)", letterSpacing:".08em"}}>ACCIONES RÁPIDAS</div>
              {quickActions.map((a, i) => (
                <button key={i} onClick={a.action} style={{display:"flex", alignItems:"center", gap:12, padding:"10px 12px", width:"100%", textAlign:"left", borderRadius:"var(--r-md)", color:"var(--body)"}}
                  onMouseEnter={(e)=>e.currentTarget.style.background="var(--hover)"}
                  onMouseLeave={(e)=>e.currentTarget.style.background=""}>
                  <span style={{width:28, height:28, display:"grid", placeItems:"center", background:"var(--muted)", borderRadius:"var(--r-sm)", color:"var(--accent)"}}>
                    <Icon name={a.icon} />
                  </span>
                  <span style={{flex:1, fontSize:13, fontWeight:500}}>{a.label}</span>
                  <Icon name="fa-arrow-right" style={{fontSize:10, color:"var(--dust)"}}/>
                </button>
              ))}
            </>
          )}
          {searching && (
            <div style={{padding: 24, textAlign:"center", color:"var(--soft)"}}>
              <i className="fa-solid fa-spinner fa-spin" style={{fontSize: 16}}/>
            </div>
          )}
          {!searching && results.length > 0 && (
            <>
              <div style={{padding: "12px 10px 4px", fontSize: 10, fontWeight:700, color:"var(--dust)", letterSpacing:".08em"}}>PRODUCTOS</div>
              {/* Cabecera de columnas: solo en desktop. En compacto cada resultado es una tarjeta apilada. */}
              {!compact && (
                <div style={{display:"flex", alignItems:"center", gap:14, padding:"4px 12px", fontSize:9, fontWeight:700, color:"var(--dust)", letterSpacing:".06em", textTransform:"uppercase", borderBottom:"1px solid var(--line)"}}>
                  <span style={{minWidth:170, flexShrink:0, display:"flex", gap:8}}>
                    <span style={{minWidth:30}}>ID</span>
                    <span style={{minWidth:80}}>Código</span>
                  </span>
                  <span style={{flex:1, minWidth:150}}>Descripción / Marca</span>
                  <span style={{flexShrink:0, textAlign:"center", minWidth:150}}>Stock por sucursal</span>
                  <span style={{flexShrink:0, textAlign:"right", minWidth:96}}>Precio</span>
                </div>
              )}
              {results.map((p) => {
                const st = p.stock ?? 0;
                const statusTone = st === 0 ? "danger" : st <= 5 ? "warning" : "success";
                const statusLabel = st === 0 ? "Agotado" : st <= 5 ? "Stock bajo" : "Disponible";
                const marcaNombre = typeof p.marca === "object" ? p.marca?.nombre : p.marca;
                const porSucursal = Array.isArray(p.stocks) ? p.stocks : null;

                // Piezas reutilizables en ambos layouts (desktop en fila / compacto apilado).
                const idCodigo = (
                  <span style={{display:"flex", alignItems:"center", gap:8, minWidth: compact ? 0 : 170, flexShrink:0}}>
                    <span className="mono" style={{fontSize:10, color:"var(--soft)", fontWeight:500, minWidth:30}}>#{p.id}</span>
                    {/* Código copiable (QA): copiar el código desde el buscador sin abrir el producto. */}
                    <CopyableCode code={p.codigo} style={{minWidth:80}} codeStyle={{fontSize:11, color:"var(--accent)", background:"var(--accent-soft)", padding:"3px 7px", borderRadius:4, fontWeight:700}}/>
                  </span>
                );
                const descripcion = (
                  <span style={{flex:1, minWidth: compact ? 0 : 150, display:"flex", flexDirection:"column"}}>
                    {/* Descripción SELECCIONABLE (QA): poder copiarla como en Productos. userSelect:text
                        + el guard openIfNoSelection evitan que seleccionar dispare la navegación. */}
                    <span style={{fontSize:13, fontWeight:600, color:"var(--body)", lineHeight:1.4, userSelect:"text", cursor:"text"}}>{p.descripcion}</span>
                    <span style={{fontSize:10.5, color:"var(--soft)", fontWeight:600, letterSpacing:".04em", marginTop: 2, userSelect:"text"}}>{marcaNombre}</span>
                  </span>
                );
                const stock = porSucursal && porSucursal.length > 0 ? (
                  // Stock por sucursal (CTR/MTR/TRJ/…), colores uniformes (QA): > 0 negro, = 0 rojo.
                  <span style={{display:"flex", alignItems:"flex-start", gap:7, flexShrink:0, minWidth: compact ? 0 : 150, justifyContent: compact ? "flex-start" : "center", flexWrap:"wrap"}}>
                    {porSucursal.map((s) => (
                      <span key={s.id} title={s.nombre || s.alias} style={{display:"flex", flexDirection:"column", alignItems:"center", minWidth:28}}>
                        <span style={{fontSize:8.5, fontWeight:800, color:"var(--dust)", letterSpacing:".02em", textTransform:"uppercase"}}>{s.alias}</span>
                        <span className="mono tabular" style={{fontSize:14, fontWeight:800, lineHeight:1.15, color: s.stock <= 0 ? "var(--danger)" : "var(--ink)"}}>{s.stock}</span>
                      </span>
                    ))}
                  </span>
                ) : (
                  <span style={{display:"flex", flexDirection:"column", alignItems: compact ? "flex-start" : "center", gap:3, flexShrink:0, minWidth: compact ? 0 : 150}}>
                    <span className="mono tabular" style={{fontSize:14, fontWeight:800, color: st === 0 ? "var(--danger)" : "var(--ink)", lineHeight:1}}>{st}</span>
                    <Badge tone={statusTone} dot>{statusLabel}</Badge>
                  </span>
                );
                const precio = (
                  // Precios de venta (QA): c/f = con factura (p_fact) · s/f = sin factura (p_norm).
                  // El COSTO (p_comp) se muestra SOLO para admin/gerente (pedido de QA): el backend
                  // ya lo manda null para el resto de roles, así que basta con chequear != null.
                  <span style={{display:"flex", flexDirection:"column", alignItems:"flex-end", flexShrink:0, lineHeight:1.25, minWidth: compact ? 0 : 96}}>
                    {p.p_comp != null && (
                      <span className="mono" style={{fontSize:10.5, fontWeight:700, color:"var(--accent)", whiteSpace:"nowrap"}}>
                        Bs {Number(p.p_comp).toFixed(2)} <span style={{fontSize:8, fontWeight:700}}>costo</span>
                      </span>
                    )}
                    <span className="mono" style={{fontSize:12.5, fontWeight:700, color:"var(--ink)", whiteSpace:"nowrap"}}>
                      Bs {Number(p.p_fact ?? 0).toFixed(2)} <span style={{fontSize:8.5, color:"var(--soft)", fontWeight:700}}>c/f</span>
                    </span>
                    <span className="mono" style={{fontSize:11, fontWeight:600, color:"var(--soft)", whiteSpace:"nowrap"}}>
                      Bs {Number(p.p_norm ?? 0).toFixed(2)} <span style={{fontSize:8.5, fontWeight:700}}>s/f</span>
                    </span>
                  </span>
                );

                return compact ? (
                  // ── Compacto/mobile: tarjeta apilada (sin solapes) ──
                  <button key={p.id} onClick={() => openIfNoSelection(p)} style={{display:"flex", flexDirection:"column", gap:7, padding:"12px", width:"100%", textAlign:"left", borderRadius:"var(--r-md)", borderBottom:"1px solid var(--line-soft)"}}
                    onMouseEnter={(e)=>e.currentTarget.style.background="var(--hover)"}
                    onMouseLeave={(e)=>e.currentTarget.style.background=""}>
                    <span style={{display:"flex", alignItems:"flex-start", justifyContent:"space-between", gap:10, width:"100%"}}>
                      {idCodigo}
                      {precio}
                    </span>
                    {descripcion}
                    {stock}
                  </button>
                ) : (
                  // ── Desktop: 4 columnas, alineadas arriba para descripciones de varias líneas ──
                  <button key={p.id} onClick={() => openIfNoSelection(p)} style={{display:"flex", alignItems:"flex-start", gap:14, padding:"10px 12px", width:"100%", textAlign:"left", borderRadius:"var(--r-md)"}}
                    onMouseEnter={(e)=>e.currentTarget.style.background="var(--hover)"}
                    onMouseLeave={(e)=>e.currentTarget.style.background=""}>
                    {idCodigo}
                    {descripcion}
                    {stock}
                    {precio}
                  </button>
                );
              })}
            </>
          )}
          {!searching && q.trim() && results.length === 0 && (
            <div style={{padding: 32, textAlign:"center", color:"var(--dust)", fontSize: 13}}>Sin resultados para "{q}"</div>
          )}
        </div>
        <div style={{padding:"10px 16px", borderTop:"1px solid var(--line)", display:"flex", justifyContent:"space-between", fontSize:11, color:"var(--soft)", background:"var(--alt)"}}>
          <span><span className="mono" style={{background:"var(--surface)", padding:"2px 5px", borderRadius:3, border:"1px solid var(--line)"}}>↑↓</span> navegar</span>
          <span><span className="mono" style={{background:"var(--surface)", padding:"2px 5px", borderRadius:3, border:"1px solid var(--line)"}}>↵</span> abrir</span>
          <span>v3.0 · La Casa Volvo</span>
        </div>
      </div>
    </div>
  );
}


/**
 * Buscador de productos reutilizable para documentos (venta, compra, cotización, pedido, envío).
 * Usa `prodApi.quicksearch` con debounce y muestra un dropdown posicionado absolutamente.
 * @param {object}   props
 * @param {function(object): void} props.onSelect   - Callback con el producto seleccionado.
 * @param {string}   [props.placeholder]             - Texto del input. Por defecto "Buscar producto…".
 * @param {boolean}  [props.disabled=false]          - Deshabilita el input.
 * @param {"p_norm"|"p_comp"|null} [props.priceField=null] - Campo de precio a mostrar en el resultado. null = no mostrar precio.
 * @param {boolean}  [props.bothPrices=false]        - Muestra AMBOS precios de venta (sin factura = p_norm, con factura = p_fact). Usado en ventas (observación de QA: el dropdown mostraba un solo precio). Tiene prioridad sobre priceField.
 * @param {boolean}  [props.showStock=true]          - Mostrar stock disponible con colores.
 * @returns {JSX.Element}
 */
export function ProductSearchInput({ onSelect, placeholder = "Buscar producto…", disabled = false, priceField = null, bothPrices = false, showStock = true }) {
  const [q, setQ]           = useState('');
  const [results, setResults] = useState([]);
  const [open, setOpen]     = useState(false);

  useEffect(() => {
    if (!q) { setResults([]); return; }
    const t = setTimeout(() => {
      prodApi.quicksearch(q)
        .then(r => setResults(Array.isArray(r.data) ? r.data : []))
        .catch(() => setResults([]));
    }, 280);
    return () => clearTimeout(t);
  }, [q]);

  function handleSelect(p) {
    setQ(''); setResults([]); setOpen(false);
    onSelect(p);
  }

  return (
    <div style={{position:"relative"}}>
      <div className="input-group">
        <span className="lead-icon"><Icon name="fa-barcode" style={{fontSize:14}}/></span>
        <input
          className="input"
          placeholder={placeholder}
          disabled={disabled}
          value={q}
          onChange={e => { setQ(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 200)}
          style={{fontSize:14, padding:"12px 12px 12px 38px"}}
        />
      </div>
      {open && results.length > 0 && (
        // maxHeight + scroll: con varios resultados (cada uno con stock por sucursal) el
        // dropdown crecía sin límite y "invadía" la pantalla sobre la tabla de ítems
        // (observación de QA). Ahora flota acotado y con scroll propio. zIndex alto para
        // que quede por encima del documento (tabla/cards).
        <div style={{position:"absolute", left:0, right:0, top:"100%", marginTop:4, background:"var(--surface)", border:"1px solid var(--line)", borderRadius:"var(--r-md)", boxShadow:"var(--sh-lg)", zIndex:40, overflowY:"auto", overflowX:"hidden", maxHeight:340}}>
          {results.map(p => (
            <button key={p.id} onMouseDown={() => handleSelect(p)}
              style={{display:"flex", alignItems:"center", gap:12, padding:"10px 14px", width:"100%", textAlign:"left", borderBottom:"1px solid var(--line-soft)"}}
              onMouseEnter={e => e.currentTarget.style.background = "var(--hover)"}
              onMouseLeave={e => e.currentTarget.style.background = ""}>
              {/* ID + código en un contenedor de ANCHO FIJO: así la descripción de TODAS las
                  filas arranca en la misma x (alineadas, "lineales"). Antes era flex de ancho
                  variable → las descripciones zigzagueaban según el largo del código (QA). */}
              <span style={{display:"flex", alignItems:"center", gap:8, width:148, flexShrink:0}}>
                <span className="mono" style={{fontSize:10, fontWeight:500, color:"var(--soft)", minWidth:26, textAlign:"right"}}>#{p.id}</span>
                <span className="mono" style={{fontSize:11, fontWeight:700, color:"var(--accent)", background:"var(--accent-soft)", padding:"3px 7px", borderRadius:4, minWidth:0, overflow:"hidden", textOverflow:"ellipsis", whiteSpace:"nowrap"}}>{p.codigo}</span>
              </span>
              <span style={{flex:1, minWidth:0}}>
                <span style={{fontSize:13, fontWeight:500, color:"var(--body)", display:"block", lineHeight:1.3}}>{p.descripcion}</span>
                {p.marca && <span style={{fontSize:10.5, color:"var(--soft)", fontWeight:600, letterSpacing:".04em"}}>{typeof p.marca === 'object' ? p.marca.nombre : p.marca}</span>}
              </span>
              {showStock && (
                Array.isArray(p.stocks) && p.stocks.length > 0 ? (
                  // Stock por sucursal (todas), no solo la actual — atajo del sistema viejo.
                  // Colores uniformes (QA): > 0 negro, = 0 rojo.
                  <span style={{display:"flex", gap:6, flexShrink:0}}>
                    {p.stocks.map(s => (
                      <span key={s.id} title={s.nombre || s.alias} style={{display:"flex", flexDirection:"column", alignItems:"center", minWidth:24}}>
                        <span style={{fontSize:8, fontWeight:800, color:"var(--dust)", textTransform:"uppercase"}}>{s.alias}</span>
                        <span className="mono" style={{fontSize:12, fontWeight:800, lineHeight:1.1, color: s.stock <= 0 ? "var(--danger)" : "var(--ink)"}}>{s.stock}</span>
                      </span>
                    ))}
                  </span>
                ) : (
                  <span className="mono" style={{fontSize:11, fontWeight:700, color: p.stock > 0 ? "var(--ink)" : "var(--danger)"}}>
                    {p.stock} disp.
                  </span>
                )
              )}
              {bothPrices ? (
                // Ambos precios de venta: con factura (p_fact) destacado y sin factura
                // (p_norm) debajo. NO se muestra p_comp (costo) — eso solo ADMIN/GERENTE.
                <span style={{display:"flex", flexDirection:"column", alignItems:"flex-end", flexShrink:0, lineHeight:1.25, minWidth:90}}>
                  <span className="mono" style={{fontSize:12.5, fontWeight:700, color:"var(--ink)", whiteSpace:"nowrap"}}>
                    Bs {Number(p.p_fact).toFixed(2)} <span style={{fontSize:8.5, color:"var(--soft)", fontWeight:700}}>c/f</span>
                  </span>
                  <span className="mono" style={{fontSize:11, fontWeight:600, color:"var(--soft)", whiteSpace:"nowrap"}}>
                    Bs {Number(p.p_norm).toFixed(2)} <span style={{fontSize:8.5, fontWeight:700}}>s/f</span>
                  </span>
                </span>
              ) : priceField && (
                <span className="mono" style={{fontSize:12, fontWeight:700, color:"var(--ink)"}}>{p[priceField] != null ? `Bs ${Number(p[priceField]).toFixed(2)}` : '—'}</span>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}


/**
 * Buscador de cuentas (clientes/proveedores) reutilizable para documentos y modales.
 * Encapsula debounce, dropdown posicionado absolutamente, shortcut "SIN NOMBRE"
 * y paginación opcional. Usado en VentaNueva, CompraDetail, CompraFormModal y CotizacionFormModal.
 *
 * @param {object}   props
 * @param {function(object): void} props.onSelect       - Callback con la cuenta elegida { id, nombre, nit, tipo }.
 * @param {"CLIENTE"|"PROVEEDOR"|null} [props.tipoFiltro=null] - Filtra por tipo. "PROVEEDOR" → usa `todos:1` + filtro client-side PROVEEDOR/CLIE-PROV. null/"CLIENTE" → sin filtro adicional.
 * @param {boolean}  [props.showSinNombre=false]        - Muestra el acceso rápido "CLIENTE SIN NOMBRE" (id:6) cuando skip===0 y query vacía.
 * @param {string}   [props.placeholder="Buscar cuenta…"] - Placeholder del input.
 * @param {number}   [props.take=5]                     - Resultados por página. 0 = sin paginación (toma todos).
 * @param {boolean}  [props.disabled=false]             - Deshabilita el input.
 * @param {boolean}  [props.autoFocus=false]            - Hace autofocus al input al montar.
 * @returns {JSX.Element}
 */
export function AccountSearchInput({ onSelect, tipoFiltro = null, showSinNombre = false, placeholder = "Buscar cuenta…", take = 5, disabled = false, autoFocus = false }) {
  const [q, setQ]           = useState('');
  const [results, setResults] = useState([]);
  const [total, setTotal]   = useState(0);
  const [skip, setSkip]     = useState(0);
  const [open, setOpen]     = useState(false);
  const inputRef            = useRef(null);

  // Forzar foco al montar cuando autoFocus=true — useLayoutEffect es síncrono post-DOM,
  // más fiable que useEffect+rAF para re-montajes condicionales.
  useLayoutEffect(() => {
    if (!autoFocus || !inputRef.current) return;
    inputRef.current.focus();
    setOpen(true);
  }, []); // solo al montar (cada instancia nueva con key distinta)

  // Búsqueda con debounce de 250ms
  useEffect(() => {
    if (!open) { setResults([]); return; }
    const t = setTimeout(() => {
      const effectiveTake = take || 8;
      const params = { search: q, skip, take: effectiveTake };
      // Proveedores necesitan `todos:1` porque la API por defecto solo retorna clientes
      if (tipoFiltro === 'PROVEEDOR') params.todos = 1;
      cuentasApi.list(params).then(r => {
        let data = Array.isArray(r.data) ? r.data : (r.data?.data ?? []);
        // Filtro client-side para proveedores (PROVEEDOR + CLIE-PROV)
        if (tipoFiltro === 'PROVEEDOR') {
          data = data.filter(c => c.tipo === 'PROVEEDOR' || c.tipo === 'CLIE-PROV');
        }
        setResults(data);
        setTotal(r.data?.total ?? data.length);
      }).catch(() => { setResults([]); setTotal(0); });
    }, 250);
    return () => clearTimeout(t);
  }, [q, open, skip, tipoFiltro, take]);

  /** Selecciona una cuenta, limpia el estado interno y propaga al padre. */
  function handleSelect(c) {
    setQ(''); setResults([]); setOpen(false); setSkip(0);
    onSelect(c);
  }

  const hasPages = take > 0 && total > (take || 8);
  // Filtrar id:6 de la lista normal (ya se muestra como shortcut arriba)
  const filteredResults = showSinNombre ? results.filter(c => c.id !== 6) : results;

  return (
    <div style={{position:"relative"}}>
      <div className="input-group">
        <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:11}}/></span>
        <input
          ref={inputRef}
          className="input"
          placeholder={placeholder}
          disabled={disabled}
          value={q}
          onChange={e => { setQ(e.target.value); setSkip(0); }}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 200)}
          style={{fontSize:12}}
        />
      </div>
      {open && (
        <div style={{position:"absolute", left:0, right:0, top:"100%", marginTop:4, background:"var(--surface)", border:"1px solid var(--line)", borderRadius:"var(--r-md)", boxShadow:"var(--sh-lg)", zIndex:20, overflow:"hidden"}}>
          {/* Shortcut SIN NOMBRE — solo al inicio, sin búsqueda activa */}
          {showSinNombre && skip === 0 && !q && (
            <button type="button" onMouseDown={() => handleSelect({ id: 6, nombre: "SIN NOMBRE", nit: "0" })}
              style={{display:"flex", alignItems:"center", gap:8, padding:"8px 10px", width:"calc(100% - 12px)", textAlign:"left", background:"var(--surface)", border:"2px solid var(--line)", borderRadius:"var(--r-sm)", margin:6, fontSize:12.5}}>
              <div style={{width:24, height:24, borderRadius:12, background:"var(--accent-soft)", color:"var(--accent)", display:"flex", alignItems:"center", justifyContent:"center", fontSize:10, fontWeight:700, flexShrink:0}}>SN</div>
              <span style={{flex:1, fontWeight:700, color:"var(--ink)", fontSize:12.5}}>CLIENTE SIN NOMBRE</span>
              <span className="mono" style={{fontSize:10.5, color:"var(--soft)"}}>NIT 0</span>
            </button>
          )}
          {/* Resultados */}
          {filteredResults.map(c => (
            <button key={c.id} type="button" onMouseDown={() => handleSelect(c)}
              style={{display:"flex", alignItems:"center", gap:8, padding:"8px 10px", width:"100%", textAlign:"left", borderBottom:"1px solid var(--line-soft)", fontSize:12.5}}
              onMouseEnter={e => e.currentTarget.style.background = "var(--hover)"}
              onMouseLeave={e => e.currentTarget.style.background = ""}>
              <span style={{flex:1, fontWeight:600, color:"var(--body)"}}>{c.nombre}</span>
              {c.nit && <span className="mono" style={{fontSize:10.5, color:"var(--soft)"}}>{c.nit}</span>}
            </button>
          ))}
          {/* Sin resultados */}
          {q.length > 1 && filteredResults.length === 0 && (
            <div style={{fontSize:12, color:"var(--soft)", padding:"10px 12px", textAlign:"center"}}>Sin resultados</div>
          )}
          {/* Paginación — onMouseDown + preventDefault evita que el blur cierre el dropdown */}
          {hasPages && (
            <div style={{display:"flex", alignItems:"center", justifyContent:"space-between", padding:"8px 10px", borderTop:"1px solid var(--line-soft)", background:"var(--alt)"}}>
              <Button variant="ghost" size="sm" disabled={skip === 0}
                onMouseDown={(e) => { e.preventDefault(); setSkip(s => Math.max(0, s - take)); }}>Anterior</Button>
              <span className="mono" style={{fontSize:11, color:"var(--soft)"}}>
                {Math.min(skip + take, total)} / {total}
              </span>
              <Button variant="ghost" size="sm" disabled={skip + take >= total}
                onMouseDown={(e) => { e.preventDefault(); setSkip(s => s + take); }}>Siguiente</Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}


/**
 * Combo con búsqueda por tipeo para listas chicas (medios, etc.). Reemplaza un `<select>`
 * cuando el usuario quiere escribir y filtrar (pedido de QA en Envíos → medio de transporte),
 * en vez de scrollear el desplegable nativo.
 *
 * @param {Object}   props
 * @param {Array<{id:(number|string), nombre:string}>} props.options  Opciones a elegir.
 * @param {(number|string|null)} props.value      Id seleccionado (o null/'' sin selección).
 * @param {(id:string)=>void}    props.onChange   Callback con el id elegido (string).
 * @param {string}  [props.placeholder]           Texto del input vacío.
 * @param {boolean} [props.invalid]               Marca el borde en rojo (validación).
 * @param {boolean} [props.disabled]
 * @returns {JSX.Element}
 */
export function ComboSelect({ options = [], value, onChange, placeholder = 'Buscar…', invalid = false, disabled = false }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const boxRef = useRef(null);
  const selected = options.find(o => String(o.id) === String(value)) || null;

  // Cerrar al hacer clic fuera.
  useEffect(() => {
    const onDoc = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) { setOpen(false); setQuery(''); } };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  const q = query.trim().toLowerCase();
  const filtered = q ? options.filter(o => (o.nombre || '').toLowerCase().includes(q)) : options;

  const pick = (o) => { onChange(String(o.id)); setOpen(false); setQuery(''); };

  return (
    <div ref={boxRef} style={{ position: 'relative' }}>
      <div className="input-group">
        <Icon name="fa-solid fa-magnifying-glass" className="lead-icon" />
        <input
          className="input"
          disabled={disabled}
          placeholder={selected ? selected.nombre : placeholder}
          value={open ? query : (selected ? selected.nombre : '')}
          onFocus={() => { if (!disabled) { setOpen(true); setQuery(''); } }}
          onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
          style={invalid ? { borderColor: 'var(--danger)' } : undefined}
          aria-label={placeholder}
        />
      </div>
      {open && (
        <div className="card" style={{
          position: 'absolute', top: 'calc(100% + 4px)', left: 0, right: 0, zIndex: 60,
          maxHeight: 220, overflowY: 'auto', padding: 4, boxShadow: '0 8px 24px rgba(15,23,42,.14)'
        }}>
          {filtered.length === 0 ? (
            <div style={{ padding: '10px 12px', fontSize: 13, color: 'var(--soft)' }}>Sin coincidencias</div>
          ) : filtered.map(o => {
            const sel = String(o.id) === String(value);
            return (
              <button key={o.id} type="button" onMouseDown={(e) => { e.preventDefault(); pick(o); }}
                style={{
                  display: 'block', width: '100%', textAlign: 'left', padding: '8px 12px',
                  borderRadius: 'var(--r-md)', border: 'none', cursor: 'pointer', fontSize: 13,
                  background: sel ? 'var(--accent-a15)' : 'transparent',
                  color: sel ? 'var(--accent)' : 'var(--ink)', fontWeight: sel ? 700 : 500
                }}
                onMouseEnter={(e) => { if (!sel) e.currentTarget.style.background = 'var(--bg)'; }}
                onMouseLeave={(e) => { if (!sel) e.currentTarget.style.background = 'transparent'; }}>
                {o.nombre}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
