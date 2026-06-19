/**
 * @fileoverview Primitivos del design system: marca, íconos, botones, badges,
 * tarjetas, KPIs, sparklines, cabecera de página, estado vacío y botón PDF.
 * Todos los estilos dependen del design system en index.css (variables CSS globales).
 */

import React, { useState, useEffect, useRef } from 'react';


/**
 * Logotipo de La Casa Volvo.
 * @param {object} props
 * @param {number} [props.size=36] - Tamaño en px del contenedor cuadrado.
 * @param {"dark"|"light"} [props.variant="dark"] - "dark" para fondo oscuro (sidebar), "light" para fondo claro (login).
 * @returns {JSX.Element}
 */
export function BrandMark({ size = 36, variant = "dark" }) {
  // variant="dark"  = fondo oscuro (sidebar navy) → logo a color (azul + blanco)
  // variant="light" = fondo claro (login form)    → logo monocromático oscuro
  const src = variant === "dark" ? "assets/logo-white.svg" : "assets/logo.svg";
  return (
    <div style={{ width: size, height: size, display:"grid", placeItems:"center", flexShrink:0 }}>
      <img src={src} alt="La Casa Volvo" style={{ width: "100%", height: "100%", objectFit: "contain" }} />
    </div>
  );
}


/**
 * Ícono de Font Awesome 6. Renderiza un `<i>` con las clases de FA.
 * @param {object} props
 * @param {string} props.name - Clase FA sin el prefijo "fa-solid" (ej: "fa-plus", "fa-trash").
 * @param {string} [props.className=""] - Clases CSS adicionales.
 * @param {object} [props.style] - Estilos inline adicionales.
 * @returns {JSX.Element}
 */
export function Icon({ name, className = "", style }) {
  return <i className={`fa-solid ${name} ${className}`} style={style}></i>;
}


/**
 * Botón del design system con variantes de estilo y soporte de íconos FA.
 * @param {object} props
 * @param {"accent"|"secondary"|"ghost"|"danger"} [props.variant="secondary"] - Estilo visual.
 * @param {"sm"|"md"|"lg"} [props.size="md"] - Tamaño del botón.
 * @param {string} [props.icon] - Ícono FA a la izquierda del texto.
 * @param {string} [props.iconRight] - Ícono FA a la derecha del texto.
 * @param {React.ReactNode} props.children - Texto o contenido del botón.
 * @param {...any} rest - Props nativos del `<button>` (onClick, disabled, type, etc.).
 * @returns {JSX.Element}
 */
export function Button({ variant = "secondary", size = "md", icon, iconRight, children, ...rest }) {
  const cls = ["btn", `btn-${variant}`];
  if (size === "sm") cls.push("btn-sm");
  if (size === "lg") cls.push("btn-lg");
  return (
    <button className={cls.join(" ")} {...rest}>
      {icon && <Icon name={icon} className="ico" />}
      {children}
      {iconRight && <Icon name={iconRight} className="ico" />}
    </button>
  );
}


/**
 * Etiqueta de estado o categoría con variantes de color.
 * @param {object} props
 * @param {"neutral"|"success"|"warning"|"danger"|"info"} [props.tone="neutral"] - Color semántico.
 * @param {boolean} [props.dot] - Muestra un punto de color antes del texto.
 * @param {boolean} [props.outline] - Estilo outline en lugar de fondo sólido.
 * @param {React.ReactNode} props.children - Texto del badge.
 * @returns {JSX.Element}
 */
export function Badge({ tone = "neutral", dot, children, outline }) {
  const cls = ["badge", tone];
  if (outline) cls.push("outline");
  return (
    <span className={cls.join(" ")}>
      {dot && <span className="dot" />}
      {children}
    </span>
  );
}


/* status helper */
const STATUS_TONE = {
  VALIDO: "success", PAGADO: "success", VÁLIDO: "success", GANADA: "success", ABIERTA: "info", RECIBIDO: "success",
  CONVERTIDA: "info",
  PROFORMA: "warning", PENDIENTE: "warning", "POR COBRAR": "warning", "POR PAGAR": "warning", ENVIADO: "info",
  ANULADO: "danger", VENCIDA: "danger", PERDIDA: "danger",
  CREDITO: "info", CONTADO: "neutral", CRÉDITO: "info",
};


/**
 * Badge que mapea automáticamente estados de documentos a colores semánticos.
 * Soporta: VALIDO, PROFORMA, ANULADO, PAGADO, PENDIENTE, POR COBRAR, POR PAGAR, ENVIADO, RECIBIDO, etc.
 * @param {object} props
 * @param {string} props.value - Valor del estado (ej: "VALIDO", "PROFORMA").
 * @returns {JSX.Element}
 */
export function StatusBadge({ value, label }) {
  const tone = STATUS_TONE[value] || "neutral";
  // `label` permite mostrar un texto distinto al estado real (mismo color): p. ej.
  // en cotizaciones "VALIDO" se muestra como "VIGENTE" para no confundirlo con una venta.
  return <Badge tone={tone} dot>{label ?? value}</Badge>;
}


/**
 * Contenedor tipo tarjeta con cabecera opcional y padding interno.
 * @param {object} props
 * @param {string} [props.title] - Título de la cabecera.
 * @param {string} [props.meta] - Texto secundario en la cabecera.
 * @param {React.ReactNode} [props.head] - Reemplaza el meta por contenido JSX libre.
 * @param {boolean} [props.pad=true] - Aplica padding interno al contenido.
 * @param {boolean} [props.elev] - Agrega sombra elevada.
 * @param {string} [props.className=""] - Clases CSS adicionales.
 * @param {React.ReactNode} props.children - Contenido de la tarjeta.
 * @returns {JSX.Element}
 */
export function Card({ title, meta, children, pad = true, head, elev, className = "" }) {
  return (
    <div className={`card ${elev ? "elev" : ""} ${className}`}>
      {(title || meta || head) && (
        <div className="card-head">
          <div className="ttl">{title}</div>
          {head ? head : <div className="meta">{meta}</div>}
        </div>
      )}
      <div className={pad ? "card-pad" : ""}>{children}</div>
    </div>
  );
}


/**
 * Cabecera / intro de un documento (cotización, venta, compra, pedido, envío).
 * Muestra el encabezado ARRIBA como introducción —subtítulo, datos clave del
 * documento y la observación— antes del detalle de ítems (pedido de René/QA:
 * "el cliente arriba como cabecera, luego los productos").
 *
 * @param {object} props
 * @param {string}  [props.title]        Título del card (por defecto "Encabezado").
 * @param {string}  [props.subtitle]     Subtítulo descriptivo del card.
 * @param {Array<{label:string, value:React.ReactNode}>} [props.fields]  Datos clave del encabezado.
 * @param {string}  [props.observacion]  Texto de observación; si se pasa la prop (aunque sea ''), se muestra el bloque.
 * @param {string}  [props.observacionLabel]  Etiqueta del bloque de observación (def. "Observación").
 * @param {React.ReactNode} [props.status]    Badge de estado, va en la esquina superior derecha.
 * @returns {JSX.Element}
 */
export function DocHeader({ title = "Encabezado", subtitle, fields = [], observacion, observacionLabel = "Observación", status }) {
  return (
    <div className="card">
      <div className="card-pad">
        <div className="row" style={{ justifyContent: "space-between", alignItems: "flex-start", flexWrap: "wrap", gap: 16 }}>
          <div>
            <div style={{ fontSize: 14, fontWeight: 700, color: "var(--ink)" }}>{title}</div>
            {subtitle && <div style={{ fontSize: 12, color: "var(--soft)", marginTop: 2 }}>{subtitle}</div>}
          </div>
          {status}
        </div>
        {fields.length > 0 && (
          <div className="row" style={{ gap: 28, flexWrap: "wrap", marginTop: 14 }}>
            {fields.map((f) => (
              <div key={f.label}>
                <div style={{ fontSize: 10.5, color: "var(--soft)", textTransform: "uppercase", letterSpacing: ".04em", fontWeight: 700 }}>{f.label}</div>
                <div style={{ fontSize: 15, fontWeight: 700, color: "var(--ink)", marginTop: 2 }}>{f.value}</div>
              </div>
            ))}
          </div>
        )}
        {observacion !== undefined && (
          <>
            <div style={{ height: 1, background: "var(--line)", margin: "14px 0 10px" }} />
            <div>
              <div style={{ fontSize: 10.5, color: "var(--soft)", textTransform: "uppercase", letterSpacing: ".04em", fontWeight: 700 }}>{observacionLabel}</div>
              <div style={{ marginTop: 4, fontSize: 13, color: observacion ? "var(--ink)" : "var(--soft)", fontWeight: 500, whiteSpace: "pre-wrap", lineHeight: 1.5 }}>
                {observacion || "Sin observación"}
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}


/**
 * Tarjeta de indicador clave de rendimiento (KPI).
 * @param {object} props
 * @param {string} props.label - Etiqueta descriptiva del KPI.
 * @param {string} [props.icon] - Ícono FA opcional junto al label.
 * @param {string|number} props.value - Valor principal a mostrar.
 * @param {string} [props.prefix] - Prefijo del valor (ej: "Bs.").
 * @param {number} [props.delta] - Variación porcentual (positivo = sube, negativo = baja).
 * @param {string} [props.deltaTone] - Fuerza el tono del delta ("up" | "down").
 * @param {string} [props.since] - Periodo de referencia del delta (ej: "vs mes anterior").
 * @param {JSX.Element} [props.spark] - Sparkline u otro elemento gráfico al pie.
 * @returns {JSX.Element}
 */
export function KPI({ label, icon, value, prefix, delta, deltaTone, since, spark }) {
  return (
    <div className="kpi">
      <div className="lbl">
        {icon && <Icon name={icon} />}
        {label}
      </div>
      <div className="val">{prefix}{value}</div>
      {delta != null && (
        <div className={`delta ${deltaTone || (delta >= 0 ? "up" : "down")}`}>
          <Icon name={delta >= 0 ? "fa-arrow-trend-up" : "fa-arrow-trend-down"} />
          <span>{delta >= 0 ? "+" : ""}{delta}%</span>
          {since && <span className="since">{since}</span>}
        </div>
      )}
      {delta == null && since && <div style={{fontSize:11,color:"var(--soft)",marginTop:4}}>{since}</div>}
      {spark && <div className="spark">{spark}</div>}
      <div style={{position:"absolute",width:80,height:80,background:"rgba(11,126,194,.055)",borderRadius:8,transform:"rotate(45deg)",bottom:-32,right:-32,pointerEvents:"none"}}/>
      <div style={{position:"absolute",width:48,height:48,background:"rgba(11,126,194,.08)",borderRadius:5,transform:"rotate(45deg)",bottom:-5,right:6,pointerEvents:"none"}}/>
    </div>
  );
}


/**
 * Gráfico de línea minimalista (SVG) para mostrar tendencias en KPIs.
 * @param {object} props
 * @param {number[]} props.values - Serie de valores numéricos a graficar.
 * @param {string} [props.color="var(--accent)"] - Color del trazo SVG.
 * @param {number} [props.w=80] - Ancho del SVG en px.
 * @param {number} [props.h=28] - Alto del SVG en px.
 * @returns {JSX.Element|null} null si values está vacío.
 */
export function Sparkline({ values, color = "var(--accent)", w = 80, h = 28 }) {
  if (!values || values.length === 0) return null;
  const min = Math.min(...values), max = Math.max(...values);
  const range = max - min || 1;
  const stepX = w / (values.length - 1);
  const pts = values.map((v, i) => `${i * stepX},${h - ((v - min) / range) * h}`).join(" ");
  const lastX = (values.length - 1) * stepX;
  const lastY = h - ((values[values.length - 1] - min) / range) * h;
  return (
    <svg width={w} height={h} style={{ overflow: "visible" }}>
      <polyline points={pts} fill="none" stroke={color} strokeWidth="1.6" strokeLinejoin="round" strokeLinecap="round" />
      <circle cx={lastX} cy={lastY} r="2.5" fill={color} />
    </svg>
  );
}


/**
 * Cabecera de página estándar con título, subtítulo y zona de acciones (botones).
 * @param {object} props
 * @param {string} props.title - Título principal de la página.
 * @param {string} [props.sub] - Subtítulo o descripción breve.
 * @param {React.ReactNode} [props.actions] - Botones o controles en el lado derecho.
 * @returns {JSX.Element}
 */
export function PageHead({ title, sub, actions, diamond = false }) {
  if (diamond) {
    return (
      <div style={{position:"relative",overflow:"hidden",background:"linear-gradient(145deg,#0d1b3e 0%,#182642 50%,#1a4a8a 100%)",borderRadius:"var(--r-lg)",padding:"22px 28px",marginBottom:24}}>
        <div style={{position:"absolute",width:220,height:220,background:"rgba(255,255,255,.035)",borderRadius:14,transform:"rotate(45deg)",top:-95,right:-95,pointerEvents:"none"}}/>
        <div style={{position:"absolute",width:130,height:130,background:"rgba(255,255,255,.055)",borderRadius:9,transform:"rotate(45deg)",top:-25,right:-25,pointerEvents:"none"}}/>
        <div style={{position:"absolute",width:75,height:75,background:"rgba(255,255,255,.075)",borderRadius:5,transform:"rotate(45deg)",top:38,right:42,pointerEvents:"none"}}/>
        <div style={{position:"absolute",width:160,height:160,background:"rgba(255,255,255,.03)",borderRadius:10,transform:"rotate(45deg)",bottom:-75,left:-70,pointerEvents:"none"}}/>
        <div style={{position:"absolute",width:90,height:90,background:"rgba(255,255,255,.055)",borderRadius:6,transform:"rotate(45deg)",bottom:-10,left:18,pointerEvents:"none"}}/>
        <div style={{position:"relative",zIndex:1,display:"flex",alignItems:"center",justifyContent:"space-between",gap:16,flexWrap:"wrap"}}>
          <div>
            <h1 style={{color:"#fff",fontSize:22,marginBottom:sub?4:0,letterSpacing:"-.02em"}}>{title}</h1>
            {sub && <div style={{color:"rgba(255,255,255,.65)",fontSize:13,marginTop:2}}>{sub}</div>}
          </div>
          {actions && <div style={{display:"flex",gap:8}}>{actions}</div>}
        </div>
      </div>
    );
  }
  return (
    <div className="page-head">
      <div>
        <h1>{title}</h1>
        {sub && <div className="sub">{sub}</div>}
      </div>
      {actions && <div className="actions">{actions}</div>}
    </div>
  );
}


/**
 * Estado vacío estándar para tablas y listas sin resultados.
 * @param {object} props
 * @param {string} [props.icon="fa-inbox"] - Ícono FA a mostrar.
 * @param {string} [props.text="Sin registros que mostrar"] - Mensaje descriptivo.
 * @returns {JSX.Element}
 */
export function Empty({ icon = "fa-inbox", text = "Sin registros que mostrar" }) {
  return (
    <div className="empty">
      <div style={{position:"relative",display:"inline-flex",alignItems:"center",justifyContent:"center",marginBottom:8}}>
        <div style={{position:"absolute",width:72,height:72,background:"rgba(11,126,194,.06)",borderRadius:8,transform:"rotate(45deg)",pointerEvents:"none"}}/>
        <div style={{position:"absolute",width:44,height:44,background:"rgba(11,126,194,.09)",borderRadius:5,transform:"rotate(45deg)",pointerEvents:"none"}}/>
        <div className="ico" style={{position:"relative",zIndex:1}}><Icon name={icon} /></div>
      </div>
      <p style={{margin:0}}>{text}</p>
    </div>
  );
}


/**
 * Botón PDF con indicador de carga integrado.
 *
 * Gestiona su propio estado de loading mientras se genera/descarga el PDF.
 * Soporta dos modos de renderizado: compacto (solo ícono, para filas de tabla)
 * y estándar (botón con etiqueta, para paneles de detalle).
 *
 * @param {object}   props
 * @param {() => Promise<void>} props.onPdf     - Función async que genera/descarga el PDF.
 * @param {string}   [props.label="PDF"]        - Texto del botón en modo estándar.
 * @param {boolean}  [props.iconOnly=false]     - Si true, renderiza como `icon-btn` compacto.
 * @param {string}   [props.variant="secondary"] - Variante visual (solo modo estándar).
 * @param {string}   [props.size="sm"]          - Tamaño del botón (solo modo estándar).
 * @returns {JSX.Element}
 */
export function PdfButton({ onPdf, label = "PDF", iconOnly = false, variant = "secondary", size = "sm" }) {
  const [loading, setLoading] = useState(false);

  /** @param {React.MouseEvent} e */
  const handle = async (e) => {
    if (e) e.stopPropagation();
    if (loading) return;
    setLoading(true);
    try { await onPdf(); } finally { setLoading(false); }
  };

  if (iconOnly) {
    return (
      <button className="icon-btn" title="Generando PDF…" onClick={handle} disabled={loading}
        style={loading ? { opacity: 0.6, cursor: "wait" } : {}}>
        <Icon
          name={loading ? "fa-circle-notch fa-spin" : "fa-file-pdf"}
          style={{ fontSize: 11 }}
        />
      </button>
    );
  }

  return (
    <Button
      variant={variant}
      icon={loading ? "fa-circle-notch fa-spin" : "fa-file-pdf"}
      size={size}
      onClick={handle}
      disabled={loading}
    >
      {loading ? "Generando…" : label}
    </Button>
  );
}


/**
 * Código de producto copiable: texto monoespaciado seleccionable + ícono de "copiar".
 * Frena la propagación de eventos (click/mousedown) para que, dentro de una fila navegable
 * o un botón de resultado de búsqueda, copiar o seleccionar el código NO dispare la
 * navegación ni la selección del producto. Observación de QA: el código era un enlace y
 * no se podía copiar para pegarlo en el buscador. Solo usa <span> (sin <button> anidado)
 * para ser válido dentro de un <button> padre (p. ej. el buscador rápido).
 * @param {object} props
 * @param {string|number} props.code - Código a mostrar y copiar.
 * @param {object} [props.style] - Estilos extra del contenedor.
 * @param {object} [props.codeStyle] - Estilos extra del texto del código.
 * @param {string} [props.title="Copiar código"] - Tooltip del ícono.
 * @returns {JSX.Element}
 */
export function CopyableCode({ code, style = {}, codeStyle = {}, title = "Copiar código" }) {
  const [copied, setCopied] = useState(false);
  const stop = (e) => { e.stopPropagation(); };
  const copy = (e) => {
    e.stopPropagation();
    e.preventDefault();
    const text = String(code ?? '');
    const done = () => { setCopied(true); setTimeout(() => setCopied(false), 1100); };
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(() => {});
    } else {
      // Fallback para contextos sin Clipboard API (permiso denegado / http): textarea temporal.
      try {
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy');
        document.body.removeChild(ta); done();
      } catch { /* sin-op */ }
    }
  };
  return (
    <span onClick={stop} onMouseDown={stop} style={{display:"inline-flex", alignItems:"center", gap:5, ...style}}>
      <span className="mono" style={{userSelect:"all", cursor:"text", ...codeStyle}}>{code}</span>
      <span role="button" tabIndex={0} title={copied ? "¡Copiado!" : title}
        onClick={copy} onMouseDown={stop}
        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') copy(e); }}
        style={{display:"inline-flex", alignItems:"center", justifyContent:"center", width:18, height:18,
          borderRadius:4, cursor:"pointer", color: copied ? "var(--success)" : "var(--soft)", flexShrink:0}}>
        <Icon name={copied ? "fa-check" : "fa-copy"} style={{fontSize:10}}/>
      </span>
    </span>
  );
}


/**
 * Selector de cantidad: − [input editable] +. A diferencia del stepper anterior, el número
 * se puede TECLEAR directamente (observación de QA: para cantidades grandes hacer clic N
 * veces era inviable). Confirma con Enter o al perder el foco; acota a [min, max] y revierte
 * si el texto es inválido. Mismo aspecto visual que el stepper que reemplaza (drop-in).
 * @param {object} props
 * @param {number} props.value - Cantidad actual.
 * @param {function(number): void} props.onChange - Se llama con la nueva cantidad (absoluta) ya acotada.
 * @param {number} [props.min=1] - Mínimo permitido.
 * @param {number} [props.max=100000] - Máximo permitido (coincide con el cap del backend).
 * @param {boolean} [props.disabled=false] - Deshabilita la edición.
 * @returns {JSX.Element}
 */
export function QtyStepper({ value, onChange, min = 1, max = 100000, disabled = false }) {
  const [draft, setDraft] = useState(String(value));
  const autoTimer = useRef(null);
  // Re-sincroniza el draft cuando el valor confirmado cambia desde afuera (reload tras guardar).
  useEffect(() => { setDraft(String(value)); }, [value]);
  useEffect(() => () => clearTimeout(autoTimer.current), []);

  const commitVal = (raw) => {
    let n = parseInt(raw, 10);
    if (isNaN(n)) { setDraft(String(value)); return; }   // texto vacío/inválido → revertir
    n = Math.max(min, Math.min(max, n));
    setDraft(String(n));
    if (n !== value) onChange(n);
  };
  const commit = () => { clearTimeout(autoTimer.current); commitVal(draft); };

  // Auto-commit con debounce al ESCRIBIR: actualiza el subtotal sin tener que hacer clic
  // afuera (pedido de QA: "cambio la cantidad y no se actualiza hasta clickear en otro lado").
  const onType = (val) => {
    setDraft(val);
    clearTimeout(autoTimer.current);
    autoTimer.current = setTimeout(() => commitVal(val), 550);
  };

  const step = (delta) => {
    if (disabled) return;
    const n = Math.max(min, Math.min(max, value + delta));
    if (n !== value) onChange(n);
  };

  return (
    <div style={{display:"inline-flex", alignItems:"center", border:"1px solid var(--line)", borderRadius:"var(--r-md)", overflow:"hidden"}}>
      <button type="button" onClick={() => step(-1)} disabled={disabled || value <= min}
        title="Disminuir" style={{width:30, height:30, color:"var(--soft)"}}><Icon name="fa-minus" style={{fontSize:10}}/></button>
      <input className="mono tabular" type="text" inputMode="numeric" value={draft} disabled={disabled}
        aria-label="Cantidad"
        onChange={(e) => onType(e.target.value.replace(/[^0-9]/g, ''))}
        onFocus={(e) => e.target.select()}
        onBlur={commit}
        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); e.currentTarget.blur(); } }}
        style={{width:48, height:30, textAlign:"center", fontWeight:700, color:"var(--ink)", fontSize:13,
          border:"none", borderLeft:"1px solid var(--line)", borderRight:"1px solid var(--line)",
          background:"transparent", outline:"none", padding:0}}/>
      <button type="button" onClick={() => step(1)} disabled={disabled || value >= max}
        title="Aumentar" style={{width:30, height:30, color:"var(--soft)"}}><Icon name="fa-plus" style={{fontSize:10}}/></button>
    </div>
  );
}
