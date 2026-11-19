import { expect, test, type Page } from '@playwright/test';
import { mailpit } from '../helpers';

// Full account lifecycle through the UI and real mails: reset → set password → login → invite → client login.

const PASSWORD = 'e2e-only-password-91';

async function linkFrom(to: string): Promise<string> {
  const [mail] = await mailpit.waitFor(`to:${to}`);
  const link = mail.Text.match(/https?:\/\/\S+\/admin\/invite\/[a-f0-9]{64}/)?.[0];
  expect(link, mail.Text).toBeTruthy();
  return link!.replace(/^https?:\/\/[^/]+/, ''); // relative to baseURL
}

async function setPassword(page: Page, path: string) {
  await page.goto(path);
  await page.getByLabel(/Neues Passwort/).fill(PASSWORD);
  await page.getByLabel('Wiederholen').fill(PASSWORD);
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.flash')).toHaveText('Passwort gespeichert. Bitte jetzt anmelden.');
}

async function login(page: Page, email: string) {
  await page.goto('/admin/login');
  await page.getByLabel('E-Mail').fill(email);
  await page.getByLabel('Passwort').fill(PASSWORD);
  await page.getByRole('button', { name: 'Anmelden' }).click();
  await expect(page.getByRole('heading', { name: 'Übersicht' })).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async () => mailpit.clear());

test('reset never reveals whether an address exists', async ({ page }) => {
  const texts: string[] = [];
  for (const email of ['unknown@e2e.test', 'admin@e2e.test']) {
    await page.goto('/admin/reset');
    await page.getByLabel('E-Mail').fill(email);
    await page.getByRole('button', { name: 'Link anfordern' }).click();
    texts.push(await page.locator('main').innerText());
  }
  expect(texts[0]).toBe(texts[1]);
  await mailpit.waitFor('to:admin@e2e.test');
  expect(await mailpit.search('to:unknown@e2e.test')).toHaveLength(0);
});

test('admin sets a password, logs in, sees all sites and invites a client', async ({ page }) => {
  await setPassword(page, await linkFrom('admin@e2e.test'));
  await login(page, 'admin@e2e.test');
  for (const site of ['e2e', 'e2elimit', 'demo']) {
    await expect(page.getByRole('link', { name: site, exact: true })).toBeVisible();
  }
  // links are single use
  await page.goto(await linkFrom('admin@e2e.test'));
  await expect(page.getByRole('heading', { name: 'Link ungültig' })).toBeVisible();

  await page.goto('/admin/users');
  await page.getByRole('row', { name: /client@e2e\.test/ }).getByRole('button', { name: 'Einladen' }).click();
  await expect(page.locator('.flash')).toHaveText('Einladung verschickt (Link 24 h gültig).');

  // Test submission from the dashboard: real mail to the alias, autoresponder to the admin, log entry
  await page.goto('/admin/sites/e2e');
  await page.getByRole('row', { name: /kontakt/ }).getByRole('button', { name: 'Testeinsendung' }).click();
  await expect(page.locator('.flash')).toHaveText('Testeinsendung verschickt.');
  await mailpit.waitFor('to:team@e2e.test');
  await expect(page.locator('table.log tbody tr').first()).toContainText('versendet');
  await expect(page.locator('svg.chart rect.s-sent')).toHaveCount(1);
});

test('client sees only own sites; logout ends the session', async ({ page }) => {
  await setPassword(page, await linkFrom('client@e2e.test'));
  await login(page, 'client@e2e.test');
  await expect(page.getByRole('link', { name: 'e2e', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'demo', exact: true })).toHaveCount(0);
  expect((await page.goto('/admin/sites/demo'))!.status()).toBe(404);
  await page.goto('/admin/users'); // admin only → back to the overview
  await expect(page).toHaveURL(/\/admin$/);

  await page.goto('/admin');
  await page.getByRole('button', { name: /Abmelden/ }).click();
  await expect(page.locator('.flash')).toHaveText('Abgemeldet.');
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/admin\/login$/);
});

test('login is blocked after 5 failed attempts', async ({ page }) => {
  for (let i = 0; i < 5; i++) {
    await page.goto('/admin/login');
    await page.getByLabel('E-Mail').fill('client@e2e.test');
    await page.getByLabel('Passwort').fill('falsch');
    await page.getByRole('button', { name: 'Anmelden' }).click();
    await expect(page.locator('.flash')).toHaveText('E-Mail oder Passwort ist falsch.');
  }
  await page.getByLabel('E-Mail').fill('client@e2e.test');
  await page.getByLabel('Passwort').fill(PASSWORD);
  await page.getByRole('button', { name: 'Anmelden' }).click();
  await expect(page.locator('.flash')).toHaveText('Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.');
});
