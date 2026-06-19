/**
 * @fileoverview Verifica que el overlay "Hay una versión nueva" aparece cuando el
 * version.json del servidor difiere de la versión embebida en el bundle. Intercepta
 * la respuesta de version.json para simular un deploy nuevo. No requiere login (el
 * overlay vive en App, se evalúa también en la pantalla de login).
 */
import { test, expect } from '@playwright/test';
import { SHOTS } from './_helpers.js';

const DIR = `${SHOTS}/qa-verify`;
test.use({ baseURL: process.env.VERIFY_BASE_URL || 'https://lacasavolvo.com', viewport: { width: 1440, height: 900 } });
test.setTimeout(60_000);

test('overlay de versión nueva aparece al cambiar version.json', async ({ page }) => {
  // Simular que el servidor publicó una versión distinta a la del bundle cargado.
  await page.route('**/version.json*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ version: 'NUEVA-9999' }) }),
  );
  await page.goto('/');
  // El chequeo inicial corre al montar App → el overlay debe aparecer.
  await expect(page.getByText('Hay una versión nueva')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole('button', { name: /Recargar ahora/i })).toBeVisible();
  await page.screenshot({ path: `${DIR}/20-overlay-version-nueva.png` });
});

test('el botón Recargar limpia Cache Storage (equivalente a hard refresh)', async ({ page }) => {
  await page.route('**/version.json*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ version: 'NUEVA-9999' }) }),
  );
  await page.goto('/');
  await expect(page.getByText('Hay una versión nueva')).toBeVisible({ timeout: 15_000 });

  // Sembrar una caché falsa para comprobar que el botón la borra.
  await page.evaluate(async () => {
    const c = await caches.open('lcv-test-cache');
    await c.put('/dummy', new Response('x'));
  });
  expect(await page.evaluate(() => caches.keys())).toContain('lcv-test-cache');

  // Clic en "Recargar ahora" → borra cachés + recarga.
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.getByRole('button', { name: /Recargar ahora/i }).click(),
  ]);
  await page.waitForTimeout(1200);

  // Tras la recarga, la caché sembrada ya no existe.
  expect(await page.evaluate(() => caches.keys())).not.toContain('lcv-test-cache');
});

test('"Después" oculta el aviso SIN recargar (para terminar la operación en curso)', async ({ page }) => {
  await page.route('**/version.json*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ version: 'NUEVA-9999' }) }),
  );
  await page.goto('/');
  await expect(page.getByText('Hay una versión nueva')).toBeVisible({ timeout: 15_000 });

  // Marca en window: si la página recargara, esta marca se perdería.
  await page.evaluate(() => { window.__sinRecarga = true; });

  await page.getByRole('button', { name: /^Después$/ }).click();

  // El aviso se ocultó…
  await expect(page.getByText('Hay una versión nueva')).toBeHidden();
  // …y la página NO se recargó (la marca sigue viva).
  expect(await page.evaluate(() => window.__sinRecarga === true)).toBe(true);
});
