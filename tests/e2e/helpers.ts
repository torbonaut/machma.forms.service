import { expect, type Page } from '@playwright/test';

const MAILPIT = 'http://localhost:8025/api/v1';

export type Address = { Name: string; Address: string };
export type Summary = { ID: string; Subject: string; From: Address; To: Address[]; Cc: Address[]; Bcc: Address[]; ReplyTo: Address[] };
export type Message = Summary & { Text: string };

export const mailpit = {
  async clear(): Promise<void> {
    const res = await fetch(`${MAILPIT}/messages`, { method: 'DELETE' });
    if (!res.ok) throw new Error(`Mailpit nicht erreichbar (${res.status})`);
  },
  async search(query: string): Promise<Summary[]> {
    const res = await fetch(`${MAILPIT}/search?query=${encodeURIComponent(query)}`);
    return (await res.json()).messages ?? [];
  },
  async count(): Promise<number> {
    return (await (await fetch(`${MAILPIT}/messages`)).json()).messages_count;
  },
  async message(id: string): Promise<Message> {
    return (await fetch(`${MAILPIT}/message/${id}`)).json();
  },
  async headers(id: string): Promise<Record<string, string[]>> {
    return (await fetch(`${MAILPIT}/message/${id}/headers`)).json();
  },
  /** Waits until exactly `n` messages match and returns them in full. */
  async waitFor(query: string, n = 1): Promise<Message[]> {
    let found: Summary[] = [];
    await expect.poll(async () => (found = await mailpit.search(query)).length, { message: `Mail: ${query}` }).toBe(n);
    return Promise.all(found.map((m) => mailpit.message(m.ID)));
  },
};

export const addresses = (list: Address[]): string[] => list.map((a) => a.Address).sort();

/** Unique, readable sender address per test. */
export const sender = (name: string): string => `${name}-${Date.now()}@sender.e2e.test`;

/** Waits until the embed script has rendered or taken over the form. */
export async function formReady(page: Page, selector = '[data-machma-form]'): Promise<void> {
  await expect(page.locator(selector).first()).toHaveClass(/mf-ready/);
}

/** Submits and waits for the POST response of the embed script. */
export async function submit(page: Page, button = page.locator('[type=submit]')) {
  const response = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/v1/'));
  await button.click();
  return response;
}
