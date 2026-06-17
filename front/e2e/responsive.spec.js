/**
 * @fileoverview Loop F — responsive en 3 breakpoints (Regla 53).
 * Verifica móvil (≤450), tablet (≤700) y desktop (1440):
 *  - sidebar → overlay con hamburguesa ≤900 (y oculta en desktop)
 *  - tablas → cards ≤700 (thead oculto)
 *  - sin scroll horizontal del documento
 *  - KPIs colapsan a 1 columna ≤450
 * SOLO LECTURA: redimensiona y observa, no escribe.
 */
import { test, expect } from '@playwright/test';
import { login, go, goMobile, attachErrorSpy, report, ADMIN, SHOTS } from './_helpers.js';

/** True si el body desborda horizontalmente (scroll-x indeseado). */
async function hasHScroll(page) {
  return page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 2);
}

test('responsive: desktop 1440 — sidebar fijo, sin hamburguesa, sin scroll-x', async ({ page }) => {
  const spy = attachErrorSpy(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page, ADMIN);
  await go(page, 'Ventas', 1500);

  const burgerVisible = await page.locator('.hamburger').first().isVisible().catch(() => false);
  expect(burgerVisible, 'en desktop la hamburguesa debe estar OCULTA').toBeFalsy();
  const sidebarVisible = await page.locator('.sidebar .sb-link').first().isVisible();
  expect(sidebarVisible, 'en desktop el sidebar debe estar visible').toBeTruthy();
  expect(await hasHScroll(page), 'desktop NO debe tener scroll horizontal').toBeFalsy();
  await page.screenshot({ path: `${SHOTS}/resp-desktop.png` });

  report('RESPONSIVE desktop', spy.findings);
  expect(spy.findings.filter((f) => ['pageerror', 'http5xx'].includes(f.kind))).toEqual([]);
});

test('responsive: tablet 700 — tablas como cards, hamburguesa visible, sin scroll-x', async ({ page }) => {
  const spy = attachErrorSpy(page);
  await page.setViewportSize({ width: 700, height: 1000 });
  await login(page, ADMIN);
  await goMobile(page, 'Ventas', 1500);

  // ≤900: hamburguesa visible
  const burgerVisible = await page.locator('.hamburger').first().isVisible().catch(() => false);
  expect(burgerVisible, '≤900 debe mostrar hamburguesa').toBeTruthy();

  // ≤700: el thead de la tabla se oculta (modo cards)
  const theadDisplay = await page.locator('.tbl thead').first().evaluate((el) => getComputedStyle(el).display).catch(() => 'none');
  expect(theadDisplay, '≤700 .tbl thead debe estar display:none (modo cards)').toBe('none');

  expect(await hasHScroll(page), 'tablet NO debe tener scroll horizontal').toBeFalsy();

  // La hamburguesa abre el overlay del sidebar
  await page.locator('.hamburger').first().click();
  await page.waitForTimeout(500);
  const overlayOpen = await page.locator('.sidebar.open, .app-shell.sidebar-open').first().count();
  expect(overlayOpen, 'la hamburguesa debe abrir el sidebar overlay').toBeGreaterThan(0);
  await page.screenshot({ path: `${SHOTS}/resp-tablet.png` });

  report('RESPONSIVE tablet', spy.findings);
  expect(spy.findings.filter((f) => ['pageerror', 'http5xx'].includes(f.kind))).toEqual([]);
});

test('responsive: móvil 420 — KPIs 1 columna, sin scroll-x, sin crash', async ({ page }) => {
  const spy = attachErrorSpy(page);
  await page.setViewportSize({ width: 420, height: 850 });
  await login(page, ADMIN);
  await goMobile(page, 'Ventas', 1500);

  expect(await hasHScroll(page), 'móvil NO debe tener scroll horizontal').toBeFalsy();

  // KPIs en una columna: el grid-4 debe colapsar (medimos que las KPI cards apilan).
  const grid = page.locator('.grid-4').first();
  if (await grid.count()) {
    const cols = await grid.evaluate((el) => getComputedStyle(el).gridTemplateColumns);
    const nCols = cols.split(' ').filter(Boolean).length;
    expect(nCols, `≤450 grid-4 debe ser 1 columna (es ${nCols})`).toBeLessThanOrEqual(1);
  }
  await page.screenshot({ path: `${SHOTS}/resp-movil.png` });

  // Recorrido rápido de pantallas clave en móvil para detectar overflow/crash
  for (const label of ['Productos', 'Caja', 'Cuentas']) {
    await goMobile(page, label, 1200);
    expect(await hasHScroll(page), `${label} en móvil NO debe tener scroll-x`).toBeFalsy();
  }

  report('RESPONSIVE móvil', spy.findings);
  expect(spy.findings.filter((f) => ['pageerror', 'http5xx'].includes(f.kind))).toEqual([]);
});
