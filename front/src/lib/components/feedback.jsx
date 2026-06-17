/**
 * @fileoverview Sistema de notificaciones toast: Toast simple, ToastProvider
 * (contexto global) y hook useToast.
 */

import React, { useState, useEffect, useCallback, useContext } from 'react';
import { Icon } from './primitives.jsx';


/**
 * Notificación tipo toast que se auto-cierra a los 3 segundos.
 * @param {object} props
 * @param {string} props.msg - Mensaje a mostrar.
 * @param {function(): void} props.onClose - Callback al cerrarse (automático o manual).
 * @returns {JSX.Element}
 */
export function Toast({ msg, onClose }) {
  useEffect(() => {
    const t = setTimeout(onClose, 3000);
    return () => clearTimeout(t);
  }, []);
  return <div className="toast"><Icon name="fa-circle-check" style={{color:"var(--success)"}}/>{msg}</div>;
}


// ── Sistema de notificaciones toast ──────────────────────────────────────────
const _ToastCtx = React.createContext(null);

const _TOAST_ICONS = {
  success: "fa-circle-check",
  warning: "fa-triangle-exclamation",
  info:    "fa-circle-info",
  error:   "fa-circle-xmark",
};
const _TOAST_STYLE = {
  success: { bg:"#f0fdf4", border:"rgba(22,163,74,.35)",   icon:"var(--success)" },
  warning: { bg:"#fffbeb", border:"rgba(245,158,11,.35)",  icon:"var(--warning)" },
  info:    { bg:"#eff6ff", border:"rgba(59,130,246,.35)",  icon:"#3b82f6" },
  error:   { bg:"#fff5f5", border:"rgba(220,38,38,.35)",   icon:"var(--danger)"  },
};


/**
 * Proveedor global de toasts. Envuelve el árbol de componentes que necesita
 * mostrar notificaciones (normalmente en App.jsx).
 */
export function ToastProvider({ children }) {
  const [list, setList] = useState([]);
  const add = useCallback((msg, type = 'error') => {
    const id = Date.now() + Math.random();
    setList(l => [...l, { id, msg, type }]);
    setTimeout(() => setList(l => l.filter(x => x.id !== id)), 4500);
  }, []);
  return (
    <_ToastCtx.Provider value={add}>
      {children}
      <div style={{position:"fixed",bottom:20,right:20,zIndex:10000,display:"flex",flexDirection:"column",gap:8,pointerEvents:"none",maxWidth:360}}>
        {list.map(t => {
          const s = _TOAST_STYLE[t.type] || _TOAST_STYLE.error;
          return (
            <div key={t.id} className="fade-up" style={{
              display:"flex",alignItems:"flex-start",gap:10,padding:"11px 14px",
              borderRadius:"var(--r-md)",boxShadow:"0 4px 16px rgba(0,0,0,.13)",
              pointerEvents:"all",background:s.bg,border:`1px solid ${s.border}`,
              fontSize:13,color:"var(--ink)",
            }}>
              <Icon name={_TOAST_ICONS[t.type]} style={{fontSize:14,flexShrink:0,marginTop:1,color:s.icon}}/>
              <span style={{flex:1,lineHeight:1.4}}>{t.msg}</span>
              <button onClick={() => setList(l => l.filter(x => x.id !== t.id))}
                style={{background:"none",border:"none",cursor:"pointer",padding:2,color:"var(--soft)",lineHeight:1,flexShrink:0}}>
                <Icon name="fa-xmark" style={{fontSize:11}}/>
              </button>
            </div>
          );
        })}
      </div>
    </_ToastCtx.Provider>
  );
}


/**
 * Hook para mostrar notificaciones toast desde cualquier componente hijo de ToastProvider.
 * @returns {(msg: string, type?: 'error'|'success'|'warning'|'info') => void} función para disparar un toast
 */
export function useToast() {
  return useContext(_ToastCtx) || (() => {});
}
