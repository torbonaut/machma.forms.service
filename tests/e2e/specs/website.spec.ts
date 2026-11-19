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
