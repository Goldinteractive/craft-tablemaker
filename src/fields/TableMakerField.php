<?php
namespace verbb\tablemaker\fields;

namespace verbb\tablemaker\fields;

use craft\redactor\FieldData;
use verbb\tablemaker\assetbundles\field\FieldAsset;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Cp;
use craft\helpers\Db;
use verbb\tablemaker\TableMaker;
use yii\db\Schema;
use craft\helpers\Json;
use craft\helpers\Template;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class TableMakerField extends Field
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('tablemaker', 'Table Maker');
    }


    // Properties
    // =========================================================================

    public ?string $columnsLabel = null;
    public ?string $columnsInstructions = null;
    public ?string $columnsAddRowLabel = null;
    public ?string $rowsLabel = null;
    public ?string $rowsInstructions = null;
    public ?string $rowsAddRowLabel = null;


    // Public Methods
    // =========================================================================

    public function getContentColumnType(): string
    {
        return Schema::TYPE_TEXT;
    }

    public function normalizeValue(mixed $value, ?\craft\base\ElementInterface $element = null): mixed
    {
        if (!is_array($value)) {
            $value = Json::decode($value);
        }

        if (!isset($value['rows'])) {
            $value['rows'] = [];
        }

        $html = '
            <table>
                <thead>
                    <tr>
        ';

        if (!empty($value['columns'])) {
            foreach ($value['columns'] as $col) {
                $html .= '<th align="' . $col['align'] . '" width="' . $col['width'] . '">' . $col['heading'] . '</th>';
            }
        }

        $html .= '
                    </tr>
                </thead>

                <tbody>';

        if (!empty($value['rows'])) {
            foreach ($value['rows'] as $row) {
                $html .= '<tr>';

                $i = 0;
                foreach ($row as $key => $cell) {
                    $align = $value['columns'][$key]['align'] ?? $value['columns'][$i]['align'];
                    $html .= '<td align="' . $align . '">' . $cell . '</td>';
                    $i++;
                }

                $html .= '</tr>';
            }
        }

        $html .= '

                </tbody>

            </table>
        ';

        $value['table'] = Template::raw($html);

        return $value;
    }

    public function serializeValue(mixed $value, ?\craft\base\ElementInterface $element = null): mixed
    {
        $plugin = TableMaker::getInstance();
        $isRedactorInstalled = $plugin->redactor->isRedactorInstalled();

        if (!empty($value['columns']) && is_array($value['columns'])) {
            $value['columns'] = array_values($value['columns']);
        }

        if (!empty($value['rows']) && is_array($value['rows'])) {
            // drop keys from the rows array
            $value['rows'] = array_values($value['rows']);

            foreach ($value['rows'] as &$row) {
                if (is_array($row)) {
                    $row = array_values($row);
                }

                if (!empty($value['columns']) && is_array($value['columns'])) {
                    if ($isRedactorInstalled) {
                        foreach ($value['columns'] as $key => &$column) {
                            // use redactors serialize function to parse ref tags
                            if (isset($column['fieldType']) && $column['fieldType'] == 'html' && isset($row[$key])) {
                                $field = new \craft\redactor\Field();
                                $rowData = $field->normalizeValue($row[$key],$element);
                                $row[$key] = $field->serializeValue($rowData,$element);
                            }
                        }
                    }
                }
            }
        }

        return parent::serializeValue($value, $element);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('tablemaker/_field/settings', [
            'settings' => $this->getSettings(),
            'field' => $this,
        ]);
    }

    public function getInputHtml(mixed $value, ?\craft\base\ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $plugin = TableMaker::getInstance();

        $isRedactorInstalled = $plugin->redactor->isRedactorInstalled();

        // Register our asset bundle
        $view->registerAssetBundle(FieldAsset::class);

        $name = $this->handle;

        $columns = [];
        $rows = [];

        $columnsInput = $name . '[columns]';
        $rowsInput = $name . '[rows]';

        $columnsInputId = $name . '-columns';
        $rowsInputId = $name . '-rows';

        // make input
        $input = '<input class="table-maker-field" type="hidden" name="' . $name . '" value="">';

        // get columns from db or fall back to default
        if (!empty($value['columns'])) {
            foreach ($value['columns'] as $key => $val) {
                $columns['col' . $key] = [
                    'heading' => $val['heading'],
                    'fieldType' => isset($val['fieldType']) ? $val['fieldType'] : null,
                    'align' => $val['align'],
                    'width' => $val['width'],
                    'type' => isset($val['fieldType']) && !empty($val['fieldType']) ? $val['fieldType'] : 'singleline',
                ];
            }
        } else {
            $columns = [
                'col0' => [
                    'heading' => '',
                    'fieldType' => '',
                    'align' => '',
                    'width' => '',
                    'type' => 'singleline',
                ],
            ];
        }

        // Get rows from db or fall back to default
        if (!empty($value['rows'])) {
            // Walk down the rows and cells appending 'row' to the rows' keys and 'col' to the cells' keys
            foreach ($value['rows'] as $rowKey => $rowVal) {
                foreach ($rowVal as $colKey => $colVal) {
                    if ($isRedactorInstalled && $columns['col'.$colKey]['type'] == 'html') {
                        $skeleton = Craft::$app->fields->createField([
                            'type'           => 'craft\redactor\Field',
                            'handle'         => $this->handle.'[rows][row'.$rowKey.'][col'.$colKey.']',
                            'name'           => $this->handle.'[rows][row'.$rowKey.'][col'.$colKey.']',
                            'redactorConfig' => $plugin->redactor->getRedactorConfigFilename(),
                        ]);

                        $colVal = $skeleton->getInputHtml($colVal, $element);
                    }

                    $rows['row' . $rowKey]['col' . $colKey] = $colVal;
                }
            }
        } else {
            $rows = ['row0' => []];
        }

        $redactorConfig = [];

        $fieldTypeOptions = [
            'singleline' => Craft::t('tablemaker', 'Text'),
            'checkbox' => Craft::t('tablemaker', 'Lichtschalter'),
        ];

        if ($isRedactorInstalled) {
            $fieldTypeOptions['html'] = Craft::t('tablemaker', 'Wysiwyg');
            $redactorConfig = $plugin->redactor->getRedactorConfig($element);
        }

        $columnSettings = [
            'heading' => [
                'heading' => Craft::t('tablemaker', 'Heading'),
                'type' => 'singleline',
            ],
            'fieldType' => [
                'heading' => Craft::t('tablemaker', 'Field type'),
                'class'   => 'thin',
                'type'    => 'select',
                'options' => $fieldTypeOptions
            ],
            'width' => [
                'heading' => Craft::t('tablemaker', 'Width'),
                'class' => 'code',
                'type' => 'singleline',
                'width' => 50,
            ],
            'align' => [
                'heading' => Craft::t('tablemaker', 'Alignment'),
                'class' => 'thin',
                'type' => 'select',
                'options' => [
                    'left' => Craft::t('tablemaker', 'Left'),
                    'center' => Craft::t('tablemaker', 'Center'),
                    'right' => Craft::t('tablemaker', 'Right'),
                ],
            ],
        ];

        $view->registerJs('new Craft.TableMaker(' .
            Json::encode($view->namespaceInputId($name), JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($view->namespaceInputId($columnsInputId), JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($view->namespaceInputId($rowsInputId), JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($view->namespaceInputName($columnsInput), JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($view->namespaceInputName($rowsInput), JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($columns, JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($rows, JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($columnSettings, JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($redactorConfig, JSON_UNESCAPED_UNICODE) .
            ');');

        $fieldSettings = $this->getSettings();
        $columnsField = Cp::editableTableFieldHtml([
            'label' => $fieldSettings['columnsLabel'] ? Craft::t('tablemaker', $fieldSettings['columnsLabel']) : Craft::t('tablemaker', 'Table Columns'),
            'instructions' => $fieldSettings['columnsInstructions'] ? Craft::t('tablemaker', $fieldSettings['columnsInstructions']) : Craft::t('tablemaker', 'Define the columns your table should have.'),
            'id' => $columnsInputId,
            'name' => $columnsInput,
            'cols' => $columnSettings,
            'rows' => $columns,
            'static' => false,
            'allowAdd' => true,
            'allowDelete' => true,
            'allowReorder' => true,
            'addRowLabel' => $fieldSettings['columnsAddRowLabel'] ? Craft::t('tablemaker', $fieldSettings['columnsAddRowLabel']) : Craft::t('tablemaker', 'Add a column'),
            'initJs' => false,
        ]);

        $rowsField = Cp::editableTableFieldHtml([
            'label' => $fieldSettings['rowsLabel'] ? Craft::t('tablemaker', $fieldSettings['rowsLabel']) : Craft::t('tablemaker', 'Table Content'),
            'instructions' => $fieldSettings['rowsInstructions'] ? Craft::t('tablemaker', $fieldSettings['rowsInstructions']) : Craft::t('tablemaker', 'Input the content of your table.'),
            'id' => $rowsInputId,
            'name' => $rowsInput,
            'cols' => $columns,
            'rows' => $rows,
            'static' => false,
            'allowAdd' => true,
            'allowDelete' => true,
            'allowReorder' => true,
            'addRowLabel' => $fieldSettings['rowsAddRowLabel'] ? Craft::t('tablemaker', $fieldSettings['rowsAddRowLabel']) : Craft::t('tablemaker', 'Add a row'),
            'initJs' => false,
        ]);

        return $input . $columnsField . $rowsField;
    }

    public function getContentGqlType(): Type|array
    {
        $typeName = $this->handle . '_TableMakerField';
        $columnTypeName = $typeName . '_column';

        $columnType = GqlEntityRegistry::getEntity($typeName) ?: GqlEntityRegistry::createEntity($columnTypeName, new ObjectType([
            'name' => $columnTypeName,
            'fields' => [
                'heading' => Type::string(),
                'width' => Type::string(),
                'align' => Type::string(),
            ],
        ]));

        $tableMakerType = GqlEntityRegistry::getEntity($typeName) ?: GqlEntityRegistry::createEntity($typeName, new ObjectType([
            'name' => $typeName,
            'fields' => [
                'rows' => [
                    'type' => Type::listOf(Type::listOf(Type::string())),
                    'resolve' => function ($source) {
                        // Extra help here for an empty field.
                        // TODO: Refactor `normalizeValue()` properly to remove this.
                        if (!is_array($source['rows'])) {
                            $source['rows'] = [];
                        }

                        return $source['rows'];
                    }
                ],
                'columns' => [
                    'type' => Type::listOf($columnType),
                    'resolve' => function ($source) {
                        // Extra help here for an empty field.
                        // TODO: Refactor `normalizeValue()` properly to remove this.
                        if (!is_array($source['columns'])) {
                            $source['columns'] = [];
                        }

                        return $source['columns'];
                    }
                ],
                'table' => [
                    'type' => Type::string(),
                ],
            ],
        ]));

        return $tableMakerType;
    }
}
