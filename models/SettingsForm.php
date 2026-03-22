<?php

/**
 * Advanced Registration Logic
 * @copyright Copyright (c) 2026 D Cube Consulting Ltd.
 * @license All rights reserved.
 */

namespace humhub\modules\advancedregistrationlogic\models;

use humhub\modules\advancedregistrationlogic\Module;
use Yii;
use yii\base\Model;

class SettingsForm extends Model
{
    public bool $enableMultiStep = true;
    public string $fieldOrder = '';
    public string $fieldHints = '';
    public string $groupCategoryMap = '';

    public function init()
    {
        parent::init();

        /** @var Module $module */
        $module = Yii::$app->getModule('advancedregistrationlogic');

        $this->enableMultiStep = (bool) $module->settings->get('enableMultiStep', true);
        $this->fieldOrder = (string) $module->settings->get('fieldOrder', '');
        $this->fieldHints = (string) $module->settings->get('fieldHints', '');
        $this->groupCategoryMap = (string) $module->settings->get('groupCategoryMap', '');
    }

    public function rules()
    {
        return [
            [['fieldOrder', 'fieldHints', 'groupCategoryMap'], 'string'],
            [['enableMultiStep'], 'boolean'],
        ];
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }

        /** @var Module $module */
        $module = Yii::$app->getModule('advancedregistrationlogic');

        $module->settings->set('enableMultiStep', (bool) $this->enableMultiStep);
        $module->settings->set('fieldOrder', trim($this->fieldOrder));
        $module->settings->set('fieldHints', trim($this->fieldHints));
        $module->settings->set('groupCategoryMap', trim($this->groupCategoryMap));

        return true;
    }
}
