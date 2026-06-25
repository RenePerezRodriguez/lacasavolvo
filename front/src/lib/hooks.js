/**
 * @fileoverview Hooks reutilizables de la aplicación.
 * Encapsulan patrones comunes de carga de datos para pantallas de lista.
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import logger from './logger.js';
import { matchesQuery } from './textSearch.js';

/**
 * Hook para persistir preferencia de columnas visibles/ocultas por módulo.
 * Guarda en localStorage para recordar la elección del usuario entre sesiones.
 *
 * @param {string} moduleKey - Identificador único del módulo (ej: 'ventas', 'compras').
 * @param {string[]} defaultHidden - Keys de columnas ocultas por defecto.
 * @returns {{ hiddenCols: Set, toggleCol: (key:string)=>void, visibleCols: (cols:object[])=>object[], showCols: boolean, setShowCols: (v:boolean)=>void }}
 */
export function useColumnVisibility(moduleKey, defaultHidden = []) {
  const storageKey = `lcv_cols_${moduleKey}`;
  const [hiddenCols, setHiddenCols] = useState(() => {
    try {
      const saved = localStorage.getItem(storageKey);
      if (saved) return new Set(JSON.parse(saved));
    } catch { /* ignore */ }
    return new Set(defaultHidden);
  });
  const [showCols, setShowCols] = useState(false);

  useEffect(() => {
    localStorage.setItem(storageKey, JSON.stringify([...hiddenCols]));
  }, [hiddenCols, storageKey]);

  const toggleCol = (key) => {
    setHiddenCols(prev => { const n = new Set(prev); n.has(key) ? n.delete(key) : n.add(key); return n; });
  };

  const visibleCols = (cols) => cols.filter(c => !hiddenCols.has(c.key));

  return { hiddenCols, toggleCol, visibleCols, showCols, setShowCols };
}

/**
 * Hook para pantallas de lista con paginación, KPIs opcionales y recarga bajo demanda.
 * Gestiona internamente los estados de items, total, kpis y loading.
 *
 * @param {function(object): Promise} apiList - Función que recibe los params y retorna la promesa de lista.
 * @param {function(): Promise|null} apiKpis - Función que retorna los KPIs, o null si no aplica.
 * @param {function(): object} getParams - Función que devuelve los parámetros actuales (skip, search, etc.).
 * @param {Array} deps - Dependencias de React que disparan una recarga (ej: [sucursalId, skip, q]).
 * @returns {{ items: Array, total: number, kpis: object|null, loading: boolean, reload: function }}
 */
export function useListData(apiList, apiKpis, getParams, deps) {
  const [items,   setItems]   = useState([]);
  const [total,   setTotal]   = useState(0);
  const [kpis,    setKpis]    = useState(null);
  const [loading, setLoading] = useState(true);
  // Guard de secuencia: si los params cambian rápido (ej. tipeo en el buscador),
  // descartamos respuestas que llegan fuera de orden para no pisar datos frescos.
  const seqRef = useRef(0);

  const run = useCallback(() => {
    setLoading(true);
    const seq = ++seqRef.current;
    (apiKpis
      ? Promise.all([apiList(getParams()), apiKpis()])
      : apiList(getParams()).then(r => [r, null])
    ).then(([lr, kr]) => {
      if (seq !== seqRef.current) return; // respuesta obsoleta: la ignoramos
      setItems(lr.data.data ?? []);
      setTotal(lr.data.total ?? 0);
      if (kr) setKpis(kr.data);
    }).catch(logger.error).finally(() => { if (seq === seqRef.current) setLoading(false); });
  }, deps); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { run(); }, [run]);

  return { items, total, kpis, loading, reload: run };
}

/**
 * Filtra (SOLO para visualización) los renglones YA AGREGADOS a un documento por código,
 * descripción, marca o ID. Tokeniza por palabras (cada palabra debe aparecer — AND), ignora
 * conectores (de/con/…) y es insensible a acentos/mayúsculas, igual que el backend
 * (ver textSearch.js / SearchHelper). El '#' inicial se ignora ("#635" == "635").
 *
 * ⚠️ NO altera totales: el documento SIEMPRE suma sobre la lista COMPLETA de `detalles`,
 * nunca sobre el resultado de este filtro (que es solo para mostrar/encontrar un renglón).
 *
 * @param {Array<object>} detalles - Renglones del documento (codigo/descripcion/marca/producto_id|id).
 * @param {string} query - Texto de filtro.
 * @returns {Array<object>} Subconjunto de `detalles` que coincide (mismas referencias).
 */
export function filterDetalles(detalles, query) {
  if (!query || !query.trim()) return detalles;
  return (detalles || []).filter(d =>
    matchesQuery(`${d.codigo ?? ''} ${d.descripcion ?? ''} ${d.marca ?? ''}`, query, d.producto_id ?? d.id)
  );
}

/**
 * Recalcula el `subtotal_num` de un renglón en RAM (costo × cantidad) tras aplicar `patch`,
 * para reflejar el total AL INSTANTE mientras se edita precio/cantidad — sin esperar el
 * round-trip al servidor (el legacy lo hacía así; el sistema nuevo dependía del refetch y
 * "tardaba", QA Tefy 24/6). El backend persiste en segundo plano y reconcilia con su
 * `subtotal_num` autoritativo: para un costo TIPEADO (≤2 decimales) `costo×cantidad` coincide
 * exactamente con lo que guardará el server, por lo que NO rompe el fix de decimales (los
 * descuadres heredados venían de costos derivados de divisiones, no de costos tipeados).
 *
 * @param {object} detalle - Renglón del documento (debe traer `costo` y `cantidad`).
 * @param {object} patch - Campos a sobreescribir (ej. `{costo}` o `{cantidad}`).
 * @returns {object} Nuevo renglón con `patch` aplicado y `subtotal_num` recalculado.
 */
export function recalcSubtotal(detalle, patch) {
  const next  = { ...detalle, ...patch };
  const costo = parseFloat(next.costo) || 0;
  const cant  = parseInt(next.cantidad, 10) || 0;
  return { ...next, subtotal_num: costo * cant };
}
