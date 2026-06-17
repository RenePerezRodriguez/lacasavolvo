/**
 * @fileoverview Loop F — accesibilidad con axe-core (Regla 36) — REGRESION REAL.
 *
 * Inyecta axe-core desde CDN (sin tocar package.json) y corre axe.run sobre las
 * pantallas principales: login, dashboard, ventas, un formulario (modal Nueva
 * marca), y dos pantallas adicionales barridas en el cierre de a11y
 * (cotizaciones y productos). Cada test ASERTA 0 violaciones de impacto
 * serio/crítico (WCAG 2 A/AA): si la deuda de accesibilidad vuelve, el test
 * FALLA — ya no es reporte-only.
 *
 * Histórico: las 8 violaciones de la primera pasada (loop 22) quedaron
 * documentadas en e2e/shots/a11y-violations.json. Tras el fix (login labels +
 * toggle de password, pager/select/seg con nombre accesible, tokens de contraste
 * --star/--sb-text-soft/--sb-section y la paleta SUC_COLORS a WCAG AA) todas las
 * pantallas reportan 0 críticas/serias. El JSON se sigue regenerando como
 * evidencia versionada.
 *
 * Si el CDN de axe no está disponible, el test se SALTA (skip) en lugar de fallar
 * en falso — no inventa resultados.
 */
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import { login, go, navLabels, ADMIN, SHOTS } from './_helpers.js';

const AXE_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.10.2/axe.min.js';

/** Inyecta axe-core. Devuelve true si quedó disponible en window.axe. */
async function injectAxe(page) {
  try { await page.addScriptTag({ url: AXE_CDN }); } catch { /* probamos local abajo */ }
  let ok = await page.evaluate(() => typeof window.axe !== 'undefined');
  if (!ok) {
    // Fallback: paquete local si existiera
    try {
      const p = require.resolve('axe-core/axe.min.js');
      await page.addScriptTag({ path: p });
      ok = await page.evaluate(() => typeof window.axe !== 'undefined');
    } catch { /* sin axe */ }
  }
  return ok;
}

/** Corre axe y devuelve solo violaciones serias/críticas (WCAG 2 A/AA). */
async function runAxe(page, context) {
  return page.evaluate(async (ctx) => {
    const res = await window.axe.run(ctx || document, {
      resultTypes: ['violations'],
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
    });
    return res.violations
      .filter((v) => ['serious', 'critical'].includes(v.impact))
      .map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, sample: v.nodes[0]?.target?.join(' '), html: v.nodes[0]?.html?.slice(0, 160) }));
  }, context);
}

/**
 * Aserta que `violations` (salida de runAxe) está vacío. Si no, imprime un
 * detalle legible y falla el test con un mensaje accionable.
 * @param {Array} violations
 * @param {string} screen
 */
function assertNoSeriousViolations(violations, screen) {
  if (violations.length) {
    const detail = violations
      .map((v) => `[${v.impact}] ${v.id} — ${v.help} (${v.nodes} nodo(s); ej: ${v.sample})`)
      .join('\n  ');
    console.log(`\n!! A11Y REGRESION en ${screen}:\n  ${detail}\n`);
  }
  expect(violations, `Violaciones a11y críticas/serias en "${screen}"`).toEqual([]);
}

const allViolations = {};

test.afterAll(() => {
  fs.mkdirSync(SHOTS, { recursive: true });
  fs.writeFileSync(`${SHOTS}/a11y-violations.json`, JSON.stringify(allViolations, null, 2));
  console.log('\n════════ A11Y (serias/críticas) ════════');
  for (const [scr, vs] of Object.entries(allViolations)) {
    console.log(`── ${scr}: ${vs.length} violación(es)`);
    for (const v of vs) console.log(`   [${v.impact}] ${v.id} — ${v.help} (${v.nodes} nodos; ej: ${v.sample})`);
  }
  console.log('════════════════════════════════════════\n');
});

test('a11y: login (0 críticas/serias)', async ({ page }) => {
  await page.goto('/');
  await page.waitForSelector('input[type=email]', { timeout: 15000 });
  const ok = await injectAxe(page);
  test.skip(!ok, 'axe-core no disponible (CDN caído) — a11y de login PENDIENTE');
  const v = await runAxe(page);
  allViolations['login'] = v;
  assertNoSeriousViolations(v, 'login');
});

test('a11y: dashboard (0 críticas/serias)', async ({ page }) => {
  await login(page, ADMIN);
  await go(page, 'Dashboard', 1500);
  const ok = await injectAxe(page);
  test.skip(!ok, 'axe-core no disponible — dashboard PENDIENTE');
  const v = await runAxe(page);
  allViolations['dashboard'] = v;
  assertNoSeriousViolations(v, 'dashboard');
});

test('a11y: ventas (lista) (0 críticas/serias)', async ({ page }) => {
  await login(page, ADMIN);
  await go(page, 'Ventas', 1600);
  const ok = await injectAxe(page);
  test.skip(!ok, 'axe-core no disponible — ventas PENDIENTE');
  const v = await runAxe(page);
  allViolations['ventas'] = v;
  assertNoSeriousViolations(v, 'ventas');
});

test('a11y: formulario (modal Nueva marca) (0 críticas/serias)', async ({ page }) => {
  await login(page, ADMIN);
  await go(page, 'Marcas', 1400);
  await page.getByRole('button', { name: /Nueva marca/i }).click();
  await page.waitForSelector('.modal', { timeout: 8000 });
  const ok = await injectAxe(page);
  test.skip(!ok, 'axe-core no disponible — formulario PENDIENTE');
  const v = await runAxe(page, '.modal');
  allViolations['form-marca'] = v;
  assertNoSeriousViolations(v, 'form-marca');
});

test('a11y: cotizaciones (0 críticas/serias)', async ({ page }) => {
  await login(page, ADMIN);
  await go(page, 'Cotizaciones', 1600);
  const ok = await injectAxe(page);
  test.skip(!ok, 'axe-core no disponible — cotizaciones PENDIENTE');
  const v = await runAxe(page);
  allViolations['cotizaciones'] = v;
  assertNoSeriousViolations(v, 'cotizaciones');
});

test('a11y: productos (0 críticas/serias)', async ({ page }) => {
  await login(page, ADMIN);
  await go(page, 'Productos', 1600);
  const ok = await injectAxe(page);
  test.skip(!ok, 'axe-core no disponible — productos PENDIENTE');
  const v = await runAxe(page);
  allViolations['productos'] = v;
  assertNoSeriousViolations(v, 'productos');
});

/**
 * BARRIDO COMPLETO — cierra el residual de a11y (las ~12 pantallas no barridas en el
 * loop 23). Como ADMIN ve TODO el sidebar, itera `navLabels` y corre axe en cada pantalla
 * (sin hardcodear la lista). Asierta 0 violaciones serias/críticas en el conjunto; si
 * alguna pantalla regresa, falla con el detalle de cuál y qué regla.
 */
test('a11y: barrido completo de todas las pantallas del sidebar (ADMIN)', async ({ page }) => {
  test.setTimeout(180_000);
  await login(page, ADMIN);
  const ok = await injectAxe(page);
  test.skip(!ok, 'axe-core no disponible — barrido PENDIENTE');

  const labels = await navLabels(page);
  const offenders = {};
  for (const label of labels) {
    if (!(await go(page, label, 1300))) continue;
    // La SPA no recarga la página → window.axe persiste; re-inyectar es no-op seguro.
    const v = await runAxe(page);
    allViolations[`nav:${label}`] = v;
    if (v.length) offenders[label] = v.map((x) => `${x.id}(${x.nodes})`);
  }

  const resumen = Object.entries(offenders).map(([k, ids]) => `${k}: ${ids.join(',')}`).join(' | ');
  expect(offenders, `Pantallas del sidebar con violaciones a11y serias/críticas → ${resumen}`).toEqual({});
});
