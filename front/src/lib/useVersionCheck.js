/**
 * @fileoverview Hook de detección de versión nueva del frontend. Compara la versión
 * embebida en el bundle (__APP_VERSION__, inyectada por Vite) contra `version.json`
 * del servidor (no-cacheado). Cuando difieren, significa que se subió una versión
 * nueva mientras la pestaña seguía abierta con la SPA vieja en memoria → el front
 * muestra un overlay que obliga a recargar. Resuelve el problema de la caché.
 */
import { useState, useEffect } from 'react';

/**
 * Sondea `version.json` periódicamente y al recuperar el foco de la pestaña.
 * @param {number} [intervalMs=60000] Período de sondeo en milisegundos.
 * @returns {boolean} true cuando el servidor publica una versión distinta a la corriente.
 */
export function useVersionCheck(intervalMs = 60000) {
  const [updateAvailable, setUpdateAvailable] = useState(false);

  useEffect(() => {
    const current = __APP_VERSION__;
    let stopped = false;

    const check = async () => {
      if (stopped) return;
      try {
        const res = await fetch(`/version.json?cb=${Date.now()}`, { cache: 'no-store' });
        if (!res.ok) return; // version.json ausente (p. ej. en dev) → se ignora
        const data = await res.json();
        if (data?.version && String(data.version) !== String(current)) {
          stopped = true;
          setUpdateAvailable(true);
        }
      } catch {
        /* sin red / respuesta inválida: se reintenta en el próximo tick */
      }
    };

    const id = setInterval(check, intervalMs);
    const onFocus = () => check();
    window.addEventListener('focus', onFocus);
    check(); // chequeo inicial

    return () => {
      clearInterval(id);
      window.removeEventListener('focus', onFocus);
    };
  }, [intervalMs]);

  return updateAvailable;
}
