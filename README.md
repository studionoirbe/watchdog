# Watchdog-connector voor Craft CMS

Plugin waarmee de Watchdog-server een Craft-site kan uitlezen en testen: instellingen opvragen,
een testmail versturen, formulieren oplijsten, een testinzending doen en mails opvragen die niet
verstuurd konden worden.

De plugin voegt niets toe aan de site zelf. Hij biedt vijf eindpunten onder
`/actions/watchdog/api/` en houdt in een eigen tabel bij welke mails mislukt zijn. Het exacte formaat
van elke aanvraag en elk antwoord staat in [API.md](API.md).

## Vereisten

- Craft CMS 4 of 5, PHP 8.0.2 of hoger
- Optioneel: Formie 2 (Craft 4) of Formie 3 (Craft 5), Freeform 5

Formie en Freeform zijn niet nodig. Zonder formulierplugin werken `info`, `mail-test` en `failures`
gewoon; `forms` geeft een lege lijst en `submit` antwoordt met 422.

## Installatie

Het pakket is privé en staat niet op Packagist. Voeg de bron toe aan `composer.json` van de site.

Via de git-repository:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:<organisatie>/craft-watchdog.git" }
]
```

Of, voor lokaal ontwikkelen, via een map op schijf:

```json
"repositories": [
    { "type": "path", "url": "../Watchdog/craft-plugin" }
]
```

Daarna:

```bash
composer require studionoir/craft-watchdog
php craft plugin/install watchdog
```

De installatie maakt de tabel `watchdog_failures` aan. Bij het verwijderen van de plugin
(`php craft plugin/uninstall watchdog`) wordt die tabel weer verwijderd.

Bij een installatie via git bepaalt de tag de versie. Houd het veld `version` in `composer.json`
gelijk aan de tag, anders slaat Composer die tag over.

## De sleutel instellen

Elke aanvraag moet de sleutel meesturen. Zolang er geen sleutel is ingesteld, of zolang hij korter is
dan 32 tekens, weigert de plugin alles met status 503.

1. Maak een sleutel aan:

   ```bash
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   ```

2. Zet hem in `.env` van de site:

   ```
   WATCHDOG_KEY=de-gegenereerde-sleutel
   ```

3. Geef dezelfde sleutel in bij de site in de Watchdog-server.

Meer is niet nodig: de instelling `apiKey` verwijst standaard naar `$WATCHDOG_KEY`.

Wil je een andere omgevingsvariabele of een vaste waarde, dan kan dat op twee plaatsen:

- `config/watchdog.php` (heeft voorrang):

  ```php
  <?php

  return [
      'apiKey' => '$MIJN_ANDERE_VARIABELE',
  ];
  ```

- In het controlepaneel onder Instellingen → Plugins → Watchdog-connector. Die waarde komt in de
  project config terecht; gebruik daar dus een verwijzing naar een omgevingsvariabele en geen
  sleutel in leesbare tekst. Op sites met `allowAdminChanges` uit is deze pagina niet beschikbaar.

De server stuurt de sleutel in twee headers; één ervan volstaat:

```
Authorization: Bearer <sleutel>
X-Watchdog-Key: <sleutel>
```

Voorbeeld:

```bash
curl -H "X-Watchdog-Key: $WATCHDOG_KEY" https://voorbeeld.be/actions/watchdog/api/info
```

## Eindpunten

| Eindpunt | Methode | Wat het doet |
| --- | --- | --- |
| `info` | GET | Versie van Craft en PHP, databank, sites, mailinstellingen, plugins, toestand van de wachtrij. |
| `mail-test` | POST | Verstuurt de testmail van Craft naar het opgegeven adres. |
| `forms` | GET | Alle formulieren van Formie en Freeform met hun velden en meldingen. |
| `submit` | POST | Maakt een testinzending aan en verstuurt de meldingen van het formulier. |
| `failures` | GET | Mails en meldingen die niet verstuurd konden worden. |

De eindpunten werken ook als het systeem in Craft uit staat. Ze werken niet zolang Craft een
databankmigratie verwacht (na een update zonder `php craft up`): dan antwoordt Craft zelf met 503.

### `info`

Geeft enkel wat Craft al weet. Updates worden uit de cache van Craft gelezen; de plugin vraagt zelf
niets op bij de updateserver. Zit er niets in de cache, dan is `craft.updates` `null`.

Van de mailinstellingen komen host, poort, versleuteling en gebruikersnaam mee, nooit het wachtwoord.
`mail.encryption` is op Craft 5 altijd `null`, omdat Craft 5 die instelling niet meer heeft.
`forms.freeform.failureLog` is altijd `false` (zie verder).

### `mail-test`

Verstuurt hetzelfde bericht als de knop "Test" onder Instellingen → E-mail, met de mailinstellingen
die op dat moment gelden. Mislukt de verzending, dan is de HTTP-status nog altijd 200 en staat de
fout van het transport in `error`. Een mislukte testmail komt ook in `failures` terecht.

### `forms`

Per formulier: plugin, id, handle, naam, velden (handle, label, type, verplicht) en meldingen (naam,
aan of uit, ingestelde ontvanger). Velden die niets opslaan, zoals titels en HTML-blokken, staan er
niet bij. Is de ontvanger een veld uit het formulier, dan staat er de verwijzing naar dat veld, bij
Formie bijvoorbeeld `{field:emailAddress}` en bij Freeform `{email}`.

### `submit`

Maakt de inzending rechtstreeks in Craft aan en verstuurt daarna de ingeschakelde meldingen van het
formulier, meteen en niet via de wachtrij. Wat er daarbij bewust niet gebeurt:

- geen captcha en geen validatie van de velden;
- geen integraties: bij Formie worden CRM-, mailinglijst- en webhook-integraties niet gestart, bij
  Freeform evenmin;
- geen spamcontrole.

Verplichte velden zonder opgegeven waarde krijgen een testwaarde: "Watchdog test" voor tekstvelden,
een e-mailadres voor e-mailvelden, de eerste keuze bij keuzelijsten, enzovoort. Bestanden,
adresvelden, tabellen, handtekeningen en gekoppelde elementen blijven leeg. Het e-mailadres is
`recipient` als dat is meegegeven, anders het afzenderadres van de site. De inzending krijgt de
titel "Watchdog test" en wordt na afloop definitief verwijderd, tenzij `delete` op `false` staat.

**Met `recipient`** gaat geen enkele mail naar iemand anders. De plugin dwingt dat af op het
mailtransport zelf, de laatste stap voor een mail de site verlaat: Aan wordt vervangen, CC en BCC
worden leeggemaakt. Dat geldt voor elke mail die tijdens de testinzending verstuurd wordt, ook door
andere plugins of eigen code. Taken die tijdens de testinzending in de wachtrij geplaatst worden,
worden tegengehouden, omdat ze later zouden lopen wanneer de vaste ontvanger niet meer geldt. Kan
de plugin het transport van een site niet inpakken, dan weigert hij met 422 en gebeurt er niets.

**Zonder `recipient`** gaan de meldingen naar de ingestelde ontvangers, inclusief CC en BCC.

In het antwoord staat per melding of ze verstuurd is, de fout van het transport, en in `to` de
adressen waar de mail werkelijk naartoe ging (Aan, CC en BCC samen). Freeform verstuurt één mail
per ontvanger; elke mail is daar een eigen regel.

Formie houdt van elke melding een regel bij onder "Sent Notifications", ook van testinzendingen.
Die regels blijven staan nadat de testinzending verwijderd is.

### `failures`

Zie hieronder. Parameters: `since` (Unix-seconden, standaard zeven dagen terug) en `limit`
(standaard 100, maximaal 500).

## Wat telt als een mislukte verzending

Er zijn vier bronnen. Elke regel heeft een vaste `id` (`formie:412`, `mailer:37`, `queue:5`), zodat
de server ze kan ontdubbelen.

### `mailer`: elke mail via de mailer van Craft

De plugin zet een laag om het mailtransport van Craft. Elke mail die via `Craft::$app->getMailer()`
vertrekt, komt daarlangs, ongeacht welke plugin of code hem verstuurt en ook vanuit de wachtrij of
de console. Geeft het transport een fout, dan komt er een regel in `watchdog_failures` met
tijdstip, onderwerp, ontvangers en de foutmelding.

Wordt herkend:

- geen verbinding met de mailserver, time-out, TLS-fout;
- aanmelden mislukt;
- de mailserver weigert het bericht, de afzender of de ontvanger;
- een fout van een API-transport (Mailgun, Postmark, SES, …), zolang dat transport via Craft loopt.

Wordt niet herkend:

- een mail die de mailserver aanvaardt en die daarna niet aankomt (bounce, spamfilter). Dat is voor
  Craft een geslaagde verzending;
- mail die buiten de mailer van Craft om verstuurd wordt (eigen `mail()`-aanroepen, een plugin die
  rechtstreeks met een API praat);
- een mail die nooit bij de mailer aankomt, bijvoorbeeld omdat het opmaken van het bericht mislukte
  of omdat andere code de verzending annuleerde;
- bij het transport Sendmail: alles wat misloopt nadat het sendmail-programma het bericht heeft
  aangenomen.

Regels ouder dan 90 dagen worden verwijderd, bij de opruiming van Craft en bij elke aanvraag van
`failures`.

### `formie`: het log van Formie

Formie houdt onder "Sent Notifications" bij welke meldingen verstuurd of mislukt zijn. De plugin
geeft de mislukte regels door, met een link naar de regel in het controlepaneel. Daar zitten ook
meldingen bij die nooit bij de mailer raakten: geen ontvanger gevonden, een fout in het sjabloon,
geannuleerd door andere code.

De foutmelding van Formie zelf bevat de fout van de mailserver niet. Kent de plugin die fout uit de
eigen registratie, dan staat die in `error`; anders de melding van Formie.

Voorwaarden en beperkingen:

- "Sent Notifications" moet aan staan in de instellingen van Formie. Staat het uit, dan meldt
  `info` dat en verschijnen mislukte Formie-mails onder `mailer`, met de naam van het formulier.
- Formie ruimt zijn log zelf op, standaard na 30 dagen.
- Mislukte integraties (CRM, mailinglijsten, webhooks) horen hier niet bij.

### `freeform`: geen eigen log

Freeform houdt geen log bij dat uitgelezen kan worden. De bron `freeform` is daarom nooit
beschikbaar en `forms.freeform.failureLog` is altijd `false`.

Mislukte Freeform-mails verschijnen onder `mailer`, met de naam van het formulier en het nummer van
de inzending. Wat Freeform zelf opvangt voor de mail bij de mailer raakt, zoals een fout in het
meldingssjabloon, staat enkel in `storage/logs/freeform-email.log` en wordt niet herkend. Bij een
testinzending via `submit` wordt zo'n melding wel als niet verstuurd gemeld.

### `queue`: mislukte wachtrijtaken

Mislukte taken uit de wachtrij van Craft waarvan de klassenaam "mail" of "notification" bevat,
zoals de meldingstaak van Formie. Een taak verdwijnt uit deze lijst zodra ze opnieuw gestart is en
slaagt, of verwijderd wordt. Dit werkt enkel met de standaardwachtrij van Craft (databank).

### Eén mislukking, één regel

- Meldt het log van Formie een mislukking, dan komt ze niet nog eens als `mailer`.
- Een mislukte wachtrijtaak van een formuliermelding komt niet nog eens als `queue` als dezelfde
  inzending al onder `formie` of `mailer` staat.
- Wordt Formie uitgeschakeld of zijn log uitgezet, dan verschijnen de eerder gekoppelde regels
  opnieuw onder `mailer`, met een `mailer:`-id.

## Foutmeldingen

Elke fout van de plugin is JSON in de vorm `{"ok": false, "error": "…"}`:

| Status | Betekenis |
| --- | --- |
| 401 | Sleutel ontbreekt of klopt niet. |
| 404 | Onbekend eindpunt of onbekend formulier. |
| 405 | Verkeerde HTTP-methode. |
| 422 | Ongeldige invoer, formulierplugin niet geïnstalleerd, of `recipient` kan niet gegarandeerd worden. |
| 500 | Onverwachte fout; de details staan in de logs van Craft. |
| 503 | Geen geldige sleutel ingesteld. |

Twee gevallen handelt Craft af voor de plugin aan bod komt, met een eigen foutpagina in plaats van
dit formaat: een aanvraag met `Content-Type: application/json` en ongeldige JSON (400), en een site
met openstaande migraties (503).
