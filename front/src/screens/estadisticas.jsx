/**
 * @fileoverview Pantalla de estadísticas con 5 paneles analíticos:
 * - RotacionPanel: rotación por compra (sell-through de órdenes de compra; lente global o
 *   por sucursal de la propia compra).
 * - RotacionSucursalPanel: rotación por sucursal (turnover real; comprado + recibido por
 *   traslados vs vendido; contempla envíos entre sucursales).
 * - VentasPeriodoPanel: ventas agrupadas por día/semana/mes con gráfico de barras.
 * - TopProductosPanel: ranking de productos por cantidad o monto vendido.
 * - TopClientesPanel: ranking de clientes por monto acumulado.
 * Todos los paneles soportan exportación CSV (solo ADMIN y GERENTE).
 */

import React, { useState } from 'react';
import { Icon, PageHead } from '../lib/components.jsx';
import { RotacionPanel, RotacionSucursalPanel, VentasPeriodoPanel, TopProductosPanel, TopClientesPanel } from './estadisticas/index.js';

/**
 * Pantalla de estadísticas con 4 paneles analíticos en tabs.
 * @param {object} props
 * @param {function(string|object): void} props.onNav
 */
export function Estadisticas({ onNav, user }) {
  const [tab, setTab] = useState("rotacion");

  const tabs = [
    { id: "rotacion",     label: "Rotación por compra",   icon: "fa-rotate" },
    { id: "rotacionSuc",  label: "Rotación por sucursal", icon: "fa-store" },
    { id: "ventas",       label: "Ventas por período",    icon: "fa-chart-line" },
    { id: "topProd",      label: "Top productos",         icon: "fa-trophy" },
    { id: "topCli",       label: "Top clientes",          icon: "fa-users" },
  ];

  return (
    <div className="fade-up stack" style={{"--gap":"20px"}}>
      <PageHead title="Estadísticas" sub="Reportes y análisis del negocio" actions={null} diamond/>

      <div className="seg-tabs es-tabs">
        {tabs.map(t => (
          <button key={t.id} className={`seg ${tab === t.id ? "active" : ""}`} onClick={() => setTab(t.id)}>
            <Icon name={t.icon} style={{fontSize: 11, marginRight: 5}}/>
            {t.label}
          </button>
        ))}
      </div>

      {tab === "rotacion"    && <RotacionPanel user={user} onVerSucursal={() => setTab("rotacionSuc")} />}
      {tab === "rotacionSuc" && <RotacionSucursalPanel user={user} />}
      {tab === "ventas"      && <VentasPeriodoPanel user={user} />}
      {tab === "topProd"     && <TopProductosPanel user={user} />}
      {tab === "topCli"      && <TopClientesPanel user={user} />}
    </div>
  );
}
