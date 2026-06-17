/**
 * @fileoverview Utilidad de logging centralizado.
 * En desarrollo (import.meta.env.DEV) imprime a consola.
 * En producción los mensajes se suprimen — sustituir el cuerpo
 * de `error()` por un servicio remoto (Sentry, Logtail, etc.)
 * cuando se requiera trazabilidad en producción.
 */

const DEV = import.meta.env.DEV;

/**
 * Logger centralizado de la aplicación.
 * Reemplaza el uso directo de `console.*` en bloques catch.
 */
const logger = {
  /**
   * Registra un error. Solo visible en modo desarrollo.
   * @param {...any} args - Mensaje o Error a registrar.
   */
  error(...args) {
    if (DEV) console.error(...args);  
  },

  /**
   * Registra una advertencia. Solo visible en modo desarrollo.
   * @param {...any} args - Mensaje a registrar.
   */
  warn(...args) {
    if (DEV) console.warn(...args);  
  },
};

export default logger;
