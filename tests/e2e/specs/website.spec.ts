import { expect, test } from '@playwright/test';
import { formReady, mailpit, sender, submit } from '../helpers';

test.beforeEach(async () => mailpit.clear());

test('live example (dryRun) shows the preview and sends nothing', async ({ page }) => {
  const errors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.goto('/beispiele/kontakt/');
  await formReady(page);
  await page.getByRole('textbox', { name: 'Name', exact: true }).fill('Demo');
  await page.getByLabel('E-Mail').fill(sender('demo'));
  await page.getByLabel('Thema').selectOption('zimmer');
  await page.getByLabel('Nachricht').fill('Nur ein Test');
  await page.getByLabel('Ich akzeptiere die Datenschutzerklärung').check();
  const res = await submit(page);
  expect(await res.json()).toMatchObject({ ok: true, dryRun: true });

  await expect(page.locator('#preview')).toBeVisible();
  await expect(page.locator('#preview-mail')).toContainText('An:       demo@e2e.test');
  await expect(page.locator('#preview-mail')).toContainText('Betreff:  Anfrage Zimmeranfrage von Demo');
  await expect(page.locator('#preview-auto')).toContainText('Fester Danke-Text.');
  expect(await mailpit.count()).toBe(0);
  expect(errors, 'no CSP or script errors on our own strict-CSP page').toEqual([]);
});

test('own-HTML example: field errors from the server, then preview without sending', async ({ page }) => {
  const errors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.goto('/beispiele/eigenes-html/');
  await formReady(page);
  const form = page.locator('form.own-form');

  // Empty submission: no browser validation in the own HTML, so the server answers with errors per field.
  const bad = await submit(page);
  expect(bad.status()).toBe(422);
  await expect(form.locator('.mf-error').first()).toBeVisible();
  await expect(form.locator('input[name=email]')).toHaveClass(/mf-invalid/);

  await form.locator('input[name=name]').fill('Eigen');
  await form.locator('input[name=email]').fill(sender('own'));
  await form.locator('select[name=thema]').selectOption('zimmer');
  await form.locator('textarea[name=nachricht]').fill('Eigenes HTML');
  await form.locator('input[name=datenschutz]').check();
  const res = await submit(page);
  expect(await res.json()).toMatchObject({ ok: true, dryRun: true });

  await expect(page.locator('.mf-success')).toContainText('es wurde nichts verschickt');
  await expect(page.locator('#preview-mail')).toContainText('Anfrage Zimmeranfrage von Eigen');
  expect(await mailpit.count()).toBe(0);
  // The deliberate 422 is logged by the browser as a failed resource – that is not an error of the page.
  expect(errors.filter((e) => !e.includes('status of 422')), 'no CSP or script errors').toEqual([]);
});
