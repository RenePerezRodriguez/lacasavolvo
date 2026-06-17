/**
 * @fileoverview Helpers compartidos para la suite E2E adversarial (loop F).
 * Login, navegación SPA por sidebar, simulador de roles, captura de errores
 * (pageerror / console / HTTP>=500) y tracker de IDs creados para limpieza
 * hard-delete. NO modifica registros existentes: solo lee, o crea filas nuevas
 * marcadas con prefijo `E2E-TEST-` que se borran al final.
 */
import fs from 'node:fs';

// Credenciales de E2E desde variables de entorno (NO hardcodeadas — el repo es público).
// Se cargan de un `.env.e2e` local (gitignored) si existe; si no, del entorno del shell.
try {
  const raw = fs.readFileSync(new URL('../.env.e2e', import.meta.url), 'utf8');
  for (const line of raw.split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].trim();
  }
} catch { /* sin .env.e2e — se usan las vars del shell */ }

export const ADMIN = { email: process.env.E2E_ADMIN_EMAIL || 'rene_perez@safesoft.tech', pass: process.env.E2E_ADMIN_PASS || '' };
export const VENDEDOR = { email: process.env.E2E_VENDEDOR_EMAIL || 'rene_perez@outlook.it', pass: process.env.E2E_VENDEDOR_PASS || '' };

export const MARK = 'E2E-TEST-';
export const SHOTS = 'e2e/shots';
fs.mkdirSync(SHOTS, { recursive: true });

/** Errores de consola/red benignos (CDN, fuentes, extensiones) — NO son bugs de la app. */
const BENIGN = [
  /favicon/i,
  /fontawesome|font awesome|kit\.fontawesome/i,
  /fonts\.googleapis|fonts\.gstatic/i,
  /Failed to load resource.*net::ERR_/i,
  /Download the React DevTools/i,
  /ResizeObserver loop/i,
];
export const isBenign = (t) => BENIGN.some((re) => re.test(t));

export function slug(s) {
  return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

/**
 * Engancha listeners de error a la página. Devuelve un objeto con `findings`
 * (array vivo) y `setScreen(name)` para etiquetar en qué pantalla ocurre cada
 * error. Solo cuenta console.error (no warnings) y HTTP >= 500 (los 4xx son
 * esperados en pruebas de validación, se cuentan aparte si se quiere).
 */
export function attachErrorSpy(page, { captureWarn = false } = {}) {
  const findings = [];
  const state = { screen: 'init' };
  page.on('console', (m) => {
    const t = m.text();
    if (isBenign(t)) return;
    if (m.type() === 'error') findings.push({ screen: state.screen, kind: 'console.error', text: t });
    else if (captureWarn && m.type() === 'warning') findings.push({ screen: state.screen, kind: 'console.warn', text: t });
  });
  page.on('pageerror', (e) => findings.push({ screen: state.screen, kind: 'pageerror', text: e.message }));
  page.on('response', (r) => {
    if (r.url().includes('/api/') && r.status() >= 500) {
      findings.push({ screen: state.screen, kind: 'http5xx', text: `${r.status()} ${r.request().method()} ${r.url().replace('http://localhost:8000', '')}` });
    }
  });
  return { findings, setScreen: (s) => { state.screen = s; } };
}

/** Login por la UI. Espera al sidebar y deja pasar el overlay de bienvenida. */
export async function login(page, who = ADMIN) {
  await page.goto('/');
  await page.locator('input[type=email]').fill(who.email);
  await page.locator('input[type=password]').fill(who.pass);
  await page.locator('input[type=password]').press('Enter');
  await page.waitForSelector('.sb-link', { timeout: 25_000 });
  await page.waitForTimeout(2200); // overlay de bienvenida ~1.8s
}

/** Lista los labels de items del sidebar (lo que el rol actual PUEDE ver). */
export async function navLabels(page) {
  const labels = await page.locator('.sb-link').allTextContents();
  return labels.map((l) => l.trim()).filter(Boolean);
}

/** Navega clickeando el item del sidebar por label. Devuelve true si existía. */
export async function go(page, label, settle = 1300) {
  const link = page.locator('.sb-link', { hasText: label }).first();
  if (!(await link.count())) return false;
  await link.click();
  await page.waitForTimeout(settle);
  return true;
}

/**
 * Navegación consciente de móvil/overlay: si hay hamburguesa visible (≤900px),
 * la clickea para desplegar el sidebar overlay antes de pulsar el item. En
 * desktop equivale a `go`. Necesario porque en overlay el .sb-link existe pero
 * queda fuera del viewport hasta abrir el panel.
 */
export async function goMobile(page, label, settle = 1300) {
  const burger = page.locator('.hamburger').first();
  if (await burger.isVisible().catch(() => false)) {
    await burger.click().catch(() => {});
    await page.waitForTimeout(350);
  }
  const link = page.locator('.sb-link', { hasText: label }).first();
  if (!(await link.count())) return false;
  await link.click({ force: true }).catch(() => {});
  await page.waitForTimeout(settle);
  return true;
}

/**
 * Activa el simulador de rol vía API directa (más estable que el botón con
 * window.confirm). Lee el token de localStorage, resuelve el role_id por nombre
 * y hace POST /api/users/simulate-role, luego recarga.
 */
export async function simulateRole(page, roleName) {
  const ok = await page.evaluate(async (rn) => {
    const token = localStorage.getItem('lcv_token');
    const h = { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` };
    const roles = await fetch('http://localhost:8000/api/roles', { headers: h }).then((r) => r.json());
    const list = Array.isArray(roles) ? roles : (roles.data || []);
    const role = list.find((x) => x.name === rn);
    if (!role) return false;
    const res = await fetch('http://localhost:8000/api/users/simulate-role', {
      method: 'POST', headers: h, body: JSON.stringify({ role_id: role.id }),
    });
    return res.ok;
  }, roleName);
  if (ok) { await page.reload(); await page.waitForSelector('.sb-link', { timeout: 20_000 }); await page.waitForTimeout(1500); }
  return ok;
}

/** Vuelve al rol real. */
export async function stopSimulate(page) {
  await page.evaluate(async () => {
    const token = localStorage.getItem('lcv_token');
    await fetch('http://localhost:8000/api/users/stop-simulate', {
      method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
  });
  await page.reload();
  await page.waitForSelector('.sb-link', { timeout: 20_000 });
  await page.waitForTimeout(1200);
}

/** Setea el valor de un input controlado de React (native setter + evento input). */
export async function reactFill(page, selector, value) {
  await page.evaluate(({ sel, val }) => {
    const el = document.querySelector(sel);
    if (!el) return;
    const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, val);
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, { sel: selector, val: value });
}

/** Guarda findings en disco e imprime un bloque resumen en consola. */
export function report(name, findings) {
  const file = `${SHOTS}/findings-${slug(name)}.json`;
  fs.writeFileSync(file, JSON.stringify(findings, null, 2));
  const real = findings.filter((f) => f.kind !== 'info');
  console.log(`\n════════ ${name} ════════`);
  for (const f of findings) console.log(`[${f.kind}] (${f.screen || f.step}) ${f.text}`);
  console.log(`════════ total: ${real.length} problemas (${file}) ════════\n`);
  return real;
}
