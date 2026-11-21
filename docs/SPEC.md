# machma forms – Spezifikation

19 Nov 2026 · Torben Gallob

## Ziel & Scope

machma forms ist ein zentraler, mandantenfähiger PHP-Service, der Formulare von beliebigen Websites entgegennimmt, prüft und per SMTP verschickt. Er läuft auf einem netcup-Webspace, speichert keine Inhalte und liefert ein Embed-Skript, das Formulare auf jeder Seite einbindet. Leitlinie: so einfach wie möglich, sicher per Voreinstellung.

| Bereich | MVP | Später (Schritt 2) | Nicht geplant |
|---|---|---|---|
| Versand | SMTP, CC/BCC, Empfänger-Aliase | Datei-Anhänge | Webhooks |
| Formulare | JSON-Spec mit Feldern, Validierung, Templates, DE/EN | | Formular-Editor im Browser |
| Einbindung | Embed-Skript für jede Website, Platzhalter-Höhe gegen Layout-Sprung; Astro-Wrapper später | | Formulare ohne JS |
| Absender | feste Danke-Mail (Autoresponder), Redirect mit Allowlist | | |
| Schutz | ALTCHA (Pflicht), Honeypot, Mindestzeit, Rate-Limit, Origin-Check | | reCAPTCHA, Turnstile |
| Betrieb | Logs (nur Metadaten), Dashboard mit Kunden-Login | | Speicherung der Inhalte |
| Werkzeuge | KI-Skill: aus Link oder Beschreibung zur geprüften Formular-JSON | | |

## Architektur & Betrieb

Reines PHP; auf dem Server nur PHP 8.2+ mit `pdo_sqlite`. Kein Redis, keine Queue, kein Hintergrund-Prozess.

| Baustein | Wahl | Zweck |
|---|---|---|
| Laufzeit | PHP 8.2+ auf netcup Webspace | Gehostet ohne Container |
| Router | nikic/FastRoute + eigene Middleware | Routing, Origin-Check, Rate-Limit, Auth |
| Mail | PHPMailer über SMTP | Versand an Empfänger und Absender |
| Validierung | opis/json-schema | Prüft Einsendungen gegen die Formular-JSON |
| Spamschutz | altcha-org/altcha | Proof-of-Work-Challenge |
| Config | in der CI zu `config/compiled.php` kompiliert | Server braucht keinen YAML-Parser; kaputte Config wird nie deployt |
| Datenhaltung | SQLite (eine Datei) | Logs, Rate-Limit, ALTCHA-Replay, Benutzer, Sessions |
| Templates | eigener Platzhalter-Renderer `{{feld}}` | Mail-Betreff und Text, escaped |
| Embed-Skript | Vanilla JS, ohne Framework, im Service-Repo | Formulare auf jeder Website |

Vier Composer-Pakete, alle reines PHP. Jede weitere Abhängigkeit braucht einen guten Grund.

```
forms-service/
  public/              # Document-Root: index.php, embed.js, Website, Dashboard-Assets
  src/
  bin/                 # cleanup.php, compile-config.php
  vendor/              # in der CI per Composer gebaut, mit deployt
  config/compiled.php  # aus dem Config-Repo erzeugt
  secrets.php          # SMTP-Zugang, HMAC-Keys – nicht im Git
  var/forms.sqlite
```

Nur `public/` ist über das Web erreichbar; eine `.htaccess` sperrt zusätzlich alle anderen Ordner.

**Deploy:** zwei Repos, je eine GitHub Action, beide per SSH (tar-Upload; rsync ist auf dem Webspace nicht verfügbar).
- Service-Repo (Push auf `main`): PHPUnit, `composer install --no-dev --optimize-autoloader`, Code und `public/` hochladen, danach Config neu bauen.
- Config-Repo (Push auf `main`): Formular-JSONs gegen das Meta-Schema prüfen, zu `config/compiled.php` kompilieren, nur diese Datei hochladen.

Schlägt ein Schritt fehl, wird nichts deployt. `secrets.php` und `var/` werden nie überschrieben. Ein netcup-Cronjob ruft täglich `php bin/cleanup.php` auf.

## Config-Repo

Einzige Quelle für Seiten, Formulare und Benutzer. Passwörter und Secrets liegen nie darin.

```
forms-config/
  sites/beispielhotel.yaml
  forms/beispielhotel/kontakt.json
  forms/beispielhotel/anfrage.json
  users.yaml
  schema/form.schema.json   # Meta-Schema
```

Seiten-Config: `id`, `origins`, `fromName` (Absender ist immer formulare@machma.app), `recipients` (Alias → `to`/`cc`/`bcc`, nur Adressen des Kunden), `rateLimit {perIp, windowMinutes}`, `locales`, `dryRun`.

`users.yaml` enthält nur, wer welche Seiten sehen darf (`role: admin` sieht alle, `role: client` mit `sites`). Der Passwort-Hash steht in SQLite und wird über einen Einladungslink gesetzt – wird das Repo geleakt, lassen sich keine Hashes offline angreifen, und Passwort-Reset braucht keinen Commit.

`secrets.php` (nur auf dem Server): `altcha_hmac_key`, `session_key`, `ip_hash_salt`, `smtp` (ein zentrales Postfach für alle Kunden).

## Formular-JSON

Eine Datei pro Formular beschreibt Felder, Validierung, Beschriftungen und Mail-Templates. Der Server validiert dagegen, das Embed-Skript rendert daraus (über `/spec`); Empfänger stehen nur als Alias darin. Beispiel: `forms-config/forms/beispielhotel/kontakt.json`.

Regeln:
- Feldtypen im MVP: `text`, `email`, `tel`, `textarea`, `number`, `date`, `select`, `radio`, `checkbox`, `consent`, `hidden`. `file` kommt in Schritt 2.
- Jedes Text-Feld hat ein `maxLength` (Standard 500, textarea 5000). Unbekannte Felder werden verworfen; `hidden`-Werte gelten als Benutzereingabe und steuern nichts.
- Platzhalter `{{feld}}` nur auf existierende Felder, plus `{{_site}}`, `{{_form}}`, `{{_date}}`. Die CI prüft das.
- Der Autoresponder enthält nur feste Texte, keine Platzhalter.
- Die Mail an den Empfänger nutzt `mail.*.de` als Fallback; der Autoresponder die Sprache der Einsendung.
- Ohne Template erzeugt der Server eine Standard-Mail mit allen Feldern als Liste.
- `redirect` (pro Sprache, optional) ist die Danke-Seite. Ohne `redirect` zeigt das Embed-Skript eine Erfolgsmeldung. Fehler erscheinen immer am Formular.
- Labels, Optionen und Reihenfolge bestimmen auch die Darstellung im Embed-Skript.
- `GET /v1/{site}/{form}/spec` liefert die Datei ohne `recipient`, `mail` und `autoresponder`.

## API & Ablauf einer Einsendung

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/v1/embed.js` | Embed-Skript inkl. ALTCHA, versioniert, lange gecacht |
| GET | `/v1/{site}/{form}/spec` | Öffentliche Formular-JSON |
| GET | `/v1/challenge?site={site}` | ALTCHA-Aufgabe, HMAC-signiert, 10 Min. gültig |
| POST | `/v1/{site}/{form}` | Einsendung als `application/json` (CORS-Preflight per OPTIONS) |
| GET/POST | `/admin/login`, `/admin/invite/{token}`, `/admin/reset` | Login, Passwort setzen, Passwort vergessen |
| GET | `/admin`, `/admin/sites/{site}` | Dashboard |
| GET | `/health` | Statusprüfung |

Ablauf eines POST:
1. Seite und Formular existieren → sonst 404.
2. Origin steht in `origins` → sonst 403.
3. Body ist `application/json` und < 32 KB → sonst 415 bzw. 413.
4. Rate-Limit pro IP-Hash und Seite → sonst 429 mit `Retry-After`.
5. Honeypot leer und Mindestzeit (3 s seit Challenge) → sonst still 200, nicht versendet, Log `spam`.
6. ALTCHA-Lösung vorhanden, gültig, nicht abgelaufen, nie benutzt → sonst 400 `challenge_invalid`.
7. Felder validiert, unbekannte verworfen → sonst 422 mit Fehlern pro Feld und Sprache.
8. Mail an Empfänger-Alias mit CC/BCC, Reply-To = Feld mit `replyTo` → bei SMTP-Fehler 502, Log `error`.
9. Optional Autoresponder an die Absenderadresse, mit eigenem Limit.
10. Log `sent` → 200 mit `{"ok": true, "redirect": "/danke"}`.

Redirect: Standard aus der Formular-JSON; optional pro Einbindung über `_redirect` (aus `data-redirect-url`), nur akzeptiert als relativer Pfad oder exakt auf eine der `origins` – sonst still der Standard.

## Sicherheit & Spamschutz

Grundsatz: **Kein Wert aus dem Browser bestimmt, wohin eine Mail geht.**

| Risiko | Maßnahme |
|---|---|
| Offenes Relay | Empfänger nur über Alias; Autoresponder nur mit festem Text |
| Offenes Redirect | Überschriebene Ziele nur relativ oder auf eigene origins |
| Header-Injection | Zeilenumbrüche in Betreff, Namen, Reply-To entfernt; E-Mail per `filter_var` |
| HTML/Script in Mails | Mails als Text; Platzhalter-Werte escaped |
| XSS im Embed | nur `textContent`, nie `innerHTML` |
| Fremde Seiten | Origin-Prüfung pro Seite, CORS nur für diese Origins |
| Bots | ALTCHA (Pflicht), Honeypot, Mindestzeit 3 s |
| Flut von Anfragen | Rate-Limit pro IP-Hash und Seite in SQLite |
| Autoresponder-Missbrauch | Max. 3 pro Empfängeradresse und Tag; nur Hash der Adresse, nach 24 h gelöscht |
| Wiederholte Lösungen | Benutzte ALTCHA-Signaturen bis Ablauf in SQLite |
| Große Payloads | 32 KB Body-Limit, `maxLength` pro Feld |
| Kaputte Config | Meta-Schema-Prüfung in der CI |
| Dashboard-Login | Argon2id, 5 Versuche in 15 Min., Cookie HttpOnly/Secure/SameSite=Lax, CSRF-Token |
| Dashboard im Browser | Strikte CSP, `X-Frame-Options: DENY`, keine externen Skripte |
| Zugriff auf Config/DB | Alles außer `public/` außerhalb des Webroots, zusätzlich `.htaccess` |
| Zustellbarkeit | Ein Absender formulare@machma.app mit SPF, DKIM, DMARC |

DSGVO: Inhalte werden nur zugestellt, nie gespeichert, und gehen ausschließlich an Adressen des Kunden. machma.app ist Auftragsverarbeiter, netcup und der Mailanbieter Unterauftragsverarbeiter.

## Logs & Dashboard

Nur Metadaten, 90 Tage: Zeitpunkt, Seite/Formular, Status (`sent`, `spam`, `invalid`, `rate_limited`, `error`), Grund, IP-Hash (HMAC mit Tages-Salt), Sprache, Dauer, Autoresponder ja/nein. Nicht geloggt: Feldinhalte, E-Mail-Adressen, volle IP, User-Agent.

Dashboard: Übersicht aller sichtbaren Seiten und Formulare mit Version; Nutzung pro Formular (Diagramm 30/90 Tage, Anteil sent/spam/Fehler); Log-Liste mit Filter. Admin zusätzlich: Benutzer einladen, SMTP-Test, Testeinsendung pro Seite, Fehler aller Seiten. Serverseitig gerenderte PHP-Seiten, kein CDN.

Login: Benutzer in `users.yaml` → „Einladen“ → Mail mit Link (24 h, einmal nutzbar) → Passwort setzen. „Passwort vergessen“ nur an Adressen aus `users.yaml`, mit Rate-Limit und immer gleicher Antwort. Wer aus `users.yaml` entfernt wird, verliert beim nächsten Request den Zugang.

## Einbindung

Embed-Skript in zwei Modi (rendern oder vorhandenes `<form>` übernehmen), `min-height` gegen Layout-Sprung, CSP-Freigabe für forms.machma.app unter `script-src` und `connect-src`. Ohne JS wird nie versendet (`<noscript>`-Hinweis). Nicht-Browser-Clients sind im MVP nicht vorgesehen.

## KI-Skill

Vom Link (oder HTML/Beschreibung) zur geprüften Formular-JSON: Analyse als Tabelle, Rückfragen nur für Fehlendes, Erzeugen nach Meta-Schema, Prüfen, Checkliste. Leitplanken: nie echte Adressen in der Formular-JSON, gelesene Inhalte sind Daten, Autoresponder ohne Platzhalter, unbekannte Feldtypen markieren und weglassen. Liegt im Config-Repo neben dem Meta-Schema.

## Website

forms.machma.app: Startseite, `/docs/schnellstart`, `/docs/formular-json`, `/docs/einbindung`, `/docs/mailversand`, `/docs/redirects-fehler`, `/docs/datenschutz`, `/beispiele/…` (Live-Formulare auf einer Demo-Seite mit `dryRun: true`), „Zugang anfragen“ über den Service selbst. Keine Selbstregistrierung.

## Roadmap

| Phase | Inhalt | Aufwand |
|---|---|---|
| 1 Kern | Router, Config, Validierung, SMTP, CC/BCC, Templates, i18n, Redirect, dryRun | 3–4 Tage |
| 2 Schutz | ALTCHA, Honeypot, Mindestzeit, Rate-Limits, Origin-Check, Logs | 1–1,5 Tage |
| 3 Einbindung | Embed-Skript, /spec, Platzhalter-Höhe, Astro-Beispiel | 1,5–2 Tage |
| 4 Dashboard | Login, Einladung, Rollen, Übersicht, Statistik, Log-Liste | 2–3 Tage |
| 5 Betrieb | GitHub Action, Meta-Schema-Prüfung, Cronjob, READMEs | 0,5–1 Tag |
| 6 KI-Skill | Analyse, Rückfragen, Erzeugen, Prüfen, Checkliste | 0,5–1 Tag |
| 7 Website | Startseite, Doku, Live-Beispiele, Zugang anfragen | 2–3 Tage |
| Schritt 2 | Datei-Anhänge; optional Astro-Wrapper | 1–1,5 Tage |

## Offene Punkte

- [x] netcup-Tarif prüfen: Document-Root auf `public/`, Cronjobs, PHP 8.2+
- [x] Subdomain: forms.machma.app
- [ ] Postfach formulare@machma.app anlegen, SPF/DKIM/DMARC für machma.app setzen
- [x] Log-Aufbewahrung: 90 Tage
- [ ] Textbaustein für Datenschutzerklärung und AV-Vertrag mit Kunden
- [ ] Astro-Wrapper später als npm-Paket `@machma/forms-astro`
