<?php

namespace studionoir\watchdog\controllers;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use craft\helpers\UrlHelper;
use craft\queue\Queue as CraftQueue;
use craft\web\Controller;
use studionoir\watchdog\ApiError;
use studionoir\watchdog\Plugin;
use studionoir\watchdog\services\Freeform;
use yii\base\Event;
use yii\base\InvalidRouteException;
use yii\queue\PushEvent;
use yii\queue\Queue;
use yii\validators\EmailValidator;
use yii\web\Response;

/**
 * The HTTP contract in API.md. Every response is JSON: {"ok": true, …} or {"ok": false, "error": "…"}.
 */
class ApiController extends Controller
{
    // The key in the header is the only protection; a CSRF token cannot be sent by a server.
    public $enableCsrfValidation = false;

    // Also while the system is switched off: that is exactly when monitoring wants an answer.
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    public function runAction($id, $params = []): mixed
    {
        try {
            return parent::runAction($id, $params);
        } catch (ApiError $e) {
            return $this->fail($e->status, $e->getMessage());
        } catch (InvalidRouteException) {
            return $this->fail(404, 'Onbekend eindpunt.');
        } catch (\Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);

            return $this->fail(500, 'Interne fout: ' . mb_substr((string)Plugin::getInstance()->mail->scrub($e->getMessage()), 0, 300));
        }
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $key = Plugin::getInstance()->getApiKey();
        if ($key === null) {
            throw new ApiError(503, 'Er is geen geldige sleutel ingesteld voor de Watchdog-connector (minstens ' . Plugin::MIN_KEY_LENGTH . ' tekens).');
        }

        // Either header is enough: some hosts drop Authorization, others replace it with their own
        // (basic auth on a staging site) or hide it from PHP's headers but pass it in the environment.
        $headers = $this->request->getHeaders();
        $given = [$headers->get('X-Watchdog-Key')];
        $authorization = $headers->get('Authorization') ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (is_string($authorization) && preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $match)) {
            $given[] = $match[1];
        }

        $valid = false;
        foreach ($given as $candidate) {
            $valid = (is_string($candidate) && hash_equals($key, $candidate)) || $valid;
        }
        if (!$valid) {
            throw new ApiError(401, 'De sleutel ontbreekt of klopt niet.');
        }

        return true;
    }

    public function actionInfo(): Response
    {
        $this->method('GET');

        $app = Craft::$app;
        $plugin = Plugin::getInstance();
        $general = $app->getConfig()->getGeneral();
        $db = $app->getDb();

        // Only what Craft already has in its cache; never a call to the update server from here.
        $updates = null;
        if ($app->getUpdates()->getIsUpdateInfoCached()) {
            $updates = [
                'total' => $app->getUpdates()->getTotalAvailableUpdates(),
                'critical' => $app->getUpdates()->getIsCriticalUpdateAvailable(),
            ];
        }

        $sites = [];
        foreach ($app->getSites()->getAllSites() as $site) {
            $sites[] = [
                'handle' => $site->handle,
                'name' => $site->getName(),
                'baseUrl' => $site->getBaseUrl(),
                'language' => $site->language,
                'primary' => (bool)$site->primary,
            ];
        }

        $plugins = [];
        foreach ($app->getPlugins()->getAllPluginInfo() as $handle => $info) {
            if ($info['isInstalled'] ?? false) {
                $plugins[] = [
                    'handle' => $handle,
                    'name' => (string)$info['name'],
                    'version' => (string)$info['version'],
                    'enabled' => (bool)($info['isEnabled'] ?? false),
                ];
            }
        }

        $queue = $app->getQueue();
        $count = static fn(string $method) => method_exists($queue, $method) ? (int)$queue->$method() : 0;

        return $this->ok([
            'connector' => ['version' => $plugin->getVersion()],
            'craft' => [
                'version' => $app->getVersion(),
                'edition' => $app->getEditionHandle(),
                'environment' => $app->env,
                'devMode' => App::devMode(),
                'allowAdminChanges' => (bool)$general->allowAdminChanges,
                'isSystemLive' => $app->getIsLive(),
                'phpVersion' => PHP_VERSION,
                'dbDriver' => $db->getDriverName(),
                'dbVersion' => App::normalizeVersion($db->getSchema()->getServerVersion()),
                'timezone' => $app->getTimeZone(),
                'updates' => $updates,
            ],
            'sites' => $sites,
            'mail' => $this->mailInfo(),
            'plugins' => $plugins,
            'forms' => [
                'formie' => $plugin->formie->status(),
                'freeform' => $plugin->freeform->status(),
            ],
            'queue' => [
                'waiting' => $count('getTotalWaiting'),
                'reserved' => $count('getTotalReserved'),
                'failed' => $count('getTotalFailed'),
            ],
        ]);
    }

    public function actionMailTest(): Response
    {
        $this->method('POST');

        $to = trim((string)($this->body()['to'] ?? ''));
        if ($to === '' || !(new EmailValidator())->validate($to)) {
            throw new ApiError(422, 'Geef een geldig e-mailadres op in “to”.');
        }

        $mail = Plugin::getInstance()->mail;
        $mailer = Craft::$app->getMailer();
        $error = null;

        // Same message as the "Test" button under Settings → Email. Its text greets a user, which
        // Craft takes from the logged-in user; here an unsaved stand-in with the address fills that role.
        $mail->startCapture();
        try {
            $sent = $mailer
                ->composeFromKey('test_email', [
                    'settings' => MailerHelper::settingsReport($mailer),
                    'user' => new User(['username' => $to, 'email' => $to]),
                ])
                ->setTo($to)
                ->send();
        } catch (\Throwable $e) {
            $sent = false;
            $error = $mail->scrub($e->getMessage());
        } finally {
            foreach ($mail->stopCapture() as $attempt) {
                $error ??= $attempt['error'];
            }
        }

        $info = $this->mailInfo();

        return $this->ok([
            'sent' => $sent,
            'error' => $sent ? null : ($error ?? 'Verzenden mislukt; het transport gaf geen foutmelding door.'),
            'transport' => $info['transport'],
            'fromEmail' => $info['fromEmail'],
        ]);
    }

    public function actionForms(): Response
    {
        $this->method('GET');
        $plugin = Plugin::getInstance();

        return $this->ok(['forms' => array_merge($plugin->formie->forms(), $plugin->freeform->forms())]);
    }

    public function actionSubmit(): Response
    {
        $this->method('POST');

        $body = $this->body();
        $which = (string)($body['plugin'] ?? '');
        $form = trim((string)($body['form'] ?? ''));
        $values = $body['values'] ?? [];
        $delete = filter_var($body['delete'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $recipient = isset($body['recipient']) ? trim((string)$body['recipient']) : null;

        if (!in_array($which, ['formie', 'freeform'], true)) {
            throw new ApiError(422, '“plugin” moet “formie” of “freeform” zijn.');
        }
        if ($form === '') {
            throw new ApiError(422, 'Geef de handle van het formulier op in “form”.');
        }
        if (!is_array($values)) {
            throw new ApiError(422, '“values” moet een object zijn met een waarde per veld-handle.');
        }
        if ($recipient !== null && !(new EmailValidator())->validate($recipient)) {
            throw new ApiError(422, 'Geef een geldig e-mailadres op in “recipient”.');
        }

        $plugin = Plugin::getInstance();
        $mail = $plugin->mail;

        if ($recipient !== null && !$mail->attach(Craft::$app->getMailer())) {
            throw new ApiError(422, 'Op deze site kan niet gegarandeerd worden dat alle meldingen enkel naar “recipient” gaan; de testinzending is niet uitgevoerd.');
        }

        $jobs = new \ArrayObject();
        $onPush = static function(PushEvent $event) use ($jobs, $recipient) {
            if (str_starts_with(get_class($event->job), Freeform::NOTIFICATION_JOB_PREFIX)) {
                // Freeform's notification job: kept out of the queue and run by the adapter.
                $jobs->append($event->job);
                $event->handled = true;
            } elseif ($recipient !== null) {
                // A job queued now would run later, when the forced recipient no longer applies.
                $event->handled = true;
            }
        };

        // A required e-mail field left empty gets an address that cannot bother anyone else.
        $testEmail = $recipient ?? ($this->mailInfo()['fromEmail'] ?: 'watchdog-test@example.com');

        Event::on(Queue::class, Queue::EVENT_BEFORE_PUSH, $onPush);
        $mail->forcedRecipient = $recipient;
        try {
            $result = $which === 'formie'
                ? $plugin->formie->submit($form, $values, $testEmail, $delete)
                : $plugin->freeform->submit($form, $values, $testEmail, $delete, $jobs);
        } finally {
            $mail->forcedRecipient = null;
            Event::off(Queue::class, Queue::EVENT_BEFORE_PUSH, $onPush);
        }

        return $this->ok([
            'submissionId' => $result['submissionId'],
            'deleted' => $result['deleted'],
            'recipientOverridden' => $recipient !== null,
            'notifications' => $result['notifications'],
        ]);
    }

    public function actionFailures(): Response
    {
        $this->method('GET');

        $since = (int)($this->request->getQueryParam('since') ?? time() - 7 * 86400);
        $limit = max(1, min(500, (int)($this->request->getQueryParam('limit') ?? 100)));

        $plugin = Plugin::getInstance();
        $mail = $plugin->mail;
        $formie = $plugin->formie->status();
        $freeform = $plugin->freeform->status();

        $sources = [
            'formie' => ['available' => $formie['failureLog'], 'note' => $formie['installed'] ? $formie['note'] : ($formie['note'] ?? 'Niet geïnstalleerd')],
            'freeform' => ['available' => $freeform['failureLog'], 'note' => $freeform['installed'] ? $freeform['note'] : ($freeform['note'] ?? 'Niet geïnstalleerd')],
            'mailer' => ['available' => true, 'note' => null],
            'queue' => ['available' => true, 'note' => null],
        ];

        $failures = $plugin->formie->failures($since, $limit);

        if (!$mail->tableExists()) {
            $sources['mailer'] = ['available' => false, 'note' => 'De tabel van de connector ontbreekt; installeer de plugin opnieuw of voer de migraties uit.'];
        } else {
            if (!$mail->attach(Craft::$app->getMailer())) {
                $sources['mailer'] = ['available' => false, 'note' => 'Het mailtransport van deze site kan niet gevolgd worden; mislukte mails worden niet of zonder foutmelding geregistreerd.'];
            }
            $mail->prune();
            foreach ($mail->failures($since, $limit, !$formie['failureLog']) as $row) {
                $failures[] = [
                    'id' => 'mailer:' . $row['id'],
                    'source' => 'mailer',
                    'occurredAt' => (int)$row['occurredAt'],
                    'form' => $row['form'],
                    'subject' => $row['subject'],
                    'to' => $row['recipients'],
                    'error' => $row['error'],
                    'submissionId' => $row['submissionId'] !== null ? (int)$row['submissionId'] : null,
                    'cpUrl' => null,
                ];
            }
        }

        $queue = Craft::$app->getQueue();
        if (!$queue instanceof CraftQueue) {
            $sources['queue'] = ['available' => false, 'note' => 'Deze site gebruikt niet de standaardwachtrij van Craft; mislukte taken kunnen niet uitgelezen worden.'];
        } else {
            // A failed form notification is already listed by its submission; do not repeat it as a job.
            $listed = array_filter(array_column($failures, 'submissionId'));
            foreach ($this->failedMailJobs($queue, $since, $limit) as $job) {
                if ($job['submissionId'] === null || !in_array($job['submissionId'], $listed, true)) {
                    $failures[] = $job;
                }
            }
        }

        usort($failures, static fn(array $a, array $b) => $b['occurredAt'] <=> $a['occurredAt'] ?: strnatcmp($b['id'], $a['id']));

        return $this->ok(['failures' => array_slice($failures, 0, $limit), 'sources' => $sources]);
    }

    /** Failed jobs in Craft's queue table whose class name says they send mail or notifications. */
    private function failedMailJobs(CraftQueue $queue, int $since, int $limit): array
    {
        $rows = (new Query())
            ->select(['id', 'job', 'description', 'dateFailed', 'timeUpdated', 'error'])
            ->from($queue->tableName)
            ->where(['channel' => $queue->channel ?? 'queue', 'fail' => true])
            ->orderBy(['id' => SORT_DESC])
            ->limit(1000)
            ->all();

        $jobs = [];
        foreach ($rows as $row) {
            // The job is a serialized object. Only its class name and submission id are read from the
            // text; it is never unserialized here.
            $payload = is_resource($row['job']) ? stream_get_contents($row['job']) : (string)$row['job'];
            $class = preg_match('/^O:\d+:"([^"]+)"/', $payload, $match) ? $match[1] : '';
            $short = substr((string)strrchr('\\' . $class, '\\'), 1);
            if (!preg_match('/mail|notification/i', $short)) {
                continue;
            }

            $occurredAt = $row['dateFailed']
                ? (new \DateTime($row['dateFailed'], new \DateTimeZone('UTC')))->getTimestamp()
                : (int)$row['timeUpdated'];
            if ($occurredAt < $since) {
                continue;
            }

            $jobs[] = [
                'id' => 'queue:' . $row['id'],
                'source' => 'queue',
                'occurredAt' => $occurredAt,
                'form' => null,
                'subject' => $row['description'] ?: $short,
                'to' => null,
                'error' => Plugin::getInstance()->mail->scrub($row['error']),
                'submissionId' => preg_match('/s:12:"submissionId";i:(\d+);/', $payload, $match) ? (int)$match[1] : null,
                'cpUrl' => UrlHelper::cpUrl('utilities/queue-manager/' . $row['id']),
            ];
            if (count($jobs) >= $limit) {
                break;
            }
        }

        return $jobs;
    }

    private function mailInfo(): array
    {
        $settings = App::mailSettings();
        try {
            $adapter = MailerHelper::createTransportAdapter($settings->transportType, $settings->transportSettings);
        } catch (\Throwable) {
            $adapter = null;
        }

        // Only these four transport settings are ever read; the password is never touched.
        $setting = static function(string $name) use ($adapter): ?string {
            $value = $adapter !== null && isset($adapter->$name) ? App::parseEnv((string)$adapter->$name) : null;

            return is_string($value) && $value !== '' ? $value : null;
        };
        $port = $setting('port');

        return [
            'transport' => $adapter ? $adapter::displayName() : (string)$settings->transportType,
            'fromEmail' => App::parseEnv($settings->fromEmail) ?: null,
            'fromName' => App::parseEnv($settings->fromName) ?: null,
            'replyToEmail' => App::parseEnv($settings->replyToEmail) ?: null,
            'host' => $setting('host'),
            'port' => $port !== null ? (int)$port : null,
            'encryption' => $setting('encryptionMethod'),
            'username' => $setting('username'),
        ];
    }

    private function method(string $method): void
    {
        if ($this->request->getMethod() !== $method) {
            $this->response->getHeaders()->set('Allow', $method);
            throw new ApiError(405, "Dit eindpunt verwacht een {$method}-aanvraag.");
        }
    }

    private function body(): array
    {
        $raw = $this->request->getRawBody();
        $body = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) {
            throw new ApiError(400, 'De aanvraag bevat geen geldige JSON.');
        }

        return $body;
    }

    private function ok(array $data): Response
    {
        return $this->asJson(['ok' => true] + $data);
    }

    private function fail(int $status, string $message): Response
    {
        $this->response->setStatusCode($status);

        return $this->asJson(['ok' => false, 'error' => $message]);
    }
}
