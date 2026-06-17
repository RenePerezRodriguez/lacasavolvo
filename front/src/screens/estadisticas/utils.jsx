import React, { useState, useEffect } from 'react';
import { Icon } from '../../lib/components.jsx';
import { sucursales as sucursalesApi } from '../../services/api.js';

export function useSucursales() {
  const [sucursales, setSucursales] = useState([]);
  useEffect(() => { sucursalesApi.list().then(r => setSucursales(r.data ?? [])).catch(() => {}); }, []);
  return sucursales;
}

export function downloadBlob(data, filename) {
  const blob = data instanceof Blob ? data : new Blob([data], { type: 'text/csv;charset=utf-8;' });
  if (blob.size === 0) throw new Error('El servidor devolvió un archivo vacío.');
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.setAttribute('download', filename);
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(() => window.URL.revokeObjectURL(url), 1000);
}

export function CsvErrMsg({ show }) {
  if (!show) return null;
  return (
    <span style={{color:"var(--danger)", fontSize:11, display:"flex", alignItems:"center", gap:4}}>
      <Icon name="fa-triangle-exclamation" style={{fontSize:10}}/>
      Error al descargar. Verifique su sesión e intente de nuevo.
    </span>
  );
}

export function RotacionBar({ pct }) {
  const tone = pct >= 75 ? "var(--success)" : pct >= 40 ? "var(--accent)" : pct >= 20 ? "var(--warning)" : "var(--danger)";
  return (
    <div style={{display:"flex", alignItems:"center", gap: 8}}>
      <div className="bar" style={{flex: 1, height: 8}}>
        <div className="fill" style={{width: `${Math.min(100, pct)}%`, background: tone}}></div>
      </div>
      <span className="mono tabular" style={{fontSize: 11, fontWeight: 700, color: tone, minWidth: 32, textAlign: "right"}}>{pct}%</span>
    </div>
  );
}

export function MiniStat({ label, value, tone }) {
  return (
    <div>
      <div style={{fontSize: 10, fontWeight: 700, color: "var(--soft)", letterSpacing: ".06em", textTransform: "uppercase"}}>{label}</div>
      <div className="display tabular" style={{fontSize: 22, fontWeight: 700, color: tone || "var(--ink)", marginTop: 3}}>{value}</div>
    </div>
  );
}

export function CsvLoadingOverlay({ show }) {
  if (!show) return null;
  return (
    <div className="overlay" style={{zIndex: 9999, backgroundColor: "rgba(0,0,0,0.6)", backdropFilter: "blur(4px)"}}>
      <div className="modal" style={{padding: 40, textAlign: "center", width: "auto", minWidth: 320}}>
         <Icon name="fa-spinner fa-spin" style={{fontSize: 32, color: "var(--accent)", marginBottom: 20}} />
         <h3 style={{fontSize: 18, color: "var(--ink)", marginBottom: 8}}>Generando reporte CSV...</h3>
         <p style={{fontSize: 13, color: "var(--soft)"}}>Esto puede tardar unos segundos dependiendo del volumen de datos.</p>
      </div>
    </div>
  );
}

/**
 * Encabezado explicativo de un panel: qué pregunta responde y para qué sirve.
 * Da contexto al usuario antes de los filtros.
 */
export function PanelIntro({ icon, title, question, purpose }) {
  return (
    <div className="card" style={{padding:"12px 16px", display:"flex", gap:12, alignItems:"flex-start", borderLeft:"3px solid var(--accent)"}}>
      <div style={{width:34, height:34, flexShrink:0, borderRadius:"var(--r-sm, 8px)", background:"var(--accent-a15)", display:"grid", placeItems:"center", color:"var(--accent)"}}>
        <Icon name={icon} style={{fontSize:15}}/>
      </div>
      <div style={{display:"flex", flexDirection:"column", gap:2}}>
        <div style={{fontSize:13.5, fontWeight:700, color:"var(--ink)"}}>{title}</div>
        <div style={{fontSize:12.5, color:"var(--ink)"}}><strong style={{color:"var(--accent)"}}>Responde:</strong> {question}</div>
        <div style={{fontSize:12, color:"var(--soft)"}}><strong>Para qué sirve:</strong> {purpose}</div>
      </div>
    </div>
  );
}
