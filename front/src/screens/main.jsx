/**
 * @fileoverview Pantallas de autenticación y dashboard principal.
 * `LoginScreen` gestiona el flujo de login (email/pass → API → token).
 * `Dashboard` muestra KPIs de ventas, caja y movimientos recientes.
 */

import React, { useState, useEffect, useRef } from 'react';
import { Icon, Button, Badge, Card, KPI, Sparkline } from '../lib/components.jsx';
import { ventas as ventasApi, caja as cajaApi, estadisticas as estadApi, compras as comprasApi, auth as authApi } from '../services/api.js';
import logger from '../lib/logger.js';

/**
 * Pantalla de autenticación. Muestra el formulario de login y estadísticas decorativas.
 * Llama a `onLogin(email, password, remember)` que maneja el token (localStorage si
 * `remember`, sessionStorage si no) y la redirección al dashboard.
 * @param {object} props
 * @param {(email: string, password: string, remember?: boolean) => Promise<void>} props.onLogin - Callback de login.
 * @param {"A"|"B"} [props.statsVariant="A"] - Variante visual de las estadísticas decorativas.
 * @param {boolean} [props.fading=false] - Activa la animación de salida al autenticar.
 * @returns {JSX.Element}
 */
export function LoginScreen({ onLogin, statsVariant = "A", fading = false }) {
  const [email, setEmail]           = useState("");
  const [pass, setPass]             = useState("");
  const [remember, setRemember]     = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError]           = useState(null);
  const [showPass, setShowPass]     = useState(false);
  const [capsLock, setCapsLock]     = useState(false);
  const [emailError, setEmailError] = useState(false);
  const [shaking, setShaking]       = useState(false);
  const [sucursalCount, setSucursalCount] = useState(null); // conteo real desde la DB (no hardcode)
  const passRef = useRef(null);

  // Conteo público de sucursales activas para la franja "Sistema activo · N sucursales".
  // Antes estaba hardcodeado en 5 (irreal: hay 4 activas). Es una vista pública, así que el
  // endpoint expone SOLO el número. Si falla, no se muestra el conteo (sin romper el login).
  useEffect(() => {
    let vivo = true;
    authApi.publicInfo()
      .then(r => { if (vivo) setSucursalCount(r.data?.sucursales ?? null); })
      .catch(() => {});
    return () => { vivo = false; };
  }, []);

  async function submit(e) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await onLogin(email, pass, remember);
    } catch (err) {
      const msg = err.response?.data?.errors?.email?.[0]
        || err.response?.data?.message
        || "Error al iniciar sesión. Verifica tus credenciales.";
      setError(msg);
      setSubmitting(false);
      setShaking(true);
      setTimeout(() => setShaking(false), 520);
    }
  }

  function validateEmail() {
    setEmailError(email.length > 0 && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));
  }

  function handlePassKey(e) {
    setCapsLock(e.getModifierState?.('CapsLock') ?? false);
  }

  function handleEmailKeyDown(e) {
    if (e.key === 'Enter') { e.preventDefault(); passRef.current?.focus(); }
  }

  const GEO = ({ s, op = .09, r = 8, style = {} }) => (
    <div style={{ width: s, height: s, background: `rgba(11,126,194,${op})`, borderRadius: r, transform: "rotate(45deg)", position: "absolute", pointerEvents: "none", ...style }} />
  );
  const GEO_W = ({ s, op = .06, r = 10, style = {} }) => (
    <div style={{ width: s, height: s, background: `rgba(255,255,255,${op})`, borderRadius: r, transform: "rotate(45deg)", position: "absolute", pointerEvents: "none", ...style }} />
  );

  return (
    <div className={`login-wrap${fading ? ' login-fading' : ''}`}>

      {/* ── LEFT: form panel ── */}
      <div className="login-form-pane" style={{position:"relative", overflow:"hidden", background:"#fff"}}>
        {/* diamonds top-left */}
        <GEO s={200} op={.06} style={{top:-90, left:-90}}/>
        <GEO s={130} op={.09} style={{top:-40, left:-40}}/>
        <GEO s={80}  op={.11} style={{top:28,  left:28}}/>
        {/* diamonds bottom-right */}
        <GEO s={170} op={.06} style={{bottom:-90, right:-90}}/>
        <GEO s={110} op={.09} style={{bottom:-35, right:-35}}/>
        <GEO s={65}  op={.1}  style={{bottom:35,  right:40}}/>

        <div className={`inner fade-up${shaking ? ' login-shake' : ''}`} style={{position:"relative", zIndex:1}}>
          <div style={{marginBottom:30, textAlign:"center"}}>
            <h1 style={{fontSize:26, fontWeight:700, color:"var(--navy)", marginBottom:8, letterSpacing:"-.02em"}}>Iniciar sesión</h1>
            <p style={{color:"var(--soft)", margin:0, fontSize:13.5}}>Ingresa con tu cuenta corporativa</p>
          </div>

          <form onSubmit={submit} className="stack" style={{"--gap":"16px"}}>
            <div className="field">
              <label className="label" htmlFor="login-email">Correo corporativo</label>
              <div className={`input-group${emailError ? ' error' : ''}`}>
                <span className="lead-icon"><Icon name="fa-envelope" style={{fontSize:12}}/></span>
                <input id="login-email" className="input" type="email" value={email}
                  autoComplete="email"
                  onChange={e => { setEmail(e.target.value); setEmailError(false); }}
                  onBlur={validateEmail}
                  onKeyDown={handleEmailKeyDown}
                  required />
              </div>
              {emailError && <span style={{fontSize:11, color:"var(--danger)", marginTop:4, display:"block"}}>Ingresa un correo válido</span>}
            </div>
            <div className="field">
              <label className="label" htmlFor="login-password">Contraseña</label>
              <div className="input-group">
                <span className="lead-icon"><Icon name="fa-lock" style={{fontSize:12}}/></span>
                <input id="login-password" className="input" type={showPass ? "text" : "password"}
                  ref={passRef}
                  autoComplete="current-password"
                  value={pass} onChange={e => setPass(e.target.value)}
                  onKeyDown={handlePassKey} onKeyUp={handlePassKey}
                  style={{paddingRight:40}}
                  required />
                <button type="button" onClick={() => setShowPass(v => !v)}
                  aria-label={showPass ? "Ocultar contraseña" : "Mostrar contraseña"}
                  title={showPass ? "Ocultar contraseña" : "Mostrar contraseña"}
                  style={{position:"absolute", right:0, top:0, bottom:0, width:40, background:"none", border:"none", cursor:"pointer", color:"var(--soft)", display:"flex", alignItems:"center", justifyContent:"center"}}>
                  <Icon name={showPass ? "fa-eye-slash" : "fa-eye"} style={{fontSize:13}}/>
                </button>
              </div>
              {capsLock && (
                <span style={{fontSize:11, color:"var(--warning)", marginTop:4, display:"flex", gap:4, alignItems:"center"}}>
                  <Icon name="fa-triangle-exclamation" style={{fontSize:10}}/>Bloq Mayús activado
                </span>
              )}
            </div>

            <div className="row" style={{gap:8}}>
              <input type="checkbox" id="remember" checked={remember} onChange={e => setRemember(e.target.checked)} style={{accentColor:"var(--accent)"}}/>
              <label htmlFor="remember" style={{fontSize:12, color:"var(--soft)", cursor:"pointer"}}>Mantener sesión iniciada</label>
            </div>

            {error && (
              <div style={{padding:"10px 14px", background:"var(--danger-soft)", border:"1px solid rgba(220,38,38,.25)", borderRadius:"var(--r-md)", fontSize:13, color:"var(--danger)", display:"flex", gap:8, alignItems:"center"}}>
                <Icon name="fa-circle-exclamation" style={{fontSize:12, flexShrink:0}}/>
                <span>{error}</span>
              </div>
            )}
            <Button variant="accent" size="lg" type="submit" disabled={submitting} style={{width:"100%", marginTop:4}}>
              {submitting ? <><Icon name="fa-spinner fa-spin" style={{marginRight:6}}/>Ingresando…</> : <>Ingresar al sistema <Icon name="fa-arrow-right" style={{marginLeft:4}}/></>}
            </Button>
          </form>

          <div style={{marginTop:20, textAlign:"center", fontSize:11, color:"var(--soft)"}}>
            <Icon name="fa-shield-halved" style={{marginRight:4}}/>Conexión segura · v3.0 · 2026
          </div>
        </div>
      </div>

      {/* ── RIGHT: brand panel ── */}
      <div className="login-art" style={{background:"linear-gradient(145deg, #0d1b3e 0%, #182642 45%, #1a4a8a 100%)", justifyContent:"center", alignItems:"center", textAlign:"center"}}>
        {/* scattered diamonds */}
        <GEO_W s={320} op={.04} r={18} style={{top:-140, right:-140}}/>
        <GEO_W s={200} op={.06} r={12} style={{top:-50,  right:-50}}/>
        <GEO_W s={130} op={.07} r={9}  style={{top:60,   right:40}}/>
        <GEO_W s={90}  op={.08} r={6}  style={{top:170,  right:120}}/>
        <GEO_W s={280} op={.04} r={16} style={{bottom:-130, left:-130}}/>
        <GEO_W s={180} op={.06} r={11} style={{bottom:-40,  left:-40}}/>
        <GEO_W s={110} op={.07} r={7}  style={{bottom:60,   left:50}}/>
        <GEO_W s={70}  op={.09} r={5}  style={{bottom:160,  left:140}}/>

        <div style={{position:"relative", zIndex:1}}>
          <img src="assets/logo-white.svg" alt="La Casa Volvo"
            style={{width:88, height:88, objectFit:"contain", marginBottom:28, opacity:.96, filter:"drop-shadow(0 4px 16px rgba(0,0,0,.3))"}}/>
          <h1 style={{color:"#fff", fontSize:52, fontWeight:900, letterSpacing:"-.02em", lineHeight:1, marginBottom:14}}>
            ¡BIENVENIDO!
          </h1>
          <p style={{color:"rgba(255,255,255,.7)", fontSize:15, lineHeight:1.5, marginBottom:28, maxWidth:320}}>
            La Casa Volvo<br/>
            <span style={{fontSize:12, letterSpacing:".08em", textTransform:"uppercase", opacity:.75}}>Sistema de gestión empresarial</span>
          </p>
          <div style={{display:"inline-flex", alignItems:"center", gap:8, fontSize:11, fontWeight:700, letterSpacing:".12em", color:"rgba(255,255,255,.65)", textTransform:"uppercase", background:"rgba(255,255,255,.08)", padding:"8px 16px", borderRadius:20}}>
            <span style={{width:7, height:7, borderRadius:"50%", background:"var(--success)", boxShadow:"0 0 0 3px rgba(34,197,94,.2)"}}/>
            Sistema activo{sucursalCount != null ? ` · ${sucursalCount} ${sucursalCount === 1 ? 'sucursal' : 'sucursales'}` : ''}
          </div>
        </div>

        <div style={{position:"absolute", bottom:28, left:0, right:0, textAlign:"center", fontSize:11, color:"rgba(255,255,255,.35)", zIndex:1}}>
          © 2026 La Casa Volvo · Bolivia
        </div>
      </div>

    </div>
  );
}

const MESES_CORTO = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
function labelDia(dia) {
  const m = parseInt((dia ?? '').split('-')[1], 10);
  return MESES_CORTO[m - 1] ?? dia?.slice(0, 3) ?? '?';
}

/**
 * Dashboard principal. Muestra KPIs de ventas y caja, gráfico de ventas del mes,
 * estadísticas de top productos/clientes y tablas de cuentas por cobrar/pagar.
 * Recarga automáticamente cuando cambia `user.sucursal_id`.
 * @param {object} props
 * @param {function(string|object): void} props.onNav - Callback de navegación.
 * @param {object} props.user - Objeto usuario con sucursal_id y name.
 * @returns {JSX.Element}
 */
export function Dashboard({ onNav, user, effectivePermissions, isAdmin = false }) {
  const hora = new Date().getHours();
  const saludo = hora < 12 ? "Buenos días" : hora < 19 ? "Buenas tardes" : "Buenas noches";
  const nombre = user?.name?.split(" ")[0] ?? "...";

  const canCreateVenta = (effectivePermissions || []).some(p => p === 'ventas.create');
  const canCreateCompra = (effectivePermissions || []).some(p => p === 'compras.create');

  const [ventasKpis,  setVentasKpis]  = useState(null);
  const [cajaKpis,    setCajaKpis]    = useState(null);
  const [chartData,   setChartData]   = useState([]);
  const [topProd,     setTopProd]     = useState([]);
  const [porCobrar,   setPorCobrar]   = useState([]);
  const [porPagar,    setPorPagar]    = useState([]);
  const [movsCaja,    setMovsCaja]    = useState([]);
  const [loading,     setLoading]     = useState(true);
  const [period,      setPeriod]      = useState(30);

  useEffect(() => {
    const hoy         = new Date().toISOString().slice(0, 10);
    const hace12m     = new Date(Date.now() - 365*24*60*60*1000).toISOString().slice(0, 10);
    const desdePeriodo = new Date(Date.now() - period*24*60*60*1000).toISOString().slice(0, 10);
    setLoading(true);
    // Solo ADMIN/GERENTE (o rol con 'estadisticas.index') puede leer estadísticas.
    // Espeja la regla de autorizarEstadisticas() del backend y respeta la simulación de roles.
    // Se usan placeholders Promise.resolve(null) para NO correr los índices del destructuring.
    const puedeVerStats = isAdmin || (effectivePermissions || []).includes('estadisticas.index');
    const apiCalls = [
      ventasApi.kpis({ fecha_desde: desdePeriodo, fecha_hasta: hoy }),
      cajaApi.kpis(),
      cajaApi.movimientos({ fecha_desde: hoy, fecha_hasta: hoy }),
      puedeVerStats ? estadApi.ventasPeriodo({ vpDesde: hace12m, vpHasta: hoy, vpGran: 'month' }) : Promise.resolve(null),
      puedeVerStats ? estadApi.topProductos({ tpDesde: hace12m, tpHasta: hoy, tpMet: 'unidades', take: 5 }) : Promise.resolve(null),
      ventasApi.list({ pagado_filtro: 'POR PAGAR', estado_filtro: 'VALIDO', skip: 0, take: 5 }),
      comprasApi.list({ pagado_filtro: 'POR PAGAR',  estado_filtro: 'VALIDO', skip: 0, take: 5 }),
    ];
    Promise.allSettled(apiCalls).then((results) => {
      // Cada llamada puede fallar individualmente sin tumbar las demás
      const ok = (r) => r.status === 'fulfilled' ? r.value : null;
      const vkRes=ok(results[0]), ckRes=ok(results[1]), movsRes=ok(results[2]);
      const chartRes=ok(results[3]), topRes=ok(results[4]);
      const ventasRes=ok(results[5]), comprasRes=ok(results[6]);

      if (vkRes) setVentasKpis(vkRes.data);
      if (ckRes) setCajaKpis(ckRes.data);
      if (movsRes) setMovsCaja((movsRes.data?.data ?? []).slice(0, 8));
      if (chartRes) setChartData(chartRes.data ?? []);
      if (topRes) setTopProd((topRes.data?.data ?? []).slice(0, 5));
      if (ventasRes) setPorCobrar(ventasRes.data?.data ?? []);
      if (comprasRes) setPorPagar(comprasRes.data?.data ?? []);
    }).finally(() => setLoading(false));
  }, [user?.sucursal_id, period]);

  // Gráfico: campo real es d.dia (formato "2025-03"), valor d.total
  const chartBars    = chartData.map(d => ({ m: labelDia(d.dia), v: parseFloat(d.total) || 0 }));
  const totalVendido = chartBars.reduce((s, d) => s + d.v, 0);

  const GEO_W = ({ s, op = .06, r = 10, style = {} }) => (
    <div style={{ width: s, height: s, background: `rgba(255,255,255,${op})`, borderRadius: r, transform: "rotate(45deg)", position: "absolute", pointerEvents: "none", ...style }} />
  );

  return (
    <div className="stack fade-up" style={{"--gap":"24px"}}>

      {/* ── Hero con motivo Diamante ── */}
      <div className="hero">
        <div className="grid-overlay"></div>
        <GEO_W s={300} op={.035} r={18} style={{top:-130, right:-130}}/>
        <GEO_W s={190} op={.055} r={12} style={{top:-45,  right:-45}}/>
        <GEO_W s={115} op={.07}  r={8}  style={{top:60,   right:60}}/>
        <GEO_W s={70}  op={.09}  r={5}  style={{top:155,  right:140}}/>
        <GEO_W s={240} op={.035} r={16} style={{bottom:-110, left:-100}}/>
        <GEO_W s={145} op={.055} r={10} style={{bottom:-25,  left:5}}/>
        <GEO_W s={85}  op={.08}  r={6}  style={{bottom:65,   left:105}}/>
        <div className="row" style={{justifyContent:"space-between", alignItems:"flex-start", gap:20}}>
          <div style={{flex: 1, minWidth: 0}}>
            <div className="row" style={{gap:8, marginBottom: 12}}>
              <Badge tone="success" dot>EN LÍNEA</Badge>
              <span style={{color:"rgba(255,255,255,.5)", fontSize:12, fontWeight:500}}>
                · {user?.sucursal?.nombre ?? 'Sucursal'} · {new Date().toLocaleDateString('es-BO', {weekday:'long', day:'numeric', month:'long', year:'numeric'})}
              </span>
            </div>
            <h1 style={{color:"#fff", fontSize:"clamp(24px, 4vw, 32px)", lineHeight:1.1, marginBottom:8, letterSpacing:"-.02em"}}>
              {saludo}, {nombre}.
            </h1>
            <p style={{color:"rgba(255,255,255,.7)", fontSize:"clamp(13px, 2vw, 14px)", margin:0}}>
              {loading
                ? "Cargando resumen del día…"
                : ventasKpis?.valido > 0
                  ? <>Hoy hay <strong style={{color:"#fff"}}>{ventasKpis.valido} ventas válidas</strong> por <strong style={{color:"#fff"}}>{ventasKpis.monto}</strong>.</>
                  : <>Sin ventas registradas hoy. {cajaKpis?.abierta ? "Caja abierta." : "Caja cerrada."}</>
              }
            </p>
          </div>
          <div className="row" style={{gap:8, flexWrap:"wrap"}}>
            {canCreateVenta && <button onClick={() => onNav("venta-nueva")} style={{display:"inline-flex", alignItems:"center", gap:8, padding:"10px 16px", background:"#fff", color:"var(--navy)", borderRadius:"var(--r-md)", fontSize:13, fontWeight:700, flex: 1, justifyContent:"center"}}>
              <Icon name="fa-plus" style={{fontSize:11}}/>Nueva venta
            </button>}
            {canCreateCompra && <button onClick={() => onNav("compras")} style={{display:"inline-flex", alignItems:"center", gap:8, padding:"10px 16px", background:"rgba(255,255,255,.08)", color:"#fff", border:"1px solid rgba(255,255,255,.18)", borderRadius:"var(--r-md)", fontSize:13, fontWeight:600, flex: 1, justifyContent:"center"}}>
              <Icon name="fa-credit-card" style={{fontSize:11}}/>Registrar compra
            </button>}
            <button onClick={() => onNav("caja")} style={{display:"inline-flex", alignItems:"center", gap:8, padding:"10px 16px", background:"rgba(255,255,255,.08)", color:"#fff", border:"1px solid rgba(255,255,255,.18)", borderRadius:"var(--r-md)", fontSize:13, fontWeight:600, flex: 1, justifyContent:"center"}}>
              <Icon name="fa-cash-register" style={{fontSize:11}}/>Ver caja
            </button>
          </div>
        </div>
      </div>

      {/* ── KPIs ── */}
      <div>
        <div className="row" style={{justifyContent:"space-between", alignItems:"center", marginBottom:12}}>
          <span style={{fontSize:13, fontWeight:600, color:"var(--ink)"}}>Resumen del período</span>
          <div className="seg-tabs" style={{gap:0}}>
            {[30, 60].map(d => (
              <button key={d} className={`seg${period === d ? " active" : ""}`} onClick={() => setPeriod(d)} style={{padding:"4px 14px", fontSize:12}}>
                {d} días
              </button>
            ))}
          </div>
        </div>
        <div className="grid-4">
          <KPI label="Ventas válidas" icon="fa-cart-shopping"
            value={loading ? "…" : (ventasKpis?.valido ?? 0)}
            since={`últimos ${period} días`} />
          <KPI label="Monto vendido" icon="fa-chart-line"
            value={loading ? "…" : (ventasKpis?.monto ?? "Bs. 0.00")}
            since={`últimos ${period} días`} />
          <KPI label="Saldo de caja" icon="fa-cash-register"
            value={loading ? "…" : (cajaKpis ? `Bs. ${cajaKpis.saldo}` : "—")}
            since={cajaKpis?.abierta ? "caja abierta" : "caja cerrada"} />
          <KPI label="Proformas" icon="fa-file-lines"
            value={loading ? "…" : (ventasKpis?.proforma ?? "—")}
            since="pendientes de validar" />
        </div>
      </div>

      {/* ── Gráfico + movimientos caja ── */}
      <div className="grid-12">
        <Card title="Ventas últimos 12 meses" pad={false}
          head={<span style={{fontSize:12, color:"var(--soft)"}}>Bs. {totalVendido.toLocaleString('es-BO', {minimumFractionDigits:2})}</span>}>
          <div style={{padding:"16px 20px 8px"}}>
            {loading
              ? <div style={{height:180, display:"grid", placeItems:"center", color:"var(--soft)"}}><i className="fa-solid fa-spinner fa-spin"/></div>
              : chartBars.length > 0
                ? <ChartBars data={chartBars} />
                : <div style={{height:180, display:"grid", placeItems:"center", color:"var(--soft)", fontSize:13}}>Sin ventas en el período</div>
            }
          </div>
        </Card>

        <Card title="Movimientos de caja" meta="Hoy" pad={false}>
          <div className="scroll-area" style={{maxHeight:340}}>
            {loading
              ? <div style={{padding:32, textAlign:"center", color:"var(--soft)"}}><i className="fa-solid fa-spinner fa-spin"/></div>
              : movsCaja.length === 0
                ? <div style={{padding:32, textAlign:"center", color:"var(--soft)", fontSize:13}}>Sin movimientos hoy</div>
                : movsCaja.map((m, i) => (
                <div key={m.id} className="row" style={{padding:"10px 16px", borderBottom: i < movsCaja.length-1 ? "1px solid var(--line-soft)" : 0, alignItems:"center", gap:10}}>
                  <div style={{width:28, height:28, borderRadius:"var(--r-sm)", display:"grid", placeItems:"center", background: m.tipo === "INGRESO" ? "rgba(34,197,94,.12)" : "rgba(245,158,11,.12)", color: m.tipo === "INGRESO" ? "var(--success)" : "var(--warning)", flexShrink:0}}>
                    <Icon name={m.tipo === "INGRESO" ? "fa-arrow-down" : "fa-arrow-up"} style={{fontSize:11}}/>
                  </div>
                  <div className="grow" style={{minWidth:0}}>
                    <div className="truncate" style={{fontSize:12.5, fontWeight:600, color:"var(--ink)"}}>{m.clase}{m.descripcion ? <span style={{fontWeight:400, color:"var(--soft)"}}> · {m.descripcion}</span> : null}</div>
                    <div style={{fontSize:11, color:"var(--soft)"}}>{m.cuenta}</div>
                  </div>
                  <div className="mono tabular" style={{fontSize:12.5, fontWeight:700, color: m.ingreso ? "var(--success)" : "var(--warning)", flexShrink:0}}>
                    {m.ingreso ? `+${m.ingreso}` : m.egreso ? `-${m.egreso}` : ""}
                  </div>
                </div>
              ))
            }
          </div>
        </Card>
      </div>

      {/* ── Top productos · Por cobrar · Por pagar ── */}
      <div className="grid-3">
        <Card title="Top productos" meta="12 meses · por unidades" pad={false}>
          {loading
            ? <div style={{padding:24, textAlign:"center", color:"var(--soft)"}}><i className="fa-solid fa-spinner fa-spin"/></div>
            : topProd.length === 0
              ? <div style={{padding:24, textAlign:"center", color:"var(--soft)", fontSize:13}}>Sin datos de ventas</div>
              : topProd.map((p, i) => (
              <div key={p.codigo ?? i} className="row no-stack" style={{padding:"10px 16px", borderBottom: i < topProd.length-1 ? "1px solid var(--line-soft)" : 0, gap:10}}>
                <div style={{width:24, height:24, borderRadius:"var(--r-sm)", display:"grid", placeItems:"center", background: i === 0 ? "var(--accent)" : "var(--muted)", color: i === 0 ? "#fff" : "var(--soft)", fontWeight:700, fontSize:11, flexShrink:0}}>{i+1}</div>
                <div className="grow" style={{minWidth:0}}>
                  <div className="truncate" style={{fontSize:12.5, fontWeight:600, color:"var(--ink)"}}>{p.descripcion}</div>
                  <div className="mono" style={{fontSize:10.5, color:"var(--soft)"}}>{p.codigo}</div>
                </div>
                <div style={{textAlign:"right", flexShrink:0}}>
                  <div className="tabular" style={{fontSize:13, fontWeight:700, color:"var(--ink)"}}>{p.total_vendido} uds</div>
                  <div className="tabular" style={{fontSize:10.5, color:"var(--soft)"}}>Bs. {parseFloat(p.total_monto||0).toLocaleString('es-BO',{minimumFractionDigits:2})}</div>
                </div>
              </div>
            ))
          }
        </Card>

        <Card title="Por cobrar" meta={`${porCobrar.length} ventas`} pad={false}>
          {loading
            ? <div style={{padding:24, textAlign:"center", color:"var(--soft)"}}><i className="fa-solid fa-spinner fa-spin"/></div>
            : porCobrar.length === 0
              ? <div style={{padding:24, textAlign:"center", color:"var(--soft)", fontSize:13}}>Sin ventas pendientes</div>
              : porCobrar.map((v, i) => (
              <div key={v.id} onClick={() => onNav({name:"venta-detail", id:v.id})} className="row no-stack" style={{padding:"10px 16px", borderBottom: i < porCobrar.length-1 ? "1px solid var(--line-soft)" : 0, cursor:"pointer", gap:10}}>
                <div style={{width:28, height:28, borderRadius:"var(--r-sm)", display:"grid", placeItems:"center", background:"rgba(220,38,38,.08)", color:"var(--danger)", flexShrink:0}}>
                  <Icon name="fa-circle-exclamation" style={{fontSize:11}}/>
                </div>
                <div className="grow" style={{minWidth:0}}>
                  <div className="truncate" style={{fontSize:12.5, fontWeight:600, color:"var(--ink)"}}>{v.cuenta}</div>
                  <div style={{fontSize:11, color:"var(--soft)"}}>Venta #{v.id} · {v.fecha}</div>
                </div>
                <div className="tabular" style={{fontSize:12.5, fontWeight:700, color:"var(--danger)", flexShrink:0}}>{v.total}</div>
              </div>
            ))
          }
        </Card>

        <Card title="Por pagar" meta={`${porPagar.length} compras`} pad={false}>
          {loading
            ? <div style={{padding:24, textAlign:"center", color:"var(--soft)"}}><i className="fa-solid fa-spinner fa-spin"/></div>
            : porPagar.length === 0
              ? <div style={{padding:24, textAlign:"center", color:"var(--soft)", fontSize:13}}>Sin compras pendientes</div>
              : porPagar.map((p, i) => (
              <div key={p.id} onClick={() => onNav({name:"compra-detail", id:p.id})} className="row no-stack" style={{padding:"10px 16px", borderBottom: i < porPagar.length-1 ? "1px solid var(--line-soft)" : 0, cursor:"pointer", gap:10}}>
                <div style={{width:28, height:28, borderRadius:"var(--r-sm)", display:"grid", placeItems:"center", background:"rgba(245,158,11,.08)", color:"var(--warning)", flexShrink:0}}>
                  <Icon name="fa-clock" style={{fontSize:11}}/>
                </div>
                <div className="grow" style={{minWidth:0}}>
                  <div className="truncate" style={{fontSize:12.5, fontWeight:600, color:"var(--ink)"}}>{p.cuenta}</div>
                  <div style={{fontSize:11, color:"var(--soft)"}}>Compra #{p.id} · {p.fecha}</div>
                </div>
                <div className="tabular" style={{fontSize:12.5, fontWeight:700, color:"var(--warning)", flexShrink:0}}>{p.total}</div>
              </div>
            ))
          }
        </Card>
      </div>
    </div>
  );
}

export function LoginStatBlock({ variant }) {
  const wrap = {display:"grid", gridTemplateColumns:"repeat(3, 1fr)", gap: 16, maxWidth: 480, padding: "20px 0", borderTop: "1px solid rgba(255,255,255,.12)"};
  const num = {fontFamily:"var(--f-display)", fontSize: 26, fontWeight: 700, color:"#fff", letterSpacing:"-.02em"};
  const lbl = {fontSize: 11, color: "rgba(255,255,255,.55)", letterSpacing:".06em", textTransform:"uppercase", marginTop: 2};
  const sub = {fontSize: 12, color: "rgba(255,255,255,.6)", marginTop: 4, lineHeight: 1.4};

  if (variant === "A") {
    return (
      <div style={wrap}>
        {[
          { v: "Catálogo",    l: "20+ marcas premium" },
          { v: "Multicanal",  l: "Ventas y cotizaciones" },
          { v: "POS",         l: "Facturación integrada" },
        ].map((s, i) => (
          <div key={i}>
            <div style={{...num, fontSize: 20}}>{s.v}</div>
            <div style={lbl}>{s.l}</div>
          </div>
        ))}
      </div>
    );
  }
  if (variant === "B") {
    return (
      <div style={wrap}>
        {[
          { v: "Calidad",   l: "Repuestos originales certificados" },
          { v: "Servicio",  l: "Atención técnica especializada" },
          { v: "Stock",     l: "Disponibilidad inmediata" },
        ].map((s, i) => (
          <div key={i}>
            <div style={{...num, fontSize: 18}}>{s.v}</div>
            <div style={sub}>{s.l}</div>
          </div>
        ))}
      </div>
    );
  }
  if (variant === "C") {
    return (
      <div style={{maxWidth: 480, padding: "24px 0 0", borderTop: "1px solid rgba(255,255,255,.12)", display:"flex", flexDirection:"column", gap: 12}}>
        {[
          "Facturación electrónica vigente",
          "Inventario en tiempo real, multi-sucursal",
          "Reportes, crédito fiscal y arqueo de caja",
        ].map((s, i) => (
          <div key={i} style={{display:"flex", alignItems:"center", gap: 10, color:"rgba(255,255,255,.85)", fontSize: 14}}>
            <span style={{width:20, height:20, borderRadius:"50%", background:"rgba(11,126,194,.25)", display:"grid", placeItems:"center", flexShrink:0}}>
              <i className="fa-solid fa-check" style={{fontSize: 9, color:"#fff"}}></i>
            </span>
            {s}
          </div>
        ))}
      </div>
    );
  }
  if (variant === "D") {
    return (
      <div style={{maxWidth: 480, padding: "24px 0 0", borderTop: "1px solid rgba(255,255,255,.12)"}}>
        <div style={{fontFamily:"var(--f-display)", fontSize: 22, fontWeight: 500, color:"#fff", lineHeight: 1.35, letterSpacing:"-.015em"}}>
          Repuestos originales.<br/>
          Atención experta.<br/>
          <span style={{color:"rgba(255,255,255,.55)"}}>Sin demoras.</span>
        </div>
      </div>
    );
  }
  // E — no stats (clean)
  return null;
}

export function Legend({ color, label }) {
  return (
    <div className="row" style={{gap: 8}}>
      <span style={{width:10, height:10, borderRadius:3, background: color}}></span>
      <span style={{fontSize:12, fontWeight:600, color:"var(--body)"}}>{label}</span>
    </div>
  );
}

/* ─── Chart bars (SVG inline) ─── */
export function ChartBars({ data }) {
  const W = 500, H = 180, P = { top: 16, right: 16, bottom: 28, left: 44 };
  const innerW = W - P.left - P.right;
  const innerH = H - P.top - P.bottom;
  const max = (Math.max(...data.map(d => d.v || 0)) || 1) * 1.1;
  const barW = innerW / data.length * 0.65;
  const groupW = innerW / data.length;

  const ticks = 4;
  return (
    <svg viewBox={`0 0 ${W} ${H}`} style={{width:"100%", height: "auto", overflow:"visible"}}>
      {Array.from({length: ticks + 1}, (_, i) => {
        const y = P.top + (innerH / ticks) * i;
        const val = Math.round(max - (max / ticks) * i);
        return (
          <g key={i}>
            <line x1={P.left} x2={P.left + innerW} y1={y} y2={y} stroke="var(--line-soft)" strokeDasharray={i === ticks ? "none" : "2 4"} />
            <text x={P.left - 8} y={y + 4} fontSize="11" textAnchor="end" fill="var(--soft)" fontFamily="var(--f-mono)">{(val/1000).toFixed(0)}k</text>
          </g>
        );
      })}
      {data.map((d, i) => {
        const x = P.left + groupW * i + (groupW - barW) / 2;
        const hv = Math.max((d.v / max) * innerH, d.v > 0 ? 2 : 0);
        return (
          <g key={i}>
            <rect x={x} y={P.top + innerH - hv} width={barW} height={hv} fill="var(--accent)" rx="3" opacity="0.9"/>
            <text x={x + barW/2} y={H - P.bottom + 18} fontSize="11" textAnchor="middle" fill="var(--soft)" fontWeight="600">{d.m.substring(0, 3)}</text>
          </g>
        );
      })}
    </svg>
  );
}

Object.assign(window, { LoginScreen, Dashboard, ChartBars, Legend });
