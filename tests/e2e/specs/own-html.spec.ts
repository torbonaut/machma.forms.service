import { expect, test } from '@playwright/test';
import { addresses, formReady, mailpit, sender, submit } from '../helpers';

// Mode 2: the embed script takes over the customer's own <form>.

test.beforeEach(async () => mailpit.clear());

test('server-side errors appear per field, after the label', async ({ page }) => {
  await page.goto('http://localhost:4400/own.html');
  await formReady(page, '#own');
  await page.getByLabel('Name').fill('Max');
  await page.getByLabel('E-Mail').fill('keine-adresse');

  const res = await submit(page);
  expect(res.status()).toBe(422);
  await expect(page.locator('.mf-status')).toHaveText('Bitte die markierten Felder prüfen.');
  await expect(page.getByLabel('E-Mail')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('.mf-error')).toHaveText([
    'Bitte eine gültige E-Mail-Adresse angeben.',
    'Bitte dieses Feld ausfüllen.',
    'Bitte bestätigen.',
  ]);
  // consent error sits after the <label>, not inside it
  const insideLabel = await page.locator('label .mf-error').count();
  expect(insideLabel).toBe(0);
  expect(await mailpit.count()).toBe(0);
});

test('second attempt after errors succeeds and redirects to data-redirect-url', async ({ page }) => {
  const from = sender('max');
  await page.goto('http://localhost:4400/own.html');
  await formReady(page, '#own');
  await page.getByLabel('Name').fill('Max');
  await submit(page); // 422 – consumes the first ALTCHA solution

  await page.getByLabel('E-Mail').fill(from);
  await page.getByLabel('Nachricht').fill('Zweiter Versuch');
  await page.getByLabel('Datenschutz').check();
  await Promise.all([page.waitForURL('http://localhost:4400/danke-eigen/'), page.locator('[type=submit]').click()]);
  await expect(page.locator('h1')).toHaveText('Danke-Seite (eigene)');

  const [mail] = await mailpit.waitFor('to:team@e2e.test');
  expect(addresses(mail.ReplyTo)).toEqual([from]);
});

test('a foreign redirect target falls back to the form default', async ({ page }) => {
  await page.goto('http://localhost:4400/own-evil-redirect.html');
  await formReady(page, '#evil');
  await page.getByLabel('Name').fill('Eve');
  await page.getByLabel('E-Mail').fill(sender('eve'));
  await page.getByLabel('Nachricht').fill('Phishing-Versuch');
  await page.getByLabel('Datenschutz').check();
  await Promise.all([page.waitForURL('http://localhost:4400/danke/'), page.locator('[type=submit]').click()]);
  await expect(page.locator('h1')).toHaveText('Danke-Seite (Standard)');
});
