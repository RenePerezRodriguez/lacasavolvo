/**
 * @fileoverview Pantalla de ajustes manuales de stock con modal de creación.
 */

import React, { useState, useEffect, useRef } from 'react';
import { useListData, useColumnVisibility } from '../lib/hooks.js';
import logger from '../lib/logger.js';
import { Icon, Button, Badge, KPI, Empty, PageHead, Pager, PageSizeSelector, ProductSearchInput, useToast } from '../lib/components.jsx';
import { productos as prodApi, apiErrorMsg } from '../services/api.js';

/**
 * Modal para registrar un ajuste positivo o negativo de stock.
 * @param {object} props
 * @param {function(): void} props.onClose - Cierra el modal.
 * @param {function(): void} props.onSaved - Se llama tras guardar exitosamente.
 * @returns {JSX.Element}
 */
export function AjusteModal({ onClose, onSaved }) {
  const toast = useToast();
  const [tipo, setTipo]         = useState("POSITIVO");
  const [producto, setProducto] = useState(null);
  const [saving, setSaving]     = useState(false);
  const cantidadRef = useRef();
  const obsRef      = useRef();

  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  const handleSubmit = async () => {
    if (!producto) return;
    setSaving(true);
    try {
      const data = { producto_id: producto.id, cantidad: cantidadRef.current.value, observacion: obsRef.current.value };
      if (tipo === "POSITIVO") await prodApi.ajustePositivo(data);
      else await prodApi.ajusteNegativo(data);
      onSaved && onSaved();
    } catch (e) {
      logger.error(e);
      // El backend rechaza (422) un ajuste negativo que dejaría el stock por debajo de 0;
      // surfacea ese mensaje en vez de fallar en silencio (antes solo se logueaba).
      const msg = apiErrorMsg(e, "No se pudo registrar el ajuste.");
      toast(msg, "error");
    }
    finally { setSaving(false); }
  };

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e)=>e.stopPropagation()} style={{maxWidth: 520}}>
        <div style={{padding: "14px 18px", borderBottom:"1px solid var(--line)", display:"flex", alignItems:"center", justifyContent:"space-between"}}>
          <h3 style={{fontSize: 15}}>Nuevo ajuste de stock</h3>
          <button className="icon-btn" onClick={onClose} aria-label="Cerrar"><Icon name="fa-xmark"/></button>
        </div>
        <div style={{padding: 20, display:"flex", flexDirection:"column", gap: 14}}>
          <div className="field"><label className="label">Buscar producto</label>
            {producto ? (
              <div style={{display:"flex", alignItems:"center", gap:10, padding:"10px 14px", background:"var(--accent-soft)", border:"1px solid var(--accent)", borderRadius:"var(--r-md)"}}>
                <span className="mono" style={{fontSize:10, fontWeight:500, color:"var(--soft)"}}>#{producto.id}</span>
                <span className="mono" style={{fontSize:12, fontWeight:700, color:"var(--accent)"}}>{producto.codigo}</span>
                <span style={{flex:1, fontSize:13, fontWeight:600, color:"var(--ink)"}}>{producto.descripcion}</span>
                <button className="icon-btn" onClick={() => setProducto(null)} title="Quitar"><Icon name="fa-times" style={{fontSize:11}}/></button>
              </div>
            ) : (
              <ProductSearchInput onSelect={setProducto} placeholder="Código o descripción…" showStock={true} />
            )}
          </div>
          <div className="field"><label className="label">Tipo de ajuste</label>
            <div className="row" style={{gap:8}}>
              {["POSITIVO","NEGATIVO"].map(t => (
                <button key={t} type="button" onClick={()=>setTipo(t)}
                  style={{flex:1, padding:"10px", borderRadius:"var(--r-md)", border: tipo===t ? "2px solid var(--accent)" : "2px solid var(--line)", background: tipo===t ? "var(--accent-soft)" : "var(--surface)", color: tipo===t ? "var(--accent)" : "var(--body)", fontSize:12, fontWeight:700}}>
                  <Icon name={t==="POSITIVO" ? "fa-plus" : "fa-minus"} style={{marginRight:6, fontSize:11}}/>{t}
                </button>
              ))}
            </div>
          </div>
          <div className="field"><label className="label">Cantidad</label>
            <input className="input mono" type="number" min="1" defaultValue="1" ref={cantidadRef} placeholder="0"/>
          </div>
          <div className="field"><label className="label">Observación</label>
            <textarea className="input" rows="3" ref={obsRef} placeholder="Inventario físico, conteo cíclico, daño…"></textarea>
          </div>
        </div>
        <div style={{padding: 12, borderTop:"1px solid var(--line)", background:"var(--alt)", display:"flex", gap: 8, justifyContent:"flex-end"}}>
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="accent" icon="fa-check" onClick={handleSubmit}>{saving ? "Guardando…" : "Registrar ajuste"}</Button>
        </div>
      </div>
    </div>
  );
}

/**
 * Historial de ajustes manuales de stock con opciones para crear y anular ajustes.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Navegación.
 * @param {number} props.sucursalId - ID de sucursal activa.
 * @returns {JSX.Element}
 */
export function Ajustes({ onNav, sucursalId, user, effectivePermissions }) {
  const [q, setQ]                   = useState("");
  const [tipo, setTipo]             = useState("TODOS");
  const [skip, setSkip]             = useState(0);
  const [modalOpen, setModalOpen]   = useState(false);
  const [pageSize, setPageSize]     = useState(15);
  const { hiddenCols, toggleCol, visibleCols, showCols, setShowCols } = useColumnVisibility('ajustes', []);
  // El botón "Nuevo ajuste" se gatea con `productos.ajustes` — el MISMO permiso que abre
  // esta pantalla (y que pide el backend en /productos/ajuste-*). Antes chequeaba
  // 'productos.ajuste-positivo'/'-negativo' (con guion), permisos que NO existen
  // (los reales son productos.ajustes / .ajustepositivo) → canCreate era SIEMPRE false
  // y nadie, ni gerente ni admin, veía el botón (bug reportado).
  const canCreate = (effectivePermissions || []).some(p => p === 'productos.ajustes');

  const { items: ajustes, total, loading, reload } = useListData(
    params => prodApi.ajustes(params), null,
    () => ({ skip, take: pageSize, ...(q && { search: q }), ...(tipo !== "TODOS" && { tipo }) }),
    [skip, q, tipo, pageSize, sucursalId]
  );

  const positivos = ajustes.filter(a => a.tipo === "POSITIVO").reduce((s,a)=>s+a.cantidad,0);
  const negativos = ajustes.filter(a => a.tipo === "NEGATIVO").reduce((s,a)=>s+a.cantidad,0);
  const page  = Math.floor(skip / pageSize) + 1;
  const pages = Math.ceil(total / pageSize);
  const handlePageSize = (n) => { setPageSize(n); setSkip(0); };

  return (
    <div className="fade-up stack" style={{"--gap":"24px"}}>
      <PageHead title="Ajustes de stock" sub="Correcciones de inventario por sucursal"
        actions={canCreate ? <Button variant="accent" icon="fa-plus" size="sm" onClick={() => setModalOpen(true)}>Nuevo ajuste</Button> : null}
      />
      <div className="grid-4">
        <KPI label="Total ajustes" value={total} icon="fa-balance-scale"/>
        <KPI label="Ingresos (positivo)" value={positivos} icon="fa-arrow-down"/>
        <KPI label="Egresos (negativo)" value={negativos} icon="fa-arrow-up"/>
        <KPI label="Página" value={`${page}/${Math.max(1,pages)}`}/>
      </div>
      <div className="card">
        <div style={{padding: 14, display:"flex", gap:10, flexWrap:"wrap", alignItems:"flex-end"}}>
          <div style={{flex:1, minWidth: 200}}>
            <div className="filter-label">Búsqueda</div>
            <div className="input-group">
              <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:12}}/></span>
              <input className="input" placeholder="Buscar código o descripción…" value={q} onChange={e=>{setQ(e.target.value); setSkip(0);}}/>
            </div>
          </div>
          <div>
            <div className="filter-label">Tipo</div>
            <div className="seg-tabs">
              {["TODOS","POSITIVO","NEGATIVO"].map(t => (
                <button key={t} className={`seg ${tipo === t ? "active" : ""}`} onClick={()=>{setTipo(t); setSkip(0);}}>
                  {t === "TODOS" ? "Todos" : t[0]+t.slice(1).toLowerCase()}
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
            <button className="btn btn-ghost btn-sm" title="Mostrar u ocultar columnas" aria-label="Mostrar u ocultar columnas" onClick={() => setShowCols(!showCols)} style={{whiteSpace:"nowrap"}}>
              <Icon name="fa-columns" style={{fontSize:10,marginRight:4}}/>
            </button>
          </div>
        </div>
        {loading ? (
          <div style={{padding:40, textAlign:"center", color:"var(--soft)"}}><Icon name="fa-spinner fa-spin" style={{fontSize:20}}/></div>
        ) : (
          <table className="tbl">
            <thead><tr>
              <th style={{width:80}}>#</th><th style={{width:110}}>Fecha</th>
              <th style={{width:130}}>Código</th><th>Descripción</th>
              <th style={{width:100}}>Marca</th>
              <th className="center" style={{width:90}}>Tipo</th>
              <th className="right" style={{width:80}}>Cant.</th>
              <th>Observación</th>
            </tr></thead>
            <tbody>
              {ajustes.map(a => (
                <tr key={a.id}>
                  <td><span className="mono" style={{fontWeight:700, color:"var(--ink)"}}>#{a.id}</span></td>
                  <td className="num">{a.fecha}</td>
                  <td><span className="mono" style={{fontSize:11, fontWeight:700, color:"var(--accent)"}}>#{a.producto_id ?? a.id} {a.codigo}</span></td>
                  <td className="strong">{a.descripcion}</td>
                  <td className="text-soft">{a.marca}</td>
                  <td className="center"><Badge tone={a.tipo === "POSITIVO" ? "success" : "warning"} dot>{a.tipo}</Badge></td>
                  <td className="right mono tabular" style={{fontWeight:700, color: a.tipo === "POSITIVO" ? "var(--success)" : "var(--warning)"}}>
                    {a.tipo === "POSITIVO" ? "+" : "-"}{a.cantidad}
                  </td>
                  <td className="text-soft">{a.observacion || "—"}</td>
                </tr>
              ))}
              {ajustes.length === 0 && <tr><td colSpan="8"><Empty text="Sin ajustes que mostrar" icon="fa-balance-scale"/></td></tr>}
            </tbody>
          </table>
        )}
        <Pager from={skip+1} to={Math.min(skip+pageSize,total)} total={total} page={page} pages={pages} onPage={p=>setSkip((p-1)*pageSize)}/>
      </div>
      {modalOpen && <AjusteModal onClose={() => setModalOpen(false)} onSaved={() => { setModalOpen(false); reload(); }}/>}
    </div>
  );
}
