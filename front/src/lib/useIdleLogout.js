/**
 * @fileoverview Cierre de sesión por INACTIVIDAD (replica lo que el legacy hacía vía la
 * sesión-cookie de PHP; el sistema nuevo usa Bearer token y no tenía esto). Tras IDLE_MS sin
 * actividad cierra la sesión; a los WARN_MS muestra un aviso con cuenta regresiva que el
 * usuario puede cancelar ("Seguir conectado"). Sincroniza la última actividad entre pestañas.
 */
import { useEffect, useRef, useState, useCallback } from 'react';

/** Inactividad total antes de cerrar sesión. */
export const IDLE_MS = 15 * 60 * 1000;   // 15 min
/** A partir de esta inactividad se muestra el modal con la cuenta regresiva. */
export const WARN_MS = 5 * 60 * 1000;    // 5 min  → countdown de 10 min hasta el cierre

const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'];
const STORAGE_KEY = 'lcv_last_activity';

/**
 * @param {boolean} enabled - Solo activo cuando hay sesión (no en la pantalla de login).
 * @param {function(): void} onLogout - Se llama al vencer la inactividad.
 * @returns {{ warning: boolean, remainingMs: number, stayConnected: function(): void }}
 */
export function useIdleLogout(enabled, onLogout) {
  const [warning, setWarning] = useState(false);
  const [remainingMs, setRemainingMs] = useState(IDLE_MS - WARN_MS);
  const lastRef = useRef(Date.now());
  const logoutRef = useRef(onLogout);
  logoutRef.current = onLogout; // siempre el último callback, sin re-montar el efecto

  /** Marca actividad (resetea el contador) y cierra el aviso si estaba abierto. */
  const bump = useCallback(() => {
    lastRef.current = Date.now();
    try { localStorage.setItem(STORAGE_KEY, String(lastRef.current)); } catch { /* storage lleno/bloqueado */ }
    setWarning(false);
  }, []);

  useEffect(() => {
    if (!enabled) { setWarning(false); return; }
    lastRef.current = Date.now();

    const onActivity = () => bump();
    ACTIVITY_EVENTS.forEach(ev => window.addEventListener(ev, onActivity, { passive: true }));

    // Entre pestañas: si otra pestaña registró actividad, la tomamos (no cerrar una sesión activa).
    const onStorage = (e) => {
      if (e.key === STORAGE_KEY && e.newValue) {
        lastRef.current = Math.max(lastRef.current, Number(e.newValue));
        setWarning(false);
      }
    };
    window.addEventListener('storage', onStorage);

    const iv = setInterval(() => {
      const idle = Date.now() - lastRef.current;
      if (idle >= IDLE_MS) { logoutRef.current?.(); return; }
      if (idle >= WARN_MS) { setWarning(true); setRemainingMs(IDLE_MS - idle); }
    }, 1000);

    return () => {
      ACTIVITY_EVENTS.forEach(ev => window.removeEventListener(ev, onActivity));
      window.removeEventListener('storage', onStorage);
      clearInterval(iv);
    };
  }, [enabled, bump]);

  return { warning, remainingMs, stayConnected: bump };
}
