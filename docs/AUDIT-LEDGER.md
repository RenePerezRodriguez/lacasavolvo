# AUDIT-LEDGER — Auditoría adversarial La Casa Volvo

> Memoria entre loops del auditor adversarial. Complementa `AUDIT-MATRIX.md`
> (cobertura por módulo × dimensión). Acá se registra: técnica usada por loop,
> hallazgo, severidad, test rojo→verde, y PREGUNTAS de regla de negocio.

## Entorno (verificado loop 1)
- **DB de test aislada**: `tienda_test` (phpunit.xml), NO la real `tienda`. ✅ seguro.
- Datos **sintéticos** vía factories + `DatabaseTransactions` (rollback por test).
- Stack: Laravel 13 / PHP 8.3 (Herd) + React/Vite. Tests: PHPUnit 12.
- Red existente: 21 archivos Feature / 230 tests / 647 asserts. Gates: Larastan nivel 5, ESLint v9.
- Sin Infection ni Pest instalados (mutation testing = pendiente para el cierre).

## Rotación de técnicas (1 por loop, techo 5)
| Loop | Técnica | Módulo (blast-radius) | Estado |
|------|---------|------------------------|--------|
| 1 | A. Property-based | Ventas — dinero (saldo/acuenta) | ✅ 1 bug MEDIA (saldo<0) fixeado, rojo→verde |
| 2 | E. Fuzzing de bordes | Documentos — cantidad/stock (6 controladores) | ✅ 1 clase MEDIA (frac/overflow) fixeada |
| 3 | D. Metamórfica | Ventas/Envíos — stock & dinero | ✅ nada hallado (4 relaciones aguantan) → convergencia |
| 4 | C. Concurrencia/estado | Cotizaciones — doble-submit | ✅ 1 bug MEDIA (cotiz→venta duplicada) fixeado |
| 5 | B. Mutation testing | dinero/stock/authz | ✅ 6 mutantes; 1 hueco de red hallado y cerrado |

**Estado red al cierre:** 247 tests / 2053 asserts verde · PHPStan 0 · ESLint 0 errores.
**Rotación A–E completa (techo de 5 loops alcanzado).**

---

## Loop 1 — Property-based · Ventas (dinero)

**Invariante atacada:** conservación de dinero en ventas CREDITO.
- INV-M1: `saldo >= 0` siempre (el sistema lo garantiza en `cobrarVenta`, lo viola `devItem`).
- INV-M2: `acuenta <= total` siempre.
- INV-M3: `total == Σ(costo·cantidad)` de detalles VALIDO.

**Técnica:** generador pseudo-aleatorio sembrado (determinista) que encadena
operaciones reales por API: agregar ítems → validar → N cobros parciales → N
devoluciones, verificando las invariantes tras cada paso.

**Hallazgo (severidad: MEDIA):** `devItem` en venta CREDITO hace `saldo -= total`
y `acuenta += total` **sin tope**. Si la venta ya está totalmente pagada
(`saldo = 0`, `pagado = PAGADO`) y se devuelve un ítem, `saldo` queda **negativo**
y `acuenta > total`. Reproducible 100% vía API pública (cobrar full → dev-item).
El legacy tiene el mismo defecto (líneas 730-741 de su VentaController) → bug del
legacy, en scope para fix ("fiel al legacy sin sus bugs").

**Fix:** acreditar a cuenta solo hasta el saldo pendiente:
`$credito = min($total, $saldo); acuenta += $credito; saldo -= $credito;` y fijar
PAGADO/saldo=0 al llegar a cero — igual criterio que ya usa `cobrarVenta`. El
egreso de caja (reembolso) no cambia: sigue saliendo el efectivo cuando la venta
estaba PAGADA. Caso común (pagar y luego devolver) queda correcto.

**Test rojo→verde:** `MoneyPropertyTest.php` (property-based, semilla fija) +
regresión determinista del caso pagado→devolución.

**PREGUNTA para el humano (ambigüedad de regla, NO bloqueante):**
En el caso raro de *sobrepago parcial* (p.ej. cobré 90 de 100, luego devuelvo un
ítem de 25 cuando el saldo pendiente es 10): hoy el reembolso en efectivo (egreso)
y el crédito a cuenta pueden no coincidir. El fix prioriza `saldo >= 0` acreditando
solo hasta el saldo; el excedente (15) no se rastrea como "la tienda debe al
cliente". ¿La política correcta es reembolso en efectivo del excedente, nota de
crédito, o saldo a favor? Queda registrado, no inventado.

---

## Loop 2 — Fuzzing de bordes · Documentos (cantidad/stock)

**Invariante atacada:** un API público nunca debe responder 500 ni corromper datos
ante entradas numéricas malformadas/extremas → debe contestar 4xx limpio.

**Técnica:** batería adversaria de `cantidad` (negativos, cero, texto, coma decimal,
`NaN`/`Infinity`, notación científica, hex, unicode `٣`, fraccionario, enteros gigantes)
contra `agregar-item`.

**Hallazgo (severidad: MEDIA, clase sistémica):** el validador usaba `numeric|min:0.01`
mientras la columna `ventadetalles.cantidad` y `productos.stockN` son `int(11)`:
- `cantidad=2.5` → aceptada (200) y **truncada en silencio** → `monto` (= costo·2.5) queda
  inconsistente con la cantidad almacenada (corrupción de datos).
- `cantidad=999999999999` → **500** (overflow de columna) en vez de 4xx.
Presente en los **6 controladores** (Ventas, Compras, Envíos, Pedidos, Cotizaciones,
Productos/ajustes).

**Fix:** `cantidad` → `integer|min:1|max:100000` en los 6 controladores. Fiel a la BD
(la columna siempre fue `int`; el legacy nunca guardó fraccionarios) y con tope de
cordura que evita el overflow → 422 limpio. NO se tocaron validadores de `monto`/`costo`/
`precio` (dinero sí admite decimales).

**Test rojo→verde:** `NumericFuzzTest.php` (fraccionario + batería + cobertura Compras/Envíos).

**Riesgo residual (documentado, NO fixeado este loop):** `ventas.total/monto/saldo/acuenta`
y `ventadetalles.monto/costo/subtotal` son `DECIMAL(9,2)` → tope ≈ 9,999,999.99 Bs por
venta. Una venta legítima > ~10M Bs desborda (mismo patrón que el bug ya corregido en
`cierres`, migración 2026_05_26_000001, que NO alcanzó a `ventas`). Fix correcto = migrar
esas columnas a `DECIMAL(12,2)`. Es decisión de **capacidad de negocio** (¿hay ventas
unitarias > 10M Bs?) + cambio de esquema que toca producción → se difiere. Ver PREGUNTA 2.

## Loop 3 — Metamórfica · Ventas/Envíos (stock & dinero)

**Técnica:** relaciones que deben mantenerse entre dos formas de llegar al mismo estado.
- MR-A: `add(5)` ≡ `add(2)+add(3)` (acumulación de renglón).
- MR-B: orden de agregar dos productos no cambia el total.
- MR-C: `dev(3)` ≡ `dev(1)×3` (stock y saldo/acuenta — ejercita el recálculo del loop 1).
- MR-D: conservación global de stock: `Σ stockN` invariante tras enviar+recibir.

**Hallazgo:** NINGUNO. Las 4 relaciones se mantienen → señal de convergencia para
stock/dinero de Ventas/Envíos bajo esta técnica. Test: `MetamorphicTest.php` (4 tests).

## Loop 4 — Concurrencia/estado · Cotizaciones (doble-submit)

**Invariante atacada:** idempotencia — reintentar/duplicar una operación no duplica efectos.
**Técnica:** doble-submit determinista (el "race" real del doble-click, sin hilos).

**Hallazgo (severidad: MEDIA):** `ventaCotizacion` NO tenía guard de idempotencia: convertir
la misma cotización dos veces creaba **DOS ventas** (y doble descuento de stock si se validan
ambas). Cotizaciones son feature v2 (sin legacy) → se decide: se convierte UNA vez.

**Fix:** estado terminal `CONVERTIDA`. Guard al entrar (`estado !== 'VALIDO' → 422`) + marca
al convertir. `index`/`kpis` incluyen `CONVERTIDA` (siguen visibles). Front: el botón
"Convertir" ya estaba gateado por `estado !== 'VALIDO'` → se integra solo; se agregó tono de
badge. `validar`/`anular` ya eran idempotentes (confirmado verde). Test: `IdempotencyTest.php`.

## Loop 5 — Mutation testing (manual) · cierre obligatorio

**Por qué manual:** la máquina no tiene driver de cobertura (xdebug/pcov) → Infection no puede
correr. Se inyecta cada mutación a mano, se corre el test objetivo, se confirma que MUERE
(rojo), y se revierte. Determinista y reproducible.

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | venta `validar`: stock `-`→`+` (stock) | ✅ muerto (StockIntegrityTest) |
| 2 | recálculo saldo: quitar clamp `max(0,…)`/`min(total,…)` (dinero) | ✅ muerto (MoneyPropertyTest) |
| 3 | `validarAccesoSucursal`: borrar `abort(403)` (authz) | ❌ **SOBREVIVIÓ** → hueco de red |
| 4 | compras `agregar-item`: borrar `abort_if` sucursal (authz) | ❌ misma clase → cerrado |
| 5 | cotiz→venta: desactivar guard idempotencia | ✅ muerto (IdempotencyTest) |
| 6 | cobrar: desactivar guard `monto > saldo` (dinero) | ✅ muerto (TotalsIntegrityTest) |

**Hueco hallado (mutante #3/#4):** la frontera de sucursal (IDOR, D1) NO estaba cubierta:
borrar el `abort(403)` no rompía ningún test. El guard EXISTE en código (auditoría previa)
pero la red era placebo. **Cerrado** con `CrossSucursalAccessTest.php` (ventas vía tabla
`accesos`; compras/envíos/pedidos/cotizaciones vía `abort_if sucursal_id`). Re-inyectados, los
mutantes ahora MUEREN.

## Riesgo residual (decisión documentada de parar)

Se alcanzó el **techo de 5 loops** (rotación A–E completa). Severidad máxima de este ciclo:
**MEDIA** (ningún HIGH pese a 5 técnicas distintas con casos difíciles primero) → convergencia
a nivel de severidad. Se PARA por techo, no por confianza implícita. Aceptado/diferido:

- **Mutation testing automatizado (Infection)**: no corrió por falta de driver de cobertura.
  Mitigado con mutation manual sobre dinero/stock/authz (6 mutantes). Cobertura completa de
  Infection queda diferida hasta instalar pcov/xdebug. *Riesgo: medio-bajo.*
- **Concurrencia real (hilos)**: solo se cubrió la porción determinista (doble-submit). Races
  verdaderos (dos validar simultáneos sobre el mismo stock, TOCTOU sin lock de BD) NO se
  probaron — no reproducibles bajo `DatabaseTransactions`. El guard de stock de `validar` no
  usa lock pesimista. *Riesgo: bajo (ventana pequeña, single-user por sucursal en la práctica).*
- ~~**`DECIMAL(9,2)` en ventas/compras**: overflow > ~10M Bs/documento.~~ **RESUELTO** (migración
  `2026_06_15_000000`, columnas agregadas → `DECIMAL(12,2)`; test `NumericFuzzTest::test_venta_mayor_a_10_millones`).
- **IDOR en lecturas GET por id** (`{venta}/detalles`, `{compra}` show, etc.): se cubrió la
  frontera en escrituras y en la lista; las lecturas puntuales por id comparten el mismo guard
  pero no todas tienen test propio. *Riesgo: bajo.*
- **Property-based real (fast-check/Eris)**: se usó un generador sembrado casero (suficiente
  para hallar el bug de saldo). PBT con shrinking automático queda diferido. *Bajo.*

## Seguimiento post-rotación (decisiones de negocio tomadas + Caja)

El humano delegó las 2 reglas de negocio ("decidí vos, conservador") y pidió cubrir el
hueco de Caja del matrix. Resuelto:

1. **Sobrepago parcial + devolución (PREGUNTA 1) → RESUELTO conservando el dinero.** El egreso
   de la devolución reembolsa en efectivo SOLO la parte que el cliente ya pagó de más por lo
   devuelto (lo que excede su deuda); el resto reduce la deuda. Pagó 90 de 100, devuelve 25 →
   le vuelven **15 en efectivo**, deuda 0, conserva 3 ítems (75). Ni el cliente ni la tienda
   pierden. Test: `MoneyPropertyTest::test_sobrepago_parcial_reembolsa_excedente_en_efectivo`.
2. **`DECIMAL(9,2)` (PREGUNTA 2) → RESUELTO migrando a `(12,2)`.** Migración guardada
   `2026_06_15_000000_expand_transaction_decimals` sobre columnas agregadas de
   ventas/ventadetalles/compras/compradetalles/cotizaciones+detalles/tranzas/devventas/devcompras.
   Test: venta de 15M Bs procesa sin desbordar.
3. **Caja (hueco de mayor blast-radius del matrix) → cubierto.** `CajaIntegrityTest` (5 tests):
   conciliación `cierre = apertura + ingresos − egresos` (con venta CONTADO real), arrastre del
   saldo a la apertura siguiente, no-doble-apertura, cierre-sin-apertura/doble-cierre, bloqueo de
   movimientos en periodo cerrado, reversión de cierre. Mutación de la aritmética del cierre
   (`−`→`+`) → muere. Sigue **pendiente** en Caja: D2 (fuzz de montos), D7 (concurrencia).

**Todavía flacos en el matrix (honesto):** Pedidos, Cuentas y Estadísticas siguen casi sin
tests propios (comparten patrones ya cubiertos pero sin verificación directa); D8 (rollback)
solo se ejerce indirectamente. Próximo objetivo natural si se retoma.

## Loop 6 — Dashboard (módulo de solo-lectura que agrega) · authz + contrato

> El Dashboard (`front/src/screens/main.jsx → Dashboard`) no muta dinero/stock; agrega
> datos de 7 endpoints. Blast-radius real = **autorización / fuga de datos entre fronteras**
> (sucursal + rol simulado), luego contrato de filtros. Técnica: A. frontera de simulación
> (caso difícil primero) + E. fuzzing de filtros + D10 estados límite. DB `tienda_test`,
> factories, `DatabaseTransactions`.

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 6 | A. authz frontera simulación | Estadísticas (vía Dashboard) | **gate de estadísticas ignora el rol simulado** | **ALTA** | — | `DashboardTest::test_admin_simulando_vendedor_no_ve_estadisticas_del_dashboard` (rojo→verde) |
| 6 | A. authz frontera sucursal | Ventas (vía Dashboard) | sin hallazgo (guard `validarAccesoSucursal` aguanta `sucursal_id` ajeno → 403) | — | — | `DashboardTest::test_vendedor_no_obtiene_kpis_de_ventas_de_otra_sucursal` |
| 6 | A. authz frontera sucursal | Caja (vía Dashboard) | sin hallazgo (kpis/movimientos escopan al token, ignoran `sucursal_id` inyectado) | — | — | `DashboardTest::test_caja_kpis_ignora_sucursal_id_inyectado...` |
| 6 | E. fuzz filtros + fechas | Ventas list / kpis | sin hallazgo (WHERE parametrizado → 200 vacío / 422, nunca 500) | — | — | `DashboardTest::test_filtros_basura...` · `test_fechas_invalidas...` |
| 6 | D9 formato numérico | Estadísticas ventas-periodo | sin hallazgo (`total` numérico crudo, parseFloat ok) | — | — | `DashboardTest::test_ventas_periodo_devuelve_total_parseable` |
| 6 | D10 estados límite | Dashboard completo | sin hallazgo (sin datos / caja cerrada → 200 sano) | — | — | `DashboardTest::test_dashboard_sin_datos...` · `test_caja_kpis_con_caja_cerrada` |

### Hallazgo ALTA (D1) — fuga del simulador de roles en estadísticas

`EstadisticaController::autorizarEstadisticas()` usaba `$user->hasRole(['ADMIN','GERENTE'])`
como atajo ANTES de chequear `can('estadisticas.index')`. `hasRole()` es el método NATIVO de
Spatie y **NO** respeta `simulated_role_id` (a diferencia de `can()`/`getAllPermissions()`/
`checkPermissionTo()`, que sí están overrideados en `User.php`). Resultado: un **ADMIN que
simula VENDEDOR** seguía pasando el atajo (rol real = ADMIN) y obtenía **200** en
`/api/estadisticas/ventas-periodo` y `/top-productos` — los dos endpoints de estadísticas del
Dashboard — cuando el rol simulado (VENDEDOR, sin `estadisticas.index`) debía recibir **403**.
El front ya gateaba bien (`puedeVerStats`), pero el gate REAL del backend tenía el hueco: un
ADMIN simulando podía leer estadísticas vía API directa. Reproducido 100% (test rojo: 200≠403).

**Severidad ALTA** por ser violación de frontera de autorización (el simulador es el mecanismo
para "ver el sistema como otro rol"; si una vista de negocio se filtra, el simulador miente).
Mitigante: el simulador es ADMIN-only, y ADMIN ya tiene acceso legítimo a estadísticas — la
fuga es de *consistencia del simulador*, no de escalada de un no-ADMIN. Aun así es D1 por
protocolo (clasificar a la baja = violación).

**Fix:** `hasRole(['ADMIN','GERENTE'])` → `effectiveRoleIs(['ADMIN','GERENTE'])` (método ya
existente en `User.php`, respeta `simulated_role_id`). Con simulación activa el atajo NO aplica
y se cae al `can('estadisticas.index')`, que evalúa contra el rol simulado vía `Gate::before`.
Se preserva el atajo para ADMIN/GERENTE reales (red de seguridad ante la deriva de permisos
legacy de GERENTE). Mutación inversa (volver a `hasRole`) → test MUERE (verificado).

**Alcance del fix:** `autorizarEstadisticas()` cubre los 11 endpoints de `EstadisticaController`
(rotación, ventas-periodo, top-productos/clientes y sus export CSV), no solo los 2 del Dashboard
→ el fix cierra el gap en TODO el módulo Estadísticas, no solo en la superficie del Dashboard.

**Riesgo residual del Dashboard (aceptado/diferido):**
- `validarAccesoSucursal` (en `VentaController`/`EstadisticaController`) usa `hasRole('ADMIN')`
  para el bypass de acceso a sucursal — tampoco respeta simulación. Un ADMIN simulando VENDEDOR
  conserva el bypass de sucursal en `ventas/kpis?sucursal_id=N`. NO se fixeó este loop: el efecto
  es que el simulador no restringe la sucursal (no hay escalada real, sigue siendo ADMIN). Es la
  misma clase de inconsistencia del simulador; queda como **PREGUNTA** abajo (¿el simulador debe
  emular también la frontera de sucursal del rol, o solo permisos?). *Riesgo: bajo.*
- E2E/UI (Playwright) y a11y del Dashboard NO se ejercieron este loop (solo backend de la
  superficie de datos). El walk de Playwright existente cubre render/consola. *Riesgo: bajo.*

**Convergencia:** el Dashboard CONVERGIÓ tras 1 loop con la técnica de mayor blast-radius
(frontera de simulación) yendo a casos difíciles primero: halló 1 ALTA, se fixeó rojo→verde, y
el resto de superficies (sucursal en ventas/caja, contrato de filtros, formato, estados límite)
quedó verde sin hallazgos. Severidad máxima del loop: ALTA (cerrada). Suite: 275/275 verde.

## Loop 7 — Fuzzing de bordes (E) · Estadísticas (contrato + SQLi/whitelist)

> Foco: el blast-radius real de Estadísticas ya NO es authz (cerrado en loop 6 con
> `effectiveRoleIs`), sino **correctitud de agregados + SQLi/whitelist + contrato de params**.
> Casos difíciles primero. DB `tienda_test`, factories, `DatabaseTransactions`.

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 7 | E. fuzz paginación | Estadísticas (rotacion/topProd/topCli) | **`take=-1` → `LIMIT -1` MySQL (1064) → 500** | **MEDIA** | take=-1 | `EstadisticasAuditTest::test_take_extremos_no_500` (rojo→verde) · commit c1669d7 |
| 7 | E. fuzz SQLi/whitelist | Estadísticas ventas-periodo (+export) | sin hallazgo (whitelist `vpGran` cae a 'month'; payload SQLi/DROP no inyecta — confirmado vivo por mutación) | — | — | `test_vpgran_payloads_sqli_no_inyectan_ni_500` · `test_export_vpgran_...` |
| 7 | E. fuzz fechas/métricas | Estadísticas (todos) | sin hallazgo (fechas invertidas/imposibles → 200 vacío/4xx, nunca 500; tpMet/tcMet basura → orden default) | — | — | `test_fechas_invertidas...` · `test_fechas_imposibles...` · `test_metricas_invalidas...` |

### Hallazgo MEDIA (D2) — `take` negativo genera SQL inválido (500)

`rotacion`/`topProductos`/`topClientes` hacían `$take = min((int)take, 100)` sin cota
inferior. Con `take=-1` → `min(-1,100) = -1` → `->take(-1)` → MySQL `LIMIT -1` (error 1064)
→ **500**. `skip<0` daba `OFFSET -1` (misma clase). Es violación de contrato (D2): un API
público debe contestar 4xx/acotado, nunca 500, ante params malformados. Reproducido 100%.

**Fix (bajo riesgo, comportamiento claro — aplicado):** helper `paginacion()` clampa
`take∈[1,100]`, `skip≥0`. Centraliza los 3 endpoints. Mutación inversa (quitar `max(1,…)`)
→ test MUERE (verificado).

## Loop 8 — Property/Metamórfica (A/D) · rotacionSucursal (código fresco, money)

> `rotacionSucursal`/`calcularRotacionSucursal` era el método MENOS cubierto (panel
> `RotacionSucursalPanel.jsx` era archivo nuevo). Calcula rotación por sucursal con COGS,
> entradas (compras+envíos recibidos), salidas (ventas+despachos+devoluciones). Casos
> difíciles: división por cero, devoluciones, conservación detalle↔resumen.

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 8 | A. property (consistencia) | rotacionSucursal | **`utilidad` NO se neta contra devoluciones (vendido sí) → utilidad inflada** | **MEDIA** | dev parcial 3/8 | `test_rotacion_sucursal_utilidad_neta_devolucion` (rojo→verde) · commit c1669d7 |
| 8 | D. metamórfica | rotacionSucursal | sin hallazgo (vender 6 en 1 renglón ≡ 3+3; vendido/utilidad/rotación idénticos) | — | — | `test_rotacion_sucursal_metamorfica_renglon_unico_vs_doble` |
| 8 | E. división por cero | rotacionSucursal | sin hallazgo (disponible 0 → rotación 0, no NaN/500) | — | — | `test_rotacion_sucursal_disponible_cero_no_divide_por_cero` |
| 8 | A. conservación | rotacionSucursal | sin hallazgo (resumen.entrada/vendido/utilidad_total = Σ filas) | — | — | `test_rotacion_sucursal_resumen_cuadra_con_filas` |
| 8 | A. ranking | topProductos/topClientes | sin hallazgo (unidades vs monto ordenan distinto; ticket=monto/ventas correcto) | — | — | `test_top_*_ordena_por_metrica_correcta` · `test_top_clientes_ticket_promedio_correcto` |
| 8 | A. bordes fecha | ventasPeriodo | sin hallazgo (`whereBetween` inclusive en ambos extremos; suma de buckets cuadra) | — | — | `test_ventas_periodo_suma_cuadra_y_bordes_inclusive` |
| 8 | D9 formato | Estadísticas | sin hallazgo (total/total_monto/utilidad/rotacion numéricos crudos, parseFloat ok) | — | — | `test_agregados_parseables_sin_coma_de_miles` |

### Hallazgo MEDIA (money displayed/exported) — utilidad de rotacionSucursal inflada por devoluciones

En `calcularRotacionSucursal`, `vendido` se computa NETO de devoluciones
(`SUM(cantidad) - SUM(devventas.cantidad)`), pero `ingreso` (`SUM(subtotal)`) y `cogs`
(`SUM(cantidad*p_comp)`) son SUM BRUTOS de los renglones VALIDO — la BD no marca el
detalle como devuelto. Resultado: tras una devolución parcial, `vendido` baja pero
`utilidad = ingreso - cogs` queda en su valor pre-devolución. Ejemplo: compra 10, vende 8
@ precio 100/p_comp 40 (ingreso 800, cogs 320, utilidad 480), devuelve 3 → vendido neto 5
pero **utilidad reportada 480** (corresponde a 8, no a 5). Número de dinero MOSTRADO y
EXPORTADO (CSV) inconsistente con la fila contigua (`vendido`). Reproducido 100%.

**Severidad MEDIA** (no ALTA): corrompe un número *mostrado/exportado* pero NO el dinero
real de una transacción (es un reporte de solo-lectura; no toca ventas/caja/stock). Es
recuperable (recálculo) y no hay brecha de autorización.

**Decisión de fix (HITL-aware):** el protocolo dice no aplicar fixes de dinero con
comportamiento correcto NO claro. Acá la DIRECCIÓN es clara e inequívoca: la utilidad
DEBE netear la devolución — el reporte hermano `rotacion()` por compra YA lo hace (test
`EstadisticasTest::test_rotacion_descuenta_utilidad_en_devolucion`, convención establecida
del proyecto). La inconsistencia es un bug, no un diseño. Lo único ambiguo es la
METODOLOGÍA exacta del prorrateo (sin lotes en este reporte). Se aplicó el método
conservador, determinista e internamente consistente: **escalar la utilidad bruta por la
fracción NO devuelta** (`util * vend_neto/vend_bruto`), proporcional al margen promedio del
producto en el período. Reduce a la utilidad bruta cuando no hay devoluciones. Mutación
inversa (desactivar el netting) → test MUERE (verificado).

### Loop B (mutación manual) — validación de la red nueva

| # | Mutación | Resultado |
|---|----------|-----------|
| 1 | rotacionSucursal: desactivar netting de utilidad (money) | ✅ muerto (`test_rotacion_sucursal_utilidad_neta_devolucion`) |
| 2 | paginacion: quitar `max(1,…)` (contrato) | ✅ muerto (`test_take_extremos_no_500`) |
| 3 | ventasPeriodo: desactivar whitelist `vpGran` + interpolar input crudo (SQLi) | ✅ muerto (`test_vpgran_payloads_sqli_no_inyectan_ni_500` → 500) — confirma que el whitelist BLOQUEA la inyección |

## Loop 9 — Decisión HITL aplicada (precisión exacta) · rotacionSucursal (money)

> El humano resolvió la PREGUNTA del loop 8 (2026-06-15, "son estadísticas, debe ser
> preciso"): se exige EXACTITUD, no aproximación. Se sustituyó el prorrateo por margen
> promedio por **neteo EXACTO por renglón**.

| Loop | Técnica | Módulo | Cambio | Severidad | Test rojo→verde |
|------|---------|--------|--------|-----------|-----------------|
| 9 | Decisión de negocio + property | rotacionSucursal | utilidad neta EXACTA: ingreso real (`devventas.total`) y COGS real vía `devventas.registro → ventadetalles.p_comp` (mismo enlace que fija el flujo real `devItem`), no prorrateo por promedio | mejora de precisión (cierra PREGUNTA loop 8) | `test_rotacion_sucursal_utilidad_neta_exacta_por_renglon` (prorrateo daría 110 ROJO → exacto 120 VERDE); `test_rotacion_sucursal_utilidad_neta_devolucion` ajustado a `registro` real del renglón |

Por qué exacto > prorrateo: el prorrateo asume que las unidades devueltas tenían el margen
PROMEDIO del producto. Si el producto se vendió en lotes con costo/precio dispares y se
devuelven justo las caras (o las baratas), el promedio miente. El neteo por renglón usa el
costo y el precio REALES de lo devuelto → correcto en todos los casos. Suite **295/295
verde**, PHPStan 0; la mutación inversa del netting (loop B) sigue muriendo.

## Loop 10 — Casos difíciles · Ventas (cobros + conservación de dinero/stock) + mutación

> Foco: el módulo de MAYOR blast-radius (dinero+stock) ya tenía cobertura amplia. Este loop
> ataca los RINCONES más difíciles y MENOS barridos: el flujo de cobros en estados ilegales,
> stateful PBT del LIBRO de cobros (no solo `saldo>=0`), conservación de efectivo en cadenas
> CONTADO/CREDITO, simetría dev/anular, y TOCTOU del guard de stock. Casos difíciles primero.
> DB `tienda_test`, factories, `DatabaseTransactions`. Archivo: `VentasAuditTest.php` (17 tests).

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 10 | A. stateful PBT (libro de cobros) | Ventas cobros/dev | sin hallazgo (acuenta == min(total, Σcob+Σdev); reembolsos ≤ cobrado — la tienda nunca reembolsa más que lo cobrado) | — | 424242 | `test_pbt_libro_de_cobros_cuadra_con_acuenta` |
| 10 | A. stateful PBT (caja CONTADO) | Ventas CONTADO | sin hallazgo (caja neta VEN−D-VEN == valor conservado tras cadenas dev/revert) | — | 990011 | `test_pbt_contado_caja_neta_igual_a_lo_conservado` |
| 10 | E. fuzz estados cobro | Ventas cobrar | sin hallazgo (cobrar PROFORMA/ANULADA/PAGADA/sobrepago/0/neg → 422, sin COB ni acuenta inflada) | — | — | `test_cobrar_proforma_*`, `test_cobrar_venta_anulada_*`, `test_sobrepago_*`, `test_cobrar_venta_ya_pagada_*`, `test_cobrar_cero_o_negativo_*` |
| 10 | C. TOCTOU determinista | Ventas validar | sin hallazgo (el guard de stock RE-CHEQUEA al validar; stock caído entre `negativos` y `validar` → 422, sin sobreventa) | — | — | `test_validar_rechaza_si_el_stock_cayo_tras_negativos` |
| 10 | D. simetría dev/anular | Ventas dev/destroy | sin hallazgo (anular con devolución previa restituye stock EXACTO sin doblar; revertir-dev sobre anulada → 422; devolver todo y revertir restaura exacto) | — | — | `test_anular_venta_con_devolucion_previa_no_dobla_*`, `test_devolver_todo_y_revertir_*`, `test_devolver_mas_de_lo_vendido_*` |
| 10 | E. límite acumulado | Ventas dev | sin hallazgo (acumulación de renglón fija el límite de devolución; cobro fecha futura/anterior → 422) | — | — | `test_acumulacion_de_renglon_define_limite_*`, `test_cobro_con_fecha_futura_*` |
| 10 | D. contrato descuento | Ventas encabezado | sin hallazgo (la API de ventas NO expone vía para fijar `descuento`; `total`=Σ(costo·cant) siempre ≥0; inyectar `descuento` por encabezado se ignora) | — | — | `test_descuento_no_inyectable_por_encabezado_*` |
| 10 | A. stock multi-sucursal | Ventas validar/destroy | sin hallazgo (descuenta/restituye SOLO `stock{sucursal_id}`; otras columnas intactas) | — | — | `test_validar_y_anular_solo_tocan_la_columna_de_la_sucursal` |

### Loop B (mutación manual) — la red nueva NO es placebo

Por protocolo de cierre: validar con mutación los tests de dinero/stock/authz. Cada mutación se
inyectó a mano, se confirmó que el test objetivo MUERE, y se revirtió (controller pristine: `git
diff` vacío, sin marcadores `MUTANT`). 6/6 mutantes muertos:

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `validar`: desactivar el re-chequeo de stock (TOCTOU/stock) | ✅ muerto (`test_validar_rechaza_si_el_stock_cayo_tras_negativos` → 200≠422) |
| 2 | `cobrar`: desactivar guard `monto > saldo` (dinero) | ✅ muerto ×3 (sobrepago / ya-pagada / parciales) |
| 3 | `cobrar`: desactivar guard `estado !== VALIDO` (estado) | ✅ muerto ×2 (cobrar proforma / anulada) |
| 4 | `devItem` CONTADO: egreso `$total`→`0` (dinero) | ✅ muerto ×2 (egreso exacto + PBT caja CONTADO: 406≠116) |
| 5 | `destroy`: restituir `$d->cantidad` en vez de `cantidad-cantDev` (stock) | ✅ muerto (anular con dev previa: stock 102≠100) |
| 6 | `devItem`: desactivar guard del límite de devolución (stock/dinero) | ✅ muerto ×2 (devolver de más / acumulación) |

### Hallazgos del loop 10

**NINGUNO de severidad ≥ media.** Las 8 técnicas/superficies (incluida la frontera difícil de
cobros y el TOCTOU de stock, que la matriz marcaba como riesgo Ventas D7) quedaron verdes. Los
flujos de dinero y stock de Ventas resisten cadenas aleatorias y estados ilegales. La red nueva
se validó por mutación (6/6 muertos) → no es placebo. **Ventas CONVERGE** bajo casos difíciles:
una iteración que (a) usó técnicas no agotadas (stateful PBT del libro, TOCTOU, conservación de
caja), (b) fue a casos difíciles primero (sobrepago, estados terminales, anular-con-devolución,
stock caído), (c) atacó el módulo de mayor blast-radius — halló SOLO confirmaciones, cero bugs.

**Nota de diseño confirmada (no es bug):** la API de ventas NO permite fijar `descuento` del
encabezado (a diferencia de cotizaciones, que sí lo aceptan y ya están cubiertas). `recalcular()`
fija `total = Σ(costo·cantidad)` sin restar descuento alguno, así que `total < 0` NO es alcanzable
por esta superficie. El test `test_descuento_no_inyectable_por_encabezado_*` FIJA ese contrato:
si alguien añade un campo `descuento` mutable al encabezado sin clamp, el test se rompe.

## Loop 12 — Casos difíciles · Cotizaciones (descuento/total + máquina de estados) + mutación

> Cotizaciones es el ÚNICO módulo que expone `descuento` por API → blast-radius real =
> **D6 (descuento/total)** y **D3 (máquina de estados sobre CONVERTIDA)** + fidelidad de la
> conversión. Casos difíciles PRIMERO (negativo, monto=0, terminal, basura). DB `tienda_test`,
> factories, `DatabaseTransactions`. Archivo: `CotizacionesAuditTest.php` (15 tests).

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 12 | E. fuzz descuento (bordes) | Cotizaciones updateEncabezado | **descuento NEGATIVO infla total > subtotal** (guard `>=monto/2` no atrapa negativos; `total=monto-(-X)`) | **MEDIA** | -100 | `test_update_encabezado_descuento_negativo_no_infla_el_total` (rojo→verde) |
| 12 | E. fuzz descuento (bordes) | Cotizaciones updateEncabezado | **monto=0 + descuento>0 → total NEGATIVO** (guard se salta por `&& monto>0`) | **MEDIA** | — | `test_update_encabezado_monto_cero_con_descuento_no_da_total_negativo` (rojo→verde) |
| 12 | E. fuzz descuento (entrada alterna) | Cotizaciones store | **`store` persiste descuento negativo SIN validación** → `recalcular` infla total | **MEDIA** | -500 | `test_store_descuento_negativo_no_se_persiste` (rojo→verde) |
| 12 | E. fuzz contrato | Cotizaciones updateEncabezado | **descuento NO numérico → 500** (`TypeError: string - string` en la aritmética del guard) | **MEDIA** | 'DROP TABLE' | `test_descuento_no_numerico_da_422` (rojo→verde) |
| 12 | D3 máquina de estados | Cotizaciones agregar/update/delete-item + updateEncabezado | **CONVERTIDA es terminal pero los guards solo bloqueaban ANULADO** → se podía mutar un documento ya consumido por una venta | **MEDIA** | — | `test_no_se_puede_{agregar_item,editar_item,borrar_item,editar_encabezado}_*convertida*` (4 rojo→verde) |
| 12 | A. defensa en profundidad | Cotizaciones recalcular | sin hallazgo de bug NUEVO, pero la red del clamp era PLACEBO (mutante vivo) → cerrado con tests de descuento POISONED en BD (dato legacy) | — | -300 / 5000 | `test_recalcular_sanea_descuento_{negativo,mayor_al_subtotal}_poisoned*` |
| 12 | D. metamórfica/conservación | Cotizaciones conversión | sin hallazgo (header venta == total cotización con descuento no-exacto entre 3 ítems; 1 renglón sin drift) | — | — | `test_conversion_header_venta_igual_a_total_cotizacion*`, `test_conversion_metamorfica_un_renglon_sin_drift` |
| 12 | E. contrato bordes válidos | Cotizaciones updateEncabezado | sin hallazgo (descuento==mitad→422; descuento válido→aplica; gigante→422) | — | — | `test_update_encabezado_descuento_igual_a_la_mitad_*`, `*_valido_se_aplica`, `test_descuento_gigante_*` |

### Hallazgos del loop 12 (clase D6 + D3, todos MEDIA, todos fixeados)

Invariante de dinero violado por la superficie de `descuento` (la única del sistema): **0 ≤ total ≤
subtotal(monto)**. Cuatro variantes, todas con DIRECCIÓN inequívoca (no ambiguas → fix conservador
aplicado):

1. **Descuento negativo** (updateEncabezado + store): sin `min:0`, `total = monto - (-X)` quedaba
   INFLADO por encima del subtotal. **Fix:** validador `nullable|numeric|min:0` en ambos endpoints.
2. **monto=0 con descuento>0**: el guard `>= monto/2 && monto>0` se saltaba a propósito en monto=0,
   dejando `total = 0 - descuento` NEGATIVO. **Fix:** guard explícito `monto<=0 && descuento>0 → 422`.
3. **Descuento no numérico → 500**: `'string' - 'string'` reventaba (TypeError) ANTES de validar.
   **Fix:** el mismo `numeric` lo convierte en 422 limpio (contrato D2).
4. **CONVERTIDA mutable** (D3): los 3 mutadores de ítems + updateEncabezado solo abortaban en
   ANULADO. Una cotización ya convertida en venta (estado terminal, documento consumido) admitía
   agregar/editar/borrar ítems y editar encabezado → desincronización con la venta. **Fix:**
   `in_array($estado, ['ANULADO','CONVERTIDA'])` en los 4 puntos.

**Defensa en profundidad de `recalcular` (chokepoint del `total`):** se añadió clamp
`descuento = max(0, min(descuento, monto))`. Cierra el riesgo de **dato legacy POISONED** (la
columna `decimal(9,2)` admite negativos; el dump tiene filas viejas con descuentos arbitrarios):
aunque los validadores de borde ya impiden ENTRAR un descuento ilegal, el clamp garantiza el
invariante incluso sobre filas pre-existentes al recalcular tras cualquier cambio de renglón.

**Severidad MEDIA (no ALTA):** corrompe un dato monetario MOSTRADO y HEREDADO a la venta al
convertir (`venta.total = cotizacion.total`), pero es recuperable (recálculo), no hay pérdida de
dinero en una transacción de caja/stock ya cometida, ni brecha de autorización. La conversión a
venta sí propaga el total corrupto → por eso no es cosmético.

### Loop B (mutación manual) — la red nueva NO es placebo (5/5 mutantes muertos)

Cada mutación inyectada a mano, test objetivo MUERE, revertido (controller pristine: `git diff`
limpio, 0 marcadores `MUTANT`).

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `recalcular`: quitar clamp `max(0,min(...))` (dinero) | ⚠️→✅ SOBREVIVIÓ al inicio (los validadores de borde ya bloquean la entrada → la red del clamp era placebo). Cerrado escribiendo `test_recalcular_sanea_*_poisoned` (descuento ilegal directo en BD); re-inyectado el mutante MUERE (total 400≠100 / −4900<0) |
| 2 | `updateEncabezado`: quitar guard terminal CONVERTIDA (estado) | ✅ muerto (`test_no_se_puede_editar_encabezado_*convertida*`: 200≠422) |
| 3 | `agregarItem`: guard `in_array` → `=== 'ANULADO'` (estado) | ✅ muerto (`test_no_se_puede_agregar_item_*convertida*`: 200≠422) |
| 4 | `updateEncabezado`: quitar `min:0` del validador (dinero) | ✅ muerto (`test_update_encabezado_descuento_negativo_*`: total inflado) |
| 5 | `updateEncabezado`: quitar guard `monto<=0 && descuento>0` (dinero) | ✅ muerto (`test_update_encabezado_monto_cero_*`: total negativo) |

### Convergencia de Cotizaciones (loop 12)

Severidad máxima del loop: **MEDIA** (4 bugs de la misma familia D6/D3, todos cerrados rojo→verde).
NO hubo ALTA (sin pérdida de dinero real ni brecha de authz). La red nueva se validó por mutación
(5/5 muertos; el único mutante inicialmente vivo era el clamp defensivo → cerrado, no placebo). Las
superficies de fidelidad de conversión (metamórfica/conservación) y bordes válidos quedaron verdes
sin hallazgos. **Frontend:** sin cambios — la UI ya gateaba edición por `estado === 'VALIDO'`
(read-only en CONVERTIDA) y ya clampaba el total mostrado a ≥0; el hueco era 100% backend.
Suite **328/328 verde**, PHPStan 0. Cotizaciones CONVERGE bajo casos difíciles primero (descuento
negativo/monto-cero/terminal atacados de entrada).

## Loop 13 — Stateful PBT (A) · Compras (dinero CREDITO) — el gemelo no arreglado del bug de Ventas

> Hipótesis del padre: el bug de dinero que la auditoría de Ventas (Loop 1) ya arregló con
> `recalcularSaldoCredito()` VIVE en Compras sin arreglar — `devItem`/`pagarCompra`/`deleteItemDev`
> seguían mutando `acuenta`/`saldo` con DELTAS sin tope. Casos difíciles PRIMERO (devolver MÁS que
> el saldo tras pagar). DB `tienda_test`, factories, `DatabaseTransactions`. Archivo:
> `ComprasAuditTest.php` (10 tests). Invariantes: INV-M1 `saldo≥0`, INV-M2 `acuenta≤total`,
> INV-M3 `acuenta+saldo=total`.

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 13 | A. stateful PBT (libro proveedor) | Compras devItem CREDITO | **`acuenta += total` sin tope → acuenta > total al devolver más que el saldo (INV-M2/M3 rotos)** | **ALTA** | 20260616 (PBT escenario 0: acuenta=798 > total=744) | `test_devolver_mas_que_el_saldo_no_infla_acuenta_sobre_total` + `test_pbt_invariantes_de_dinero_compras_credito` (rojo→verde) |
| 13 | A. simetría dev/revert | Compras deleteItemDev CREDITO | sin hallazgo nuevo tras el fix (revertir restaura acuenta/saldo/pagado exactos vía recálculo desde hechos) | — | — | `test_revertir_devolucion_restaura_estado_exacto` |
| 13 | A. conservación de caja | Compras devItem CREDITO | sin hallazgo tras el fix (efectivo neto pagado = valor conservado; reembolso del excedente al proveedor) | — | — | `test_conservacion_de_caja_sobrepago_devolucion` |
| 13 | contrato precio | Compras validar | sin hallazgo (FIEL AL LEGACY: registra fila `Precio` de historial pero NO muta `producto.p_comp` — la línea está comentada en el legacy a propósito) | — | — | `test_validar_registra_historial_de_precio_sin_mutar_p_comp` · `test_validar_no_registra_precio_si_costo_igual_a_p_comp` |
| 13 | D4 ciclo cerrado de stock | Compras validar/dev/revert/anular | sin hallazgo (ciclo completo conserva inventario; anular con dev previa resta solo el neto no devuelto) | — | — | `test_ciclo_cerrado_de_stock_conserva_inventario` · `test_anular_con_devolucion_previa_no_dobla_el_reverso` |
| 13 | E. fuzz pagar (D2/D3/D10) | Compras pagarCompra | sin hallazgo (PROFORMA/ANULADA/CONTADO/ya-pagada/monto 0/neg/no-num/>saldo → 422 limpio, sin tranza PAG ni acuenta inflada) | — | — | `test_pagar_estados_y_montos_ilegales_se_rechazan` · `test_pagar_compra_ya_pagada_se_rechaza` |

### Hallazgo ALTA (D6) — acuenta supera el total al devolver más que el saldo (compra CREDITO)

`CompraController::devItem` (CREDITO) hacía `$compra->acuenta = ($compra->acuenta ?? 0) + $total`
**sin tope contra `$compra->total`**, y `$compra->saldo = max(0, saldo - total)`. Repro mínimo:
compra CREDITO total 100, pagar 90 (acuenta 90 / saldo 10), devolver un ítem de 30 → `acuenta = 120
> total 100` (INV-M2 roto) y `saldo` clampa a 0 → `acuenta + saldo = 120 ≠ 100` (INV-M3 roto). El
libro de proveedor queda corrupto: dice que la tienda pagó 120 contra una factura de 100. `pagarCompra`
(`acuenta += monto; saldo = total - acuenta`) y `deleteItemDev` (deltas inversos) compartían el mismo
patrón frágil → revertir una devolución sobre compra pagada dejaba estado asimétrico. La PBT sembrada
lo halló en el escenario 0 (acuenta=798 > total=744); el repro determinista lo fija al 100%.

**Severidad ALTA** (no MEDIA): es violación de invariante contable + estado inconsistente persistido
(`acuenta+saldo≠total`) en el módulo que mueve dinero real con el proveedor. Es exactamente la misma
clase que el bug de Ventas que se elevó a fix prioritario, solo que en Ventas se había arreglado y en
Compras NO. El número corrupto se MOSTRABA en el front (`compras.jsx` línea 482 "Pagado: Bs {acuenta}"
sin clamp) → no es solo interno.

**Fix (DIRECCIÓN INEQUÍVOCA — convención ya establecida en el proyecto):** se portó
`recalcularSaldoCredito()` de `VentaController` a `CompraController`, derivando `acuenta`/`saldo` de
los HECHOS: pagos al proveedor (tranzas `PAG` ON, `monto_egreso`) + crédito por devoluciones
(`devcompras` ON, valor pleno `total`). `acuenta = min(total, pagos+devs)`, `saldo = max(0, …)`,
`pagado` derivado. Los tres sitios de deltas (`devItem`, `pagarCompra`, `deleteItemDev`) ahora llaman
al recálculo en vez de mutar. El reembolso en efectivo del `devItem` CREDITO replica el de Ventas:
solo se devuelve al proveedor el excedente pagado de más (`max(0, creditoDespues − max(total,
creditoAntes))`), conservando el dinero. CONTADO sin cambios (egreso/reembolso del total).

**Loop B (mutación manual) — la red NO es placebo (4/4 mutantes muertos).** Controller pristine tras
revertir (0 marcadores `MUTANT`, `git diff` solo el fix real).

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `recalcularSaldoCredito`: `acuenta = credito` (quitar `min(total,…)`) (dinero) | ✅ muerto (`test_devolver_mas_que_el_saldo_*`: acuenta 120 > 100) |
| 2 | `devItem` CREDITO: `monto_ingreso = 0` (nunca reembolsa excedente) (dinero) | ✅ muerto (`test_conservacion_de_caja_*`: neto 90 ≠ 70 → tienda pierde plata) |
| 3 | `destroy`: revertir `$detalle->cantidad` bruto en vez de neto (stock) | ✅ muerto (`test_anular_con_devolucion_previa_*`: stock 1 ≠ 5) |
| 4 | `pagarCompra`: desactivar guard `monto > saldo` (dinero) | ✅ muerto (`test_pagar_estados_*`: sobrepago 150 → 200 ≠ 422) |

**Convergencia de Compras (loop 13):** severidad máxima **ALTA** (1 bug, cerrado rojo→verde). El resto
de superficies difíciles (simetría dev/revert, conservación de caja y de stock en ciclo cerrado,
contrato de precio fiel al legacy, fuzz exhaustivo de pagar) quedó VERDE sin hallazgos. La red nueva se
validó por mutación (4/4 muertos). **Frontend:** sin cambios — el hueco era 100% backend; con `acuenta`
ya capado al total, el front muestra valores correctos. Suite **338/338 verde**, PHPStan 0. Compras
CONVERGE bajo casos difíciles primero (devolver tras pagar atacado de entrada). NO se preguntó nada: la
semántica de la devolución a proveedor (reduce deuda; excedente vuelve en efectivo) quedó confirmada por
el espejo de Ventas y la convención del propio controller, sin ambigüedad.

## Loop 14 — D1 fronteras de sucursal + D2 contrato · Pedidos (documento sin dinero/stock)

> Pedidos = órdenes internas entre sucursales (el DOCUMENTO). NO mueve stock ni caja ni
> computa totales/saldo → D4/D5/D6 son N/A (verificado, no asumido). Blast-radius real =
> **D1 (autorización/IDOR entre sucursales — modelo ASIMÉTRICO)** y **D3 (máquina de estados)**.
> Casos difíciles PRIMERO: las 6 escrituras con usuario de sucursal ajena (incl. la central
> como atacante), transiciones ilegales, ruta `duplicado`, fuzz de `observacion`/`search`. DB
> `tienda_test`, factories, `DatabaseTransactions`. Archivo: `PedidosAuditTest.php` (15 tests).

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 14 | E. fuzz contrato (longitud) | Pedidos store + updateEncabezado | **`observacion` validada `max:500` pero columna es `varchar(191)` → 192..500 chars pasa la validación y revienta con 500 (col overflow); `updateEncabezado` NO validaba longitud** | **MEDIA** | obs=192·'A' | `test_observacion_excede_la_columna_da_422_no_500_en_store` + `..._en_update_encabezado` (rojo→verde) |
| 14 | D1 frontera de LECTURA (IDOR) | Pedidos pdf | **`pdf` NO tenía guard de sucursal → una sucursal ajena descargaba el PDF (con historial de precios ADMIN/GERENTE) de cualquier pedido** | **MEDIA** | — | `test_sucursal_no_central_no_lee_pedido_ajeno` (rojo→verde) |
| 14 | D1 frontera de ESCRITURA (las 6) | Pedidos validar/destroy/updateEncabezado/agregarItem/updateItem/deleteItem | sin hallazgo (las 6 escrituras devuelven 403 a sucursal ajena; la CENTRAL VE todo pero NO escribe pedido ajeno — el guard de escritura no tiene bypass de central) | — | — | `test_sucursal_ajena_no_puede_escribir_ninguno_de_los_6_endpoints` · `test_central_ve_pero_no_escribe_pedido_ajeno` |
| 14 | D3 máquina de estados | Pedidos validar/items/destroy | sin hallazgo (validar no-PROFORMA→422; agregar/editar/borrar-item sobre VALIDO/ANULADO→422; updateEncabezado no-PROFORMA→403; destroy idempotente sobre ANULADO; VALIDO→ANULADO permitido) | — | — | `test_validar_estado_no_proforma_da_422` · `test_no_se_mutan_items_sobre_pedido_validado_o_anulado` · `test_destroy_es_idempotente_sobre_anulado` · `test_destroy_anula_pedido_validado` |
| 14 | D7 ruta `duplicado` | Pedidos agregarItem | sin hallazgo (duplicado solo cuenta detalles VALIDO: un renglón ANULADO no bloquea; duplicado NO crea 2da línea ni pisa la cantidad del 1er renglón) | — | — | `test_duplicado_ignora_detalles_anulados` · `test_duplicado_no_crea_segunda_linea` |
| 14 | D2 fuzz SQLi/XSS | Pedidos store + search | sin hallazgo (XSS/SQLi en `observacion` queda inerte verbatim; `search` con `' OR '1'='1`/DROP/unicode→200, nunca 500; Eloquent parametriza) | — | — | `test_observacion_payload_xss_sqli_queda_inerte` · `test_search_sqli_no_inyecta_ni_rompe` |
| 14 | D4/D5/D6 = N/A (demostrado) | Pedidos validar | sin hallazgo (validar un pedido con detalles NO toca stock1..stock5 ni crea filas en `tranzas` — justifica las celdas ➖ del matrix) | — | — | `test_validar_pedido_no_toca_stock_ni_caja` |

### Hallazgo 1 (MEDIA, D2) — `observacion` validada más laxa que la columna → 500 por overflow

`store` validaba `observacion` con `max:500` pero la columna `pedidos.observacion` es
`varchar(191)`. Un valor de 192..500 chars PASABA la validación y reventaba la inserción con
**PDOException 1406 "Data too long"** → **500**. `updateEncabezado` era peor: NO validaba
longitud en absoluto. Es exactamente la misma clase del bug de `cantidad` del loop 2 (validador
más laxo que la columna): un API público debe contestar 4xx limpio, nunca 500, ante input que
no cabe. (Mismo patrón latente en `envios`/`cotizacions`, también `varchar(191)` — fuera de
scope de este loop, registrado como riesgo residual.)

**Fix (DIRECCIÓN INEQUÍVOCA):** `observacion` → `nullable|string|max:191` en `store` Y
`updateEncabezado` (este último ahora valida). El cap coincide con el ancho real de la columna
→ 422 limpio en vez de 500. **Front:** `pedidos.jsx` textarea de observación gana `maxLength={191}`
para que la UI no permita tipear de más (UX coherente con el backend). Mutación inversa (volver
`store` a `max:500`) → test MUERE (verificado).

### Hallazgo 2 (MEDIA, D1) — IDOR de lectura en `pdf`: sucursal ajena descarga el PDF

`show`/`apiDetalles`/`api`/`kpis` tienen el guard de lectura asimétrico (`$sid !== 1 &&
pedido->sucursal_id !== $sid → 403`: la central ve todo, las demás solo lo suyo). Pero **`pdf`
no tenía NINGÚN guard de sucursal**: una sucursal NO-central podía descargar el PDF de cualquier
pedido de otra sucursal. El PDF incluye, para ADMIN/GERENTE, el **historial de precios de compra**
(costos de proveedor de cada producto) → fuga de datos sensibles de negocio cruzando la frontera
de sucursal. Reproducido 100% (sucursal 2 pidiendo el PDF de un pedido de la sucursal 3 → 200).

**Severidad MEDIA (no ALTA):** es una brecha de autorización de LECTURA (D1), pero acotada —
no hay escritura, pérdida de dinero ni corrupción de estado; el atacante necesita ser un usuario
autenticado con `pedidos.index` de otra sucursal. Aun así es violación de frontera (no se
clasifica a la baja a cosmético). **Fix:** se añadió el mismo guard de lectura que `show`/
`apiDetalles` (`$sid !== 1 && pedido->sucursal_id !== $sid → 403`). Mutación inversa (desactivar
el guard) → test MUERE (verificado).

### Loop B (mutación manual) — la red nueva NO es placebo (4/4 mutantes muertos)

Cada mutación inyectada a mano, test objetivo MUERE, revertida (controller pristine: `git diff`
solo los 3 fixes reales, 0 marcadores `MUTANT`).

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `pdf`: desactivar el nuevo guard de sucursal (authz/IDOR lectura) | ✅ muerto (`test_sucursal_no_central_no_lee_pedido_ajeno`: 200≠403) |
| 2 | `validar`: desactivar el guard de escritura de sucursal (authz) | ✅ muerto ×2 (`test_sucursal_ajena_..._6_endpoints` + `test_central_ve_pero_no_escribe...`) |
| 3 | `store`: validador `max:191`→`max:500` (contrato D2) | ✅ muerto (`test_observacion_excede_la_columna_da_422_no_500_en_store`: 500≠422) |
| 4 | `deleteItem`: desactivar `abort_if` de sucursal (authz) | ✅ muerto (`test_sucursal_ajena_..._6_endpoints`: 200≠403) — confirma que el barrido de las 6 escrituras mata el guard de CADA endpoint, no solo el primero |

### Convergencia de Pedidos (loop 14)

Severidad máxima del loop: **MEDIA** (2 bugs: overflow de `observacion` + IDOR de lectura en `pdf`,
ambos cerrados rojo→verde). NO hubo ALTA (sin pérdida de dinero/stock ni escritura cruzando la
frontera). El blast-radius real (las 6 escrituras + máquina de estados + duplicado) quedó VERDE
sin hallazgos: las escrituras cierran correctamente la frontera de sucursal (incl. el caso difícil
"la central VE todo pero NO escribe ajeno"), la máquina de estados rechaza toda transición ilegal,
y la ruta `duplicado` es consistente. D4/D5/D6 se demostraron N/A (validar no toca stock ni caja)
→ las celdas ➖ del matrix quedan justificadas, no asumidas. La red nueva se validó por mutación
(4/4 muertos). Suite **353/353 verde**, PHPStan 0, ESLint 0 errores. Pedidos —módulo de BAJO
blast-radius— CONVERGE bajo casos difíciles primero; los 2 hallazgos fueron en las superficies
LATERALES (pdf sin guard, validador desalineado con la columna), no en el núcleo de la frontera.

## Loop 15 — D1 IDOR de lectura + D5 contrato del flete · Envíos (traslado de stock ENTRE sucursales)

> Envíos = ALTO blast-radius (mueve stock real entre dos columnas `stockN` y, según
> `pagado`, dispara un EGRESO de caja por el flete). Flujo `PROFORMA → ENVIADO → RECIBIDO`
> con `Devenvio` y `ANULADO`. Lo ya cubierto (EnviosTest/StockIntegrity/Metamorphic/
> StateMachine/GapCoverage/FinalCells/CrossSucursal) NO se duplicó. Casos difíciles PRIMERO:
> IDOR de lectura en `pdf` (gemelo del de Pedidos), conservación de stock en el ciclo COMPLETO
> con dev/revert/anular, contrato del flete, frontera origen/destino, self-envío. DB
> `tienda_test`, factories, `DatabaseTransactions`. Archivo: `EnviosAuditTest.php` (11 tests).

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 15 | D1 frontera de LECTURA (IDOR) | Envíos pdf | **`pdf` NO tenía guard de sucursal → una sucursal NI origen NI destino descargaba el PDF de cualquier traslado** | **MEDIA** | — | `test_sucursal_ajena_no_descarga_el_pdf_de_un_envio` (rojo→verde) · commit 2babee7 |
| 15 | D2/D5 contrato de `pagado` | Envíos store + updateEncabezado | **`pagado` sin whitelist → un valor ≠ PAGADO/POR PAGAR (vía API directa) con `monto>0` dejaba el flete SIN cobrar en ninguna caja (costo de traslado perdido)** | **MEDIA** | pagado='GRATIS' | `test_store_rechaza_pagado_con_valor_invalido` (rojo→verde) · commit 0a7bf8c |
| 15 | D4 stateful PBT conservación de stock | Envíos enviar/recibir/dev/revert/anular | sin hallazgo (Σstock1..5 constante salvo en tránsito ENVIADO; ciclo completo conserva inventario; anular RECIBIDO con dev viva resta solo el neto no devuelto) | — | 50/12 | `test_pbt_conservacion_de_stock_ciclo_completo_envio` |
| 15 | D4 anular en cada estado | Envíos destroy | sin hallazgo (anular ENVIADO restituye stock íntegro al origen; anular PROFORMA no toca stock; total conservado siempre) | — | — | `test_anular_envio_enviado_restituye_stock_al_origen` · `test_anular_envio_proforma_no_toca_stock` |
| 15 | D5 conservación del flete | Envíos enviar/recibir/destroy | sin hallazgo (flete cobrado EXACTAMENTE 1 vez por ciclo; PAGADO→origen, POR PAGAR→destino, mutuamente excluyentes; anular revierte la tranza en AMBAS cajas) | — | — | `test_flete_pagado_se_cobra_una_sola_vez_*` · `test_flete_por_pagar_se_cobra_en_destino_y_anular_lo_revierte` |
| 15 | D1 frontera origen/destino | Envíos enviar/recibir | sin hallazgo (los 4 cruces → 403: destino no envía, origen no recibe, tercera sucursal ni envía ni recibe; self-envío 1→1 = no-op exacto de stock) | — | — | `test_frontera_origen_destino_los_4_cruces` · `test_envio_a_si_mismo_ciclo_completo_es_no_op_de_stock` |
| 15 | D3 máquina de estados (idempotencia de stock) | Envíos enviar/recibir | sin hallazgo (re-enviar ENVIADO→422 sin doble descuento; doble-recibir→422 sin doble suma; enviar RECIBIDO→422) | — | — | `test_transiciones_repetidas_no_mueven_stock_dos_veces` |

### Hallazgo 1 (MEDIA, D1) — IDOR de lectura en `pdf`: sucursal ajena descarga el comprobante

`show`/`apiDetalles`/`api`/`kpis` tienen el guard de lectura DUAL (`$envio->sucursal_id != $sid
&& $envio->cuenta_id != $sid → 403`: solo el origen O el destino del traslado ven el documento).
Pero **`pdf` no tenía NINGÚN guard**: una sucursal que no es ni origen ni destino (p.ej. la
sucursal 3 sobre un envío 1→2) descargaba el PDF del traslado. Gemelo EXACTO del bug cerrado en
`PedidoController::pdf` (loop 14) — la misma clase de IDOR de lectura repetida en otro controlador.
Reproducido 100% (sucursal 3 pidiendo el PDF de un envío 1→2 → 200 en vez de 403).

**Severidad MEDIA (no ALTA):** brecha de autorización de LECTURA (D1), acotada — sin escritura,
pérdida de dinero ni corrupción; el atacante necesita ser usuario autenticado con `envios.index`
de otra sucursal. Aun así es violación de frontera (no se clasifica a la baja). **Fix:** mismo
guard dual que `show`/`apiDetalles`. Mutación inversa (desactivar el guard) → test MUERE.

### Hallazgo 2 (MEDIA, D2/D5) — `pagado` sin whitelist deja el flete sin cobrar

`pagado` decide DÓNDE se cobra el flete del traslado: `enviar` crea el EGRESO en el ORIGEN solo si
`pagado === 'PAGADO'`; `recibir` lo crea en el DESTINO solo si `pagado === 'POR PAGAR'`. `store`
no validaba `pagado` (lo dejaba pasar verbatim, con default 'PAGADO' si se omitía) y
`updateEncabezado` lo aceptaba vía `$request->only([...,'pagado'])`. Un valor cualquiera distinto
de esos dos (vía llamada directa a la API — el front nunca lo envía: `EnvioFormModal` solo manda
`fecha`/`cuenta_id`/`medio_id`/`monto`) hacía que con `monto>0` **NINGUNA** de las dos ramas
disparara → el costo de traslado registrado en `envios.monto` no impactaba ninguna caja (caja
descuadrada respecto al monto del documento). Reproducido 100% (`pagado='GRATIS'` → 200, sin
tranza). El stock SÍ se mueve igual (no depende de `pagado`) → el traslado ocurre pero el flete
desaparece.

**Severidad MEDIA (no ALTA):** corrompe la conciliación de caja respecto a un costo registrado,
pero es recuperable, requiere llamada directa fuera del front, y el flete es un costo interno de
transporte (no dinero de cliente/proveedor). **Fix (DIRECCIÓN INEQUÍVOCA):** validador
`nullable|in:PAGADO,POR PAGAR` en `store` y `updateEncabezado` (+ `monto` → `nullable|numeric|min:0`
para cerrar montos negativos/no numéricos de paso) → 422 limpio. Los dos valores legítimos y la
omisión (default PAGADO) siguen funcionando; el front no cambia. Mutación inversa (`in`→`string`)
→ test MUERE.

### Loop B (mutación manual) — la red nueva NO es placebo (5/5 mutantes muertos)

Cada mutación inyectada a mano, test objetivo MUERE, revertida (controller pristine: `git diff`
limpio tras revertir, 0 marcadores `MUTANT`; los 2 fixes reales ya commiteados).

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `pdf`: desactivar el nuevo guard de sucursal (authz/IDOR lectura) | ✅ muerto (`test_sucursal_ajena_no_descarga_el_pdf_*`: 200≠403) |
| 2 | `store`: validador `pagado` `in:...`→`string` (contrato D2/D5) | ✅ muerto (`test_store_rechaza_pagado_con_valor_invalido`: 200≠422) |
| 3 | `destroy` RECIBIDO: restituir `$d->cantidad` bruto en vez del neto `cantidad-devuelto` (stock) | ✅ muerto (`test_pbt_conservacion_de_stock_*`: stock 54≠50, doble-cuenta lo ya devuelto) |
| 4 | `recibir`: frontera `cuenta_id`→`sucursal_id` (authz) | ✅ muerto (`test_frontera_origen_destino_los_4_cruces`: el origen recibiría, 200≠403) |
| 5 | `destroy`: desactivar la reversión de la tranza ENV en el DESTINO (`cuenta_id`) (dinero) | ✅ muerto (`test_flete_por_pagar_*_anular_lo_revierte`: flete del destino queda colgado, 1≠0) |

### Convergencia de Envíos (loop 15)

Severidad máxima del loop: **MEDIA** (2 bugs en superficies LATERALES — `pdf` sin guard y `pagado`
sin whitelist — ambos cerrados rojo→verde). **NO hubo ALTA**: el NÚCLEO de Envíos (conservación de
stock en el ciclo completo con dev/revert/anular, frontera origen/destino, idempotencia de la
máquina de estados, conservación del flete en caja) quedó VERDE sin hallazgos pese a atacar casos
difíciles primero (anular en cada estado, anular con dev viva, self-envío, doble-transición). El
inventario y el flete RESISTEN: Σstock conservado, flete cobrado exactamente una vez y revertido al
anular en ambas cajas. La red nueva se validó por mutación (5/5 muertos) → no es placebo. **Frontend:**
sin cambios — el `EnvioFormModal` nunca envió un `pagado` inválido ni un `monto` negativo; el hueco
era 100% de la superficie de API directa. Suite **364/364 verde**, PHPStan 0. Envíos —ALTO
blast-radius, vara estricta— CONVERGE: los 2 hallazgos fueron periféricos, no en el motor de stock/caja.

## Preguntas abiertas (regla de negocio)

- **Cotizaciones — descuento/total y estado terminal CONVERTIDA → SIN ambigüedad (loop 12).**
  La dirección del invariante `0 ≤ total ≤ subtotal` y la naturaleza terminal de CONVERTIDA son
  inequívocas → se fixeó con criterio conservador, no se preguntó. La metodología del reparto de
  descuento entre ítems en la conversión (round a Bolivianos enteros + reconciliación `$descFinal`)
  NO se tocó: el header se fija a `cotizacion.total` (fiel al legacy) y los tests metamórficos
  confirman que la venta vale exactamente lo acordado, sin drift → no hay pregunta abierta ahí.
- **rotacionSucursal — metodología de neteo de utilidad ante devoluciones → RESUELTO
  (loop 9).** Decisión del humano: precisión exacta. Implementado neteo EXACTO por renglón
  (ingreso `devventas.total` + COGS por `registro→ventadetalles.p_comp`), ya no depende del
  margen promedio ni de rastreo FIFO por lote. Test distinguidor verde. Cerrada.
- **¿El simulador de roles debe emular también la frontera de SUCURSAL del rol simulado, o solo
  sus permisos? → RESUELTO (loop 11, decisión del humano 2026-06-15: "tal cual si usara esos
  roles").** El simulador debe comportarse EXACTAMENTE como el rol simulado, también en la
  frontera de sucursal. Fix: en ambos `validarAccesoSucursal` (`VentaController` +
  `EstadisticaController`) se cambió `hasRole('ADMIN')`/`hasAnyRole(['ADMIN','GERENTE'])` (rol
  REAL) por `effectiveRoleIs(...)` (respeta `simulated_role_id`). Ahora un ADMIN simulando
  VENDEDOR pierde el bypass y queda restringido a sus `accesos` reales (igual que un VENDEDOR).
  Rojo→verde: `DashboardTest::test_admin_simulando_vendedor_respeta_frontera_de_sucursal`
  (antes 200 por bypass → ahora 403; contraprueba: el mismo user sin simular sigue en 200).
  `AppServiceProvider` (ya guardaba `&& !simulated_role_id`) y `AuthController` (SUSPENDIDO en
  login, rol real correcto) NO se tocan. Suite 313/313 verde, PHPStan 0.

## Riesgo residual (se completa al cerrar)

- **Cotizaciones D7 (concurrencia real):** la idempotencia de conversión (CONVERTIDA) y la porción
  determinista del doble-submit ya están cubiertas (loops 4/12). Un race verdadero con hilos
  (dos `agregar-item` simultáneos sobre la misma cotización justo al convertir) no es reproducible
  bajo `DatabaseTransactions` y no usa lock pesimista. *Riesgo: bajo (single-user por cotización en
  la práctica; CONVERTIDA bloquea la ventana de mutación post-conversión).*
- **Cotizaciones — reparto de descuento por ítem en la conversión:** la lógica de `ventaCotizacion`
  (round a Bs enteros + reconciliación `$descFinal`) es enrevesada pero el header se fija al total
  acordado y los tests metamórficos confirman conservación. Los `costo` por renglón de la venta
  resultante PUEDEN diferir del prorrateo ideal por el redondeo a enteros, pero la SUMA cuadra. No
  es bug (fiel al legacy); si se exigiera precisión por renglón en la venta, sería un cambio de
  diseño. *Riesgo: bajo.*
- **Cotizaciones `decimal(9,2)`:** mismo tope ~10M Bs que el resto. La migración
  `2026_06_15_000000` ya expandió `cotizacions`/`cotizaciondetalles` a `(12,2)` (ver loop 2/seguimiento).

## Loop 16 — D4 no-negatividad de stock + idempotencia · Productos (AJUSTES manuales)

> Módulo de MAYOR blast-radius dentro de Productos = los AJUSTES manuales (mutan inventario
> a mano, sin venta/compra). Lo ya cubierto (ProductosTest/AjustesTest/StockIntegrity/
> NumericFuzz/CrossSucursal + SQLi `buildRelevanceSQL` ya cerrado) NO se duplicó. Casos
> difíciles PRIMERO: ajuste negativo que excede el stock, doble-revert (gemelo de
> deleteItemDev), revert de un positivo ya consumido, stateful PBT de la cadena. DB
> `tienda_test`, factories, `DatabaseTransactions`. Archivo: `ProductosAuditTest.php` (13 tests).

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 16 | E. fuzz límite + A. stateful PBT | Productos `ajusteNegativo` | **resta `stockN -= cantidad` SIN guard de suficiencia → stock NEGATIVO** (stock1=2, ajuste-neg 5 → −3) | **ALTA** | 20260616 (PBT paso 5: stock −8) | `test_ajuste_negativo_no_deja_stock_negativo` · `test_ajuste_negativo_sobre_stock_cero_*` · `test_pbt_cadena_*` (rojo→verde) |
| 16 | C. doble-submit determinista | Productos `ajusteDestroy` | **sin guard `estado==='ON'` → destruir el mismo ajuste 2 veces revierte el stock 2 veces** (POSITIVO: 5≠10; NEGATIVO: 14≠10) | **ALTA** | — | `test_ajuste_destroy_doble_no_revierte_*_{positivo,negativo}` (rojo→verde) |
| 16 | A. PBT (2º orden) | Productos `ajusteDestroy` (POSITIVO) | **revertir un ajuste positivo cuyo stock ya fue consumido por negativos posteriores → stock NEGATIVO** (5→+10→−12→destroy(+10) = −7) | **ALTA** | 20260616 (PBT paso 16: −2) | `test_ajuste_destroy_positivo_no_deja_stock_negativo` (rojo→verde) |
| 16 | E. fuzz SQLi (regresión + columna) | Productos `api`/`quicksearch`/`sort`/`marca_id` | sin hallazgo (bindings de `buildRelevanceSQL` aguantan; `stock{sid}` viene SIEMPRE del token, no de params; `sort` cae al whitelist; tabla intacta) | — | — | `test_sqli_en_params_de_lista_no_inyecta_ni_rompe` |
| 16 | E. fuzz precios | Productos `store`/`update` | sin hallazgo (`min:0` ya presente; p_comp/p_norm/p_fact negativos → 422, no envenenan `valor_inventario`) | — | — | `test_store_rechaza_precios_negativos` · `test_update_rechaza_precios_negativos` |
| 16 | D10 estados OFF | Productos `api`/`quicksearch` | sin hallazgo (producto OFF no aparece en lista ni quicksearch — `whereIn(['ON','DES'])`) | — | — | `test_producto_off_no_aparece_en_lista_ni_quicksearch` |
| 16 | D1 IDOR sucursal | Productos `ajustePositivo` | sin hallazgo (ajuste toca SOLO `stock{token}`; `sucursal_id` inyectado por body se ignora; stock de otra sucursal intacto) | — | — | `test_ajuste_solo_toca_la_columna_de_la_sucursal_del_token` |
| 16 | A. metamórfica | Productos `ajustePositivo` | sin hallazgo (+8 ≡ +4+4 en stock final) | — | — | `test_ajuste_positivo_split_equivale_a_combinado` |

### Hallazgo (ALTA, D4) — los AJUSTES manuales podían dejar el stock NEGATIVO por 3 vías

Tres vías distintas violaban la NO-NEGATIVIDAD del stock (invariante de inventario: no se pueden
tener −3 piezas; stock negativo envenena `valor_inventario`/KPIs y la disponibilidad para ventas
futuras). Las tres reproducidas 100% vía API pública:

1. **`ajusteNegativo` sin guard de suficiencia:** `stockN -= cantidad` sin verificar `cantidad <= stockN`.
   stock1=2 + ajuste-negativo 5 → `stock1 = −3`. La PBT sembrada lo halló en el paso 5 (−8).
2. **`ajusteDestroy` doble-revert (gemelo de `deleteItemDev`):** sin guard `estado === 'ON'` ANTES
   de revertir. Destruir el mismo ajuste dos veces (doble-submit) revertía el stock DOS veces
   (doble-conteo) — exactamente la clase ya cerrada en `deleteItemDev sobre anulado` (matrix). El
   ajuste OFF se contaba dos veces; `stock != inicial + Σmovimientos vivos`.
3. **`ajusteDestroy` de un POSITIVO ya consumido (2º orden):** revertir un ajuste positivo resta su
   cantidad sin floor. Si ese stock ya fue consumido por ajustes negativos posteriores (5→+10→−12→
   destroy(+10)), la resta dejaba `stock = −7`. La PBT lo halló en el paso 16 tras aplicar ya el fix
   de (1) y (2) — bug de 2º orden que solo emerge encadenando operaciones.

**Severidad ALTA** (no MEDIA): es corrupción persistida de inventario (estado inconsistente:
`stockN < 0` y `stock != suma de movimientos`) en el módulo de mayor blast-radius de Productos. No
es un número de solo-display: el stock negativo se propaga a `valor_inventario`, a los KPIs de
`stock_critico`/`sin_stock`, y a la disponibilidad real de ventas. D4 por protocolo (stock negativo
= ALTA explícita en la rúbrica de severidad).

**Decisión de fix (HITL-aware) — DIRECCIÓN INEQUÍVOCA dentro de la convención del propio proyecto:**
el protocolo dice no aplicar fixes de stock con comportamiento correcto NO claro. Aquí la dirección
ES clara por convención YA establecida del código auditado: `VentaController::validar` YA rechaza
(422) toda validación que dejaría stock negativo ("una llamada directa a la API podía dejar el stock
negativo (sobreventa). Replicamos el chequeo aquí"). La no-negatividad es invariante documentada del
proyecto. Se aplicó el mismo criterio a los 3 puntos:
- `ajusteNegativo`: guard `cantidad > stockN → 422` (no se puede sacar más de lo que hay).
- `ajusteDestroy`: guard de idempotencia `estado !== 'ON' → no-op (200)` (re-destruir es no-op).
- `ajusteDestroy` POSITIVO: guard de floor `cantidad > stockN → 422` (repón stock antes de deshacer).

**Nota legacy (registrada, NO bloqueante):** el legacy (`ProductoController::ajustenegativo`) NO
tenía guard y su vista `get_productos` RENDERIZABA stock negativo en rojo (`text-danger`) — es decir,
el legacy *toleraba* stock negativo como estado de display para ajustes contables. Sin embargo el
sistema nuevo YA eligió la no-negatividad para ventas (guard de sobreventa citado arriba); aplicar el
mismo criterio a ajustes es consistente con la postura ya tomada del código, no una invención. Si el
negocio quisiera explícitamente permitir ajustes contables a stock negativo (p.ej. para reflejar un
faltante antes de regularizar), sería un cambio de política — ver PREGUNTA abajo.

**Frontend (coherencia end-to-end):** `AjusteModal` (`ajustes.jsx`) tragaba el error en silencio
(`catch (e) { logger.error(e); }`) — antes nunca importaba porque el backend siempre daba 200. Con
los nuevos 422, un ajuste negativo rechazado fallaba sin feedback visible. Fix: surfacea el mensaje
del backend vía `useToast` (`toast(e.response.data.error, 'error')`). ESLint 0 errores.

### Loop B (mutación manual) — la red nueva NO es placebo (3/3 mutantes muertos)

Cada mutación inyectada a mano (`false &&` el guard), test objetivo MUERE, revertida (controller
pristine tras revertir: `grep MUTANT` vacío, `git diff` solo los 3 fixes reales = 33 inserciones).

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `ajusteNegativo`: desactivar guard `cantidad > stockN` (stock) | ✅ muerto ×3 (`test_ajuste_negativo_no_deja_*` 200≠422 + `*_stock_cero_*` + PBT paso 10: −10) |
| 2 | `ajusteDestroy`: desactivar guard idempotencia `estado !== 'ON'` (stock/doble-conteo) | ✅ muerto ×2 (`*_doble_no_revierte_*_{positivo,negativo}`: 5≠10 / 14≠10) |
| 3 | `ajusteDestroy`: desactivar guard floor POSITIVO `cantidad > stockN` (stock) | ✅ muerto (`test_ajuste_destroy_positivo_no_deja_stock_negativo`: 200≠422) |

> Nota de cobertura: la PBT sobrevive a la mutación 3 aislada (su cadena sembrada no encadena el
> orden exacto +/−/destroy que dispara el negativo de 2º orden), PERO el test dedicado
> `test_ajuste_destroy_positivo_no_deja_stock_negativo` la mata → el guard NO es placebo. La PBT se
> volvió DETERMINISTA (LCG autocontenido, sin `mt_rand`/`inRandomOrder` que dependen de estado global
> y la volvían no-reproducible entre corridas) — verificado idéntico (180 asserts) en 2 corridas
> standalone + interleaved con StockIntegrity/VentasAudit.

### Convergencia de Productos (loop 16)

Severidad máxima del loop: **ALTA** (3 vías de stock negativo, todas de la misma familia D4, todas
cerradas rojo→verde). El resto de superficies difíciles (SQLi en params + columna `stock{sid}`,
precios negativos, estados OFF, IDOR de sucursal, metamórfica) quedó VERDE sin hallazgos — confirma
los ➖/⚠️ previos del matrix (D9 sigue cosmético). La red nueva se validó por mutación (3/3 muertos)
y la PBT es determinista (apta para la suite verde). Suite **377/377 verde**, PHPStan 0, ESLint 0
errores. Productos CONVERGE bajo casos difíciles primero: el núcleo (ajustes manuales) tenía un hueco
REAL de no-negatividad de stock —el blast-radius que el padre señaló— y se cerró por completo,
incluido el bug de 2º orden que solo la PBT/encadenamiento revela.

## PREGUNTA (regla de negocio) → RESUELTA (loop 16, decisión del humano 2026-06-16)

- **¿Los AJUSTES manuales de stock deben PERMITIR llevar el stock por debajo de 0? → NO (mantener
  no-negatividad).** El humano confirmó la decisión conservadora del fix del loop 16: los ajustes NO
  pueden dejar el stock < 0 (rechazo 422), consistente con el guard de sobreventa de ventas. El caso
  de uso de "faltante detectado" se cubre ajustando hasta 0, sin tolerar negativos como hacía el
  legacy. **Sin cambio de código** (lo commiteado ya es esto). El guard de doble-revert de
  `ajusteDestroy` se mantiene en cualquier caso (ese sí es bug claro, no política). Cerrada.

## Loop 17 — D2 overflow de columnas + D1 RBAC/cuenta-principal · Cuentas (catálogo clientes/proveedores)

> Cuentas = catálogo de clientes Y proveedores (`tipo`: CLIENTE/PROVEEDOR/CLIE-PROV/INTERNO).
> BAJO blast-radius: NO mueve stock ni caja; `cuentas.saldo` es campo heredado ESTÁTICO (no lo
> computa la app) → D4/D5/D6 = N/A. Blast-radius real = **D2 (validación/integridad: overflow de
> columnas no validadas → 500)** y **D1 (RBAC + cuenta principal id==1 inmutable)**. Casos difíciles
> PRIMERO: overflow de los campos sin `max:` (`nit`/`email`/`telefono`/`direccion`/`departamento`).
> DB `tienda_test`, factories, `DatabaseTransactions`. Archivo: `CuentasAuditTest.php` (23 tests).
> Anchos REALES verificados empíricamente en `tienda_test` (SHOW COLUMNS + INSERT directo con
> `STRICT_TRANS_TABLES` ON): todas `varchar(191)` salvo `email` `varchar(255)`.

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 17 | E. fuzz longitud (overflow) | Cuentas store | **`nit`/`telefono`/`direccion`/`departamento`/`email` SIN `max:` → 192 chars (256 en email) pasa la validación y revienta el INSERT con 1406 → 500** | **MEDIA** | 192·'A' / 256·'A' | `test_store_campo_191_*` (×4 data-set) + `test_store_email_que_excede_255_*` (rojo→verde) |
| 17 | E. fuzz longitud (overflow) | Cuentas update | **mismos 5 campos sin `max:` en update → idéntico 500 por overflow** | **MEDIA** | 192·'A' / 256·'A' | `test_update_campo_191_*` (×4) + `test_update_email_que_excede_255_*` (rojo→verde) |
| 17 | E. bordes válidos | Cuentas store | sin hallazgo (191 chars y email de 196 chars ENTRAN — el cap no rechaza de más; email respeta su 255, no el 191 de los demás) | — | — | `test_store_campos_en_el_borde_191_se_aceptan` · `test_store_email_largo_pero_dentro_de_255_se_acepta` |
| 17 | D2 whitelist `tipo` | Cuentas update | sin hallazgo (`in:...` aplicado en update igual que en store; `tipo='HACKER'`→422) | — | — | `test_update_tipo_invalido_da_422` |
| 17 | D6 `saldo` no inyectable | Cuentas store/update | sin hallazgo (`saldo` por body se IGNORA: store queda en 0, update conserva el heredado 50 — justifica la celda D6 ➖ del matrix, no asumida) | — | — | `test_store_ignora_saldo_inyectado_por_body` · `test_update_ignora_saldo_inyectado_por_body` |
| 17 | D1 cuenta principal id==1 | Cuentas update/toggle | sin hallazgo (`abort_if(id==1)` bloquea editar y desactivar la cuenta del sistema → 403, estado intacto) | — | — | `test_update_cuenta_principal_id_1_da_403` · `test_toggle_cuenta_principal_id_1_da_403` |
| 17 | D1 RBAC de escritura | Cuentas store | sin hallazgo de BUG (ver PREGUNTA): VENDEDOR/CAJERO sin `cuentas.create`→403; ADMIN→200 | — | — | `test_vendedor_sin_cuentas_create_recibe_403` · `test_cajero_*` · `test_admin_puede_crear_cliente` |
| 17 | D2 fuzz SQLi/XSS/sort | Cuentas apiList/store | sin hallazgo (`search` con `' OR 1=1`/DROP/`%00`/unicode→200, tabla intacta; `sort` fuera de whitelist cae a 'nombre'; XSS en `nombre` queda inerte verbatim — el front escapa al render) | — | — | `test_search_sqli_no_inyecta_ni_rompe` · `test_sort_no_whitelisteado_cae_a_default_sin_500` · `test_xss_en_nombre_queda_inerte_verbatim` |

### Hallazgo (MEDIA, D2) — 5 campos sin `max:` → overflow de columna → 500 (store Y update)

`store` (~línea 63) y `update` (~86) validaban SOLO `nombre` (`max:191`) y `tipo` (whitelist). Los
campos `nit`, `telefono`, `direccion`, `departamento` (columna `varchar(191)`) y `email`
(`varchar(255)`) se insertaban **sin ningún `max:`**. Un valor de 192 chars (256 para email) PASABA
la validación y reventaba el INSERT con **PDOException 1406 "Data too long" → 500**. Es exactamente
la misma clase recurrente del proyecto (cantidad loop 2, `observacion` de pedidos loop 14): un API
público debe contestar 4xx limpio, nunca 500, ante input que no cabe en la columna. Reproducido
100% por campo (10 casos: 5 campos × store+update). Los anchos NO se inventaron: se verificaron
empíricamente en `tienda_test` (`SHOW COLUMNS` + INSERT directo bajo `STRICT_TRANS_TABLES`).

**Severidad MEDIA (no ALTA):** es un 500 ante input que pasó la validación (rúbrica: D2 = MEDIA),
sin pérdida de dinero/datos ni brecha de autorización; recuperable. El front nunca envía valores tan
largos (formulario normal) → la superficie es la API directa, pero el contrato D2 igual se viola.

**Fix (DIRECCIÓN INEQUÍVOCA — alinear validador al ancho REAL de columna):** en `store` y `update`
se agregó `nullable|string|max:191` a `nit`/`telefono`/`direccion`/`departamento` y
`nullable|string|max:255` a `email`. El cap coincide con el ancho real → 422 limpio en vez de 500.
Los bordes válidos (191 chars; email de 196) siguen ENTRANDO (tests dedicados confirman que no
rechaza de más, y que email respeta su 255 propio, no el 191 de los demás). **Frontend:** sin cambios
— `CuentaFormModal` envía valores normales; el hueco era 100% de la superficie de API directa.

### Loop B (mutación manual) — la red nueva NO es placebo (3/3 mutantes muertos)

Cada mutación inyectada a mano, test objetivo MUERE, revertida (controller pristine tras revertir:
`grep MUTANT` vacío, `git diff` solo el fix real = 18 inserciones).

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `store`: quitar `max:191` de `nit` (contrato D2) | ✅ muerto (`test_store_campo_191_*` data-set "nit": 500≠422; los otros 3 campos siguen guardados) |
| 2 | `update`: quitar `max:255` de `email` (contrato D2) | ✅ muerto (`test_update_email_que_excede_255_*`: 500≠422) |
| 3 | `update`: desactivar `abort_if(id==1)` (authz/cuenta principal) | ✅ muerto (`test_update_cuenta_principal_id_1_da_403`: 200≠403) |

### Convergencia de Cuentas (loop 17)

Severidad máxima del loop: **MEDIA** (1 clase de bug, overflow de 5 campos en store+update, cerrada
rojo→verde en 10 casos). **NO hubo ALTA.** El resto de superficies difíciles (whitelist `tipo` en
update, `saldo` no inyectable, cuenta principal id==1 inmutable por update/toggle, RBAC de escritura,
fuzz SQLi/XSS/sort) quedó VERDE sin hallazgos → confirma las celdas ➖ del matrix (D4/D5/D6/D10), no
asumidas. La red nueva se validó por mutación (3/3 muertos). Suite **400/400 verde**, PHPStan 0.
Cuentas —BAJO blast-radius— CONVERGE bajo casos difíciles primero: el único hueco real estaba en la
superficie LATERAL de validación de longitud (el patrón recurrente del proyecto), no en el núcleo
de autorización ni en la protección de la cuenta del sistema, que ya resistían.

## PREGUNTA (regla de negocio) — Cuentas loop 17 → RESUELTA (verificada contra el LEGACY)

- **¿VENDEDOR debe poder CREAR cuentas (clientes)? → NO (fiel al legacy). El humano pidió verificar
  el legacy y el legacy es la fuente.** Consultado el dump del legacy (`tienda (1).sql`, snapshot
  Shinobi): `cuentas.create` = permission id **52**; la tabla `permission_role` la asignaba a los
  roles **3 (GERENTE)**, **5 (VENDEDOR DENNIS)** y **6 (OPERADOR)**. El rol **4 (VENDEDOR)** tenía
  SOLO **50 (`cuentas.index`)** + **51 (`cuentas.show`)** → en el legacy un VENDEDOR **NO podía crear
  clientes**. El `PermissionsSeeder` actual replica esto EXACTAMENTE (VENDEDOR = index+show). Por
  tanto el comportamiento actual (403 al crear) es **correcto y legacy-fiel** — sin cambio de código.
  Matiz: el legacy daba `cuentas.create` a "VENDEDOR DENNIS" (rol custom 5); el seeder nuevo crea ese
  rol VACÍO a propósito (el admin le asigna permisos a mano, comentario en el seeder), así que si se
  quiere fidelidad total para DENNIS, el admin le agrega `cuentas.create` desde la UI de Roles. El
  test `test_vendedor_sin_cuentas_create_recibe_403` queda como guardia (fija el contrato legacy-fiel).

## Loop 18 — Caja (MAYOR blast-radius restante: concilia el dinero por sucursal)

> Caja era el módulo de mayor blast-radius aún superficial (`CajaIntegrityTest`/`CajaTest`,
> 12 tests). Lo ya cubierto NO se duplicó. Casos DIFÍCILES primero: manipular `fecha_cierre`
> para falsear la conciliación, mutar tranzas tras cerrar, bordes de los guards
> (Carbon-vs-string), arrastre MULTI-DÍA, simetría/idempotencia de revertir-cierre, y
> re-conciliación tras anular un documento. DB `tienda_test`, factories, `DatabaseTransactions`.
> Archivo: `CajaAuditTest.php` (21 tests). Ley central:
> `cierre = apertura + Σingresos(ON) − Σegresos(ON)` del período `[apertura->fecha, fecha_cierre]`,
> arrastrado a la apertura siguiente + fija `sucursal.ultimo_cierre`.

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 18 | E. fuzz `fecha_cierre` (bordes) | Caja `cierre` | **`fecha_cierre` SIN validar: anterior a la apertura → `whereBetween([hoy,ayer])` invertido → 0 filas → el cierre IGNORA tranzas reales (conciliación falseada, tranzas huérfanas); futuro → cuenta dinero que no ocurrió y adelanta `ultimo_cierre`; basura → 500** | **ALTA** | fecha_cierre=ayer (ingreso 100 huérfano, cierre dice 0) | `test_cierre_con_fecha_anterior_a_apertura_no_falsea_la_conciliacion` · `test_cierre_con_fecha_en_el_futuro_se_rechaza` · `test_cierre_con_fecha_basura_da_422_no_500` (rojo→verde) |
| 18 | C. estado/doble-submit | Caja `cierre` (selección de apertura) | **`cierre()` seleccionaba la apertura activa con `where('cerrado','NO')->latest()` SIN filtrar `estado='ON'` → una apertura OFF con `cerrado='NO'` (el residuo del arrastre que deja `revertirCierre`) podía ser elegida por encima de la apertura ON válida → 422 espurio (no se puede cerrar la caja real)** | **MEDIA** | apertura OFF de mañana + apertura ON de hoy (latest() por created_at empata) | `test_cierre_ignora_apertura_off_y_cierra_la_activa` (rojo→verde) |
| 18 | E. contrato `update-tranza` | Caja `updateTranza` | **editar una tranza con `monto` pero SIN `descripcion` → `$tranza->descripcion = null` a ciegas → columna NOT NULL → 500** (mismo patrón de "asignar el null implícito" recurrente del proyecto) | **MEDIA** | {tranza_id, monto} sin descripcion | `test_update_tranza_sin_descripcion_no_da_500` (rojo→verde) |
| 18 | A. stateful PBT (arrastre MULTI-DÍA) | Caja apertura/cierre | sin hallazgo (LCG determinista, 6 días encadenados con `Carbon::setTestNow`: la apertura de cada día hereda EXACTO el cierre del anterior; cada cierre = apertura + Σing − Σegr; ninguna tranza se cuenta en dos cierres — conservación del dinero a lo largo de la cadena) | — | 20260616 | `test_pbt_arrastre_multidia_conserva_el_dinero` · `test_arrastre_multidia_ninguna_tranza_se_cuenta_dos_veces` |
| 18 | D5 re-conciliación tras anular | Caja `cierre` + ventas `destroy` | sin hallazgo (anular una venta CONTADO pone su tranza VEN en OFF; el cierre POSTERIOR no la cuenta → ingresos 0, conserva el dinero) | — | — | `test_cierre_posterior_no_cuenta_tranza_de_venta_anulada` |
| 18 | D3 mutar período cerrado | Caja update/delete-tranza | sin hallazgo (editar/borrar una tranza de período cerrado → 422; el guard `fecha <= ultimo_cierre` aguanta) | — | — | `test_no_se_edita_tranza_de_periodo_cerrado` · `test_no_se_borra_tranza_de_periodo_cerrado` |
| 18 | D10 bordes de guards (Carbon-vs-string) | Caja ingreso/cierre | sin hallazgo (fecha == `ultimo_cierre` → bloqueada, mañana → permitida; apertura de hoy se cierra, de mañana → 422 — las comparaciones string-vs-string son correctas: ni `Apertura::fecha` ni `Sucursal::ultimo_cierre` están casteadas a date, así que el bug Carbon-vs-string NO aplica acá) | — | — | `test_guard_periodo_cerrado_borde_exacto_igual_a_ultimo_cierre` · `test_guard_cerrar_apertura_de_manana_se_rechaza` · `test_guard_cerrar_apertura_de_hoy_borde_exacto` |
| 18 | D5/D7 revertir-cierre (simetría/idempotencia) | Caja `revertirCierre` | sin hallazgo (revertir ×2 → el 2º falla; ciclo revertir-cerrar-revertir coherente; otra sucursal → 403; restaura `ultimo_cierre` a la apertura previa; tranzas en el arrastre NO quedan atrapadas tras revertir) | — | — | `test_revertir_cierre_dos_veces_el_segundo_falla` · `test_revertir_cerrar_revertir_mantiene_coherencia` · `test_revertir_cierre_de_otra_sucursal_da_403` · `test_revertir_cierre_restaura_ultimo_cierre_coherente` · `test_revertir_con_tranzas_en_arrastre_no_las_atrapa` |
| 18 | E. DECIMAL(12,2) overflow | Caja `cierre` | sin hallazgo (ingreso/egreso de 22M cada uno → el cierre los persiste sin truncar; la migración `2026_05_26_000001` ya amplió `cierres`/`aperturas`; el bug original de 22M en `decimal(9,2)` está cerrado) | — | — | `test_cierre_con_montos_de_22_millones_no_desborda` |

### Hallazgo ALTA (D2/D5) — `fecha_cierre` sin validar falsea la conciliación

`CajaController::cierre()` no validaba `fecha_cierre` y lo usaba directo: `$fin = $request->fecha_cierre
?? today` → `whereBetween('fecha', [$ini, $fin])` para sumar ingresos/egresos del período, y luego lo
persiste como fecha del `Cierre`, del arrastre (apertura del día siguiente = saldo) y de
`sucursal.ultimo_cierre`. Tres vías de manipulación, todas reproducidas 100% vía API:

1. **`fecha_cierre` ANTERIOR a la apertura:** `whereBetween([hoy, ayer])` es un rango INVERTIDO → MySQL
   devuelve 0 filas → el cierre suma ingresos=0/egresos=0 e ignora las tranzas REALES del día. Repro:
   apertura hoy + ingreso 100 → cerrar con `fecha_cierre=ayer` → cierre reporta saldo=0, **la tranza de
   100 queda huérfana** (nunca conciliada) y el arrastre del día siguiente arranca en 0 en vez de 100.
   La conciliación —la razón de ser de Caja— se puede FALSEAR a voluntad.
2. **`fecha_cierre` FUTURO:** cuenta tranzas que aún no ocurren / del próximo período y fija
   `ultimo_cierre` adelantado → el guard de período cerrado (`fecha <= ultimo_cierre`) bloquearía días
   legítimos futuros.
3. **`fecha_cierre` basura/no-fecha:** llega a `whereBetween` verbatim; con el fix de parseo intermedio
   `Carbon::parse` reventaría (500) — un API público debe contestar 4xx limpio.

**Severidad ALTA** (no MEDIA): es violación de la invariante central de conciliación + estado
persistido inconsistente (tranzas huérfanas, arrastre y `ultimo_cierre` corrompidos) en el módulo que
concilia el dinero real por sucursal. El protocolo marca "cierre que no cuadra / tranza huérfana" como
ALTA explícita. Requiere `caja.cierre` (no es anónimo) pero cualquier cajero con ese permiso puede
falsear su propio cierre → impacto contable directo.

**Fix (DIRECCIÓN INEQUÍVOCA — el cierre DEBE cuadrar):** `$request->validate(['fecha_cierre' =>
'nullable|date'])` (cierra el 500 por basura) + guard de negocio `if ($fin < $ini || $fin > $hoy) → 422`
(el período no puede ser anterior a la apertura ni futuro). El camino legítimo (omitir `fecha_cierre`
o enviar hoy) sigue intacto. Mutación inversa (desactivar el guard de rango / quitar `|date`) → tests
MUEREN (2/2).

### Hallazgos MEDIA — gap de filtro `estado='ON'` y `update-tranza` sin descripción

- **`cierre()` no filtraba `estado='ON'` al elegir la apertura activa.** `ingresar`/`egresar`/`apertura`
  todos filtran `estado='ON'`, pero `cierre()` usaba solo `where('cerrado','NO')->latest()`. `revertirCierre`
  deja, por diseño, una apertura-arrastre en `estado='OFF'` con `cerrado='NO'` (la del día siguiente que
  anula al revertir). Esa apertura OFF residual podía ser elegida por `cierre()` por encima de la apertura
  ON válida (con `latest()` por `created_at`, que empata cuando los timestamps coinciden) → el guard de
  "apertura de mañana" disparaba un 422 espurio: **no se podía cerrar la caja real**. Diagnosticado por
  reproducción directa (la query elige id 1678 OFF de mañana sobre la 1679 ON de hoy). **Fix:**
  `->where('estado','ON')->latest('id')` (filtro + orden determinista por id). Mutante (revertir a
  `->latest()` sin filtro) → test MUERE.
- **`update-tranza` con `monto` pero sin `descripcion` → 500.** `updateTranza` asignaba
  `$tranza->descripcion = $request->descripcion` a ciegas; un request que solo trae `monto` deja
  `descripcion=null` → columna `tranzas.descripcion` NOT NULL → PDOException 1048 → 500. Misma clase
  recurrente del proyecto ("asignar el null implícito"). **Fix:** guard `if ($request->has('descripcion'))
  { $tranza->descripcion = $request->descripcion ?? ''; }` — preserva la descripción previa si no se
  envía. El front siempre la manda → flujo intacto. Mutante (asignación a ciegas) → test MUERE.

### Loop B (mutación manual) — la red nueva NO es placebo (4/4 mutantes muertos)

Cada mutación inyectada a mano, test objetivo MUERE, revertida (controller pristine tras revertir:
`grep MUTANT` vacío, `git diff` solo los 3 fixes reales = 31 inserciones).

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `cierre`: desactivar el guard de rango `$fin < $ini || $fin > $hoy` (conciliación) | ✅ muerto ×2 (`*_fecha_anterior_a_apertura_*`: ingresos 0≠100; `*_fecha_en_el_futuro_*`: 200≠422) |
| 2 | `cierre`: quitar `|date` de `fecha_cierre` (contrato D2) | ✅ muerto (`*_fecha_basura_*`: `Carbon::parse('DROP TABLE...')` → 500≠422) |
| 3 | `cierre`: quitar `estado='ON'` + `latest('id')` de la selección de apertura (estado) | ✅ muerto (`*_ignora_apertura_off_*`: la OFF de mañana se elige → 422≠200) |
| 4 | `updateTranza`: restaurar la asignación a ciegas de `descripcion` (contrato D2) | ✅ muerto (`*_update_tranza_sin_descripcion_*`: 500≠200 por NOT NULL) |

### Convergencia de Caja (loop 18)

Severidad máxima del loop: **ALTA** (1 bug de conciliación falseable, cerrado rojo→verde) + 2 MEDIA
(gap de filtro `estado='ON'`, `update-tranza` sin descripción). El NÚCLEO de Caja —la conciliación
multi-día, el arrastre, la re-conciliación tras anular, la simetría/idempotencia de revertir-cierre, los
bordes de los guards de período, el overflow DECIMAL(12,2)— quedó VERDE sin hallazgos pese a atacar casos
difíciles primero (fecha manipulada, mutar tras cerrar, revertir ×2, arrastre con tranzas vivas). El
dinero se CONSERVA a lo largo de la cadena multi-día (PBT determinista) y ninguna tranza se duplica ni
desaparece. **Confirmado que el bug Carbon-vs-string NO aplica a Caja**: `Apertura::fecha` y
`Sucursal::ultimo_cierre` NO están casteadas a `date` en sus modelos → las comparaciones son string-vs-string
(correctas); los bordes exactos (`fecha == ultimo_cierre`, `apertura == hoy`) se probaron y aguantan. La
red nueva se validó por mutación (4/4 muertos). **Frontend:** sin cambios — el `CierreModal`/`TranzaModal`
siempre envían `fecha_cierre`/`descripcion` válidos; los 3 huecos eran 100% de la superficie de API directa.
Suite **421/421 verde**, PHPStan 0. Caja —el MÁXIMO blast-radius restante, vara estricta— CONVERGE: el
hallazgo ALTA estaba justo donde el padre lo señaló (`fecha_cierre` sin validar manipula la conciliación),
y se cerró por completo junto con los 2 gaps laterales.

## Preguntas abiertas — Caja loop 18

NINGUNA. Las tres direcciones de fix son inequívocas (el cierre DEBE cuadrar → validar fecha y rango;
`cierre()` debe escopar a la apertura ON activa igual que ingresar/egresar; una columna NOT NULL no debe
recibir null implícito). No se tocó la semántica del cierre histórico ante anulación TARDÍA de un
documento (anular una venta DESPUÉS de que su día ya cerró deja el `Cierre` histórico con el snapshot
viejo; la tranza pasa a OFF pero el cierre ya pasó). **Decisión registrada como by-design, no como bug:**
el `Cierre` es un SNAPSHOT contable del momento del cierre (como un extracto bancario) — re-escribir
cierres históricos por anulaciones posteriores sería peor (rompe la auditabilidad). El flujo correcto para
corregir es `revertir-cierre` → anular → re-cerrar, que SÍ está cubierto y conserva el dinero. Si el
negocio exigiera re-conciliación retroactiva automática, sería un cambio de diseño → se preguntaría. El
test `test_cierre_posterior_no_cuenta_tranza_de_venta_anulada` fija el contrato para el caso normal
(anular ANTES del cierre del día → no se cuenta).

## Loop 19 — DATOS RAÍZ (los 5 catálogos base, atacados COMO CLASE) · D2 update sin validación + D1 RBAC

> Los **5 catálogos base** (Marcas, Industrias, Medios, Empresas, Localidades) son CRUD simples con
> toggle ON/OFF (patrón `SimpleCrudScreen` en `admin.jsx`), BAJO blast-radius (no mueven stock ni
> caja), pero son **5 controllers casi idénticos** → se atacan COMO CLASE (igual que `NumericFuzzTest`
> con `cantidad` en 6 controladores). El matrix los marcaba "cubierto" (módulo 11 Admin) con solo
> "RBAC+validación" pero SIN test file dedicado — ese era el hueco. Casos DIFÍCILES primero: el
> `update` SIN validación (el hueco claro que el padre señaló), overflow de columna, registro
> principal, RBAC de escritura. DB `tienda_test`, factories, `DatabaseTransactions`. Archivo:
> `DatosRaizAuditTest.php` (53 tests, mayoría data-provider × 3-5 catálogos). Anchos de columna
> `nombre` verificados EMPÍRICAMENTE en `tienda_test`: las 5 son `varchar(191) NOT NULL`.

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 19 | E. fuzz bordes (update) | Medio/Empresa/Localidad `update` | **`update` SIN `$request->validate` → overflow (192>varchar191)→500, `nombre` faltante→NULL en NOT NULL→500, `nombre` vacío→500** | **MEDIA** | str(192) | `test_update_nombre_excede_la_columna_da_422_no_500` + `test_update_sin_nombre_da_422_no_500` + `test_update_nombre_vacio_da_422` (×3 catálogos, rojo→verde) |
| 19 | E. bordes válidos | Medio/Empresa/Localidad `update` | sin hallazgo (191 chars ENTRA tras el fix; Marca/Industria ya validaban max:100) | — | — | `test_update_nombre_borde_191_se_acepta` · `test_update_marca_e_industria_ya_validan_nombre` |
| 19 | D1 RBAC escritura | los 5 catálogos × {store,update,toggle,destroy} | sin hallazgo (VENDEDOR con solo `*.index` → 403 en TODA escritura; ADMIN → 200; el Gate del controller aguanta) | — | — | `test_vendedor_no_puede_{crear,editar,togglear}` (×5) · `test_admin_si_puede_crear` (×5) · `test_vendedor_no_puede_eliminar_empresa` |
| 19 | D1/D10 registro principal | los 5 × {update,toggle} + empresa destroy | sin hallazgo (id==1, Empresa id<=1 → 403 e intacto; el `<=1` de Empresa NO regresiona con id 0/neg porque la fila principal real es id 1) | — | — | `test_no_se_puede_{editar,togglear}_el_registro_principal` (×5) · `test_no_se_puede_eliminar_el_registro_principal_empresa` |
| 19 | D3/D10 toggle | los 5 catálogos | sin hallazgo (toggle alterna ON↔OFF, doble-toggle vuelve al original; destroy empresa = soft-OFF reversible por toggle) | — | — | `test_toggle_alterna_y_doble_toggle_vuelve_al_original` (×5) · `test_destroy_empresa_es_soft_off_y_toggle_lo_reactiva` |
| 19 | E. SQLi/XSS | Marca/Medio `nombre` | sin hallazgo (payloads inertes/verbatim, tabla sobrevive, sin 500) | — | — | `test_sqli_xss_en_nombre_quedan_inertes` |
| 19 | hallazgo lateral (código muerto) | `LocalidadController::destroy` | sin ruta DELETE → método **dead-code** inalcanzable (cosmético, NO bug: no se puede invocar) | COSMÉTICA | — | `test_localidades_no_expone_ruta_delete` (fija que 405 es by-design; el front no borra localidades) |

### Hallazgo MEDIA (D2) — `update` de 3 catálogos sin validación → 500

`MedioController::update`, `EmpresaController::update` y `LocalidadController::update` hacían
`$x->update(['nombre' => $request->nombre])` **sin `$request->validate(...)`**, a diferencia de su
propio `store` (que sí valida `required|string|max:191`) y de `Marca`/`Industria` (que validan en
AMBOS). Tres vías de 500, todas reproducidas al 100%:
1. **Overflow**: `nombre` de 192 chars > `varchar(191)` → PDOException 1406 (Data too long) → 500.
2. **Faltante**: `update` sin `nombre` → `$request->nombre` es `null` → NULL en columna NOT NULL
   (1048) → 500 (y, peor, ANTES de fallar habría sobrescrito el nombre con null si la columna lo
   permitiera).
3. **Vacío**: `nombre=''` → mismo 500 por la cadena.

Misma clase sistémica recurrente de la sesión: `cantidad` (loop 2), `observacion` de pedidos
(loop 14), 5 campos de cuentas (loop 17). **Severidad MEDIA** (no ALTA): es un 500 ante input que la
validación deja pasar (recuperable, sin pérdida de dinero/datos persistidos ni brecha de authz), pero
es violación de contrato D2 (un API público nunca debe responder 500 ante input malformado → 4xx limpio).

**Fix (DIRECCIÓN INEQUÍVOCA — el store ya lo hace, Marca/Industria ya lo hacen en update):** agregar
`$data = $request->validate(['nombre' => 'required|string|max:191']);` al inicio del `update` de los 3
controllers y persistir `$data['nombre']`. `max:191` alineado al ancho REAL de la columna (verificado
empíricamente, no inventado). NO se tocó Marca/Industria (su `update` ya validaba con `max:100`, más
estricto que la columna → sin overflow; se dejó como estaba y se fijó su contrato con un test guardia).

**Anchos de columna (verificados en `tienda_test`, no en el dump):** las 5 tablas tienen
`nombre varchar(191) NOT NULL`. → NO hay desalineamiento `max:`↔columna en ningún `store` (Medio/
Empresa/Localidad usan `max:191`=columna; Marca/Industria usan `max:100`<columna, más estricto, seguro).
La superficie de overflow estaba EXCLUSIVAMENTE en los 3 `update` sin validar. (La sospecha del padre
sobre una columna `varchar(50)` con `max:191` que dejara pasar overflow → NO se materializó; todas
son 191.)

### Loop B (mutación manual) — la red nueva NO es placebo (3/3 mutantes muertos)

Cada mutación inyectada a mano, test objetivo MUERE, revertida (controllers pristine: `git diff` solo
muestra los 3 fixes intencionados, 0 marcadores `MUTANT`).

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `MedioController::update`: quitar `$request->validate` (contrato D2) | ✅ muerto ×3 (overflow/faltante/vacío de `medios` → 500≠422) |
| 2 | `MarcaController::update`: comentar `Gate::authorize('marcas.edit')` (authz D1) | ✅ muerto (`test_vendedor_no_puede_editar[marcas]`: VENDEDOR edita → 200≠403) |
| 3 | `EmpresaController::update`: `id <= 1` → `id <= 0` (registro principal D1/D10) | ✅ muerto (`test_no_se_puede_editar_el_registro_principal[empresas]`: id 1 editable → 200≠403) |

### Convergencia de Datos Raíz (loop 19)

Severidad máxima del loop: **MEDIA** (1 clase de bug en 3 controllers, cerrada rojo→verde). NO hubo
ALTA (sin pérdida de dinero, sin brecha de authz: el RBAC, el registro principal y el toggle ya estaban
correctos en los 5 — solo faltaba la VALIDACIÓN del update en 3). El único hallazgo lateral
(`LocalidadController::destroy` dead-code) es COSMÉTICO (inalcanzable). La red se validó por mutación
(3/3 muertos cubriendo las 3 dimensiones tocadas: D2 validación, D1 authz, D1/D10 registro principal).
**Frontend:** sin cambios — `SimpleCrudScreen`/`*FormModal` siempre envían un `nombre` no vacío; el
hueco era 100% de la superficie de API directa. Suite **474/474 verde** (+53), PHPStan 0. Datos Raíz
CONVERGE bajo casos difíciles primero: el `update` sin validar (el hueco que el padre señaló) se atacó
de entrada y se cerró; el resto de superficies (RBAC, principal, toggle, SQLi) quedó verde sin hallazgos.

## Preguntas — Datos Raíz loop 19 → RESUELTAS (decisión delegada al asistente, "tú ve lo mejor", 2026-06-16)

1. **Duplicados de `nombre` (sin `unique:`) → SE MANTIENE (fiel al legacy), sin cambio.** El legacy
   permite catálogos con `nombre` repetido y no es una corrupción (un nombre duplicado no rompe stock,
   dinero ni FKs). Imponer `unique:` ahora sería un cambio de política que además chocaría con los
   duplicados legacy ya presentes en el dump. Decisión conservadora: NO agregar unicidad. Si el negocio
   lo pide explícitamente más adelante, se hace con migración `unique` + limpieza de duplicados.
2. **`LocalidadController::destroy` código muerto → SE DEJA, sin cambio.** Es inalcanzable (sin ruta
   DELETE), inofensivo, y el test `test_localidades_no_expone_ruta_delete` fija el 405 para que ningún
   refactor lo exponga por accidente. Borrarlo sería churn cosmético sin valor; se deja documentado.

## Loop 20 — SUCURSALES (la INVARIANTE DURA del sistema: máximo 5 / columnas stock1..stock5)

> El módulo de MAYOR blast-radius estructural: crear una sucursal con id fuera de
> stock1..stock5 (o id > 5) corrompe TODO el inventario (ventas/compras/envíos/ajustes
> leen `'stock'.$sucursal_id`). El commit `5e2e1d0` ya había puesto un guard
> (`max('id') >= 5`); este loop lo ATACA a fondo + barre el resto de la superficie.
> Casos difíciles PRIMERO: gap de ids, atomicidad, central inmutable vía `update`,
> overflow de columnas, NOT NULL sin default, RBAC contra el legacy real.
> DB `tienda_test` (5 sucursales seedeadas), factories, DatabaseTransactions.
> Archivo: `SucursalesAuditTest.php` (14 tests). Técnicas: A (property/atomicidad),
> E (fuzz bordes: overflow/NOT NULL/gap de id), D1 (RBAC vs legacy).

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test |
|------|---------|--------|----------|-----------|------|------|
| 20 | E. gap de ids | Sucursal store (guard de 5) | **`max('id') >= 5` se BURLA con ids no contiguos: con sucursales 1,2,3 y AUTO_INCREMENT alto el guard PASA y el INSERT asigna id >> 5 → sucursal SIN columna stockN → inventario roto** | **ALTA** | autoinc=6071 | `test_no_crea_sucursal_con_id_mayor_a_5_aunque_haya_gap` (rojo: 200+id 6071 → verde: 422) |
| 20 | D1. RBAC vs legacy | Seeder / VENDEDOR | **el seeder otorga `sucursales.create` a VENDEDOR; el dump legacy verificado NO le daba NINGÚN `sucursales.*`** → rol de baja jerarquía con escritura de estructura organizacional | **ALTA** | — | `test_seeder_no_otorga_escritura_de_sucursales_a_vendedor` + `test_rol_sin_permiso_no_escribe_sucursales` (rojo: 422 != 403 → verde) |
| 20 | E. overflow de columna | Sucursal store/update | `direccion` validada `max:255` pero la columna es `varchar(191)` → 192..255 chars pasan y revientan el UPDATE/INSERT (1406) → **500** | MEDIA | dir x200 | `test_direccion_que_excede_la_columna_da_422_no_500_en_{update,store}` (rojo: 500 → verde: 422) |
| 20 | E. NOT NULL sin default | Sucursal store | `alias/nit/direccion/telefono/email` NOT NULL sin default; `store({nombre})` omite esas columnas → 1364 → **500** | MEDIA | — | `test_store_solo_con_nombre_no_da_500` (rojo: 500 → verde: 200 con defaults) |
| 20 | A. atomicidad (D8) | Sucursal store | el `store` NO tenía `DB::transaction` (sucursal+accesos+cuenta+apertura no atómicos) y `Cuenta::find($id)` solo ACTUALIZABA una preexistente: en BD fresca la sucursal quedaba SIN cuenta INTERNO → envíos sin destino | MEDIA | — | `test_store_permitido_crea_sucursal_con_accesos_y_cuenta` |
| 20 | D1/D10. central inmutable | update/destroy/toggle | sin hallazgo — el guard `unset($data['estado'])` para id==1 YA bloquea desactivar la central por `update?estado=OFF` (el vector que el padre marcó); mutación lo confirma | — | — | `test_central_no_se_desactiva_via_update_con_estado_off` · `test_central_no_se_desactiva_via_destroy_ni_toggle` |
| 20 | E. contrato | update | sin hallazgo — `ultimo_cierre` NO editable (fuera del `validate`); `email` inválido → 422; toggle reversible | — | — | `test_ultimo_cierre_no_es_manipulable_via_update` · `test_email_invalido_da_422` · `test_toggle_es_reversible_en_doble_toggle` |

### Hallazgo ALTA #1 (D10/D4) — el guard de 5 se burla con ids no contiguos

`if (Sucursal::max('id') >= 5)` asume ids CONTIGUOS 1..5. En una BD donde se borraron filas
(AUTO_INCREMENT histórico alto, como `tienda_test` con autoinc=6071), con solo 3 sucursales
(ids 1,2,3) `max('id')=3 < 5` → el guard PASA, pero el próximo INSERT recibe id >> 5 → sucursal
sin columna `stockN` → **corrupción total del inventario**. Reproducido 100% (guard viejo: 200 +
id 6071 creado). **Fix (dirección inequívoca):** guard de DOS capas — (a) `count() >= 5`
(pre-chequeo, caso común) y (b) re-chequeo DENTRO de la transacción del id REALMENTE asignado
(`$sucursal->id > 5 → throw SucursalFueraDeRango`, revierte el INSERT, devuelve 422 limpio). NO se
usa `information_schema.AUTO_INCREMENT` (se cachea por conexión y queda stale) → se lee el id del
modelo insertado.

### Hallazgo ALTA #2 (D1) — el seeder da `sucursales.create` a VENDEDOR (no fiel al legacy)

Verificado contra el dump legacy (`tienda (1).sql`, `permission_role`): VENDEDOR (legacy id 4) NO
tenía NINGÚN `sucursales.*` (ni index ni create) — solo GERENTE (id 3) tenía `sucursales.create`.
El `PermissionsSeeder` agregaba `index/show/create` a VENDEDOR → un VENDEDOR pasaba la ruta y el
`Gate::authorize('sucursales.create')` y llegaba al controlador de creación (en estado normal 422
por el guard de 5, pero combinado con el bug del gap podría crear una sucursal corruptora). Brecha
de frontera de authz (D1). **Fix (regla del proyecto "permisos como el legacy"):** se eliminan
`sucursales.*` de VENDEDOR en el seeder. `seedIfEmpty` solo aplica a roles vacíos → no pisa la
matriz legacy en prod. El front ya gateaba Sucursales como `ADMIN_ONLY` → cero impacto de UI.

### Loop B (mutación manual) — 7/7 mutantes muertos (la red nueva NO es placebo)

| # | Mutación | Resultado |
|---|----------|-----------|
| 1 | guard `count() >= 5` desactivado | muerto (`test_con_5_sucursales_ningun_payload_burla_el_guard`) |
| 2 | re-chequeo `id > 5` en transacción desactivado | muerto (gap test: 200 != 422) |
| 3 | `direccion` `max:191`→`max:255` | muerto (overflow update: 500) |
| 4 | defaults NOT NULL desactivados | muerto (store solo-nombre: 500) |
| 5 | guard central `id==1`→`id==99` | muerto (central via update: OFF != ON) |
| 6 | seeder re-agrega `sucursales.create` a VENDEDOR | muerto (seeder test) |
| 7 | no fijar `$cuenta->id` | muerto (atomicidad: cuenta null) |

> Harness: los tests del camino permitido usaban `ALTER TABLE AUTO_INCREMENT` (commit implícito en
> MySQL → escapa al rollback de DatabaseTransactions) y limpiaban su residuo en un `finally`.
>
> **CORRECCIÓN POST-LOOP (defecto de aislamiento que el subagente NO detectó, hallado en la
> verificación del padre):** ese `ALTER TABLE` rompía el aislamiento de `DatabaseTransactions` — la
> transacción del test quedaba confirmada y filas que debían revertirse se persistían en
> `tienda_test` (se halló sucursal id 5 BORRADA + ~13 filas residuales). Fix: se RETIRARON los 3
> tests que usan `ALTER TABLE` (`store_permitido_*`, `direccion_*_en_store`, `store_solo_con_nombre_*`).
> Lo que probaban queda cubierto: el guard de 5/id>5 por `test_no_crea_sucursal_con_id_mayor_a_5_aunque_haya_gap`
> (solo DELETE en transacción → revierte, NO usa ALTER) + `count()>=5`; el overflow/NOT NULL del
> `store` por la misma validación del `update` (`test_direccion_*_en_update`). La atomicidad
> (accesos+cuenta+apertura) y el id==5 exacto del camino feliz quedan como **riesgo residual
> verificado MANUALMENTE** (script standalone: store con count<5 + autoinc=5 crea la 5ª con sus 3
> efectos; e id 7000 → excepción → fila revertida) — no reproducibles en la suite transaccional sin
> DDL. `tienda_test` se limpió (sucursal 5 restaurada, fuga de la sesión purgada). Suite estable en
> **485/485** (3 tests menos) con `sucursals=5` y cero crecimiento entre corridas consecutivas.

### Convergencia de Sucursales (loop 20)

Severidad máxima: **ALTA x2** (guard de 5 burlado por gap de ids → corrupción de inventario; seeder
otorga escritura de sucursales a VENDEDOR), ambas cerradas rojo→verde con dirección inequívoca. El
resto (central inmutable vía update —el vector que el padre marcó—, overflow, NOT NULL, atomicidad,
contrato de `ultimo_cierre`, toggle) quedó verde. Red validada por mutación (7/7). Suite **488/488
verde** (+14), PHPStan 0. Sucursales CONVERGE: el guard de 5 (mayor blast-radius) se atacó de entrada
y se halló el gap.

### Riesgo residual

- **Concurrencia/TOCTOU del guard de 5 (D7):** dos `store` simultáneos con 4 sucursales podrían, sin
  lock pesimista, pasar ambos `count() >= 5`. La capa (b) MITIGA el peor caso: el 2do INSERT recibiría
  id 6 → excepción → rollback → 422; una sucursal con id > 5 NUNCA se persiste. Sin lock de BD no es
  100% determinista; no reproducible bajo `DatabaseTransactions`. *Bajo (single-admin; la capa (b)
  corta la corrupción estructural).*
- **`Cuenta::create` con id explícito en BD fresca:** el fix CREA la cuenta INTERNO si no existe
  (empresa_id=1/localidad_id=1, convención legacy). En prod la cuenta 1..5 ya existe → rama UPDATE. La
  rama CREATE solo se ejercita en BD fresca; probada en test.

## Loop 21 — Casos difíciles · USUARIOS + ROLES + PERFIL (el de MÁS alto riesgo: RBAC) + mutación

> Módulo que controla la autorización de TODO el sistema → toda falla = ESCALADA DE PRIVILEGIOS.
> Casos difíciles PRIMERO: simular rol superior, crear/editarse como ADMIN, inyección en perfil,
> super-admin/roles núcleo, auto-escalada vía editor de roles. DB `tienda_test`, factories,
> `DatabaseTransactions` (cero DDL). Archivo: `UsuariosRolesAuditTest.php` (14 tests).

| Loop | Técnica | Módulo | Hallazgo | Severidad | Seed | Test / commit |
|------|---------|--------|----------|-----------|------|----------------|
| 21 | E. authz frontera simulador | UserController::simulateRole | **GERENTE puede simular ADMIN** (200 en vez de 403) → con `simulated_role_id=ADMIN` heredaría el bypass total de Gate::before = escalada al control total | **ALTA** | — | `test_gerente_no_puede_simular_admin` (rojo→verde) |
| 21 | E. authz escalada store/update | UserController::store/update | **GERENTE (con users.create/edit) puede CREAR un usuario ADMIN, PROMOVER cualquiera a ADMIN y AUTO-PROMOVERSE a ADMIN** — `role` sólo validaba `exists:roles,name`, sin whitelist de jerarquía | **ALTA ×3** | — | `test_gerente_no_puede_crear_usuario_admin`, `_promover_usuario_a_admin`, `_auto_promoverse_a_admin` (rojo→verde) |
| 21 | D10 roles núcleo protegidos | RoleController::update/destroy | **el rol ADMIN (y SUSPENDIDO) era editable/renombrable/borrable** — el guard `in_array($role->id,[1,2])` protegía ids 1 y 2 que NO EXISTEN (los ids son auto-increment: ADMIN=149031 en test) → guard PLACEBO. Borrar/vaciar ADMIN = catástrofe (sin súper-admin) | **ALTA** | — | `test_no_se_puede_editar_ni_borrar_el_rol_admin` + `test_rol_con_roles_edit_no_puede_mutar_el_rol_admin` (rojo→verde) |
| 21 | D1 inyección de campos | UserController::updateProfile | sin hallazgo (asigna SÓLO name/email/password explícitos; role/simulated_role_id/sucursal_id/id inyectados por body se ignoran) | — | — | `test_update_profile_no_permite_inyectar_role_ni_simulacion` |
| 21 | D1 frontera simulador (no-priv) | simulateRole ruta | sin hallazgo (VENDEDOR → 403 vía `role:ADMIN\|GERENTE`; defensa en profundidad: simular ADMIN no concede Gate::before en test) | — | — | `test_vendedor_no_puede_simular_ningun_rol`, `test_gerente_simulando_admin_no_obtiene_bypass_total` |
| 21 | D10 super-admin id 1 | update/destroy/acces | sin hallazgo (id 1 inmutable: no se suspende, no se edita, no pierde accesos; ADMIN no se auto-suspende) | — | — | `test_no_se_puede_tocar_al_super_admin_id_1`, `test_admin_no_puede_auto_suspenderse` |
| 21 | D1 editor de roles (no-ADMIN) | RoleController vía ruta | sin hallazgo (GERENTE no tiene roles.edit/create/destroy → 403 en ruta) | — | — | `test_gerente_no_puede_editar_permisos_de_roles` |
| 21 | D1 accesos a sucursal | UserController::acces | sin hallazgo (guard "≥1 acceso ON" → 422 al desactivar el último) | — | — | `test_no_se_puede_dejar_usuario_sin_ningun_acceso` |
| 21 | D2 fuzz contrato | UserController::store | sin hallazgo (rol inexistente / email basura / password corto → 422 limpio, sin creación) | — | — | `test_store_entradas_basura_dan_422` |

### Hallazgos ALTA del loop 21 (5 escaladas, todas dirección INEQUÍVOCA → fix aplicado)

Invariante maestra violada: **ningún usuario puede ganar permisos por encima de su rol real**. El
mecanismo de escalada universal era llegar al rol **ADMIN** (único con el bypass total de
`Gate::before`). Cuatro vías + un guard placebo:

1. **Simular ADMIN** (`simulateRole`): el endpoint sólo verificaba que el rol REAL fuera ADMIN/GERENTE,
   pero NO restringía QUÉ rol se simula. Un GERENTE podía `POST /simulate-role {role_id: <ADMIN>}` →
   200. **Fix:** `abort_if($role->name==='ADMIN' && $realRole!=='ADMIN', 403)` — sólo un ADMIN real
   puede simular ADMIN. *(Mitigante en `tienda_test`: el rol ADMIN tiene 0 permisos explícitos, así que
   el Gate::before de simulación no concede nada → no había escalada REAL en test; PERO en
   producción/legacy el rol ADMIN podría tener permisos explícitos asignados → escalada real. Y la
   identidad simulada ADMIN ya es semánticamente una usurpación. ALTA por protocolo.)*
2-4. **Crear/promover/auto-promover a ADMIN** (`store`/`update`): `role` sólo validaba
   `exists:roles,name`. **Fix:** helper `autorizarAsignacionDeRol()` — sólo un ADMIN real puede asignar
   el rol ADMIN. Movido ANTES de cualquier escritura (en `store` evitaba un user huérfano; en `update`
   evitaba persistir name/email de un intento de auto-promoción).
5. **Rol ADMIN/SUSPENDIDO mutable/borrable** (`RoleController`): el guard `in_array($role->id,[1,2])`
   protegía ids fijos 1 y 2 que NO existen (ids auto-increment). Cualquier ADMIN —o un rol custom con
   `roles.edit`— podía renombrar/vaciar/BORRAR el rol ADMIN. **Fix:** protección por NOMBRE
   (`ROLES_NUCLEO = ['ADMIN','SUSPENDIDO']`), no por id frágil.

**Frontend (defensa en profundidad):** el botón "Simular" se mostraba para TODOS los roles incl. ADMIN
sin importar el rol real → un GERENTE veía "Simular ADMIN". Se oculta para ADMIN salvo que el rol REAL
(`user.roles[0]`, que NUNCA refleja la simulación) sea ADMIN. ESLint 0 errores.

### Loop B (mutación manual) — la red nueva NO es placebo (3/3 mutantes muertos)

Cada mutación inyectada a mano, test objetivo MUERE, revertida (controllers pristine: `git diff` sin
marcadores `MUTANT`).

| # | Mutación (área) | Resultado |
|---|-----------------|-----------|
| 1 | `simulateRole`: anular el guard `&& false` (escalada simulador) | ✅ muerto (`test_gerente_no_puede_simular_admin`: 200≠403) |
| 2 | `autorizarAsignacionDeRol`: anular `&& false` (escalada store/update) | ✅ muerto ×3 (crear/promover/auto-promover ADMIN: 200≠403) |
| 3 | `RoleController`: volver al guard placebo `[1,2]` (roles núcleo) | ✅ muerto (`test_no_se_puede_editar_ni_borrar_el_rol_admin`: 200≠403) — CONFIRMA que el `[1,2]` previo era placebo (con él, el rol ADMIN es editable en test) |

### Convergencia de Usuarios/Roles/Perfil (loop 21)

Severidad máxima: **ALTA** (5 escaladas de privilegios, todas cerradas rojo→verde con dirección
inequívoca — "no puedes volverte ADMIN sin serlo"). Es el módulo de MÁS alto riesgo y la vara fue
estricta: se fue a los casos difíciles PRIMERO (simular superior, auto-edición a ADMIN, super-admin,
roles núcleo) y se halló todo el clúster de escalada que el camino feliz (sólo testeado con ADMIN como
actor) ocultaba. Las superficies sin hallazgo (inyección de perfil, accesos, super-admin id 1, fuzz)
quedaron verdes. Red validada por mutación (3/3 muertos; ninguno placebo — el #3 prueba que el código
PREVIO sí lo era). Suite **499/499 verde** (+14), PHPStan 0, ESLint 0. `tienda_test` verificado sin
contaminación (0 users/roles sintéticos filtrados; ADMIN intacto; cero DDL). CONVERGE.

### Riesgo residual / PREGUNTAS → RESUELTAS (decisión del humano 2026-06-16: "dejemos así")

- **Jerarquía de roles más allá de ADMIN → SE DEJA SIN jerarquía estricta (by-design).** El fix cierra
  la escalada crítica (volverse ADMIN). Que un GERENTE pueda asignar/simular roles laterales (otro
  GERENTE, OPERADOR) NO es escalada (no sube de nivel) y el legacy era plano (Shinobi sin jerarquía).
  El humano decidió no agregar una pirámide formal de roles. Riesgo: bajo.
- **Restringir `roles.edit/create/destroy` a ADMIN → SE DEJA como está (by-design).** Un no-ADMIN con
  `roles.edit` puede ampliar permisos granulares de roles NO núcleo, pero NO alcanza el bypass de ADMIN
  (blindado: inmutable + no asignable + no simulable). El humano decidió no restringirlo. Riesgo: bajo.
- **Verificación en PRODUCCIÓN (HECHA 2026-06-16, vía API de solo-lectura sobre `lacasavo_staging`):**
  el rol **ADMIN tiene solo 1 permiso explícito** en la BD → depende de `Gate::before`, NO de permisos
  cargados. Implicancia: la escalada #1 (simular ADMIN) tenía impacto REAL pequeño — `Gate::before`
  excluye a quien simula (`hasRole('ADMIN') && !simulated_role_id`), así que un GERENTE simulando ADMIN
  sólo habría heredado ese 1 permiso, NO acceso total. Las escaladas verdaderamente graves eran #2-#5
  (crear/promover un ADMIN REAL → sí entra a Gate::before; y borrar/renombrar el rol ADMIN → rompe el
  acceso de todos). Todas cerradas. (Otros roles: GERENTE 84, OPERADOR 83, VENDEDOR 52 — permisos
  explícitos normales del legacy.)

## Loop 22 — Técnica F (E2E/UI con Playwright) · TODA la app en el navegador real (no solo backend)

> Los loops 1–21 atacaron el BACKEND con DB de test aislada (`tienda_test`). Este loop es el
> PRIMERO que maneja la **app REAL en el navegador** (front `:3000` → API `:8000` → DB **`tienda`
> DEV**) — el hueco "E2E/UI no ejercido" que la matriz arrastraba (ver nota loop 6). Objetivo del
> humano: checklist honesto verde/rojo de TODA la app, no solo lo crítico. **Seguridad de datos
> innegociable:** cobertura mayormente solo-lectura; los flujos que escriben crean filas NUEVAS,
> capturan su ID y las HARD-DELETEAN al final, con verificación de residuo cero en `tienda`.

**Suite nueva (`front/e2e/`, corre con `npm run e2e`):** 21 tests / 5 specs nuevos + 2 heredados.
- `_helpers.js` — login UI, nav SPA por sidebar, simulador de rol vía API, error-spy (pageerror /
  console.error / HTTP≥500), tracker de IDs para limpieza.
- `full-walk.spec.js` — recorre las ~20 pantallas como **ADMIN + GERENTE + VENDEDOR + OPERADOR**
  (simulador). Verifica: cero crash/blank/5xx por rol + RBAC visual.
- `forms.spec.js` — validación inline (form vacío NO llama API), estado vacío (búsqueda sin match),
  filtros/tabs/toggle (confirm cancelado, sin mutar), paginación de Ventas.
- `flows.spec.js` — flujos de plata/stock con limpieza: **venta mostrador (crear→ítem→validar),
  ajuste +N→revertir (verifica stock vuelve al original), cotización, pedido**. IDs hard-deleteados
  en `afterAll` vía artisan tinker; observación con prefijo `E2E-TEST-` como segunda red.
- `responsive.spec.js` — 1440 / 700 / 420 px (hamburguesa, tablas→cards, KPIs 1 col, sin scroll-x).
- `a11y.spec.js` — axe-core (inyectado por CDN, sin tocar package.json) en login/dashboard/ventas/form.

| Dimensión | Técnica | Resultado | Severidad |
|-----------|---------|-----------|-----------|
| Render+consola+red, 4 roles | F. walk multi-rol | **VERDE** — 0 crash, 0 console.error, 0 HTTP≥500, 0 blank en ~20 pantallas × 4 roles | — |
| RBAC visual | F. menú por rol | VENDEDOR estricto VERDE (no ve Sucursales/Usuarios/Roles/Estadísticas/Ajustes). OPERADOR/GERENTE ven Usuarios/Roles **pero es DATA DRIFT legacy de la BD dev** (OPERADOR=83 perms), no bug de front | INFO |
| Formularios/validación/estados | F. interacción | VERDE — form vacío muestra error inline y NO llama API; búsqueda sin match → estado vacío; filtros/tabs/pager sin romper | — |
| Flujos críticos + limpieza | F. E2E con writes | VERDE — venta/ajuste/cotización/pedido llegan al final; ajuste revertido deja stock idéntico; **residuo `E2E-TEST-` = 0**, 0 orphans recientes | — |
| Responsive 3 breakpoints | F. viewport | VERDE — hamburguesa ≤900, thead oculto ≤700, KPIs 1 col ≤450, sin scroll-x | — |
| Accesibilidad | axe-core (Regla 36) | **ROJO (deuda, no crash)** — ver abajo. Reporte-only: no rompe el build | a11y debt |

### Hallazgo (a11y) — violaciones serias/críticas de axe-core (deuda, NO bug funcional)

axe-core corrió OK y halló deuda real de accesibilidad (impacto serio/crítico, WCAG 2 A/AA). NO son
crashes ni bugs de datos — son barreras de accesibilidad. Reporte-only (no bloquean), cuantificadas en
`front/e2e/shots/a11y-violations.json`:

| Pantalla | Violación (axe id) | Impacto | Nodos | Qué es |
|----------|--------------------|---------|-------|--------|
| login | `button-name` | crítico | 1 | botón solo-icono sin texto accesible (toggle/ojo) |
| login | `label` | crítico | 2 | inputs email/password con label visual NO asociado programáticamente |
| login | `color-contrast` | serio | 1 | contraste insuficiente en `.btn` |
| dashboard | `color-contrast` | serio | 8 | texto `.sub` bajo contraste |
| ventas | `button-name` | crítico | 2 | botones del pager (chevrons) solo-icono sin nombre |
| ventas | `select-name` | crítico | 1 | `<select>` sin nombre accesible |
| ventas | `color-contrast` | serio | 9 | textos secundarios bajo contraste |
| form (marca) | `color-contrast` | serio | 1 | botón accent bajo contraste |

**Patrón:** (a) botones solo-icono sin `aria-label` (pager, toggles), (b) `<select>` y inputs sin
`aria-label`/`<label for>` asociado, (c) `--soft`/`.sub` y el accent sobre blanco no llegan a 4.5:1.
Fix sugerido (futuro, decisión del humano): `aria-label` en icon-buttons y selects, asociar labels,
subir el contraste de `--soft`/accent. **No se aplicó** (fuera del scope descubrir≠arreglar de este loop).

### Seguridad de datos — VERIFICADA

Limpieza confirmada: barrido final sobre `tienda` → **0 filas `E2E-TEST-`** (tranzas/ajustes/
cotizacions/pedidos/envios/cuentas) y **0 filas recientes** (<30 min) en ventas/compras/cotizacions/
pedidos/envios + sus detalles. El `afterAll` de `flows.spec.js` hard-deletea por ID capturado y por
marca de texto; re-verificado a mano vía artisan tinker. Cero datos inventados de cliente; cero
servicios externos. Ningún registro EXISTENTE fue modificado/borrado.

### Convergencia (loop 22)

La app **NO está "rota" a nivel funcional**: ninguna de las ~20 pantallas crashea ni para ningún rol;
los flujos de plata/stock corren de punta a punta; responsive sólido en 3 breakpoints. La ÚNICA familia
de hallazgos es **deuda de accesibilidad** (serio/crítico de axe), que es real pero no es un crash ni
corrupción de datos. El miedo del humano ("muchas cosas rotas") NO se confirma a nivel E2E/UI: el
sistema responde verde salvo a11y. Suite E2E: **21/21 verde**. Cierra el hueco "E2E/UI no ejercido" de
la matriz. Pendiente futuro: ejecutar el walk también con VENDEDOR real (credencial existe) y axe sobre
las pantallas restantes; el fix de a11y queda a decisión del humano.

## Loop 23 — Técnica F (a11y / axe-core) · cierre de la DEUDA de accesibilidad del loop 22

> El loop 22 dejó la a11y como DEUDA documentada (reporte-only, NO arreglada — era "descubrir ≠
> arreglar"). Este loop la CIERRA: rojo→verde = axe ROJO → axe VERDE, y convierte la red en
> regresión REAL (`a11y.spec.js` ahora asierta `violations == []`, ya no reporte-only). App
> corriendo en :3000 (DB `tienda` dev, SOLO LECTURA de pantallas). Decisión del humano 2026-06-16:
> "cerrar los 2 frentes abiertos" (a11y + performance). Fan-out de 2 agentes `auditor-qa-adversarial`.

| Pantalla | Violación (axe) | Impacto | Fix | Ratio antes→después |
|----------|-----------------|---------|-----|---------------------|
| login | `label` ×2 | crítico | `htmlFor`+`id`+`autoComplete` en email/password | n/a |
| login | `button-name` | crítico | `aria-label`/`title` en toggle ojo de password | n/a |
| login/ventas/form | `color-contrast` `.btn-accent` | serio | `--star`/accent base #0b7ec2→#0a6fa8 | 4.40→5.45 |
| dashboard/ventas | `color-contrast` `.sb-section` | serio | `--sb-section` #5d6a85→#828fac | 3.03→5.08 |
| dashboard/ventas | `color-contrast` `.sb-text-soft` | serio | `--sb-text-soft` #7a85a0→#8e99b0 | 4.47→5.76 |
| ventas | `button-name` ×2 (chevrons pager) | crítico | `aria-label`/`title` + `aria-current` en `Pager` | n/a |
| ventas | `select-name` | crítico | `aria-label`/`title` en `PageSizeSelector` | n/a |
| productos **[NUEVO]** | `button-name` ×2 (toggles vista) | crítico | `aria-label`/`aria-pressed`/`title` en tabla/cuadrícula | n/a |

### Hallazgo lateral (NO estaba en el reporte del loop 22) — la paleta SUC_COLORS fallaba AA

El `.btn-accent` NO rinde el `--star` azul: `App.jsx` aplica un acento POR SUCURSAL desde
`SUC_COLORS` (`layout.jsx`). 4 de los 5 colores fallaban WCAG AA con texto blanco (green 2.28,
amber 2.08, blue 4.40, purple 4.23). axe solo midió el color del usuario de prueba; el barrido de
casos difíciles destapó TODA la paleta. **Fix:** los 5 subidos a ≥5.1:1 manteniendo el matiz
(green #22c55e→#177a3c, amber #f0a500→#a35a00, blue #0b7ec2→#0a6fa8, purple #8b5cf6→#7c3aed;
violet #6b64b0 ya pasaba). Además **Productos tenía 2 críticas NUEVAS** (toggles vista tabla/
cuadrícula solo-icono) que ninguna pantalla del loop 22 cubría.

### Red convertida en regresión REAL (no placebo) — verificado por el padre

`a11y.spec.js` ahora asierta `expect(violations).toEqual([])` en 6 pantallas (las 4 del loop 22 +
**cotizaciones + productos**). Mutación del subagente: revertir el `aria-label` del pager → test de
ventas ROJO (`button-name`); restaurado → verde. **Verificación independiente del padre:**
`npx playwright test e2e/a11y.spec.js` → **6 passed (54s), 0 críticas/serias** en login/dashboard/
ventas/form-marca/cotizaciones/productos. `front/e2e/shots/a11y-violations.json` regenerado a `[]`.
ESLint 0 errores (52 warns preexistentes, ninguno nuevo). Estilo Diamante intacto (solo se subió
contraste, el matiz de cada token/color se conservó).

### Convergencia (loop 23)
Cerradas: 8 violaciones documentadas + 4 nuevas (paleta SUC_COLORS) + 2 nuevas (toggles productos).
6 pantallas axe VERDE, red de regresión real. **Riesgo residual (honesto, diferido):** ~12 pantallas
SIN barrer con axe (compras/pedidos/envíos/caja/cuentas/ajustes/admin/estadísticas/historial +
modales que no sean "Nueva marca") — patrón muy probable de más icon-buttons sin nombre y `<select>`
sin `aria-label`; dark mode (`html.dark` tiene sus propios tokens) NO medido; violaciones
`moderate`/`minor` no atacadas (filtro serio/crítico del proyecto). Próximo loop a11y futuro:
barrido `aria-label` global de icon-buttons + extender axe a las pantallas restantes.

## Loop 24 — PERFORMANCE (dimensión NUNCA atacada en 22 loops) · N+1 + índices + payloads

> Primer loop dedicado a performance. NO micro-optimiza: caza CLIFFS de escalabilidad (coste que
> crece con el nº de filas). Técnica: análisis de hot-paths + EXPLAIN/query-count contra datos
> reales (`tienda` dev, SOLO LECTURA) y red rojo→verde contra `tienda_test`. Archivo:
> `PerformanceAuditTest.php` (7 tests). DB sintética + DatabaseTransactions; PROHIBIDO DDL inline
> (los índices van en migración, no en el test — la lección del loop 20).

| # | Área | Hallazgo | Severidad | Evidencia antes→después |
|---|------|----------|-----------|--------------------------|
| 1 | Caja `kpis`/`movimientos`/`reportCaja` (tranzas) | **`whereDate('fecha')` envuelve la columna DATE en `CAST(fecha AS DATE)` → inutiliza `tranzas_fecha_idx` → FULL TABLE SCAN de 31,032 tranzas en cada carga de Caja** | **ALTA** | `Table scan on tranzas (cost=3159, rows=31032)` → `Index range scan tranzas_fecha_idx (cost=0.72, rows=1)` |
| 2 | ventas/compras/cotiz/pedidos/envíos listas | mismo `whereDate` defeat-índice (penalización menor: hay índice compuesto de respaldo) | MEDIA | ventas cost 33.5→22.6 |
| 3 | `tranzas` sin índice de `sucursal_id` | rango de fecha amplio escanea tranzas de TODA la red y filtra sucursal fila a fila | MEDIA | índice compuesto nuevo: 6,489 filas escaneadas → directo a 2,771 |
| 4 | listas (ventas/productos/compras/…) | **SIN N+1**: eager loading `with()` presente → query-count constante vs N (camino bueno confirmado con evidencia) | OK | 5→15 filas: mismo query-count |

### Fix (dirección INEQUÍVOCA)
- **`whereDate('fecha', op, X)` → `where('fecha', op, X)`** en 6 controladores (Caja/Venta/Compra/
  Cotizacion/Pedido/Envio). La columna `fecha` es `DATE` en TODAS las tablas — **verificado por el
  padre vía `information_schema`**: ventas/compras/tranzas/envios/pedidos/cotizacions/aperturas/
  cierres todas `DATA_TYPE=date` → el `CAST(... AS DATE)` es un **no-op semántico** (misma lógica,
  índice usable). `apertura()` usa `->toDateString()` (no pasa el Carbon crudo).
- **Índice compuesto `tranzas_sucursal_estado_fecha_idx` (sucursal_id, estado, fecha)** — migración
  `2026_06_16_000000` con guard `Schema::hasIndex` + `up()`/`down()`. Complementa (no reemplaza)
  `tranzas_fecha_idx`.

### Defecto del subagente CAZADO por el padre
La migración usaba **`dropIndexIfExists`** en `down()` — método que **NO existe** en Laravel
(verificado: ausente en TODO `vendor/`; `dropIndex` sí existe en `Blueprint.php:469`). `up()` corre
OK (guardado por `hasIndex`; la suite verde lo confirma) pero el `down()` reventaría en cualquier
`migrate:rollback` → rollback roto latente. **Corregido** a `if (Schema::hasIndex(...)) { dropIndex }`.
(Mismo patrón que los 2 defectos de subagente cazados antes: contaminación DDL loop 20, residuo E2E.)

### Validación de la red (no placebo) — verificado por el padre
- **Mutación:** reintroducir un `whereDate` en `CajaController::kpis` → `test_caja_kpis_*` ROJO
  (detecta `date(\`fecha\`)` en el SQL emitido). Revertido.
- **Suite completa: 506/506 VERDE** (+7), 6433 asserts, exit 0 — corrida por el padre. El cambio de
  filtrado NO rompió ninguna ley de negocio (incl. la PBT de conciliación multi-día de Caja del loop 18).
- **PHPStan 0.** `tienda_test` SIN contaminación (sucursals=5 intacto, 0 filas `perf-seed` residuales;
  DatabaseTransactions revirtió; el índice se aplicó vía migración = schema, NO data → cero DDL en test).

### HITL / riesgo residual (honesto)
- **El índice NO se aplicó a `tienda` dev ni a prod** (regla del proyecto: no migrar dev/prod sin
  aviso). El `where()`-fix viaja con el código (deploy normal); el índice requiere `php artisan
  migrate` en cada entorno. En `tienda_test` ya está (registrado en `migrations`).
- **`EstadisticaController`** (rotación FIFO en memoria PHP iterando detalles por producto; exports
  CSV sin `skip/take`) NO medido con EXPLAIN a fondo → en datasets enormes (muchos productos × muchas
  compras) coste de memoria/CPU potencialmente no acotado. **Diferido.**
- Latencia wall-clock HTTP real no medida (solo coste de optimizador + query-count). Infection
  automatizado no corrido (validación por mutación manual de la línea clave). Trade-off lectura/
  escritura del índice nuevo despreciable en 31k filas; re-medir a escala mucho mayor.

### Convergencia (loop 24)
Severidad máxima: **ALTA** (full scan de tranzas en Caja —el módulo de conciliación de dinero— en
cada carga), cerrada rojo→verde con dirección inequívoca (CAST no-op sobre DATE). El resto: los N+1
quedaron VERDE confirmados con evidencia (las listas ya hacían eager loading — camino bueno probado,
no asumido). Red validada por mutación. Performance CONVERGE en los hot-paths de listas/caja; las
agregaciones pesadas de Estadísticas quedan como frente futuro acotado. Suite 506/506, PHPStan 0.
