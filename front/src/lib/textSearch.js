/**
 * @fileoverview Utilidades de búsqueda por texto para los filtros client-side, alineadas con
 * `App\Helpers\SearchHelper` del backend: tokeniza, ignora conectores (stopwords) y es
 * insensible a acentos y mayúsculas. Usado por filterDetalles (buscador de productos ya
 * seleccionados), ComboSelect y el resaltado de coincidencias en los buscadores.
 */

// Mismos conectores que App\Helpers\SearchHelper::STOPWORDS (+ los de 1 char y/o/a).
const STOPWORDS = new Set([
  'de', 'del', 'con', 'la', 'el', 'los', 'las', 'un', 'una',
  'para', 'sin', 'en', 'por', 'al', 'que', 'su', 'y', 'o', 'a',
]);

/**
 * Minúsculas + sin acentos (folding 1:1 que PRESERVA la longitud — clave para el resaltado).
 * "Depósito" → "deposito", "MUÑOZ" → "munoz".
 * @param {*} s
 * @returns {string}
 */
export function foldText(s) {
  return (s ?? '').toString().toLowerCase()
    .replace(/[áàäâã]/g, 'a').replace(/[éèëê]/g, 'e').replace(/[íìïî]/g, 'i')
    .replace(/[óòöô]/g, 'o').replace(/[úùüû]/g, 'u').replace(/ñ/g, 'n');
}

/**
 * Tokens de CONTENIDO: foldeados, sin conectores ni tokens de 1 char. El '#' inicial se
 * ignora ("#635" == "635"). Si tras filtrar no queda nada, devuelve los crudos (no romper).
 * @param {string} query
 * @returns {string[]}
 */
export function tokenize(query) {
  const folded = foldText(query).replace(/^#+/, '').trim();
  if (!folded) return [];
  const raw = folded.split(/\s+/).filter(Boolean);
  const content = raw.filter(t => t.length >= 2 && !STOPWORDS.has(t));
  return content.length ? content : raw;
}

/**
 * ¿`haystack` matchea TODOS los tokens de contenido de `query`? (AND, sin acentos, ignorando
 * conectores). `id` permite además match exacto por ID ("#635"/"635").
 * @param {string} haystack
 * @param {string} query
 * @param {number|string|null} [id]
 * @returns {boolean}
 */
export function matchesQuery(haystack, query, id = null) {
  const toks = tokenize(query);
  if (!toks.length) return true;
  const hay = foldText(haystack);
  const idStr = id != null ? String(id) : null;
  return toks.every(t => hay.includes(t) || (idStr !== null && idStr === t));
}

/**
 * Parte `text` en segmentos `{text, match}` para resaltar las coincidencias de los tokens.
 * Insensible a acentos (matchea sobre el foldeado 1:1, conserva el texto original para mostrar).
 * @param {string} text
 * @param {string} query
 * @returns {{text:string, match:boolean}[]}
 */
export function highlightParts(text, query) {
  const str = (text ?? '').toString();
  const toks = [...new Set(tokenize(query))].filter(t => t.length >= 2).sort((a, b) => b.length - a.length);
  if (!toks.length || !str) return [{ text: str, match: false }];

  const folded = foldText(str); // misma longitud que `str` (folding 1:1)
  const cover = new Array(str.length).fill(false);
  for (const t of toks) {
    let from = 0, idx;
    while ((idx = folded.indexOf(t, from)) !== -1) {
      for (let i = idx; i < idx + t.length; i++) cover[i] = true;
      from = idx + t.length;
    }
  }

  const parts = [];
  let cur = '', curMatch = cover[0] ?? false;
  for (let i = 0; i < str.length; i++) {
    if (cover[i] === curMatch) cur += str[i];
    else { parts.push({ text: cur, match: curMatch }); cur = str[i]; curMatch = cover[i]; }
  }
  if (cur) parts.push({ text: cur, match: curMatch });
  return parts;
}
