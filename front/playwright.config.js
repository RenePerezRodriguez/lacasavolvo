import { defineConfig, devices } from '@playwright/test';

/**
 * Config de QA visual/E2E. La app (Vite :3000) y la API (:8000) deben estar
 * corriendo (.\start.ps1). No levantamos webServer aquí a propósito.
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 90_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://localhost:3000',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'off',
    viewport: { width: 1440, height: 900 },
  },
});
