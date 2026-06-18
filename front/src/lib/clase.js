/**
 * @fileoverview Diccionario compartido de códigos de CLASE de tranza (abreviaturas heredadas
 * del legacy en la BD) → etiqueta legible. Observación de QA: "SAL", "VEN", etc. no se
 * entendían. Se centraliza acá para usarlo igual en Caja e Historial de caja.
 */

/** Mapa código → etiqueta legible. */
export const CLASE_LABELS = {
  VEN: 'Venta', COB: 'Cobro', COM: 'Compra', PAG: 'Pago',
  ENV: 'Envío', ENT: 'Entrada', SAL: 'Salida',
  'D-VEN': 'Dev. venta', 'D-COM': 'Dev. compra', 'D-ENV': 'Dev. envío',
  'D-REC': 'Dev. recepción', REC: 'Recepción', AJU: 'Ajuste',
};

/**
 * Traduce un código de clase de tranza a su etiqueta legible.
 * @param {string} clase - Código de clase (ej. "SAL").
 * @returns {string} Etiqueta legible, o el propio código si no está mapeado.
 */
export const claseLabel = (clase) => CLASE_LABELS[clase] ?? clase;
