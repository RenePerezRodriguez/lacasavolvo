import { test, expect } from '@playwright/test';
import fs from 'node:fs';

import { ADMIN } from './_helpers.js'; // credenciales desde env (no hardcodeadas)
const EMAIL = ADMIN.email;
const PASS  = ADMIN.pass;

const SHOTS = 'e2e/shots';
fs.mkdirSync(SHOTS, { recursive: true });

/** Errores de consola benignos que NO son bugs de la app (CDN/fuentes/extensiones). */
const BENIGN = [
  /favicon/i,
  /fontawesome|font awesome|kit\.fontawesome/i,
  /fonts\.googleapis|fonts\.gstatic/i,
  /Failed to load resource.*net::ERR_/i,        // recursos externos CDN
  /Download the React DevTools/i,
];
const isBenign = (t) => BENIGN.some((re) => re.test(t));

function slug(s) {
  return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

test('QA visual: login + recorrido de todas las pantallas', async ({ page }, testInfo) => {
  const findings = [];
  let current = 'login';

  page.on('console', (msg) => {
    if (msg.type() === 'error' && !isBenign(msg.text())) {
      findings.push({ screen: current, kind: 'console.error', text: msg.text() });
    }
  });
  page.on('pageerror', (err) => {
    findings.push({ screen: current, kind: 'pageerror', text: err.message });
  });
  page.on('response', (resp) => {
    const url = resp.url();
    if (url.includes('/api/') && resp.status() >= 400) {
      findings.push({ screen: current, kind: 'api', text: `${resp.status()} ${resp.request().method()} ${url.replace('http://localhost:8000', '')}` });
    }
  });

  // ── Login ──
  await page.goto('/');
  await page.locator('input[type=email]').fill(EMAIL);
  await page.locator('input[type=password]').fill(PASS);
  await page.locator('input[type=password]').press('Enter');
  await page.waitForSelector('.sb-link', { timeout: 20_000 });
  await page.waitForTimeout(2200); // dejar pasar el overlay de bienvenida (1.8s)

  // ── Enumerar items del sidebar ──
  const labels = await page.locator('.sb-link').allTextContents();
  const clean = labels.map((l) => l.trim()).filter(Boolean);
  findings.push({ screen: 'nav', kind: 'info', text: `Items de menú visibles: ${clean.join(' · ')}` });

  // ── Recorrer cada pantalla (desktop) ──
  for (const label of clean) {
    current = label;
    const link = page.locator('.sb-link', { hasText: label }).first();
    await link.click();
    await page.waitForTimeout(1400); // carga de datos
    // Detección de pantalla en blanco / crash de render
    const bodyText = (await page.locator('.app-main, main, body').first().innerText().catch(() => '')) || '';
    if (bodyText.trim().length < 5) {
      findings.push({ screen: label, kind: 'blank', text: 'Pantalla renderizó casi vacía (<5 chars)' });
    }
    await page.screenshot({ path: `${SHOTS}/desktop-${slug(label)}.png`, fullPage: true });
  }

  // ── Pasada mobile (375px) sobre las mismas pantallas ──
  await page.setViewportSize({ width: 375, height: 812 });
  await page.waitForTimeout(500);
  for (const label of clean) {
    current = `mobile:${label}`;
    // En mobile el sidebar es overlay: abrir con el botón hamburguesa
    const burger = page.locator('.topbar-burger, [aria-label="menu"], .hamburger').first();
    if (await burger.count()) { await burger.click().catch(() => {}); await page.waitForTimeout(300); }
    const link = page.locator('.sb-link', { hasText: label }).first();
    if (await link.count()) {
      await link.click().catch(() => {});
      await page.waitForTimeout(1000);
    }
    await page.screenshot({ path: `${SHOTS}/mobile-${slug(label)}.png` });
  }

  // ── Reporte ──
  const real = findings.filter((f) => !['info'].includes(f.kind));
  fs.writeFileSync(`${SHOTS}/findings.json`, JSON.stringify(findings, null, 2));
  console.log('\n════════ QA FINDINGS ════════');
  for (const f of findings) console.log(`[${f.kind}] (${f.screen}) ${f.text}`);
  console.log(`════════ total problemas: ${real.length} ════════\n`);

  await testInfo.attach('findings', { body: JSON.stringify(findings, null, 2), contentType: 'application/json' });
  // No fallamos el test por hallazgos: queremos el reporte completo. Los reviso manual.
  expect(true).toBe(true);
});
