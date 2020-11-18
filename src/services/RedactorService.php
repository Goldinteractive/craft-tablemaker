<?php

/**
 * Table Maker plugin for Craft CMS 3.x
 *
 * A user-definable table field type for Craft CMS
 *
 * @link      http://www.supercooldesign.co.uk/
 * @copyright Copyright (c) 2018 Supercool Ltd
 */

namespace supercool\tablemaker\services;

use Craft;

use craft\base\Component;
use craft\helpers\Json;
use craft\redactor\assets\redactor\RedactorAsset;

/**
 * RedactorService Service
 */
class RedactorService extends Component
{
    public function isRedactorInstalled() {
        // check if the plugin is installed & enabled
        return Craft::$app->plugins->isPluginEnabled('redactor');
    }

    public function getRedactorConfigFilename()
    {
        //todo filename from settings
        return 'Project.json';
    }

    /**
     * Here we rebuild the settings from \craft\redactor\Field::getInputHtml
     */
    public function getRedactorConfig()
    {
        $view = Craft::$app->getView();
        $site = Craft::$app->sites->getCurrentSite();
        $redactorEditorConfig = $this->getRedactorConfigFile('redactor', $this->getRedactorConfigFilename());

        // figure out which language we ended up with
        /** @var RedactorAsset $bundle */
        $bundle = $view->getAssetManager()->getBundle(RedactorAsset::class);
        $redactorLang = $bundle::$redactorLanguage ?? 'en';

        // maybe add linkOptions and volumes later,
        // it would require a whole lot of copy / paste from the redactor plugin
        return [
            'id'               => null, // is set in js
            'linkOptions'      => [],
            'volumes'          => [],
            'transforms'       => [],
            'elementSiteId'    => $site->id,
            'redactorConfig'   => $redactorEditorConfig,
            'redactorLang'     => $redactorLang,
            'showAllUploaders' => false,
        ];
    }

    /**
     * Copied from \craft\redactor\Field::_getConfig
     */
    private function getRedactorConfigFile(string $dir, string $file = null)
    {
        if (!$file) {
            return false;
        }

        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            return false;
        }

        return Json::decode(file_get_contents($path));
    }
}
