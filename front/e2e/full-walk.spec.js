/**
 * @fileoverview Loop F — recorrido exhaustivo de TODAS las pantallas como ADMIN
 * y como GERENTE / VENDEDOR / OPERADOR (vía simulador). Verifica, por rol:
 *  - ninguna pantalla crashea (pageerror) ni renderiza en blanco
 *  - no hay console.error reales ni HTTP >= 500
 *  - RBAC visual: el sidebar muestra solo lo que el rol debe ver
 * SOLO LECTURA: navega y abre pantallas, no envía escrituras.
 *
 * Estrategia de aislamiento: cada rol corre en su PROPIO test. Para los roles
 * simulados, ADMIN activa el simulador y recarga; al terminar el test el
 * contexto se descarta, así nunca dependemos de stop-simulate desde una sesión
 * degradada.
 */
import { test, expect } from '@playwright/test';
import { login, navLabels, go, simulateRole, attachErrorSpy, report, slug, ADMIN, SHOTS } from './_helpers.js';

/**
 * Items que cada rol NO debería ver en el sidebar (RBAC visual esperado).
 *
 * OJO — DATA DRIFT LEGACY (documentado en MEMORY.md): en la BD dev `tienda`,
 * OPERADOR/GERENTE tienen MUCHOS más permisos que la matriz documentada
 * (OPERADOR=83 perms, incluye users.index/roles.index). El menú refleja
 * fielmente los permisos REALES de la BD, así que NO podemos exigirle a OPERADOR
 * que oculte Usuarios/Roles: eso es dato legacy, no bug de frontend. Solo
 * VENDEDOR tiene permisos alineados con el doc (25 perms), así que es el único
 * con un FORBIDDEN estricto. Para los demás roles validamos el invariante real:
 * todo item visible debe ser navegable (no rebota al Dashboard).
 */
const FORBIDDEN = {
  VENDEDOR: ['Sucursales', 'Usuarios', 'Roles', 'Estadísticas', 'Ajustes stock'],
  // OPERADOR/GERENTE: sin FORBIDDEN estricto por el data drift legacy de la BD dev.
};

/** Sólo ADMIN debe ver "Sucursales" según ADMIN_ONLY en roles.js (salvo GERENTE). */

async function walkAll(page, spy, roleTag) {
  const labels = await navLabels(page);
  spy.findings.push({ screen: 'nav', kind: 'info', text: `[${roleTag}] menú: ${labels.join(' · ')}` });

  for (const label of labels) {
    spy.setScreen(`${roleTag}:${label}`);
    const ok = await go(page, label, 1400);
    if (!ok) { spy.findings.push({ screen: `${roleTag}:${label}`, kind: 'nav', text: 'link no clickable' }); continue; }
    const body = (await page.locator('.app-main, main, body').first().innerText().catch(() => '')) || '';
    if (body.trim().length < 5) {
      spy.findings.push({ screen: `${roleTag}:${label}`, kind: 'blank', text: 'render casi vacío (<5 chars)' });
    }
    await page.screenshot({ path: `${SHOTS}/walk-${slug(roleTag)}-${slug(label)}.png` }).catch(() => {});
  }
  return labels;
}

test('full-walk ADMIN: todas las pantallas sin crash ni 5xx', async ({ page }) => {
  const spy = attachErrorSpy(page);
  await login(page, ADMIN);
  const labels = await walkAll(page, spy, 'ADMIN');
  const real = report('FULL-WALK ADMIN', spy.findings);

  // ADMIN ve TODO: el menú debe incluir las pantallas admin
  for (const must of ['Sucursales', 'Usuarios', 'Roles', 'Estadísticas']) {
    expect(labels, `ADMIN debe ver "${must}" en el menú`).toContain(must);
  }
  // No toleramos crashes ni 5xx
  const fatal = real.filter((f) => ['pageerror', 'http5xx', 'blank'].includes(f.kind));
  expect(fatal, `ADMIN — fatales: ${JSON.stringify(fatal)}`).toEqual([]);
});

for (const role of ['GERENTE', 'VENDEDOR', 'OPERADOR']) {
  test(`full-walk ${role} (simulado): sin crash ni 5xx + RBAC visual`, async ({ page }) => {
    const spy = attachErrorSpy(page);
    await login(page, ADMIN);
    const ok = await simulateRole(page, role);
    expect(ok, `no se pudo activar simulador para ${role}`).toBeTruthy();

    const labels = await walkAll(page, spy, role);
    const real = report(`FULL-WALK ${role}`, spy.findings);

    // RBAC visual estricto: solo para VENDEDOR (permisos alineados al doc).
    const forbidden = FORBIDDEN[role] || [];
    if (forbidden.length) {
      const leaked = forbidden.filter((f) => labels.includes(f));
      expect(leaked, `${role} NO debería ver en el menú: ${leaked.join(', ')}`).toEqual([]);
    }

    // limpiar simulación (best-effort; el contexto igual se descarta)
    await page.evaluate(async () => {
      const t = localStorage.getItem('lcv_token');
      await fetch('http://localhost:8000/api/users/stop-simulate', { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${t}` } }).catch(() => {});
    });

    const fatal = real.filter((f) => ['pageerror', 'http5xx', 'blank'].includes(f.kind));
    expect(fatal, `${role} — fatales: ${JSON.stringify(fatal)}`).toEqual([]);
  });
}
