import { expect, test } from '@playwright/test';
import { addresses, formReady, mailpit, sender, submit } from '../helpers';

// Mode 1: the embed script renders the form from /spec on a customer page (cross-origin :4400 → :8080).

const PAGE = 'http://localhost:4400/render.html';

test.beforeEach(async () => mailpit.clear());

test('renders fields, labels, options and defaults in German', async ({ page }) => {
  await page.goto(PAGE);
  await formReady(page);
  await expect(page.getByRole('textbox', { name: 'Name', exact: true })).toHaveAttribute('maxlength', '100');
  await expect(page.getByLabel('E-Mail')).toHaveAttribute('type', 'email');
  await expect(page.getByLabel('Personen')).toHaveValue('2');
  await expect(page.getByLabel('Thema')).toContainText('Zimmeranfrage');
  await expect(page.getByLabel('Mit Hund')).toBeVisible();
  await expect(page.locator('input[type=hidden][name=quelle]')).toHaveValue('e2e');
  await expect(page.getByRole('button', { name: 'Anfrage senden' })).toBeVisible();
  // Honeypot exists but is not visible for humans
  await expect(page.locator('input[name=_website]')).not.toBeInViewport();
});

test('uses the page language', async ({ page }) => {
  await page.goto('http://localhost:4400/render-en.html');
  await formReady(page);
  await expect(page.getByLabel('Guests')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Send request' })).toBeVisible();
});

test('placeholder height prevents a layout shift', async ({ page }) => {
  await page.goto(PAGE);
  const before = await page.locator('#below').boundingBox();
  await formReady(page);
  const formHeight = (await page.locator('.mf-form').boundingBox())!.height;
  expect(formHeight).toBeLessThan(1400); // otherwise raise min-height in render.html
  const after = await page.locator('#below').boundingBox();
  expect(after!.y).toBe(before!.y);
});

test('browser validation blocks an empty submission without any request', async ({ page }) => {
  let posts = 0;
  page.on('request', (r) => { if (r.method() === 'POST') posts++; });
  await page.goto(PAGE);
  await formReady(page);
  await page.getByRole('button', { name: 'Anfrage senden' }).click();
  await expect(page.getByRole('textbox', { name: 'Name', exact: true })).toBeFocused();
  expect(posts).toBe(0);
});

test('complete submission: success message, recipient mail and autoresponder', async ({ page }) => {
  const from = sender('erika');
  await page.goto(PAGE);
  await formReady(page);
  await page.getByRole('textbox', { name: 'Name', exact: true }).fill('Erika {{email}} E2E');
  await page.getByLabel('E-Mail').fill(from);
  await page.getByLabel('Telefon').fill('+43 1 234567');
  await page.getByLabel('Anreise').fill('2027-07-01');
  await page.getByLabel('Personen').fill('3');
  await page.getByLabel('Thema').selectOption('zimmer');
  await page.getByLabel('Suite').check();
  await page.getByLabel('Parkplatz').check();
  await page.getByLabel('Mit Hund').check();
  await page.getByLabel('Newsletter').check();
  await page.getByLabel('Nachricht').fill('Hallo\n<script>alert(1)</script>');
  await page.getByLabel('Ich akzeptiere die Datenschutzerklärung').check();

  const res = await submit(page);
  expect(res.status()).toBe(200);
  await expect(page.locator('.mf-success')).toHaveText('Danke, wir melden uns!');
  await expect(page.locator('.mf-success')).toBeFocused();

  const [mail] = await mailpit.waitFor('to:team@e2e.test');
  expect(addresses(mail.To)).toEqual(['team@e2e.test']);
  expect(addresses(mail.Cc)).toEqual(['cc@e2e.test']);
  expect(addresses(mail.Bcc)).toEqual(['bcc@e2e.test']);
  expect(addresses(mail.ReplyTo)).toEqual([from]);
  expect(mail.From).toEqual({ Name: 'E2E Hotel via machma forms', Address: 'formulare@machma.app' });
  expect(mail.Subject).toBe('Anfrage Zimmeranfrage von Erika {{email}} E2E'); // value not re-parsed
  expect(mail.Text).toContain('Telefon: +43 1 234567');
  expect(mail.Text).toContain('Anreise: 01.07.2027');
  expect(mail.Text).toContain('Personen: 3');
  expect(mail.Text).toContain('Zimmer: Suite');
  expect(mail.Text).toContain('Extras: Parkplatz, Mit Hund');
  expect(mail.Text).toContain('Newsletter: Ja');
  expect(mail.Text).toContain('Quelle: e2e');
  expect(mail.Text).toContain('<script>alert(1)</script>'); // plain text mail, shown literally

  const [auto] = await mailpit.waitFor(`to:${from}`);
  expect(auto.Subject).toBe('Danke für Ihre Anfrage');
  expect(auto.Text.trim()).toBe('Fester Danke-Text.');
  expect(addresses(auto.ReplyTo)).toEqual(['team@e2e.test']);
  expect((await mailpit.headers(auto.ID))['Auto-Submitted']).toEqual(['auto-replied']);
});

test('embedded SHA-256 matches the standard test vectors', async ({ page }) => {
  await page.goto(PAGE);
  await formReady(page);
  const hashes = await page.evaluate(() => {
    const h = (window as unknown as { machmaForms: { _sha256: (s: string) => string } }).machmaForms._sha256;
    return [h(''), h('abc'), h('abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq'), h('x'.repeat(200))];
  });
  expect(hashes.slice(0, 3)).toEqual([
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
    '248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1',
  ]);
  const expected200 = await page.evaluate(async () => {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('x'.repeat(200)));
    return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
  });
  expect(hashes[3]).toBe(expected200);
});
