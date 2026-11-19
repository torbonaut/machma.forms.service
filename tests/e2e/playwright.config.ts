import { defineConfig, devices } from '@playwright/test';

/**
 * Voraussetzung: Docker-Stack läuft (Service :8080, falsch gerooteter Service :8081, Mailpit :8025)
 * und config/compiled.php wurde aus tests/e2e/config kompiliert – siehe README, Abschnitt „Tests“.
 */
export default defineConfig({
  testDir: './specs',
  // One shared server, one Mailpit inbox, one SQLite file: run serially for deterministic results.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  forbidOnly: !!process.env.CI,
  timeout: 30_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  globalSetup: './global-setup.ts',
  use: {
    baseURL: 'http://localhost:8080',
    trace: 'retain-on-failure',
    locale: 'de-DE',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'node fixtures/server.mjs',
    url: 'http://localhost:4400/render.html',
    reuseExistingServer: !process.env.CI,
  },
});
