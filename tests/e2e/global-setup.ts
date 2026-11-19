import { rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { mailpit } from './helpers';

/** Fresh state for every run: empty SQLite (logs, rate limits, users) and empty Mailpit. */
export default async function globalSetup(): Promise<void> {
  const health = await fetch('http://localhost:8080/health').catch(() => null);
  if (!health) {
    throw new Error('Service auf http://localhost:8080 nicht erreichbar – Docker-Stack starten (siehe README, Abschnitt „Tests“).');
  }
  const body = await health.json();
  if (!body.ok || body.sites < 3) {
    throw new Error(`Service nicht bereit oder falsche Config geladen: ${JSON.stringify(body)} – E2E-Config kompilieren.`);
  }
  const db = fileURLToPath(new URL('../../var/forms.sqlite', import.meta.url));
  for (const suffix of ['', '-wal', '-shm']) rmSync(db + suffix, { force: true });
  await mailpit.clear();
}
