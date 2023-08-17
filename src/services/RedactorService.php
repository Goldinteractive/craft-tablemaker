<?php

namespace verbb\tablemaker\services;

use Craft;

use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\models\Section;
use craft\redactor\assets\redactor\RedactorAsset;
use craft\redactor\events\RegisterLinkOptionsEvent;
use verbb\tablemaker\TableMaker;

/**
 * Redactor service
 */
class RedactorService extends Component
{
    /**
     * @event RegisterLinkOptionsEvent The event that is triggered when registering the link options for the field.
     */
    const EVENT_REGISTER_LINK_OPTIONS = 'registerLinkOptions';

    /**
     * @var string|array|null The volumes that should be available for Image selection.
     */
    public $availableVolumes = '*';

    /**
     * @var string|array|null The transforms available when selecting an image
     */
    public $availableTransforms = '*';

    /**
     * @var bool Whether to show input sources for volumes the user doesn’t have permission to view.
     * @since 2.6.0
     */
    public $showUnpermittedVolumes = false;

    public function isRedactorInstalled() {
        // check if the plugin is installed & enabled
        return Craft::$app->plugins->isPluginEnabled('redactor');
    }

    public function getRedactorConfigFilename()
    {
        return TableMaker::$plugin->getSettings()->redactorConfig;
    }

    /**
     * Here we rebuild the settings from \craft\redactor\Field::getInputHtml
     */
    public function getRedactorConfig(ElementInterface $element = null)
    {
        $view = Craft::$app->getView();
        $site = Craft::$app->sites->getCurrentSite();
        $redactorEditorConfig = $this->getRedactorConfigFile('redactor', $this->getRedactorConfigFilename());

        // figure out which language we ended up with
        /** @var RedactorAsset $bundle */
        $bundle = $view->getAssetManager()->getBundle(RedactorAsset::class);
        $redactorLang = $bundle::$redactorLanguage ?? 'en';

        return [
            'id'               => null, // is set in js
            'linkOptions'      => $this->_getLinkOptions($element),
            'volumes'          => $this->_getVolumeKeys(),
            'transforms'       => $this->_getTransforms(),
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

    // --- COPIED PRIVATE FUNCTIONS ---

    /**
     * Returns the link options available to the field.
     * Each link option is represented by an array with the following keys:
     * - `optionTitle` (required) – the user-facing option title that appears in the Link dropdown menu
     * - `elementType` (required) – the element type class that the option should be linking to
     * - `sources` (optional) – the sources that the user should be able to select elements from
     * - `criteria` (optional) – any specific element criteria parameters that should limit which elements the user can select
     * - `storageKey` (optional) – the localStorage key that should be used to store the element selector modal state (defaults to RedactorInput.LinkTo[ElementType])
     *
     * @param Element|null $element The element the field is associated with, if there is one
     * @return array
     */
    private function _getLinkOptions(Element $element = null): array
    {
        $linkOptions = [];

        $sectionSources = $this->_getSectionSources($element);
        $categorySources = $this->_getCategorySources($element);

        if (!empty($sectionSources)) {
            $linkOptions[] = [
                'optionTitle' => Craft::t('redactor', 'Link to an entry'),
                'elementType' => Entry::class,
                'refHandle' => Entry::refHandle(),
                'sources' => $sectionSources,
                'criteria' => ['uri' => ':notempty:']
            ];
        }

        if (!empty($this->_getVolumeKeys())) {
            $linkOptions[] = [
                'optionTitle' => Craft::t('redactor', 'Link to an asset'),
                'elementType' => Asset::class,
                'refHandle' => Asset::refHandle(),
                'sources' => $this->_getVolumeKeys(),
            ];
        }

        if (!empty($categorySources)) {
            $linkOptions[] = [
                'optionTitle' => Craft::t('redactor', 'Link to a category'),
                'elementType' => Category::class,
                'refHandle' => Category::refHandle(),
                'sources' => $categorySources,
            ];
        }

        // Give plugins a chance to add their own
        $event = new RegisterLinkOptionsEvent([
            'linkOptions' => $linkOptions
        ]);
        $this->trigger(self::EVENT_REGISTER_LINK_OPTIONS, $event);
        $linkOptions = $event->linkOptions;

        // Fill in any missing ref handles
        foreach ($linkOptions as &$linkOption) {
            if (!isset($linkOption['refHandle'])) {
                /** @var ElementInterface|string $class */
                $class = $linkOption['elementType'];
                $linkOption['refHandle'] = $class::refHandle() ?? $class;
            }
        }

        return $linkOptions;
    }

    /**
     * Returns the available section sources.
     *
     * @param Element|null $element The element the field is associated with, if there is one
     * @return array
     */
    private function _getSectionSources(Element $element = null): array
    {
        $sources = [];
        $sections = Craft::$app->getSections()->getAllSections();
        $showSingles = false;

        // Get all sites
        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sections as $section) {
            if ($section->type === Section::TYPE_SINGLE) {
                $showSingles = true;
            } else if ($element) {
                $sectionSiteSettings = $section->getSiteSettings();
                foreach ($sites as $site) {
                    if (isset($sectionSiteSettings[$site->id]) && $sectionSiteSettings[$site->id]->hasUrls) {
                        $sources[] = 'section:' . $section->uid;
                    }
                }
            }
        }

        if ($showSingles) {
            array_unshift($sources, 'singles');
        }

        if (!empty($sources)) {
            array_unshift($sources, '*');
        }

        return $sources;
    }

    /**
     * Returns the available category sources.
     *
     * @param Element|null $element The element the field is associated with, if there is one
     * @return array
     */
    private function _getCategorySources(Element $element = null): array
    {
        $sources = [];

        if ($element) {
            $categoryGroups = Craft::$app->getCategories()->getAllGroups();

            foreach ($categoryGroups as $categoryGroup) {
                // Does the category group have URLs in the same site as the element we're editing?
                $categoryGroupSiteSettings = $categoryGroup->getSiteSettings();
                if (isset($categoryGroupSiteSettings[$element->siteId]) && $categoryGroupSiteSettings[$element->siteId]->hasUrls) {
                    $sources[] = 'group:' . $categoryGroup->uid;
                }
            }
        }

        return $sources;
    }

    /**
     * Returns the available volumes.
     *
     * @return string[]
     */
    private function _getVolumeKeys(): array
    {
        if (!$this->availableVolumes) {
            return [];
        }

        $criteria = ['parentId' => ':empty:'];

        $allVolumes = Craft::$app->getVolumes()->getAllVolumes();
        $allowedVolumes = [];
        $userService = Craft::$app->getUser();

        foreach ($allVolumes as $volume) {
            $allowedBySettings = $this->availableVolumes === '*' || (is_array($this->availableVolumes) && in_array($volume->uid, $this->availableVolumes));
            if ($allowedBySettings && ($this->showUnpermittedVolumes || $userService->checkPermission("viewVolume:{$volume->uid}"))) {
                $allowedVolumes[] = $volume->uid;
            }
        }

        $criteria['volumeId'] = Db::idsByUids('{{%volumes}}', $allowedVolumes);

        $folders = Craft::$app->getAssets()->findFolders($criteria);

        // Sort volumes in the same order as they are sorted in the CP
        $sortedVolumeIds = Craft::$app->getVolumes()->getAllVolumeIds();
        $sortedVolumeIds = array_flip($sortedVolumeIds);

        $volumeKeys = [];

        usort($folders, function($a, $b) use ($sortedVolumeIds) {
            // In case Temporary volumes ever make an appearance in RTF modals, sort them to the end of the list.
            $aOrder = $sortedVolumeIds[$a->volumeId] ?? PHP_INT_MAX;
            $bOrder = $sortedVolumeIds[$b->volumeId] ?? PHP_INT_MAX;

            return $aOrder - $bOrder;
        });

        foreach ($folders as $folder) {
            $volumeKeys[] = 'folder:' . $folder->uid;
        }

        return $volumeKeys;
    }

    /**
     * Get available transforms.
     *
     * @return array
     */
    private function _getTransforms(): array
    {
        if (!$this->availableTransforms) {
            return [];
        }

        $allTransforms = Craft::$app->getImageTransforms()->getAllTransforms();
        $transformList = [];

        foreach ($allTransforms as $transform) {
            if (!is_array($this->availableTransforms) || in_array($transform->uid, $this->availableTransforms, false)) {
                $transformList[] = [
                    'handle' => Html::encode($transform->handle),
                    'name' => Html::encode($transform->name)
                ];
            }
        }

        return $transformList;
    }
}
