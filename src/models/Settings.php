<?php

namespace verbb\tablemaker\models;

use craft\base\Model;

class Settings extends Model
{
    public $redactorConfig = null;

    public function rules(): array
    {
        return [
            [['redactorConfig'], 'string'],
        ];
    }
}
