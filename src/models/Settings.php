<?php

namespace studionoir\watchdog\models;

use craft\base\Model;

class Settings extends Model
{
    /** The key itself or a reference to an environment variable. */
    public string $apiKey = '$WATCHDOG_KEY';

    protected function defineRules(): array
    {
        return [
            [['apiKey'], 'trim'],
            [['apiKey'], 'string'],
        ];
    }
}
