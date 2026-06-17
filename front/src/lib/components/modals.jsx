/**
 * @fileoverview Modales de producto: vista rápida (ProductQuickViewModal)
 * e historial de movimientos (MovimientosModal).
 */

import React, { useState, useEffect } from 'react';
import { productos as prodApi } from '../../services/api.js';
import { Icon, Button, Badge, Empty } from './primitives.jsx';


/**
 * Modal de vista rápida de producto, invocado desde el SearchModal.
 * Muestra código, descripción, precios (C/N/F) y stock actual de la sucursal del usuario.
 * @param {object} props
 * @param {object} props.product - Objeto producto con codigo, descripcion, marca, p_comp, p_norm, p_fact, stock.
 * @param {function(): void} props.onClose - Cierra el modal.
 * @param {function(): void} props.onMovimientos - Abre el modal de movimientos del mismo producto.
 * @param {function(string): void} props.onNav - Navega a una ruta.
 * @returns {JSX.Element}
 */
export function ProductQuickViewModal({ product, onClose, onMovimientos, onNav }) {
  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  const total = product.stock ?? 0;
  const marcaNombre = typeof product.marca === "object" ? product.marca?.nombre : product.marca;

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()} style={{maxWidth: 480}}>
        {/* header */}
        <div style={{padding: "14px 18px", borderBottom:"1px solid var(--line)", display:"flex", alignItems:"center", justifyContent:"space-between"}}>
          <div style={{display:"flex", alignItems:"center", gap: 10}}>
            <span style={{width:30, height:30, borderRadius:"var(--r-sm)", background:"var(--accent-soft)", color:"var(--accent)", display:"grid", placeItems:"center"}}>
              <Icon name="fa-magnifying-glass" style={{fontSize: 12}}/>
            </span>
            <h3 style={{fontSize: 15}}>Búsqueda de producto</h3>
          </div>
          <button className="icon-btn" onClick={onClose} aria-label="Cerrar"><Icon name="fa-xmark"/></button>
        </div>

        {/* body */}
        <div style={{padding: 18}}>
          {/* code header */}
          <div style={{textAlign:"center", padding:"4px 0 14px"}}>
            <div className="display tabular" style={{fontSize: 36, fontWeight: 700, color:"var(--accent)", lineHeight: 1, letterSpacing:"-.02em"}}>{product.codigo}</div>
            <div style={{fontSize: 11, color:"var(--soft)", marginTop: 6, letterSpacing:".06em", textTransform:"uppercase", fontWeight: 700}}>ID · {product.id}</div>
          </div>

          {/* brand + desc */}
          <div style={{textAlign:"center", marginBottom: 14}}>
            <div style={{fontSize: 13, fontWeight: 700, color:"var(--success)", letterSpacing:".04em", textTransform:"uppercase", marginBottom: 4}}>
              {marcaNombre}
            </div>
            <div style={{fontSize: 14, fontWeight: 600, color:"var(--ink)"}}>{product.descripcion}</div>
          </div>

          {/* prices row */}
          <div style={{background:"var(--alt)", border:"1px solid var(--line)", borderRadius:"var(--r-md)", padding:"12px 14px", display:"flex", justifyContent:"space-around", alignItems:"center", marginBottom: 16}}>
            <PriceCol label="Compra" value={product.p_comp} color="var(--warning)" />
            <div style={{width:1, height: 28, background:"var(--line)"}}/>
            <PriceCol label="Normal" value={product.p_norm} color="var(--success)" />
            <div style={{width:1, height: 28, background:"var(--line)"}}/>
            <PriceCol label="Factura" value={product.p_fact} color="var(--accent)" />
          </div>

          {/* stock current + grid por sucursal */}
          <div style={{marginBottom: 14}}>
            <div style={{textAlign:"center", marginBottom: 10}}>
              <div style={{fontSize: 11, fontWeight: 700, color:"var(--soft)", letterSpacing:".08em", textTransform:"uppercase"}}>Stock sucursal activa</div>
              <div className="display tabular" style={{fontSize: 28, fontWeight: 700, marginTop: 4, color: total === 0 ? "var(--danger)" : total <= 5 ? "var(--warning)" : "var(--ink)"}}>{total}</div>
            </div>
            {product.stocks && product.stocks.length > 0 && (
              <div style={{display:"grid", gridTemplateColumns:`repeat(${product.stocks.length}, 1fr)`, gap:6}}>
                {product.stocks.map(s => (
                  <div key={s.id} style={{background:"var(--alt)", border:"1px solid var(--line)", borderRadius:8, padding:"8px 4px", textAlign:"center"}}>
                    <div style={{fontSize:9, fontWeight:700, textTransform:"uppercase", letterSpacing:".06em", color:"var(--dust)", marginBottom:3}}>{s.alias}</div>
                    <div className="mono tabular" style={{fontSize:15, fontWeight:800, color: s.stock < 0 ? "var(--danger)" : s.stock === 0 ? "var(--soft)" : "var(--ink)", lineHeight:1}}>{s.stock}</div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* footer */}
        <div style={{padding: 12, borderTop:"1px solid var(--line)", background:"var(--alt)", display:"flex", gap: 8}}>
          <Button variant="secondary" icon="fa-arrow-left" onClick={onClose} style={{flex:1}}>Salir</Button>
          <Button variant="secondary" icon="fa-eye" onClick={() => { onClose(); onNav("productos"); }} style={{flex:1}}>Ver ficha</Button>
          <Button variant="accent" icon="fa-arrow-right-arrow-left" onClick={onMovimientos} style={{flex:1.4}}>Movimientos</Button>
        </div>
      </div>
    </div>
  );
}


/**
 * Columna de precio para ProductQuickViewModal. Muestra label (C/N/F) y valor formateado.
 * @param {object} props
 * @param {string} props.label - Etiqueta del precio ("C", "N" o "F").
 * @param {string|number|null} props.value - Valor numérico del precio.
 * @param {string} props.color - Color CSS del valor (ej: "var(--accent)").
 * @returns {JSX.Element}
 */
export function PriceCol({ label, value, color }) {
  const num = value == null ? null : parseFloat(String(value).replace(/,/g, ''));
  const display = num == null || isNaN(num) ? "—" : num.toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return (
    <div style={{textAlign:"center", flex:1, minWidth: 0}}>
      <div style={{fontSize: 10, fontWeight: 700, color:"var(--dust)", letterSpacing:".06em", marginBottom: 3}}>{label}</div>
      <div className="mono tabular" style={{fontSize: 15, fontWeight: 700, color}}>{display}</div>
    </div>
  );
}


/**
 * Modal de historial de movimientos de un producto (entradas/salidas).
 * Carga los movimientos desde la API y permite paginación y búsqueda local.
 * @param {object} props
 * @param {object} props.product - Producto con id y codigo/descripcion para el header.
 * @param {function(): void} props.onClose - Cierra el modal.
 * @returns {JSX.Element}
 */
export function MovimientosModal({ product, onClose }) {
  const [movs, setMovs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [q, setQ] = useState("");

  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    prodApi.movimientos(product.id)
      .then(r => {
        const raw = r.data?.data ?? r.data ?? [];
        setMovs(raw.map(m => ({
          id:     m.registro,
          tipo:   m.tipo,
          dir:    m.ingreso !== "-" && m.ingreso ? "in" : "out",
          fecha:  m.fecha,
          cuenta: m.nombre,
          ing:    m.ingreso !== "-" ? m.ingreso : null,
          egr:    m.egreso  !== "-" ? m.egreso  : null,
          costo:  m.costo && m.costo > 0 ? m.costo : null,
        })));
      })
      .catch(() => setMovs([]))
      .finally(() => setLoading(false));
    return () => window.removeEventListener("keydown", onKey);
  }, [product.id]);

  const ql = q.toLowerCase().trim();
  const filtered = ql ? movs.filter(m => (m.cuenta || "").toLowerCase().includes(ql) || (m.tipo || "").toLowerCase().includes(ql) || String(m.id).includes(ql)) : movs;
  const total = filtered.length;
  const pages = Math.max(1, Math.ceil(total / perPage));
  const page2 = Math.min(page, pages);
  const start = (page2 - 1) * perPage;
  const pageItems = filtered.slice(start, start + perPage);

  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()} style={{maxWidth: 880, width:"95vw"}}>
        {/* header */}
        <div style={{padding: "14px 18px", borderBottom:"1px solid var(--line)", display:"flex", alignItems:"center", justifyContent:"space-between"}}>
          <div style={{display:"flex", alignItems:"center", gap: 10, minWidth:0}}>
            <span style={{width:30, height:30, borderRadius:"var(--r-sm)", background:"var(--accent-soft)", color:"var(--accent)", display:"grid", placeItems:"center"}}>
              <Icon name="fa-arrow-right-arrow-left" style={{fontSize: 11}}/>
            </span>
            <div style={{minWidth: 0}}>
              <h3 style={{fontSize: 15}}>Movimientos del producto</h3>
              <div style={{fontSize: 11, color:"var(--soft)", marginTop: 2}}>
                <span className="mono" style={{color:"var(--accent)", fontWeight:700}}>#{product.id} {product.codigo}</span>
                <span style={{margin:"0 6px"}}>·</span>
                <span>{product.descripcion}</span>
              </div>
            </div>
          </div>
          <button className="icon-btn" onClick={onClose} aria-label="Cerrar"><Icon name="fa-xmark"/></button>
        </div>

        {/* filters */}
        <div style={{padding:"12px 18px", display:"flex", alignItems:"center", gap: 10, borderBottom:"1px solid var(--line)", background:"var(--alt)"}}>
          <div style={{display:"flex", alignItems:"center", gap:6}}>
            <span style={{fontSize:11, color:"var(--soft)"}}>Mostrar</span>
            <select className="input" value={perPage} onChange={(e) => { setPerPage(+e.target.value); setPage(1); }}
              style={{width: 64, padding:"6px 8px", fontSize:12}}>
              <option value="5">5</option>
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
          </div>
          <span style={{flex:1}}/>
          <div className="input-group" style={{maxWidth: 240}}>
            <span className="lead-icon"><Icon name="fa-magnifying-glass" style={{fontSize:11}}/></span>
            <input className="input" placeholder="Buscar cuenta o tipo…" value={q} onChange={(e) => setQ(e.target.value)} style={{padding:"6px 10px 6px 30px", fontSize: 12}}/>
          </div>
        </div>

        {/* table */}
        <div className="scroll-area" style={{maxHeight: "55vh"}}>
          {loading ? (
            <div style={{padding: 40, textAlign:"center", color:"var(--soft)"}}>
              <i className="fa-solid fa-spinner fa-spin" style={{fontSize: 20}}/>
            </div>
          ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th style={{width: 70}}>Tipo</th>
                <th style={{width: 70}}>N°</th>
                <th style={{width: 100}}>Fecha</th>
                <th>Cuenta</th>
                {/* "Precio ref." (precio con el que se vendió/compró). Siempre visible: en
                    ventas es el precio de venta; el costo de compra ya viene null del backend
                    para quien no puede ver costos (observación de QA). */}
                <th className="right" style={{width: 100}}>Precio ref.</th>
                <th className="center" style={{width: 60}}>Ing</th>
                <th className="center" style={{width: 60}}>Egr</th>
              </tr>
            </thead>
            <tbody>
              {pageItems.map((m, i) => (
                <tr key={i}>
                  <td><Badge tone={m.dir === "in" ? "success" : "warning"} outline>{m.tipo}</Badge></td>
                  <td><span className="mono" style={{fontWeight: 700, color:"var(--ink)"}}>{m.id}</span></td>
                  <td className="mono" style={{color:"var(--soft)"}}>{m.fecha}</td>
                  <td className="strong">{m.cuenta}</td>
                  <td className="right mono tabular" style={{color: m.costo ? "var(--ink)" : "var(--dust)", fontWeight: m.costo ? 600 : 400}}>{m.costo ? m.costo.toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2}) : "—"}</td>
                  <td className="center mono tabular" style={{color: m.ing ? "var(--success)" : "var(--dust)", fontWeight: m.ing ? 700 : 400}}>{m.ing || "—"}</td>
                  <td className="center mono tabular" style={{color: m.egr ? "var(--warning)" : "var(--dust)", fontWeight: m.egr ? 700 : 400}}>{m.egr || "—"}</td>
                </tr>
              ))}
              {pageItems.length === 0 && <tr><td colSpan={7}><Empty text="Sin movimientos" icon="fa-list"/></td></tr>}
            </tbody>
          </table>
          )}
        </div>

        {/* footer */}
        <div className="pager" style={{borderTop:"1px solid var(--line)"}}>
          <span>Mostrando <strong style={{color:"var(--ink)"}}>{total === 0 ? 0 : start + 1}–{Math.min(start + perPage, total)}</strong> de <strong style={{color:"var(--ink)"}}>{total}</strong></span>
          <div className="pager-btns">
            <button className="pager-btn" disabled={page2 === 1} onClick={() => setPage(1)}><Icon name="fa-angles-left"/></button>
            <button className="pager-btn" disabled={page2 === 1} onClick={() => setPage(p => p - 1)}><Icon name="fa-chevron-left"/></button>
            {Array.from({length: Math.min(5, pages)}, (_, i) => i + 1).map(p => (
              <button key={p} className={`pager-btn ${p === page2 ? "active" : ""}`} onClick={() => setPage(p)}>{p}</button>
            ))}
            <button className="pager-btn" disabled={page2 === pages} onClick={() => setPage(p => p + 1)}><Icon name="fa-chevron-right"/></button>
            <button className="pager-btn" disabled={page2 === pages} onClick={() => setPage(pages)}><Icon name="fa-angles-right"/></button>
          </div>
        </div>
      </div>
    </div>
  );
}
