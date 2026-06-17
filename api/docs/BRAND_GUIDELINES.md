# PALETA GRÁFICA Y GUÍA DE ESTILOS - LA CASA VOLVO

Este documento rige los estilos y colores que deben ser usados en el código fuente (Tailwind/CSS) del sistema V2 de La Casa Volvo.
No se deben utilizar variantes de colores que no estén definidas aquí.

## Paleta de Colores

### 1. Blue Navy (Primario)
- **HEX:** `#182642`
- **Uso en Tailwind CSS:** `bg-[#182642]`, `text-[#182642]`, `border-[#182642]`
- **Variables CSS:** `--lcv-accent`
- **Contexto:** Color principal institucional. Usado en encabezados, botones primarios, fondos de sidebar (en ciertos modos), textos resaltados.

### 2. Star of Life (Secundario / Interactivo)
- **HEX:** `#0B7EC2`
- **Uso en Tailwind CSS:** `bg-[#0B7EC2]`, `text-[#0B7EC2]`
- **Variables CSS:** `--lcv-info`
- **Contexto:** Elementos activos, hover sobre el color primario, botones de acción (CTAs), y enlaces.

### 3. Aster (Gris Azul Oscuro)
- **HEX:** `#808DA7`
- **Uso en Tailwind CSS:** `text-[#808DA7]`, `bg-[#808DA7]`
- **Variables CSS:** `--lcv-text-muted`
- **Contexto:** Textos secundarios, íconos sin foco, etiquetas descriptivas (labels).

### 4. Stardust Evening (Gris Azul Claro)
- **HEX:** `#B9C4DC`
- **Uso en Tailwind CSS:** `text-[#B9C4DC]`, `border-[#B9C4DC]`
- **Variables CSS:** `--lcv-placeholder`
- **Contexto:** Placeholders en inputs, bordes sutiles, divisiones, marcas de agua.

### 5. Blue Lips (Azul Claro)
- **HEX:** `#A4BFE5`
- **Uso en Tailwind CSS:** `bg-[#A4BFE5]`
- **Contexto:** Fondos secundarios muy ligeros, hovers sutiles en tablas.

---

## Tipografía Oficial

- **Principal:** `Lakes Neue` (Light, Regular, Medium, Bold, Black). Usada en toda la aplicación.
- **Secundaria:** `Technica`. Usada para montos, datos numéricos o facturas.
- **Fallback:** `Helvetica`, `Arial`, `sans-serif`.

---

## Colores Estructurales Permitidos

Para mantener el diseño limpio ("Industrial Premium"), se permiten los siguientes colores de fondo y texto neutros:
- Fondo de la App: `#F4F6FA` (Light) / `#020617` (Dark)
- Superficies (Tarjetas/Modales): `#FFFFFF` (Light) / `#0F172A` (Dark)
- Texto Principal: `#0F172A` (Light) / `#F8FAFC` (Dark)
- Bordes Genéricos: `#E2E8F0`

*Nota: Todas estas reglas ya se encuentran implementadas en el archivo `resources/css/app.css` mediante variables CSS globales.*
