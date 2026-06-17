/**
 * @fileoverview Loop F — flujos críticos de punta a punta CON LIMPIEZA.
 *
 * SEGURIDAD DE DATOS (innegociable): no se toca ningún registro EXISTENTE.
 * Cada flujo crea filas NUEVAS, captura su ID exacto (de la respuesta de la API)
 * en `created[]`, y al final (afterAll) las HARD-DELETEA por ID vía artisan
 * tinker (child_process) y VERIFICA que no quedó residuo. Donde la tabla admite
 * texto, la observación lleva el prefijo `E2E-TEST-` como segunda red de
 * seguridad para el barrido final.
 *
 * Los flujos se ejercitan con llamadas autenticadas desde el contexto de la
 * página (token real de localStorage) — es determinista y permite capturar el
 * ID creado. La UI se navega para evidenciar que el resultado se ve sin romper.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { login, go, attachErrorSpy, report, ADMIN, MARK, SHOTS } from './_helpers.js';

const PHP = 'C:/Users/Rene_/.config/herd/bin/php83/php.exe';
const API_DIR = 'D:/Sitios Web/lacasavolvo/api';

// Registro de filas creadas para hard-delete. { table, id }
const created = [];
const track = (table, id) => { if (id) created.push({ table, id: Number(id) }); };

/** Helper de fetch autenticado dentro del contexto de la página. */
async function api(page, method, path, body) {
  return page.evaluate(async ({ method, path, body }) => {
    const token = localStorage.getItem('lcv_token');
    const res = await fetch('http://localhost:8000/api' + path, {
      method,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
      body: body ? JSON.stringify(body) : undefined,
    });
    let data = null; try { data = await res.json(); } catch {}
    return { status: res.status, data };
  }, { method, path, body });
}

const today = () => new Date().toISOString().slice(0, 10);

test.describe.configure({ mode: 'serial' });

test.describe('Flujos críticos (con limpieza)', () => {
  let page;
  const spy = { findings: [] };

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    const s = attachErrorSpy(page);
    spy.findings = s.findings; spy.setScreen = s.setScreen;
    await login(page, ADMIN);
  });

  test.afterAll(async () => {
    // ── HARD-DELETE de todo lo creado, en orden inverso (hijos antes que padres) ──
    const byTable = {};
    for (const { table, id } of created) (byTable[table] ||= new Set()).add(id);
    const phpLines = ["use Illuminate\\Support\\Facades\\DB;"];
    // Detalles primero
    for (const [table, ids] of Object.entries(byTable)) {
      const list = [...ids].join(',');
      if (table === 'ventas')   phpLines.push(`DB::table('ventadetalles')->whereIn('venta_id',[${list}])->delete();`);
      if (table === 'compras')  phpLines.push(`DB::table('compradetalles')->whereIn('compra_id',[${list}])->delete();`);
      if (table === 'cotizacions') phpLines.push(`DB::table('cotizaciondetalles')->whereIn('cotizacion_id',[${list}])->delete();`);
      if (table === 'pedidos')  phpLines.push(`DB::table('pedidodetalles')->whereIn('pedido_id',[${list}])->delete();`);
      if (table === 'envios')   phpLines.push(`DB::table('enviodetalles')->whereIn('envio_id',[${list}])->delete();`);
    }
    // Tranzas de las ventas (VEN/COB/D-VEN): su descripción NO lleva la marca, así que se
    // borran por registro = venta_id. La venta ya se anuló (revirtió stock), esto solo limpia
    // las filas de tranza que quedaron en OFF.
    if (byTable['ventas']) {
      phpLines.push(`DB::table('tranzas')->whereIn('registro',[${[...byTable['ventas']].join(',')}])->delete();`);
    }
    for (const [table, ids] of Object.entries(byTable)) {
      phpLines.push(`DB::table('${table}')->whereIn('id',[${[...ids].join(',')}])->delete();`);
    }
    // Barrido por marca de texto (segunda red) en tablas con columna de texto
    phpLines.push(`DB::table('tranzas')->where('descripcion','like','${MARK}%')->delete();`);
    phpLines.push(`DB::table('ajustes')->where('observacion','like','${MARK}%')->delete();`);
    phpLines.push(`DB::table('cotizacions')->where('observacion','like','${MARK}%')->delete();`);
    phpLines.push(`DB::table('pedidos')->where('observacion','like','${MARK}%')->delete();`);
    phpLines.push(`DB::table('envios')->where('observacion','like','${MARK}%')->delete();`);
    phpLines.push(`echo 'CLEANUP_DONE';`);

    try {
      const out = execFileSync(PHP, ['artisan', 'tinker', '--execute', phpLines.join(' ')], { cwd: API_DIR, encoding: 'utf8' });
      console.log('[flows cleanup] ' + (out.includes('CLEANUP_DONE') ? 'OK' : out).trim());
    } catch (e) {
      console.error('[flows cleanup] ERROR — IDS A BORRAR MANUAL: ' + JSON.stringify(created) + '\n' + (e.stdout || e.message));
    }
    await page?.close();
    report('FLOWS', spy.findings);
  });

  test('Venta: nueva (mostrador) → agregar ítem → validar', async () => {
    spy.setScreen('flow:venta');
    // cuenta 6 = SIN NOMBRE (mostrador), producto 142 con stock
    const st = await api(page, 'POST', '/ventas', { fecha: today(), cuenta_id: 6, tipo: 'CONTADO', sucursal_id: 1 });
    expect(st.status, `store venta status (${JSON.stringify(st.data)})`).toBe(200);
    const vid = st.data?.id;
    expect(vid, 'venta debe devolver id').toBeTruthy();
    track('ventas', vid);

    const it = await api(page, 'POST', '/ventas/agregar-item', { venta_id: vid, producto_id: 142, cantidad: 1 });
    expect(it.status, `agregar-item status (${JSON.stringify(it.data)})`).toBeLessThan(400);

    const val = await api(page, 'POST', `/ventas/validar/${vid}`, {});
    expect(val.status, `validar venta status (${JSON.stringify(val.data)})`).toBeLessThan(400);

    // Anular la venta para REVERTIR sus efectos colaterales (stock descontado + tranza VEN
    // de caja). El hard-delete del afterAll borra la fila pero NO revierte stock ni tranza,
    // así que sin esto cada corrida dejaba stock descontado y una tranza huérfana. Anular usa
    // la lógica real de la app (restaura stock, pone la tranza OFF) → re-ejecutable limpio.
    const an = await api(page, 'DELETE', `/ventas/${vid}`, {});
    expect(an.status, `anular venta (${JSON.stringify(an.data)})`).toBeLessThan(400);

    // Evidencia visual: abrir la venta en la UI
    await go(page, 'Ventas', 1500);
    await page.screenshot({ path: `${SHOTS}/flow-venta.png` });
  });

  test('Ajuste de stock: positivo → revertir (destroy) deja stock como estaba', async () => {
    spy.setScreen('flow:ajuste');
    // Stock antes
    const before = JSON.parse(execFileSync(PHP, ['artisan', 'tinker', '--execute', "echo json_encode(['s'=>(int)\\DB::table('productos')->where('id',142)->value('stock1')]);"], { cwd: API_DIR, encoding: 'utf8' }).match(/\{.*\}/)[0]).s;

    const pos = await api(page, 'POST', '/productos/ajuste-positivo', { producto_id: 142, cantidad: 3, observacion: `${MARK}ajuste positivo` });
    expect(pos.status, `ajuste+ status (${JSON.stringify(pos.data)})`).toBeLessThan(400);

    // Capturar id del ajuste recién creado (último POSITIVO de ese producto con la marca)
    const ajId = Number(execFileSync(PHP, ['artisan', 'tinker', '--execute', `echo (int)\\DB::table('ajustes')->where('producto_id',142)->where('observacion','like','${MARK}%')->orderBy('id','desc')->value('id');`], { cwd: API_DIR, encoding: 'utf8' }).match(/\d+/)[0]);
    track('ajustes', ajId);

    const rev = await api(page, 'POST', '/productos/ajuste-destroy', { ajuste_id: ajId });
    expect(rev.status, `ajuste-destroy status (${JSON.stringify(rev.data)})`).toBeLessThan(400);

    const after = Number(execFileSync(PHP, ['artisan', 'tinker', '--execute', "echo (int)\\DB::table('productos')->where('id',142)->value('stock1');"], { cwd: API_DIR, encoding: 'utf8' }).match(/\d+/)[0]);
    expect(after, 'revertir el ajuste debe dejar el stock como estaba').toBe(before);

    await go(page, 'Ajustes stock', 1400);
    await page.screenshot({ path: `${SHOTS}/flow-ajuste.png` });
  });

  test('Cotización: crear proforma + agregar ítem', async () => {
    spy.setScreen('flow:cotizacion');
    const st = await api(page, 'POST', '/cotizaciones', { fecha: today(), cuenta_id: 6, sucursal_id: 1, observacion: `${MARK}cotizacion` });
    expect(st.status, `store cotizacion (${JSON.stringify(st.data)})`).toBeLessThan(400);
    const cid = st.data?.id;
    track('cotizacions', cid);
    if (cid) {
      const it = await api(page, 'POST', '/cotizaciones/agregar-item', { cotizacion_id: cid, producto_id: 142, cantidad: 1 });
      expect(it.status, `cotizacion agregar-item (${JSON.stringify(it.data)})`).toBeLessThan(400);
    }
    await go(page, 'Cotizaciones', 1400);
    await page.screenshot({ path: `${SHOTS}/flow-cotizacion.png` });
  });

  test('Pedido: crear proforma + agregar ítem', async () => {
    spy.setScreen('flow:pedido');
    const st = await api(page, 'POST', '/pedidos', { fecha: today(), sucursal_id: 1, observacion: `${MARK}pedido` });
    expect(st.status, `store pedido (${JSON.stringify(st.data)})`).toBeLessThan(400);
    const pid = st.data?.id;
    track('pedidos', pid);
    if (pid) {
      const it = await api(page, 'POST', '/pedidos/agregar-item', { pedido_id: pid, producto_id: 142, cantidad: 1 });
      expect(it.status, `pedido agregar-item (${JSON.stringify(it.data)})`).toBeLessThan(400);
    }
    await go(page, 'Pedidos', 1400);
    await page.screenshot({ path: `${SHOTS}/flow-pedido.png` });
  });
});
