<?php

namespace studionoir\watchdog;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\helpers\App;
use craft\services\Gc;
use studionoir\watchdog\models\Settings;
use studionoir\watchdog\services\Formie;
use studionoir\watchdog\services\Freeform;
use studionoir\watchdog\services\Mail;
use yii\base\Event;

/**
 * @property-read Mail $mail
 * @property-read Formie $formie
 * @property-read Freeform $freeform
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const MIN_KEY_LENGTH = 32;

    /** Value put in test submissions, so they are recognisable in the CP and in mails. */
    public const TEST_VALUE = 'Watchdog test';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'mail' => Mail::class,
            'formie' => Formie::class,
            'freeform' => Freeform::class,
        ]);

        $this->mail->listen();
        Event::on(Gc::class, Gc::EVENT_RUN, fn() => $this->mail->prune());
    }

    /** The configured key, or null while it is missing, an unresolved env reference, or too short. */
    public function getApiKey(): ?string
    {
        $raw = trim($this->getSettings()->apiKey);
        $key = App::parseEnv($raw);

        // Craft 4 hands an undefined "$VAR" back unchanged; that is not a key.
        if (!is_string($key) || (str_starts_with($raw, '$') && $key === $raw)) {
            return null;
        }

        return strlen($key) >= self::MIN_KEY_LENGTH ? $key : null;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('watchdog/settings', [
            'settings' => $this->getSettings(),
            'keyOk' => $this->getApiKey() !== null,
            'minLength' => self::MIN_KEY_LENGTH,
            'overridden' => array_key_exists('apiKey', Craft::$app->getConfig()->getConfigFromFile('watchdog')),
        ]);
    }
}
