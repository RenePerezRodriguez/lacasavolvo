/**
 * @fileoverview Loop F — formularios, validación y estados (vacío/carga/error).
 * SOLO LECTURA / NO-DESTRUCTIVO: abre modales, envía VACÍO para forzar la
 * validación inline, prueba búsquedas sin resultados (estado vacío), filtros,
 * tabs segmentados y paginación. Los toggles ON/OFF se ejercitan abriendo el
 * window.confirm pero SIEMPRE cancelándolo (dismiss) para no mutar registros
 * existentes. NO crea ni guarda nada.
 */
import { test, expect } from '@playwright/test';
import { login, go, attachErrorSpy, report, ADMIN, SHOTS } from './_helpers.js';

test.describe('Formularios y estados', () => {
  test('validación inline: form vacío de catálogo NO llama API y muestra error', async ({ page }) => {
    const spy = attachErrorSpy(page);
    await login(page, ADMIN);

    // Marcas usa NombreFormModal (el form CRUD más simple).
    spy.setScreen('marcas');
    await go(page, 'Marcas');
    // Interceptamos POST a /marcas para confirmar que NO se dispara con form vacío.
    let postFired = false;
    page.on('request', (r) => { if (r.method() === 'POST' && /\/api\/marcas$/.test(r.url())) postFired = true; });

    await page.getByRole('button', { name: /Nueva marca/i }).click();
    await page.waitForSelector('.modal', { timeout: 8000 });
    // Submit vacío
    await page.locator('.modal').getByRole('button', { name: /Guardar/i }).click();
    await page.waitForTimeout(600);

    // Debe aparecer el error inline "requerido" y NO haberse llamado la API.
    const errVisible = await page.getByText(/requerido/i).count();
    expect(errVisible, 'el form vacío debe mostrar error inline "requerido"').toBeGreaterThan(0);
    expect(postFired, 'el form vacío NO debe llamar a POST /marcas').toBeFalsy();
    await page.screenshot({ path: `${SHOTS}/forms-marca-vacia.png` });
    // Cerrar modal sin guardar
    await page.keyboard.press('Escape').catch(() => {});

    report('FORMS validación inline', spy.findings);
    const fatal = spy.findings.filter((f) => ['pageerror', 'http5xx'].includes(f.kind));
    expect(fatal).toEqual([]);
  });

  test('estado vacío: búsqueda sin resultados en Productos', async ({ page }) => {
    const spy = attachErrorSpy(page);
    await login(page, ADMIN);
    spy.setScreen('productos');
    await go(page, 'Productos', 1500);

    const search = page.locator('input[placeholder*="Buscar"], input[placeholder*="buscar"]').first();
    await expect(search).toBeVisible();
    await search.fill('ZZZQXNOEXISTE-' + Date.now());
    await page.waitForTimeout(1800); // debounce + fetch

    // Debe mostrar un estado vacío (Empty) o tabla sin filas — y NO crashear.
    const rows = await page.locator('.tbl tbody tr').count();
    const emptyMsg = await page.getByText(/Sin resultados|No hay|vac[ií]o|Sin registros|Nada que mostrar/i).count();
    expect(rows === 0 || emptyMsg > 0, 'búsqueda sin match debe dar 0 filas o mensaje de vacío').toBeTruthy();
    await page.screenshot({ path: `${SHOTS}/forms-productos-vacio.png` });

    report('FORMS estado vacío', spy.findings);
    const fatal = spy.findings.filter((f) => ['pageerror', 'http5xx'].includes(f.kind));
    expect(fatal).toEqual([]);
  });

  test('filtros, tabs segmentados y toggle-confirm (cancelado) en Marcas', async ({ page }) => {
    const spy = attachErrorSpy(page);
    await login(page, ADMIN);
    spy.setScreen('marcas');
    await go(page, 'Marcas', 1400);

    // Tabs de estado: Todos / Activas / Inactivas
    for (const t of ['Activas', 'Inactivas', 'Todos']) {
      const seg = page.locator('.seg', { hasText: new RegExp(`^${t}$`, 'i') }).first();
      if (await seg.count()) { await seg.click(); await page.waitForTimeout(400); }
    }

    // Toggle: SIEMPRE cancelamos el confirm para no mutar datos existentes.
    let confirmSeen = false;
    page.on('dialog', async (d) => { confirmSeen = true; await d.dismiss().catch(() => {}); });
    const toggleBtn = page.locator('.icon-btn[title="Toggle estado"]').first();
    if (await toggleBtn.count()) {
      await toggleBtn.click();
      await page.waitForTimeout(400);
      expect(confirmSeen, 'toggle debe pedir confirmación antes de mutar').toBeTruthy();
    }
    await page.screenshot({ path: `${SHOTS}/forms-marcas-filtros.png` });

    report('FORMS filtros/tabs/toggle', spy.findings);
    const fatal = spy.findings.filter((f) => ['pageerror', 'http5xx'].includes(f.kind));
    expect(fatal).toEqual([]);
  });

  test('paginación y filtros de Ventas no rompen la UI', async ({ page }) => {
    const spy = attachErrorSpy(page);
    await login(page, ADMIN);
    spy.setScreen('ventas');
    await go(page, 'Ventas', 1600);

    // Filtros de estado (PROFORMA/VALIDO/ANULADO) si existen como segmentos.
    const segs = page.locator('.seg-tabs .seg');
    const n = await segs.count();
    for (let i = 0; i < Math.min(n, 5); i++) {
      await segs.nth(i).click().catch(() => {});
      await page.waitForTimeout(700);
    }

    // Paginación: clic en el botón de página "2" (preciso, no ambiguo).
    const page2 = page.locator('.pager-btns .pager-btn', { hasText: /^2$/ }).first();
    if (await page2.count()) {
      await page2.click().catch(() => {});
      await page.waitForTimeout(1200);
      // El pager debe reflejar "Mostrando" un rango distinto (no crashear).
      const pagerText = await page.locator('.pager').first().innerText().catch(() => '');
      expect(pagerText).toMatch(/Mostrando/i);
    }
    await page.screenshot({ path: `${SHOTS}/forms-ventas-pager.png` });

    report('FORMS ventas pager/filtros', spy.findings);
    const fatal = spy.findings.filter((f) => ['pageerror', 'http5xx'].includes(f.kind));
    expect(fatal).toEqual([]);
  });
});
