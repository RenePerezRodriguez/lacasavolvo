/**
 * @fileoverview Componentes de tablas y paginación: DataTable (con ordenamiento
 * y data-labels para responsive), Pager y PageSizeSelector.
 */

import React from 'react';
import { Icon, Empty } from './primitives.jsx';


/**
 * Componente de paginación para listas server-side.
 * @param {object} props
 * @param {number} props.from - Primer registro de la página actual (1-based).
 * @param {number} props.to - Último registro de la página actual.
 * @param {number} props.total - Total de registros en la colección.
 * @param {number} props.page - Página actual (1-based).
 * @param {number} props.pages - Total de páginas.
 * @param {function(number): void} props.onPage - Callback al cambiar de página.
 * @returns {JSX.Element}
 */
export function Pager({ from, to, total, page, pages, onPage }) {
  if (!pages || pages < 1) pages = 1;
  let start = Math.max(1, page - 2);
  let end   = Math.min(pages, start + 4);
  if (end - start < 4) start = Math.max(1, end - 4);
  const nums = Array.from({ length: end - start + 1 }, (_, i) => start + i);
  return (
    <div className="pager">
      <span>Mostrando <strong style={{color:"var(--ink)"}}>{from}–{to}</strong> de <strong style={{color:"var(--ink)"}}>{total.toLocaleString()}</strong></span>
      <div className="pager-btns">
        <button className="pager-btn" disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label="Página anterior" title="Página anterior"><Icon name="fa-chevron-left" /></button>
        {start > 1 && <>
          <button className="pager-btn" onClick={() => onPage(1)} aria-label="Página 1">1</button>
          {start > 2 && <span className="pager-btn" style={{cursor:"default",pointerEvents:"none"}}>…</span>}
        </>}
        {nums.map(p => (
          <button key={p} className={`pager-btn ${p === page ? "active" : ""}`} onClick={() => onPage(p)} aria-label={`Página ${p}`} aria-current={p === page ? "page" : undefined}>{p}</button>
        ))}
        {end < pages && <>
          {end < pages - 1 && <span className="pager-btn" style={{cursor:"default",pointerEvents:"none"}}>…</span>}
          <button className="pager-btn" onClick={() => onPage(pages)} aria-label={`Página ${pages}`}>{pages}</button>
        </>}
        <button className="pager-btn" disabled={page >= pages} onClick={() => onPage(page + 1)} aria-label="Página siguiente" title="Página siguiente"><Icon name="fa-chevron-right" /></button>
      </div>
    </div>
  );
}


/**
 * Selector de cantidad de registros por página.
 * @param {object} props
 * @param {number} props.value - Cantidad seleccionada actualmente.
 * @param {function(number): void} props.onChange - Callback al cambiar el tamaño de página.
 * @returns {JSX.Element}
 */
export function PageSizeSelector({ value, onChange }) {
  return (
    <select className="input" style={{width:"auto",minWidth:90}} value={value}
      aria-label="Registros por página"
      title="Registros por página"
      onChange={e => onChange(Number(e.target.value))}>
      {[15, 30, 50, 100].map(n => <option key={n} value={n}>{n} / pág.</option>)}
    </select>
  );
}


/**
 * Tabla de datos reutilizable con soporte nativo para ordenamiento de columnas (Server-side o Client-side).
 * @param {object} props
 * @param {Array} props.data - Array de objetos a renderizar.
 * @param {Array} props.columns - Configuración de columnas: [{ key: 'id', title: '#', render: (row) => JSX, sortable: true, className: "right", width: 80 }]
 * @param {string} [props.sortCol] - Llave de la columna ordenada actualmente.
 * @param {string} [props.sortDir] - Dirección del ordenamiento ("asc" o "desc").
 * @param {function(string, string): void} [props.onSort] - Callback al hacer click en una columna ordenable.
 * @param {function(object): void} [props.onRowClick] - Evento al hacer click en la fila entera.
 * @param {string} [props.className="tbl"] - Clase base de la tabla.
 * @returns {JSX.Element}
 */
export function DataTable({ data, columns, sortCol, sortDir, onSort, onRowClick, className="tbl" }) {
  const handleSort = (key) => {
    if (!onSort) return;
    if (sortCol === key) onSort(key, sortDir === "asc" ? "desc" : "asc");
    else onSort(key, "desc");
  };

  return (
    <table className={className}>
      <thead>
        <tr>
          {columns.map(c => (
            <th key={c.key || c.title} 
                className={`${c.className || ''} ${c.sortable ? 'sortable' : ''}`}
                style={{width: c.width, cursor: c.sortable ? "pointer" : "default"}}
                title={c.sortable ? `Ordenar por ${c.title}` : c.tooltip}
                onClick={() => c.sortable && handleSort(c.key)}>
              <span style={{display: "inline-flex", alignItems: "center", gap: 4}}>
                {c.title}
                {c.sortable && sortCol === c.key && (
                  <Icon name={sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down'} style={{fontSize: 11, color: "var(--accent)"}}/>
                )}
                {c.sortable && sortCol !== c.key && (
                  <Icon name="fa-sort" style={{fontSize: 11, color: "var(--line)"}}/>
                )}
              </span>
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {data.map((row, i) => (
          <tr key={row.uid ?? row.id ?? i}
              onClick={(e) => {
                if (!onRowClick) return;
                // No navegar si el usuario está SELECCIONANDO texto: así puede seleccionar y
                // copiar los datos de la fila (descripción, ID, código, marca) sin que la fila
                // salte al detalle. Observación de QA: al intentar copiar, la fila navegaba.
                if (typeof window !== "undefined" && window.getSelection && String(window.getSelection()).length > 0) return;
                onRowClick(row, e);
              }}
              style={{cursor: onRowClick ? "pointer" : "default"}}>
            {columns.map((c, j) => (
              <td key={c.key || j} className={c.className || ''} data-label={c.title}>
                {c.render ? c.render(row) : row[c.key]}
              </td>
            ))}
          </tr>
        ))}
        {data.length === 0 && (
          <tr>
            <td colSpan={columns.length}><Empty text="Sin registros que mostrar" /></td>
          </tr>
        )}
      </tbody>
    </table>
  );
}
