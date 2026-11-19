# machma forms

**Formular-Backend für statische Websites. Selbst gehostet, DSGVO-freundlich, ohne Drittanbieter.**

[Website & Doku](https://forms.machma.app) · [Spezifikation](docs/SPEC.md) · [Config-Repo](https://github.com/torbonaut/machma.forms.config)

## Funktionen

- **Formulare als JSON** – Felder, Validierung, Labels (DE/EN), Mail-Templates, Autoresponder
- **Mandantenfähig** – beliebig viele Seiten und Formulare, Kunden sehen nur ihre eigenen
- **SMTP mit CC/BCC** – über ein zentrales Postfach, Empfänger nur über Aliase aus der Config
- **Spamschutz ohne Cookies** – ALTCHA (Proof-of-Work), Honeypot, Mindestzeit, Rate-Limit, Origin-Check
- **Embed-Skript** – rendert Formulare auf jeder Website oder übernimmt eigenes HTML
- **Dashboard** – Nutzung, Statistik, Logs (nur Metadaten), Einladungen, SMTP-Test
- **Läuft auf Webspace** – PHP 8.2+ mit `pdo_sqlite`, kein Redis, keine Queue, kein Hintergrund-Prozess
- **Speichert keine Inhalte** – Einsendungen werden zugestellt, nie gespeichert

## So funktioniert’s

```
Browser (embed.js)                         forms.machma.app
──────────────────                         ────────────────────────────────────────────
GET  /v1/{site}/{form}/spec     ───────►   Formular-JSON ohne Empfänger und Templates
GET  /v1/challenge?site=…       ───────►   ALTCHA-Aufgabe (HMAC, 10 Min.)
     löst Aufgabe (< 1 s)
POST /v1/{site}/{form}  (JSON)  ───────►   1  Seite/Formular existiert         sonst 404
                                           2  Origin erlaubt                   sonst 403
                                           3  JSON, < 32 KB                    sonst 415/413
                                           4  Rate-Limit (IP-Hash + Seite)     sonst 429
                                           5  Honeypot leer, ≥ 3 s             sonst still 200
                                           6  ALTCHA gültig & unbenutzt        sonst 400
                                           7  Felder validiert                 sonst 422
                                           8  Mail an Alias (CC/BCC, Reply-To) sonst 502
                                           9  Autoresponder (max. 3/Adresse/Tag)
                ◄───────────────────────  10  {"ok": true, "redirect": "/danke"}
```

Grundsatz: **Kein Wert aus dem Browser bestimmt, wohin eine Mail geht.**

## Formular einbinden

```html
<!-- Modus 1: rendern -->
<div data-machma-form="beispielhotel/kontakt" data-locale="de"></div>

<!-- Modus 2: eigenes HTML übernehmen -->
<form data-machma-form="beispielhotel/kontakt" data-redirect-url="/danke-zimmer">
  … eigene Felder mit denselben name-Attributen …
</form>

<script src="https://forms.machma.app/v1/embed.js?v=1" defer></script>
```

Die Kundenseite muss `https://forms.machma.app` in ihrer CSP unter `script-src` und `connect-src` erlauben.
Gegen Layout-Sprünge bekommt der Platzhalter eine `min-height` in Formularhöhe. Details: [/docs/einbindung](https://forms.machma.app/docs/einbindung/).

## Projektstruktur

```
public/             Document-Root: index.php, Website, Doku, assets/ (embed.v1.js, CSS)
src/                Anwendung (Router, Validierung, Mail, Sicherheit, Dashboard)
templates/admin/    serverseitig gerenderte Dashboard-Seiten
bin/                compile-config.php, cleanup.php, invite.php
config/compiled.php aus dem Config-Repo erzeugt (nicht im Git)
var/forms.sqlite    Logs, Rate-Limits, ALTCHA-Replay, Benutzer, Sessions (nicht im Git)
secrets.php         SMTP-Zugang, HMAC-Keys (nicht im Git, Vorlage: secrets.example.php)
tests/              PHPUnit
```

Nur `public/` ist über das Web erreichbar; `.htaccess`-Dateien sperren zusätzlich alle anderen Ordner.

Abhängigkeiten (alle reines PHP): `nikic/fast-route`, `phpmailer/phpmailer`, `opis/json-schema`, `altcha-org/altcha`.
Nur in Dev/CI: `symfony/yaml` (Config-Compiler) und `phpunit/phpunit`.

## Lokal starten

Beide Repos nebeneinander auschecken – unter den Ordnernamen `forms-service/` und `forms-config/`
(das Config-Repo ist privat; für eigene Installationen ein eigenes Config-Repo nach diesem Aufbau anlegen):

```bash
git clone https://github.com/torbonaut/machma.forms.service.git forms-service
git clone https://github.com/torbonaut/machma.forms.config.git forms-config
```

Dann in `forms-service/`:

```bash
cp secrets.example.php secrets.php           # SMTP zeigt auf mailpit:1025, ohne Login
docker compose run --rm app composer install
docker compose run --rm app composer dev     # kompiliert die Config aus ../forms-config
docker compose up
```

- Service: <http://localhost:8080> (Website, Doku, Live-Beispiele, `/admin`)
- Mails: <http://localhost:8025> (Mailpit)
- Astro-Seite mit `npm run dev` auf Port 4321 starten und `data-machma-form="dev/kontakt"` einbinden.

Erster Login: einen Benutzer aus `users.yaml` einladen – der Link landet in Mailpit –
oder ohne Mail einen Link direkt ausgeben lassen (geht auch auf dem Server für den ersten Admin):

```bash
docker compose exec app php bin/invite.php torben@machma.app
```

Tests:

```bash
docker compose run --rm app vendor/bin/phpunit
```

**Ohne Docker:** `php -S localhost:8080 -t public public/index.php` plus Mailpit (dann ohne `.htaccess`-Test;
in `secrets.php` SMTP-Host auf `localhost` setzen).

## Konfiguration

Seiten, Formulare und Benutzer liegen ausschließlich im **Config-Repo** und werden in der CI zu
`config/compiled.php` kompiliert – der Server braucht keinen YAML-Parser, und kaputte Config wird nie deployt.

```bash
php bin/compile-config.php ../forms-config               # lokal kompilieren
php bin/compile-config.php ../forms-config --check       # nur prüfen
php bin/compile-config.php ../forms-config --check-form=forms/<site>/<form>.json
php bin/compile-config.php ../forms-config --production  # ohne dev-Seite (CI)
```

Secrets liegen nur auf dem Server in `secrets.php` (außerhalb von `public/`). Neue Keys erzeugen:
`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`

Neue Formulare am schnellsten mit dem **KI-Skill** im Config-Repo (`.claude/skills/formular-json`):
vom Link auf ein altes Formular oder einer Beschreibung zur geprüften Formular-JSON samt Checkliste.

## Deploy

GitHub Action `.github/workflows/deploy.yml` bei jedem Push auf `main`:

1. PHPUnit-Tests (mit dem Config-Repo ausgecheckt)
2. Config prüfen und mit `--production` kompilieren
3. `composer install --no-dev --optimize-autoloader`
4. Code und `public/` per rsync über SSH hochladen, danach `config/compiled.php`

Benötigte Secrets: `SSH_HOST`, `SSH_USER`, `SSH_KEY`, `DEPLOY_PATH` (optional `SSH_PORT`, `SSH_KNOWN_HOSTS`,
`CONFIG_REPO_TOKEN` – nötig, weil das Config-Repo privat ist: fine-grained Token mit Lesezugriff auf dessen Inhalt).
`secrets.php` und `var/` werden nie überschrieben.
Das Config-Repo hat eine eigene Action, die nur `config/compiled.php` hochlädt.

Auf dem Server einmalig: Document-Root der Subdomain auf `…/forms-service/public` stellen,
`secrets.php` anlegen, `var/` beschreibbar machen, mit `php bin/invite.php <admin-email>` den ersten
Login-Link erzeugen und den **Cronjob** (täglich) einrichten:

```
php /pfad/zu/forms-service/bin/cleanup.php
```

Er löscht Logs nach 90 Tagen sowie abgelaufene Sessions, Links, Rate-Limit- und ALTCHA-Einträge.
Monitoring: `GET /health`.

## Sicherheit

- Empfänger nur über Aliase aus der Config; Autoresponder nur mit festem Text → kein offenes Relay
- Redirect-Overrides nur relativ oder auf eigene Origins → kein offenes Redirect
- Zeilenumbrüche aus Betreff, Namen und Reply-To entfernt; Adressen per `filter_var` geprüft
- Mails nur als Text; Embed-Skript setzt Texte nur per `textContent`
- Origin-Prüfung und CORS pro Seite, ALTCHA (Pflicht), Honeypot, Mindestzeit, Rate-Limits
- Dashboard: Argon2id, Login-Limit (5 in 15 Min.), Session-Cookie `HttpOnly; Secure; SameSite=Lax`, CSRF-Token,
  strikte CSP, `X-Frame-Options: DENY`
- Logs nur mit Metadaten und täglich wechselndem IP-Hash

Sicherheitslücken bitte an **security@machma.app** melden, nicht als Issue.

## Roadmap

- Schritt 2: Datei-Anhänge (Multipart, Limits, MIME-Prüfung, nur als Anhang, nie gespeichert)
- Astro-Wrapper als npm-Paket `@machma/forms-astro`, der das HTML schon beim Build rendert
- API-Key pro Seite für Nicht-Browser-Clients (bei Bedarf)

## Lizenz

[MIT](LICENSE), © 2026 machma.app
