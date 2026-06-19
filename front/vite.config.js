import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import fs from 'node:fs';
import path from 'node:path';

// Identificador ÚNICO por build: se estampa DENTRO del bundle (define __APP_VERSION__)
// y también se escribe en dist/version.json. En runtime el front compara ambos; cuando
// difieren (porque se subió una versión nueva) muestra un overlay que obliga a recargar.
// Resuelve el problema recurrente de la caché / SPA vieja que queda en memoria.
const BUILD_ID = Date.now().toString();

export default defineConfig({
  define: { __APP_VERSION__: JSON.stringify(BUILD_ID) },
  plugins: [
    react(),
    {
      // Escribe dist/version.json con el mismo BUILD_ID al terminar el build de producción.
      name: 'lcv-version-json',
      apply: 'build',
      closeBundle() {
        const out = path.resolve(__dirname, 'dist', 'version.json');
        fs.writeFileSync(out, JSON.stringify({ version: BUILD_ID }));
      },
    },
  ],
  server: { port: 3000 },
});
