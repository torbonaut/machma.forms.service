import { expect, test } from '@playwright/test';

// Apache, .htaccess, rewrites and headers on PHP 8.2 – what the in-process PHPUnit tests cannot see.

test('health endpoint reports db and config', async ({ request }) => {
  const res = await request.get('/health');
  expect(res.status()).toBe(200);
  expect(await res.json()).toMatchObject({ ok: true, db: true });
});

test('embed.js is served via rewrite with long caching', async ({ request }) => {
  const res = await request.get('/v1/embed.js?v=1');
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toMatch(/javascript/);
  expect(res.headers()['cache-control']).toContain('max-age=86400');
  expect(await res.text()).toContain('machmaForms');
});

test('website pages and directory indexes work', async ({ request }) => {
  for (const path of ['/', '/docs/schnellstart/', '/beispiele/kontakt/']) {
    const res = await request.get(path);
    expect(res.status(), path).toBe(200);
    expect(res.headers()['content-type'], path).toContain('text/html');
  }
});

test('unknown API paths answer with JSON 404', async ({ request }) => {
  const res = await request.get('/v1/does/not/exist');
  expect(res.status()).toBe(404);
  expect(await res.json()).toMatchObject({ ok: false });
});

test('dashboard sends strict security headers', async ({ request }) => {
  const res = await request.get('/admin/login');
  expect(res.status()).toBe(200);
  const h = res.headers();
  expect(h['content-security-policy']).toContain("frame-ancestors 'none'");
  expect(h['content-security-policy']).toContain("default-src 'none'");
  expect(h['x-frame-options']).toBe('DENY');
  expect(h['x-content-type-options']).toBe('nosniff');
  expect(h['cache-control']).toContain('no-store');
});

test('.htaccess files are never served', async ({ request }) => {
  expect((await request.get('/.htaccess')).status()).toBe(403);
});

test.describe('wrong document root (repo root instead of public/)', () => {
  const misrooted = 'http://localhost:8081';
  for (const path of ['/secrets.php', '/secrets.example.php', '/var/forms.sqlite', '/config/compiled.php', '/composer.json', '/src/App.php', '/bin/invite.php', '/']) {
    test(`blocks ${path}`, async ({ request }) => {
      const res = await request.get(misrooted + path);
      expect(res.status()).toBe(403);
      const body = await res.text();
      expect(body).not.toContain('smtp');
      expect(body).not.toContain('<?php');
    });
  }
});
