<?php
namespace verbb\tablemaker;

use verbb\tablemaker\base\PluginTrait;
use verbb\tablemaker\fields\TableMakerField;

use craft\base\Plugin;
use craft\services\Fields;
use craft\events\RegisterComponentTypesEvent;

use verbb\tablemaker\models\Settings;
use verbb\tablemaker\services\RedactorService;
use yii\base\Event;

/**
 * @author    Supercool Ltd
 * @package   TableMaker
 * @since     1.0.0
 *
 * @property  RedactorService $redactor
 */

class TableMaker extends Plugin
{
    // Properties
    // =========================================================================

    public string $schemaVersion = '3.0.0';
    public string $minVersionRequired = '1.0.0';


    // Traits
    // =========================================================================

    use PluginTrait;


    // Public Methods
    // =========================================================================

    public function init()
    {
        parent::init();

        self::$plugin = $this;

        $this->setComponents(
            [
                'redactor' => services\RedactorService::class,
            ]
        );

        $this->_setPluginComponents();
        $this->_setLogging();
        $this->_registerFieldTypes();
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    // Private Methods
    // =========================================================================

    private function _registerFieldTypes()
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = TableMakerField::class;
        });
    }
}
