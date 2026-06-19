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
