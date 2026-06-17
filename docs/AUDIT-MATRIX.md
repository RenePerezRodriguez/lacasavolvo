# Matriz de Auditoría — La Casa Volvo

> Artefacto vivo. Un módulo está **LISTO** solo cuando todas las celdas de sus operaciones
> están ✅ (con test que lo prueba) o ⚠️/➖ con nota justificada. Sin evidencia ≠ revisado.

## Leyenda de dimensiones

| Cód | Dimensión | Cómo se verifica |
|-----|-----------|------------------|
| D1  | AuthZ / IDOR | test: rol sin permiso → 403; sucursal ajena → 403 |
| D2  | Validación input | fuzz: tipos, rangos, faltantes, `exists` |
| D3  | Máquina de estados | test: transición ilegal → 422 |
| D4  | Stock (efecto + reverso) | invariante: ciclo cerrado → stock igual |
| D5  | Caja/contable (efecto + reverso) | invariante: anular → neto tranzas = 0 |
| D6  | Saldo/total/pagado | invariante: total=Σsub, saldo≥0 |
| D7  | Concurrencia / doble-submit | test/análisis: 2 llamadas → no duplica |
| D8  | Errores / rollback | test: falla → revierte, código correcto |
| D9  | Formato numérico | patrón number_format/parseFloat |
| D10 | Edge cases | cero, negativo, duplicado, caja cerrada |

Estados de celda: ✅ probado · ⚠️ riesgo/nota · ❌ bug abierto · ➖ no aplica · ⬜ pendiente

## Tooling transversal (cubre dimensiones en todos los módulos a la vez)

| Herramienta | Cubre | Estado |
|-------------|-------|--------|
| Larastan (PHPStan) nivel 5 | tipos, undefined, dead code, arg.type | ✅ verde (phpstan.neon); cazó str_pad |
| ESLint (front) v9 flat | rules-of-hooks, undefined, dead code | ✅ 0 errores (54 warns calidad); `npm run lint` |
| `composer audit` / `npm audit` | dependencias | ✅ 0 advisories (2026-06-15) |
| Playwright E2E/UI (técnica F, loop 22) | render+consola+5xx en ~20 pantallas × 4 roles (ADMIN/GERENTE/VENDEDOR/OPERADOR simulado), RBAC visual, formularios/validación/estados, flujos venta/ajuste/cotización/pedido con hard-delete + residuo cero, responsive 1440/700/420 | ✅ 21/21 verde (`npm run e2e`); 0 crash/5xx/blank |
| axe-core a11y (Regla 36, loops 22→23) | WCAG 2 A/AA en login/dashboard/ventas/form/**cotizaciones/productos** | ✅ **0 críticas/serias (6 pantallas)** — deuda del loop 22 CERRADA (loop 23): aria-label/label/select-name + contraste de tokens (`--star`/`--sb-section`/`--sb-text-soft`/SUC_COLORS) a AA; `a11y.spec.js` asierta `violations==[]` (regresión real, no reporte-only). Residual: ~12 pantallas sin barrer + dark mode (AUDIT-LEDGER loop 23) |
| **PerformanceAuditTest** (loop 24) | N+1 (query-count vs N) + índices (EXPLAIN) en hot-paths | ✅ 7 tests — **`whereDate` rompía índice de `fecha` → full scan 31k tranzas en Caja (ALTA fixeado)** vía `where()` plano (columna DATE, CAST no-op) ×6 controladores + índice compuesto `tranzas(sucursal_id,estado,fecha)`; listas SIN N+1 (eager loading confirmado). AUDIT-LEDGER loop 24 |
| StockIntegrityTest | D4 ciclos cerrados | ✅ 10 tests |
| **AuthorizationMatrixTest** | D1 en 48 endpoints + caja.cierre | ✅ 49 tests |
| **AccountingIntegrityTest** | D5 anular→tranzas OFF | ✅ 6 tests (ventas/compras/envíos) |
| **TotalsIntegrityTest** | D6 total=Σsub, saldo≥0, cotiz→venta | ✅ 3 tests |
| **EdgeCasesTest** | D2 cantidades inválidas, D3 estados terminales, D6 descuento | ✅ 9 tests |
| **StateMachineTest** | D3 envíos/caja transiciones ilegales | ✅ 5 tests |
| **FinancialFlowsTest** | D6/D10 crédito+cobro+devolución, caja cerrada | ✅ 3 tests |
| **ModulesCoverageTest** | D2/D4 pedidos sin stock, productos, cuentas | ✅ 4 tests |
| **MoneyPropertyTest** (PBT) | D6 saldo>=0 / acuenta<=total / total-acuenta=saldo (60 escenarios) | ✅ 3 tests |
| **NumericFuzzTest** | D2 cantidad int/overflow (batería) en 6 controladores | ✅ 3 tests |
| **MetamorphicTest** | D4/D6 split=combinado, orden, dev decompuesta, Σstock | ✅ 4 tests |
| **IdempotencyTest** | D7 doble-submit (cotiz→venta, validar, anular) | ✅ 3 tests |
| **CrossSucursalAccessTest** | D1 frontera de sucursal (IDOR) en los 5 doc-controllers | ✅ 4 tests |
| **CajaIntegrityTest** | D5/D6/D10 cierre=apertura+ingresos−egresos, guards, revert | ✅ 5 tests |
| **GapCoverageTest** | D1 estadísticas authz · D8 atomicidad (venta/envío) · D7 envío · D2 pedido/marca | ✅ 8 tests |
| **FinalCellsTest** | D7 compra idempotente · D10 envío recibir/cotiz anulada · D2 caja monto | ✅ 4 tests |
| **DashboardTest** | D1 simulador→estadísticas (ALTA, fixeado) · D1 IDOR sucursal ventas/caja · D2 fuzz filtros/fechas · D9 total parseable · D10 sin datos/caja cerrada | ✅ 9 tests |
| **EstadisticasAuditTest** | D2 fuzz paginación (`take`<0→500, fixeado) · SQLi whitelist `vpGran` · D6 utilidad neta devolución (rotacionSucursal, MEDIA fixeado) + **neteo EXACTO por renglón** (loop 9) · metamórfica top-prod/rot-sucursal · ticket/ranking · bordes fecha inclusive · D9 parseable | ✅ 20 tests |
| **VentasAuditTest** | D6/D7/D10 cobros (stateful PBT del libro: acuenta=min(total,Σcob+Σdev), reembolsos≤cobrado) · cobrar PROFORMA/ANULADA/PAGADA/sobrepago/0/neg→422 · D7 TOCTOU validar (stock caído tras `negativos`→422) · D4/D5 conservación caja CONTADO/CREDITO en cadenas dev/revert · D4 anular-con-dev sin doblar stock · límite acumulado dev · D3 fecha cobro futura/anterior · stock multi-sucursal aislado · contrato descuento no inyectable | ✅ 17 tests (6/6 mutantes muertos) |
| **CotizacionesAuditTest** | D6 descuento negativo→total inflado (MEDIA) · monto=0+desc→total negativo (MEDIA) · store persiste desc negativo (MEDIA) · desc no numérico→500 (MEDIA) · D3 CONVERTIDA terminal: agregar/editar/borrar-item+encabezado→422 (MEDIA ×4) · recalcular clampa desc POISONED legacy · conversión header==total cotización (metamórfica) · bordes desc (mitad/válido/gigante) | ✅ 15 tests (5/5 mutantes muertos) |
| **PedidosAuditTest** | D1 frontera ESCRITURA (las 6: validar/destroy/updateEncabezado/agregarItem/updateItem/deleteItem → 403 sucursal ajena; central VE pero NO escribe ajeno) · **D1 IDOR lectura `pdf` sin guard (MEDIA fixeado)** · **D2 `observacion` `max:500`>columna `varchar(191)`→500 overflow (MEDIA fixeado)** · D3 validar/items/destroy transiciones ilegales+idempotencia · D7 ruta `duplicado` (ignora anulados, no crea 2da línea) · D2 SQLi/XSS observacion/search inerte · D4/D5/D6 N/A demostrado (validar no toca stockN ni tranzas) | ✅ 15 tests (4/4 mutantes muertos) |
| **EnviosAuditTest** | **D1 IDOR lectura `pdf` sin guard (MEDIA fixeado)** · **D2/D5 `pagado` sin whitelist → flete sin cobrar (MEDIA fixeado)** · D4 stateful PBT conservación de stock (Σstock1..5 constante salvo en tránsito; ciclo completo dev/revert/anular) · D4 anular ENVIADO/PROFORMA/RECIBIDO-con-dev-viva · D5 flete cobrado 1 vez/ciclo (PAGADO→origen, POR PAGAR→destino) + anular revierte ambas cajas · D1 frontera origen/destino (4 cruces) + self-envío no-op · D3 transiciones repetidas sin doble efecto de stock | ✅ 11 tests (5/5 mutantes muertos) |
| **ProductosAuditTest** | **D4 ajusteNegativo sin guard → stock NEGATIVO (ALTA fixeado)** · **D4 ajusteDestroy doble-revert sin guard estado=ON → doble-conteo (ALTA fixeado)** · **D4 ajusteDestroy de positivo ya consumido → stock negativo 2º orden (ALTA fixeado)** · stateful PBT determinista (no-negatividad + stock=inicial+Σpos−Σneg) · D2 SQLi params/columna stock{sid} inerte · D2/D6 precios negativos→422 · D10 producto OFF no lista/quicksearch · D1 ajuste solo toca stock{token} · metamórfica +8≡+4+4 | ✅ 13 tests (3/3 mutantes muertos) |
| **CuentasAuditTest** | **D2 overflow 5 campos (`nit`/`telefono`/`direccion`/`departamento` `varchar(191)`, `email` `varchar(255)`) sin `max:` → 500 en store Y update (MEDIA fixeado)** · bordes válidos (191/email 196 entran) · D2 whitelist `tipo` en update · D6 `saldo` no inyectable (store→0, update conserva heredado) · D1 cuenta principal id==1 inmutable (update/toggle) · D1 RBAC (VENDEDOR/CAJERO→403, ADMIN→200) · SQLi/XSS/sort inerte | ✅ 23 tests (3/3 mutantes muertos) |
| **CajaAuditTest** | **D2/D5 `fecha_cierre` sin validar (ALTA fixeado)**: anterior a apertura→whereBetween invertido→tranzas huérfanas (conciliación falseada) · futuro→ultimo_cierre adelantado · basura→500 · **D2 update-tranza sin `descripcion`→500 NOT NULL (MEDIA fixeado)** · **D5 `cierre()` no filtraba estado='ON'→apertura OFF residual bloquea el cierre válido (MEDIA fixeado)** · D5/D6 stateful PBT arrastre MULTI-DÍA (LCG determinista, 6 días: apertura hereda cierre previo, cada cierre=apertura+Σing−Σegr; ninguna tranza contada 2 veces) · D5 anular venta→tranza OFF→cierre posterior no la cuenta · D3 no editar/borrar tranza de período cerrado · D10 bordes de guards (=ultimo_cierre, apertura=hoy/mañana) · D5/D7 revertir ×2/ciclo/otra-sucursal/arrastre-con-tranzas-vivas · DECIMAL(12,2) 22M no desborda | ✅ 21 tests (4/4 mutantes muertos) |
| **DatosRaizAuditTest** | D2 los 5 catálogos COMO CLASE · **`update` de Medio/Empresa/Localidad sin validación → overflow(192>varchar191)/`nombre` faltante/vacío → 500 (MEDIA fixeado)** vía `validate(['nombre'=>'required\|string\|max:191'])` (alineado al ancho real verificado en `tienda_test`) · borde 191 entra · Marca/Industria ya validaban (max:100, contrato fijado) · D1 RBAC VENDEDOR(solo *.index)→403 en store/update/toggle/destroy ×5, ADMIN→200 · D1/D10 registro principal id==1 (Empresa id<=1) inmutable update/toggle/destroy · D3/D10 toggle alterna+doble-toggle reversible+destroy empresa soft-OFF · SQLi/XSS nombre inerte · `LocalidadController::destroy` dead-code (sin ruta, 405 by-design) | ✅ 53 tests (3/3 mutantes muertos) |
| **ComprasAuditTest** | D6 stateful PBT del libro proveedor (acuenta=min(total,pagos+devs), saldo≥0, acuenta+saldo=total): **devItem CREDITO `acuenta+=total` sin tope → acuenta>total al devolver más que el saldo, ALTA fixeado** vía `recalcularSaldoCredito` (espejo Ventas) · simetría dev/revert exacta · conservación de caja (reembolso del excedente al proveedor) · contrato precio (registra `Precio`, NO muta `p_comp`, fiel al legacy) · D4 ciclo cerrado de stock + anular-con-dev neto · D2/D3/D10 fuzz pagar (proforma/anulada/contado/ya-pagada/0/neg/no-num/>saldo→422) | ✅ 10 tests (4/4 mutantes muertos) |
| **SucursalesAuditTest** | **D10/D4 guard de 5 burlado por gap de ids: `max('id')>=5` con sucursales 1,2,3 + AUTO_INCREMENT alto → INSERT id>>5 sin columna stockN → inventario roto (ALTA fixeado)** vía guard de 2 capas (`count()>=5` + re-chequeo `id>5` en transacción que revierte → 422) · **D1 seeder otorga `sucursales.create` a VENDEDOR (el legacy verificado NO le daba ninguno) → rol baja jerarquía con escritura de estructura org (ALTA fixeado)** removiendo `sucursales.*` de VENDEDOR + test del seeder y del enforcement · **D2 overflow `direccion` max:255>varchar(191)→500 (MEDIA fixeado, store+update)** · **D2 NOT NULL sin default (alias/nit/direccion/telefono/email) `store({nombre})`→500 (MEDIA fixeado)** con defaults · **D8 atomicidad: store sin `DB::transaction` + cuenta INTERNO no se creaba en BD fresca (MEDIA fixeado)** vía transaction + create de cuenta · D1/D10 central id==1 inmutable vía update?estado=OFF/destroy/toggle · contrato `ultimo_cierre` no editable · toggle reversible | ✅ 11 tests (3 tests de `ALTER TABLE`/store-feliz RETIRADOS por romper aislamiento DatabaseTransactions; su cobertura: gap-guard sin DDL + overflow del update + atomicidad verificada manual — ver AUDIT-LEDGER loop 20) |

---

## Progreso por módulo

| Módulo | D1 | D2 | D3 | D4 | D5 | D6 | D7 | D8 | D9 | D10 | Estado |
|--------|----|----|----|----|----|----|----|----|----|-----|--------|
| 1. Auth         | ✅ | ✅ | ✅ | ➖ | ➖ | ➖ | ➖ | ✅ | ➖ | ✅ | cubierto ✅ |
| 2. Ventas       | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | PBT+metamórfica+idempotencia+atomicidad+cobros/TOCTOU/conservación (loop 10, 6/6 mutantes) ✅ |
| 3. Compras      | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | PBT dinero CREDITO (ALTA acuenta>total fixeado)+ciclo stock+fuzz pagar (loop 13, 4/4 mutantes) ✅ |
| 4. Envíos       | ✅ | ✅ | ✅ | ✅ | ✅ | ➖ | ✅ | ✅ | ➖ | ✅ | invariantes+atomicidad+IDOR pdf+flete/contrato+conservación stock ciclo completo (loop 15, 5/5 mutantes) ✅ |
| 5. Cotizaciones | ✅ | ✅ | ✅ | ➖ | ➖ | ✅ | ✅ | ➖ | ➖ | ✅ | idempotencia+IDOR+descuento/total+estado terminal CONVERTIDA (loop 12, 5/5 mutantes) ✅ |
| 6. Pedidos      | ✅ | ✅ | ✅ | ➖ | ➖ | ➖ | ✅ | ➖ | ➖ | ✅ | IDOR pdf+escrituras·estados·duplicado·obs overflow (loop 14, 4/4 mutantes) ✅ |
| 7. Caja         | ✅ | ✅ | ✅ | ➖ | ✅ | ✅ | ✅ | ✅ | ➖ | ✅ | conciliación+guards+revert · **fecha_cierre validada (ALTA), estado=ON apertura + update-tranza descripcion (MEDIA), PBT arrastre multi-día** (loop 18, 4/4 mutantes) ✅ |
| 8. Productos    | ✅ | ✅ | ➖ | ✅ | ➖ | ✅ | ✅ | ➖ | ⚠️ | ✅ | ajustes: no-negatividad stock (ALTA ×3 fixeado)+idempotencia+PBT+SQLi+precios neg (loop 16, 3/3 mutantes) · D9 cosmético |
| 9. Cuentas      | ✅ | ✅ | ➖ | ➖ | ➖ | ➖ | ➖ | ➖ | ➖ | ➖ | CRUD+validación · overflow 5 campos store/update (MEDIA fixeado)+saldo-no-inyectable+id==1 inmutable+RBAC (loop 17, 3/3 mutantes) ✅ |
| 10. Estadísticas| ✅ | ✅ | ➖ | ➖ | ➖ | ✅ | ➖ | ➖ | ✅ | ✅ | authz(+simulación)+FIFO+whitelist+agregados(metamórfica/property)+paginación ✅ |
| 11. Admin       | ✅ | ✅ | ➖ | ✅ | ➖ | ➖ | ➖ | ✅ | ➖ | ✅ | RBAC+validación · Datos Raíz (loop 19) · Sucursales (loop 20) · **Usuarios/Roles/Perfil (loop 21): ESCALADA ×4 ALTA fixeadas — GERENTE podía simular ADMIN, crear/promover/auto-promoverse a ADMIN; rol ADMIN/SUSPENDIDO editable/borrable (guard `[1,2]` placebo→protección por nombre) ALTA** (3/3 mutantes) ✅ |
| 12. Dashboard   | ✅ | ✅ | ➖ | ➖ | ➖ | ➖ | ➖ | ➖ | ✅ | ✅ | agregador solo-lectura · simulador+IDOR+fuzz ✅ |

> **Sin celdas ⬜.** Cada celda es ✅ (probada), ➖ (no aplica, ver justificación) o ⚠️ (cosmético conocido).

### Notas abiertas
- **✅ Caja D1 (RESUELTO, fiel al legacy)**: el legacy gateaba `caja/cierre_caja → permission:caja.cierre`
  pero NO gateaba `add_ingreso/add_egreso/update_tranza/delete_tranza` (solo auth). El sistema nuevo
  ya es **más estricto** en esos (group-OR) — mejora silenciosa, se mantiene. El único punto donde
  el nuevo era **menos** estricto que el legacy era `cierre` (lo dejaba con solo `caja.index`):
  **fixeado** → `cierre` y `revertir-cierre` ahora exigen `caja.cierre` (test en AuthorizationMatrixTest).
- **Ventas D7**: race en `VentaNueva.addItem` (doble-scan rápido podría crear 2 ventas). Edge, sin fix.
  La porción DETERMINISTA del TOCTOU de `validar` (stock que cae entre `negativos` y `validar`) SÍ
  está cubierta (loop 10, `VentasAuditTest::test_validar_rechaza_si_el_stock_cayo_tras_negativos`):
  el guard de stock re-chequea del lado servidor → 422, sin sobreventa. Races reales con hilos
  simultáneos (sin lock pesimista en BD) siguen como residual no reproducible bajo `DatabaseTransactions`.
- **Ventas D10 / filtro pago**: proforma aparece al filtrar PAGADO/POR PAGAR mostrando "—" (cosmético).
- **Productos D9**: KPI con monto enorme se trunca en la tarjeta.
- **✅ Estadísticas/Dashboard D1 (RESUELTO, ALTA)**: `autorizarEstadisticas()` usaba `hasRole()`
  (rol REAL) como atajo → un **ADMIN simulando VENDEDOR** veía estadísticas vía API directa
  (fuga del simulador). Fixeado → `effectiveRoleIs()` (respeta `simulated_role_id`). Test:
  `DashboardTest::test_admin_simulando_vendedor_no_ve_estadisticas_del_dashboard` (rojo→verde).
- **✅ Dashboard/Ventas/Estadísticas D1 / sucursal en simulación (RESUELTO, loop 11)**: decisión
  del humano — el simulador debe comportarse **tal cual el rol simulado**, también en la frontera
  de sucursal. Fix: ambos `validarAccesoSucursal` (Venta/Estadistica) usan `effectiveRoleIs()`
  (respeta `simulated_role_id`) en vez de `hasRole('ADMIN')`. ADMIN simulando VENDEDOR pierde el
  bypass → restringido a sus `accesos`. Test: `DashboardTest::test_admin_simulando_vendedor_respeta_frontera_de_sucursal` (rojo→verde).
- **✅ Estadísticas D2 / `take` negativo (RESUELTO, MEDIA)**: `rotacion/topProductos/topClientes`
  hacían `min((int)take,100)` sin cota inferior → `take=-1` daba `LIMIT -1` (MySQL 1064) → 500.
  Fixeado → helper `paginacion()` (take∈[1,100], skip≥0). Test: `EstadisticasAuditTest::test_take_extremos_no_500`.
- **✅ Estadísticas D6 / utilidad rotacionSucursal (RESUELTO, MEDIA — money displayed)**:
  `vendido` se netaba contra devoluciones pero `utilidad` (ingreso−cogs) no → utilidad inflada
  tras una devolución parcial. **Decisión del humano (loop 9): precisión exacta.** Fixeado →
  **neteo EXACTO por renglón**: al ingreso y al COGS brutos se les resta el ingreso real
  (`devventas.total`) y el COGS real de lo devuelto (vía `devventas.registro → ventadetalles.p_comp`,
  el mismo enlace que fija `devItem`). Correcto aunque haya lotes con costos/precios dispares; ya
  no depende del margen promedio. Tests: `test_rotacion_sucursal_utilidad_neta_devolucion` +
  `test_rotacion_sucursal_utilidad_neta_exacta_por_renglon` (prorrateo daría 110 → exacto 120).
- **Estadísticas — SQLi `vpGran` (verificado seguro)**: el `$label` interpolado con `DB::raw` en
  `ventasPeriodo`/`exportarVentasPeriodo` NO usa el input crudo: hay whitelist (`in_array`→'month')
  antes del `match` de constantes. Confirmado por mutación: desactivar el whitelist hace que un
  payload `DROP TABLE` produzca 500 (no se inyecta porque el SQL malformado falla; con el whitelist
  ni siquiera llega). Test: `test_vpgran_payloads_sqli_no_inyectan_ni_500`.
- **✅ Cotizaciones D6 / descuento (RESUELTO, MEDIA ×4)**: la única superficie del sistema que
  expone `descuento` violaba `0 ≤ total ≤ subtotal` por 4 vías — descuento negativo (infla total),
  monto=0+descuento (total negativo, el guard `>=monto/2` se saltaba por `&& monto>0`), `store` sin
  validación, y descuento no numérico (500 por `string - string`). Fixeado: validador
  `nullable|numeric|min:0` en `store`+`updateEncabezado`, guard explícito `monto<=0 && desc>0 → 422`,
  y clamp defensivo `max(0,min(desc,monto))` en `recalcular` (cierra dato legacy POISONED). Tests:
  `CotizacionesAuditTest` (5/5 mutantes muertos).
- **✅ Compras D6 / acuenta supera el total (RESUELTO, ALTA)**: `devItem` CREDITO hacía
  `acuenta += total` sin tope → devolver más que el saldo pendiente (p.ej. pagar 90 de 100, devolver
  ítem de 30) dejaba `acuenta=120 > total=100` y `acuenta+saldo≠total` (libro de proveedor corrupto,
  número mostrado en `compras.jsx`). Mismo bug que Ventas Loop 1, pero Compras nunca recibió el arreglo.
  Fixeado portando `recalcularSaldoCredito()` de `VentaController`: deriva acuenta/saldo de los HECHOS
  (pagos PAG ON + devoluciones D-COM por valor pleno), `acuenta=min(total,…)`. Los 3 sitios de deltas
  (`devItem`/`pagarCompra`/`deleteItemDev`) recalculan en vez de mutar. Tests: `ComprasAuditTest`
  (stateful PBT, halló acuenta=798>744 en escenario sembrado 0; 4/4 mutantes muertos).
- **✅ Compras precio (verificado FIEL AL LEGACY)**: `validar` registra fila `Precio` de historial
  cuando `costo != p_comp` pero NO muta `producto.p_comp` (la línea `$producto->p_comp = $detalle->costo`
  está comentada a propósito en el legacy). El nuevo código replica esa decisión. Test fija el contrato:
  `ComprasAuditTest::test_validar_registra_historial_de_precio_sin_mutar_p_comp`.
- **✅ Cotizaciones D3 / estado terminal CONVERTIDA (RESUELTO, MEDIA)**: agregar/editar/borrar-item y
  editar encabezado solo abortaban en ANULADO → se podía mutar una cotización ya convertida en venta
  (documento terminal consumido). Fixeado: `in_array($estado,['ANULADO','CONVERTIDA'])` en los 4
  puntos. Tests: `CotizacionesAuditTest::test_no_se_puede_*_convertida` (4 rojo→verde).
- **✅ Pedidos D1 / IDOR de lectura en `pdf` (RESUELTO, MEDIA)**: `pdf` no tenía guard de
  sucursal (sí lo tenían `show`/`apiDetalles`/`api`/`kpis`) → una sucursal NO-central descargaba
  el PDF de cualquier pedido ajeno, incluido el **historial de precios de compra** (costos de
  proveedor) que el PDF muestra a ADMIN/GERENTE. Fixeado con el mismo guard asimétrico
  (`$sid !== 1 && pedido->sucursal_id !== $sid → 403`). Test:
  `PedidosAuditTest::test_sucursal_no_central_no_lee_pedido_ajeno`.
- **✅ Pedidos D2 / `observacion` overflow → 500 (RESUELTO, MEDIA)**: `store` validaba
  `observacion` con `max:500` pero la columna es `varchar(191)`; un valor de 192..500 chars
  pasaba la validación y reventaba con 500 (PDOException 1406). `updateEncabezado` no validaba
  longitud en absoluto. Misma clase que el bug de `cantidad` del loop 2. Fixeado →
  `nullable|string|max:191` en ambos endpoints + `maxLength={191}` en el textarea de `pedidos.jsx`.
  Tests: `PedidosAuditTest::test_observacion_excede_la_columna_da_422_no_500_en_{store,update_encabezado}`.
  *Riesgo residual:* `envios`/`cotizacions` también tienen `observacion varchar(191)` y podrían
  compartir el mismo desalineamiento validador↔columna. **Envíos verificado (loop 15): `observacion`
  NO es escribible por la API** (no está en el validador ni en el array de `store`/`update-encabezado`)
  → el desalineamiento no es alcanzable ahí. Queda `cotizacions` sin barrer. *Bajo.*
- **✅ Productos D4 / AJUSTES manuales dejaban stock NEGATIVO (RESUELTO, ALTA ×3)**: los ajustes
  manuales —el mayor blast-radius del módulo— violaban la no-negatividad de stock por 3 vías:
  (1) `ajusteNegativo` restaba sin guard de suficiencia (`stock1=2` − ajuste 5 = −3); (2)
  `ajusteDestroy` sin guard `estado==='ON'` revertía el stock 2 veces al doble-destruir (gemelo de
  `deleteItemDev sobre anulado`); (3) revertir un ajuste POSITIVO ya consumido por negativos
  posteriores bajaba a negativo (bug de 2º orden, hallado por la PBT). Stock negativo es corrupción
  de inventario (envenena `valor_inventario`/KPIs/disponibilidad). Fixeado con la misma postura que el
  guard de sobreventa de `VentaController::validar`: `ajusteNegativo` y el revert de positivo →
  `cantidad > stockN → 422`; `ajusteDestroy` → idempotente (`estado !== 'ON'` no-op). Front:
  `AjusteModal` ahora surfacea el 422 vía `useToast` (antes tragaba el error). Tests:
  `ProductosAuditTest` (13 tests, 3/3 mutantes muertos; PBT determinista). **PREGUNTA → RESUELTA
  (humano 2026-06-16): mantener no-negatividad** — los ajustes NO pueden dejar stock < 0 (el caso de
  faltante se cubre ajustando hasta 0). Sin cambio de código; el guard de doble-revert se mantiene.
- **✅ Cuentas D2 / overflow de 5 campos sin `max:` → 500 (RESUELTO, MEDIA)**: `store` y `update`
  validaban SOLO `nombre`/`tipo`; `nit`/`telefono`/`direccion`/`departamento` (`varchar(191)`) y
  `email` (`varchar(255)`) se insertaban sin `max:` → un valor de 192 chars (256 en email) pasaba la
  validación y reventaba el INSERT con 1406 → 500. Misma clase recurrente (cantidad loop 2,
  observacion pedidos loop 14). Anchos verificados empíricamente en `tienda_test`. Fixeado →
  `nullable|string|max:191`/`max:255` alineados a la columna en AMBOS endpoints. Tests:
  `CuentasAuditTest` (3/3 mutantes muertos). **PREGUNTA → RESUELTA (verificada contra el legacy)**: en
  el dump legacy, `cuentas.create` (perm 52) la tenían GERENTE/VENDEDOR DENNIS/OPERADOR; el rol
  VENDEDOR (4) tenía SOLO index+show → en el legacy VENDEDOR NO creaba clientes. El seeder actual lo
  replica → el 403 es legacy-fiel, sin cambio. Test `test_vendedor_sin_cuentas_create_recibe_403` =
  guardia del contrato (ver AUDIT-LEDGER.md loop 17).
- **✅ Datos Raíz (5 catálogos) D2 / `update` sin validación → 500 (RESUELTO, MEDIA)**: `Medio`/`Empresa`/
  `Localidad` `update` hacían `$x->update(['nombre'=>$request->nombre])` SIN `validate` (a diferencia de
  su `store` y de `Marca`/`Industria` que validan en ambos) → overflow (192>`varchar(191)`), `nombre`
  faltante (NULL en NOT NULL) o vacío → 500. Misma clase recurrente (cantidad loop 2, observacion loop 14,
  cuentas loop 17). Fixeado → `validate(['nombre'=>'required|string|max:191'])` alineado al ancho REAL
  (verificado en `tienda_test`: las 5 columnas `nombre` son `varchar(191) NOT NULL`). Atacados COMO CLASE;
  RBAC/registro-principal/toggle de los 5 ya eran correctos. Tests: `DatosRaizAuditTest` (53, 3/3 mutantes).
  **PREGUNTAS → RESUELTAS (decisión delegada, "tú ve lo mejor", ver AUDIT-LEDGER.md loop 19):** (1) duplicados
  de `nombre` → SE MANTIENEN sin `unique:` (fiel al legacy, no corrompen nada). (2) `LocalidadController::destroy`
  código muerto → SE DEJA (inalcanzable, inofensivo; el test fija el 405 contra exposición accidental).
- **Larastan**: modelos sin `@property` → se ignoró el ruido de Eloquent. Mejora futura: anotar
  modelos para re-activar `property.notFound` (cazaría typos de columnas).

### Justificación de los ➖ (no aplica) — para que no queden como dudas
- **D8 (rollback) en Cotizaciones/Pedidos/Productos/Cuentas/Estadísticas/Admin**: la atomicidad
  multi-paso se prueba donde mueve stock/dinero (Ventas, Envíos, Compras, Caja). El resto son
  inserts/updates de UNA fila o reportes de solo-lectura → no hay transacción multi-paso que
  revertir. La atomicidad real (all-or-nothing) sí está probada en los flujos de inventario.
- **D7 (concurrencia)**: la porción determinista (doble-submit) está cubierta donde importa
  (cotiz→venta, validar venta/compra, enviar, apertura). Races reales con hilos NO son
  reproducibles bajo `DatabaseTransactions` → riesgo residual documentado en AUDIT-LEDGER.md.
  En CRUD de catálogo (Productos/Cuentas/Admin) no hay efecto que duplicar → ➖.
- **D9 (formato numérico)**: ✅ donde la respuesta alimenta formularios y un `number_format`
  rompería `parseFloat` (Ventas, Productos). En Envíos/Cotizaciones/Caja/Estadísticas los montos
  vuelven como `(float)` crudo o son solo-display → ➖. **Productos D9 ⚠️**: KPI con monto enorme
  se trunca en la tarjeta (cosmético, front; no afecta datos).
- **D6 (saldo/total) en Cuentas**: `cuentas.saldo` es un campo heredado estático, no lo computa
  la app → ➖. **D10 en CRUD**: duplicados/edge de catálogo permitidos por diseño legacy → ➖.
- **Dashboard (D3/D4/D5/D6/D7/D8 ➖)**: es una pantalla de **solo-lectura que AGREGA** datos de 7
  endpoints ya cubiertos por sus módulos. No tiene máquina de estados (D3), no mueve stock (D4) ni
  caja (D5), no computa totales propios (D6 — reusa los KPIs de Ventas/Caja), no muta nada
  (D7/D8 sin transacción que duplicar/revertir). Lo que SÍ aplica y se probó: **D1** (frontera de
  rol simulado + IDOR de sucursal en los endpoints que el Dashboard llama), **D2** (fuzz de filtros
  `pagado_filtro`/`estado_filtro`/fechas), **D9** (`total` parseable para el gráfico), **D10**
  (sin datos / caja cerrada → 200 sano).

### Bugs cerrados esta auditoría (referencia)
envíos stock guard · filterNav/canAccess · ajustes validation · compras devItem límite ·
API 500→401 · simulateRole/roles validation · **SQL injection buildRelevanceSQL** ·
4 CVEs deps · caja registro/ref · **deleteItemDev sobre anulado (stock doble-conteo) ×3**.

### Bugs cerrados — rotación adversarial A–E (ver AUDIT-LEDGER.md)
**saldo<0 al devolver venta pagada** (recálculo determinista de saldo) ·
**cantidad fraccionaria/overflow** (`numeric`→`integer` ×6 controladores) ·
**cotiz→venta duplicada** (idempotencia, estado CONVERTIDA) ·
**hueco de red IDOR sucursal** (CrossSucursalAccessTest — mutante sobreviviente cerrado) ·
**cotizaciones descuento negativo/monto-cero → total inflado/negativo** (validador `numeric|min:0` +
guard monto=0 + clamp en `recalcular`) · **cotizaciones descuento no numérico → 500** (TypeError) ·
**cotizaciones CONVERTIDA mutable** (D3: agregar/editar/borrar-item+encabezado solo bloqueaban ANULADO) ·
**compras acuenta>total al devolver más que el saldo** (recalcularSaldoCredito, espejo Ventas) ·
**pedidos IDOR pdf + observacion overflow** (loop 14) ·
**envíos IDOR pdf (sucursal ajena descarga comprobante) + `pagado` sin whitelist → flete sin cobrar** (loop 15) ·
**productos AJUSTES manuales dejaban stock NEGATIVO ×3** (ajusteNegativo sin guard · ajusteDestroy doble-revert · revert de positivo ya consumido — loop 16, ALTA) ·
**cuentas overflow de 5 campos sin `max:` → 500 en store/update** (loop 17, MEDIA — `nit`/`telefono`/`direccion`/`departamento` a `max:191`, `email` a `max:255`, alineados al ancho real de columna) ·
**caja `fecha_cierre` sin validar → conciliación falseable** (loop 18, ALTA — anterior a apertura huérfana tranzas; futuro adelanta `ultimo_cierre`; basura→500; fix: `nullable|date` + guard de rango `[apertura->fecha, hoy]`) · **caja `cierre()` no filtraba `estado='ON'` → apertura OFF residual del arrastre revertido bloquea el cierre válido** (loop 18, MEDIA) · **caja update-tranza sin `descripcion` → 500 NOT NULL** (loop 18, MEDIA — guard `has('descripcion')`) ·
**datos raíz: `update` de Medio/Empresa/Localidad sin validación → overflow/faltante/vacío → 500** (loop 19, MEDIA — `validate(['nombre'=>'required|string|max:191'])` alineado al ancho real; Marca/Industria ya validaban; atacados COMO CLASE los 5 catálogos) ·
**sucursales: guard de 5 burlado por gap de ids (`max('id')>=5` con ids no contiguos + AUTO_INCREMENT alto → INSERT id>>5 sin columna stockN → inventario roto)** (loop 20, ALTA — guard de 2 capas: `count()>=5` + re-chequeo `id>5` en transacción que revierte→422) · **sucursales: seeder otorga `sucursales.create` a VENDEDOR contra el legacy verificado** (loop 20, ALTA — removido del seeder; el legacy no daba ningún `sucursales.*` a VENDEDOR) · **sucursales: overflow `direccion` max:255>varchar(191) + NOT NULL sin default → 500; store sin transacción + cuenta INTERNO no creada en BD fresca** (loop 20, MEDIA).
**a11y: deuda WCAG del loop 22 cerrada** (loop 23 — 8 documentadas + 4 paleta SUC_COLORS + 2 toggles productos: aria-label/label/select-name + contraste de tokens a AA; 6 pantallas axe verde; `a11y.spec.js` ahora regresión real) ·
**performance: `whereDate('fecha')` inutilizaba el índice de `fecha` → FULL SCAN de 31k tranzas en Caja** (loop 24, ALTA — `where()` plano sobre columna DATE ×6 controladores + índice compuesto `tranzas(sucursal_id,estado,fecha)`; listas confirmadas sin N+1).
