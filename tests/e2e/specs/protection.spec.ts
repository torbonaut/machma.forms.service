import { expect, test } from '@playwright/test';
import { formReady, mailpit, sender, submit } from '../helpers';

test.beforeEach(async () => mailpit.clear());

async function fillMinimal(page: import('@playwright/test').Page, from: string) {
  await page.getByRole('textbox', { name: 'Name', exact: true }).fill('Bot');
  await page.getByLabel('E-Mail').fill(from);
  await page.getByLabel('Thema').selectOption('sonstiges');
  await page.getByLabel('Nachricht').fill('Hallo');
  await page.getByLabel('Ich akzeptiere die Datenschutzerklärung').check();
}

test('filled honeypot looks successful but sends nothing', async ({ page }) => {
  await page.goto('http://localhost:4400/render.html');
  await formReady(page);
  await fillMinimal(page, sender('bot'));
  await page.locator('input[name=_website]').fill('http://spam.example', { force: true });
  const res = await submit(page);
  expect(res.status()).toBe(200);
  await expect(page.locator('.mf-success')).toBeVisible();
  expect(await mailpit.count()).toBe(0); // the mail would have been sent before the response
});

test('a foreign origin cannot load the form', async ({ page }) => {
  await page.goto('http://localhost:4401/render.html');
  await expect(page.locator('[data-machma-form]')).toHaveClass(/mf-failed/);
  await expect(page.locator('.mf-status')).toHaveText('Das Formular konnte nicht geladen werden.');
});

test('a foreign origin cannot post, and the preflight is refused', async ({ request }) => {
  const post = await request.post('/v1/e2e/kontakt', { headers: { Origin: 'http://localhost:4401' }, data: { name: 'x' } });
  expect(post.status()).toBe(403);
  expect(post.headers()['access-control-allow-origin']).toBeUndefined();
  const preflight = await request.fetch('/v1/e2e/kontakt', {
    method: 'OPTIONS',
    headers: { Origin: 'http://localhost:4401', 'Access-Control-Request-Method': 'POST' },
  });
  expect(preflight.status()).toBe(403);
});

test('an ALTCHA solution works only once (replay → 400)', async ({ page, request }) => {
  await page.goto('http://localhost:4400/render.html');
  await formReady(page);
  await fillMinimal(page, sender('replay'));
  const res = await submit(page);
  expect(res.status()).toBe(200);
  const body = res.request().postDataJSON();
  expect(body.altcha).toBeTruthy();

  const replay = await request.post('/v1/e2e/kontakt', { headers: { Origin: 'http://localhost:4400' }, data: body });
  expect(replay.status()).toBe(400);
  expect(await replay.json()).toMatchObject({ error: 'challenge_invalid' });

  const without = await request.post('/v1/e2e/kontakt', { headers: { Origin: 'http://localhost:4400' }, data: { ...body, altcha: undefined } });
  expect(without.status()).toBe(400);
  await expect.poll(() => mailpit.count()).toBe(2); // only the first submission: mail + autoresponder
});

test('rate limit per IP and site shows a message with the waiting time', async ({ page }) => {
  for (let i = 1; i <= 3; i++) {
    await page.goto('http://localhost:4400/limit.html');
    await formReady(page);
    await page.getByLabel('Name').fill(`Versuch ${i}`);
    const res = await submit(page);
    if (i <= 2) {
      expect(res.status()).toBe(200);
      await expect(page.locator('.mf-success')).toBeVisible();
    } else {
      expect(res.status()).toBe(429);
      expect(Number(res.headers()['retry-after'])).toBeGreaterThan(500);
      await expect(page.locator('.mf-status')).toHaveText('Zu viele Anfragen. Bitte in 10 Minuten erneut versuchen.');
    }
  }
});
