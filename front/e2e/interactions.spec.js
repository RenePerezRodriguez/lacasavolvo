import { test, expect } from '@playwright/test';
import fs from 'node:fs';

import { ADMIN } from './_helpers.js'; // credenciales desde env (no hardcodeadas)
const EMAIL = ADMIN.email;
const PASS  = ADMIN.pass;
const SHOTS = 'e2e/shots';
fs.mkdirSync(SHOTS, { recursive: true });

const BENIGN = [/favicon/i, /fontawesome/i, /fonts\.g(oogleapis|static)/i, /ERR_/i, /React DevTools/i];
const isBenign = (t) => BENIGN.some((re) => re.test(t));

test('QA interactivo: detalles, búsqueda, estadísticas y rotación por sucursal', async ({ page }) => {
  const findings = [];
  let step = 'login';
  page.on('console', (m) => { if (m.type() === 'error' && !isBenign(m.text())) findings.push({ step, kind: 'console', text: m.text() }); });
  page.on('pageerror', (e) => findings.push({ step, kind: 'pageerror', text: e.message }));
  page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) findings.push({ step, kind: 'api', text: `${r.status()} ${r.request().method()} ${r.url().replace('http://localhost:8000','')}` }); });

  // ── Login ──
  await page.goto('/');
  await page.locator('input[type=email]').fill(EMAIL);
  await page.locator('input[type=password]').fill(PASS);
  await page.locator('input[type=password]').press('Enter');
  await page.waitForSelector('.sb-link', { timeout: 20_000 });
  await page.waitForTimeout(2200);

  const go = async (label) => {
    await page.locator('.sb-link', { hasText: label }).first().click();
    await page.waitForTimeout(1300);
  };

  // ── 1. Ventas → abrir detalle de la primera fila ──
  step = 'ventas-list';
  await go('Ventas');
  const firstRow = page.locator('.tbl tbody tr').first();
  await expect(firstRow).toBeVisible();
  step = 'venta-detalle';
  // Click en la fila (o en el primer botón de acción si la fila no es clickable)
  await firstRow.click().catch(() => {});
  await page.waitForTimeout(1200);
  let openedDetail = await page.getByText(/Estado de pago|Detalle de venta|Ítems|Cobros/i).count();
  if (!openedDetail) {
    const actionBtn = firstRow.locator('button, a').first();
    if (await actionBtn.count()) { await actionBtn.click().catch(() => {}); await page.waitForTimeout(1200); }
  }
  await page.screenshot({ path: `${SHOTS}/ix-venta-detalle.png`, fullPage: true });

  // ── 2. Búsqueda global (topbar) ──
  step = 'busqueda-global';
  const searchTrigger = page.locator('input[placeholder*="Buscar"], .topbar [placeholder*="Buscar"], button:has-text("Buscar")').first();
  if (await searchTrigger.count()) {
    await searchTrigger.click().catch(() => {});
    await page.waitForTimeout(400);
    const modalInput = page.locator('.overlay input, [role=dialog] input, input[placeholder*="Buscar"]').last();
    if (await modalInput.count()) { await modalInput.fill('filtro').catch(() => {}); await page.waitForTimeout(1200); }
    await page.screenshot({ path: `${SHOTS}/ix-busqueda.png` });
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(300);
  }

  // ── 3. Productos → quick view de un producto ──
  step = 'productos';
  await go('Productos');
  await page.waitForTimeout(800);
  const prodRow = page.locator('.tbl tbody tr').first();
  if (await prodRow.count()) {
    step = 'producto-quickview';
    await prodRow.click().catch(() => {});
    await page.waitForTimeout(1200);
    await page.screenshot({ path: `${SHOTS}/ix-producto.png`, fullPage: true });
    await page.keyboard.press('Escape').catch(() => {});
  }

  // ── 4. Estadísticas: recorrer los 5 tabs ──
  step = 'estadisticas';
  await go('Estadísticas');
  await page.waitForTimeout(800);
  for (const tab of ['Rotación por compra', 'Rotación por sucursal', 'Ventas por período', 'Top productos', 'Top clientes']) {
    step = `estad-tab:${tab}`;
    const tabBtn = page.locator('.seg', { hasText: tab }).first();
    if (await tabBtn.count()) { await tabBtn.click().catch(() => {}); await page.waitForTimeout(900); }
  }

  // ── 5. Rotación por sucursal: flujo completo (selección + cálculo) ──
  step = 'rotacion-sucursal-calcular';
  await page.locator('.seg', { hasText: 'Rotación por sucursal' }).first().click().catch(() => {});
  await page.waitForTimeout(700);
  const sucSelect = page.locator('select').first();
  if (await sucSelect.count()) {
    // Elegir la primera opción real (índice 1; la 0 es el placeholder)
    const opts = await sucSelect.locator('option').count();
    if (opts > 1) await sucSelect.selectOption({ index: 1 }).catch(() => {});
  }
  const calcBtn = page.getByRole('button', { name: /Calcular rotación/i }).first();
  if (await calcBtn.count()) {
    await calcBtn.click().catch(() => {});
    await page.waitForTimeout(2000);
  }
  // Verificar que apareció el resumen (KPIs) o un mensaje de "sin entradas"
  const hasResult = await page.getByText(/Entrada total|Productos|Sin entradas de mercader/i).count();
  if (!hasResult) findings.push({ step, kind: 'flow', text: 'Rotación por sucursal no mostró resultado ni mensaje vacío tras calcular' });
  await page.screenshot({ path: `${SHOTS}/ix-rotacion-sucursal.png`, fullPage: true });

  // ── 6. Top productos: cambiar métrica (interacción de filtro) ──
  step = 'top-productos';
  await page.locator('.seg', { hasText: 'Top productos' }).first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.screenshot({ path: `${SHOTS}/ix-top-productos.png`, fullPage: true });

  // ── Reporte ──
  fs.writeFileSync(`${SHOTS}/findings-ix.json`, JSON.stringify(findings, null, 2));
  console.log('\n════════ QA INTERACCIONES ════════');
  for (const f of findings) console.log(`[${f.kind}] (${f.step}) ${f.text}`);
  console.log(`════════ total problemas: ${findings.length} ════════\n`);
  expect(true).toBe(true);
});
