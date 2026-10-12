# Watchdog-connector: HTTP-contract (v1)

Dit is de afspraak tussen de Watchdog-server en de Craft-plugin. De server roept deze eindpunten aan;
de macOS-app praat nooit rechtstreeks met een Craft-site.

## Basis

```
{basis}/actions/watchdog/api/<actie>
```

`{basis}` is standaard schema + host van de bewaakte URL (bv. `https://voorbeeld.be`).

## Authenticatie

De server stuurt de sleutel in twee headers, omdat sommige hosts `Authorization` weggooien:

```
Authorization: Bearer <sleutel>
X-Watchdog-Key: <sleutel>
```

De plugin aanvaardt een van beide en vergelijkt met `hash_equals`.

| Situatie | Status | Antwoord |
| --- | --- | --- |
| Geen sleutel ingesteld in de plugin, of korter dan 32 tekens | 503 | `{"ok": false, "error": "…"}` |
| Sleutel ontbreekt of klopt niet | 401 | `{"ok": false, "error": "…"}` |

## Algemeen

- Alle antwoorden zijn JSON met `Content-Type: application/json`.
- Gelukt: `{"ok": true, …}`. Mislukt: passende HTTP-status en `{"ok": false, "error": "leesbare melding in het Nederlands"}`.
- Tijdstippen zijn Unix-seconden (integer).
- Sleutels in camelCase.
- Geheimen komen nooit in een antwoord: geen SMTP-wachtwoord, geen licentiesleutels, geen omgevingsvariabelen.
- Geen CSRF-token nodig; de eindpunten zijn anoniem bereikbaar en enkel door de sleutel beveiligd.

## `GET info`

Instellingen en toestand van de site.

```json
{
  "ok": true,
  "connector": { "version": "1.0.0" },
  "craft": {
    "version": "5.4.2",
    "edition": "pro",
    "environment": "production",
    "devMode": false,
    "allowAdminChanges": false,
    "isSystemLive": true,
    "phpVersion": "8.3.1",
    "dbDriver": "mysql",
    "dbVersion": "8.0.36",
    "timezone": "Europe/Brussels",
    "updates": { "total": 3, "critical": false }
  },
  "sites": [
    { "handle": "default", "name": "Voorbeeld", "baseUrl": "https://voorbeeld.be/", "language": "nl-BE", "primary": true }
  ],
  "mail": {
    "transport": "SMTP",
    "fromEmail": "info@voorbeeld.be",
    "fromName": "Voorbeeld",
    "replyToEmail": null,
    "host": "smtp.voorbeeld.be",
    "port": 587,
    "encryption": "tls",
    "username": "info@voorbeeld.be"
  },
  "plugins": [
    { "handle": "formie", "name": "Formie", "version": "3.0.1", "enabled": true }
  ],
  "forms": {
    "formie": { "installed": true, "version": "3.0.1", "failureLog": true, "note": null },
    "freeform": { "installed": false, "version": null, "failureLog": false, "note": null }
  },
  "queue": { "waiting": 0, "reserved": 0, "failed": 0 }
}
```

- `craft.updates` is `null` als er geen update-info in de cache zit. Dit eindpunt mag zelf geen trage
  externe aanvraag doen om updates op te halen.
- `mail.host`, `port`, `encryption`, `username` zijn `null` als het transport ze niet heeft.
- `forms.<plugin>.failureLog` zegt of de plugin mislukte meldingen bijhoudt (bv. Formie's
  "Sent Notifications"). Staat dat uit, dan legt `note` uit wat er moet aangezet worden.

## `POST mail-test`

Verstuurt een testmail met de mailinstellingen van Craft, zoals de knop "Test" onder Instellingen → E-mail.

Aanvraag:

```json
{ "to": "iemand@voorbeeld.be" }
```

Antwoord (ook bij een mislukte verzending is de HTTP-status 200):

```json
{ "ok": true, "sent": true, "error": null, "transport": "SMTP", "fromEmail": "info@voorbeeld.be" }
```

Bij `sent: false` bevat `error` de foutmelding van het transport. Ongeldig adres: 422.

## `GET forms`

Alle formulieren van Formie en Freeform.

```json
{
  "ok": true,
  "forms": [
    {
      "plugin": "formie",
      "id": 3,
      "handle": "contact",
      "name": "Contact",
      "fields": [
        { "handle": "email", "label": "E-mail", "type": "email", "required": true }
      ],
      "notifications": [
        { "name": "Melding naar beheerder", "enabled": true, "to": "info@voorbeeld.be" }
      ]
    }
  ]
}
```

Geen formulierplugin geïnstalleerd: `{"ok": true, "forms": []}`.

## `POST submit`

Maakt binnen Craft een testinzending aan (dus zonder captcha) en verstuurt de meldingen van het formulier.

Aanvraag:

```json
{
  "plugin": "formie",
  "form": "contact",
  "recipient": "tester@studionoir.be",
  "delete": true,
  "values": { "email": "tester@studionoir.be" }
}
```

- `recipient` (optioneel): als dit meegegeven is, gaan **alle** meldingen uitsluitend naar dit adres
  (To overschreven, CC en BCC leeg). Kan de plugin dat voor een formulier niet garanderen, dan weigert
  hij met 422 in plaats van toch naar de echte ontvangers te sturen.
- Zonder `recipient` gaan de meldingen naar de ingestelde ontvangers.
- `delete` (standaard `true`): verwijder de testinzending na het versturen.
- `values` (optioneel): waarden per veld-handle. Ontbrekende verplichte velden krijgen een herkenbare
  testwaarde ("Watchdog test", een geldig e-mailadres, …).
- Meldingen worden meteen verstuurd, niet via de wachtrij, zodat het resultaat in het antwoord staat.

Antwoord:

```json
{
  "ok": true,
  "submissionId": 412,
  "deleted": true,
  "recipientOverridden": true,
  "notifications": [
    { "name": "Melding naar beheerder", "success": true, "error": null, "to": "tester@studionoir.be" }
  ]
}
```

Onbekend formulier: 404. Plugin niet geïnstalleerd: 422.

## `GET failures`

Mails en meldingen die niet verstuurd konden worden.

Query: `since` (Unix-seconden, standaard 7 dagen terug), `limit` (standaard 100, maximaal 500).

```json
{
  "ok": true,
  "failures": [
    {
      "id": "formie:412",
      "source": "formie",
      "occurredAt": 1760000000,
      "form": "Contact",
      "subject": "Nieuwe inzending",
      "to": "info@voorbeeld.be",
      "error": "Connection could not be established with host smtp.voorbeeld.be",
      "submissionId": 398,
      "cpUrl": "https://voorbeeld.be/admin/formie/sent-notifications/412"
    }
  ],
  "sources": {
    "formie": { "available": true, "note": null },
    "freeform": { "available": false, "note": "Niet geïnstalleerd" },
    "mailer": { "available": true, "note": null },
    "queue": { "available": true, "note": null }
  }
}
```

- `id` is stabiel en uniek binnen de site; de server ontdubbelt hierop. Dezelfde mislukking levert bij
  elke aanvraag hetzelfde `id` op.
- `source`:
  - `formie` / `freeform`: mislukte melding volgens het log van de plugin zelf.
  - `mailer`: elke mail die Craft's mailer niet kon versturen, ongeacht welke code hem verstuurde.
    De plugin houdt dit zelf bij in een eigen tabel.
  - `queue`: mislukte wachtrijtaken die mails versturen.
- Eén mislukte mail levert waar mogelijk één regel op: is dezelfde mislukking al via `formie` of
  `freeform` gemeld, dan komt ze niet nog eens als `mailer`.
- Gesorteerd van nieuw naar oud.
