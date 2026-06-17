# Política de seguridad

## Reportar una vulnerabilidad

Si encontrás una vulnerabilidad de seguridad en el sistema de La Casa Volvo, **no la publiques en
issues públicos**. Reportala de forma privada al equipo de desarrollo (Rene Arturo Perez Rodriguez)
para que se pueda evaluar y corregir antes de cualquier divulgación.

Incluí en el reporte: descripción, pasos para reproducir, impacto estimado y, si es posible, una
prueba de concepto.

## Prácticas del proyecto

- **Autenticación:** Bearer token (Laravel Sanctum); los tokens previos del dispositivo se revocan al
  iniciar sesión.
- **Autorización:** RBAC granular (Spatie) con frontera por rol y por sucursal; el ocultamiento de
  costos y el simulador de roles respetan el rol efectivo.
- **Datos sensibles fuera del repo:** `.env`, credenciales de deploy y dumps de base de datos están
  excluidos por `.gitignore` y **nunca** deben commitearse.
- **Producción:** `APP_DEBUG=false`, `APP_ENV=production`, CORS restringido a los dominios propios.
- El sistema pasó por una auditoría adversarial de seguridad (IDOR, inyección, escaladas de
  privilegios, fronteras de sucursal) — ver `docs/AUDIT-LEDGER.md`.

## Manejo de credenciales

Nunca pegues tokens, contraseñas ni claves en commits, issues, PRs ni mensajes. Si una credencial se
expone, **revocala y rotala de inmediato**.
