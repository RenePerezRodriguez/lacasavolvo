/**
 * @fileoverview Barrel de componentes UI compartidos de La Casa Volvo.
 * Re-exporta los módulos de src/lib/components/ para mantener estable la ruta
 * de import histórica ('../lib/components.jsx') usada por todas las pantallas.
 *
 * Módulos:
 * - primitives: Button, Badge, Card, KPI, PageHead, Empty, PdfButton, etc.
 * - feedback:   Toast, ToastProvider, useToast
 * - table:      DataTable, Pager, PageSizeSelector
 * - search:     SearchModal, ProductSearchInput, AccountSearchInput
 * - modals:     ProductQuickViewModal, MovimientosModal
 * - layout:     Sidebar, Topbar, UserMenu, CrumbBar, AppLayout
 */

export * from './components/primitives.jsx';
export * from './components/feedback.jsx';
export * from './components/table.jsx';
export * from './components/search.jsx';
export * from './components/modals.jsx';
export * from './components/layout.jsx';
