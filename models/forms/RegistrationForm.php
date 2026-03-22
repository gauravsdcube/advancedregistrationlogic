<?php

/**
 * Advanced Registration Logic
 * @copyright Copyright (c) 2026 D Cube Consulting Ltd.
 * @license All rights reserved.
 */

namespace humhub\modules\advancedregistrationlogic\models\forms;

use humhub\modules\advancedregistrationlogic\Module;
use humhub\modules\user\models\Group;
use humhub\modules\user\models\Profile;
use humhub\modules\user\models\ProfileField;
use humhub\modules\user\models\ProfileFieldCategory;
use humhub\modules\user\models\forms\Registration;
use humhub\modules\user\services\AuthClientUserService;
use Yii;

class RegistrationForm extends Registration
{
    public const STEP_ACCOUNT = 1;
    public const STEP_GROUP = 2;
    public const STEP_PROFILE = 3;
    private const STEP_MAX = 3;
    private bool $enablePasswordFormFlag = true;

    public function __construct(
        $definition = [],
        $primaryModel = null,
        array $config = [],
        private readonly bool $enableEmailField = false,
        private readonly bool $enablePasswordForm = true,
        private readonly bool $enableMustChangePassword = false,
    ) {
        $this->enablePasswordFormFlag = $enablePasswordForm;
        parent::__construct($definition, $primaryModel, $config, $enableEmailField, $enablePasswordForm, $enableMustChangePassword);
    }

    protected function setFormDefinition()
    {
        parent::setFormDefinition();
        $this->applyFieldOrder($this->definition['elements']);
        $this->applyFieldHints($this->definition['elements']);
    }

    public function setFormSingle(): void
    {
        $this->setFormDefinition();
        $this->setModels();
        $this->trigger(static::EVENT_AFTER_SET_FORM);
    }

    public function setFormForStep(int $step, ?int $groupId = null): void
    {
        $this->setFormDefinitionForStep($step, $groupId);
        $this->setModels();
        $this->trigger(static::EVENT_AFTER_SET_FORM);
    }

    public static function getMaxStep(): int
    {
        return self::STEP_MAX;
    }

    protected function setFormDefinitionForStep(int $step, ?int $groupId = null): void
    {
        if (!isset($this->definition['elements']) || !is_array($this->definition['elements'])) {
            $this->definition['elements'] = [];
        }

        $this->definition['elements'] = [];

        switch ($step) {
            case self::STEP_ACCOUNT:
                $this->definition['elements']['User'] = $this->getUserFormDefinition();
                if ($this->enablePasswordFormFlag) {
                    $this->definition['elements']['Password'] = $this->getPasswordFormDefinition();
                }
                break;
            case self::STEP_GROUP:
                $this->definition['elements']['GroupUser'] = $this->getGroupFormDefinition();
                if ($groupId !== null) {
                    $this->definition['elements']['GroupUser']['elements']['group_id']['value'] = (int) $groupId;
                }
                $this->definition['elements']['Profile'] = array_merge(
                    ['type' => 'form'],
                    $this->getProfileFormDefinitionForGroup($groupId, true),
                );
                break;
            case self::STEP_PROFILE:
                $this->definition['elements']['Profile'] = array_merge(
                    ['type' => 'form'],
                    $this->getProfileFormDefinitionForGroup($groupId, false),
                );
                break;
            default:
                parent::setFormDefinition();
                break;
        }

        $this->applyFieldOrder($this->definition['elements']);
        $this->applyFieldHints($this->definition['elements']);
        $this->definition['buttons'] = $this->getButtonsForStep($step);
    }

    private function getButtonsForStep(int $step): array
    {
        $buttons = [];

        if ($step > self::STEP_ACCOUNT) {
            $buttons['back'] = [
                'type' => 'submit',
                'class' => 'btn btn-default',
                'label' => Yii::t('UserModule.auth', 'Back'),
            ];
        }

        $buttons['save'] = [
            'type' => 'submit',
            'class' => 'btn btn-primary',
            'label' => $step === self::STEP_PROFILE
                ? Yii::t('UserModule.auth', 'Create account')
                : Yii::t('UserModule.auth', 'Next'),
        ];

        return $buttons;
    }

    public function getFieldNamesForModel(string $modelKey): array
    {
        $elements = $this->definition['elements'][$modelKey]['elements'] ?? [];
        return $this->collectFieldNames($elements);
    }

    private function collectFieldNames(array $elements): array
    {
        $fieldNames = [];

        foreach ($elements as $name => $element) {
            if (isset($element['type']) && $element['type'] === 'form' && isset($element['elements'])) {
                $fieldNames = array_merge($fieldNames, $this->collectFieldNames($element['elements']));
                continue;
            }
            $fieldNames[] = $name;
        }

        return array_values(array_unique($fieldNames));
    }

    private function getProfileFormDefinitionForGroup(?int $groupId, bool $onlyGroupSpecific): array
    {
        $categoryIds = $this->getProfileCategoryIdsForGroup($groupId);

        if ($onlyGroupSpecific) {
            return $this->getProfileFormDefinitionByCategoryIds($categoryIds, false);
        }

        if (empty($categoryIds)) {
            return $this->getProfileFormDefinitionByCategoryIds(null, false);
        }

        return $this->getProfileFormDefinitionByCategoryIds($categoryIds, true);
    }

    private function getProfileCategoryIdsForGroup(?int $groupId): array
    {
        if ($groupId === null) {
            return [];
        }

        $group = Group::findOne(['id' => $groupId]);
        if ($group === null) {
            return [];
        }

        $map = $this->getGroupCategoryMap();
        $categoryTitle = $map[$group->name] ?? $group->name;

        $category = ProfileFieldCategory::findOne(['title' => $categoryTitle]);
        if ($category === null) {
            return [];
        }

        return [$category->id];
    }

    private function getProfileFormDefinitionByCategoryIds(?array $categoryIds, bool $exclude): array
    {
        $definition = [];
        $definition['elements'] = [];

        $profile = $this->getProfile();
        $syncAttributes = [];
        if ($profile->user !== null) {
            $syncAttributes = (new AuthClientUserService($profile->user))->getSyncAttributes();
        }

        $safeAttributes = $profile->safeAttributes();

        foreach (ProfileFieldCategory::find()->orderBy('sort_order')->all() as $profileFieldCategory) {
            if (is_array($categoryIds)) {
                $inFilter = in_array($profileFieldCategory->id, $categoryIds, true);
                if ($exclude && $inFilter) {
                    continue;
                }
                if (!$exclude && !$inFilter) {
                    continue;
                }
            }

            $category = [
                'type' => 'form',
                'title' => Yii::t($profileFieldCategory->getTranslationCategory(), $profileFieldCategory->title),
                'elements' => [],
            ];

            foreach (
                ProfileField::find()->orderBy('sort_order')
                    ->where(['profile_field_category_id' => $profileFieldCategory->id])->all() as $profileField
            ) {
                /** @var ProfileField $profileField */
                $profileField->editable = true;

                if ($profileField->fieldType->isVirtual) {
                    continue;
                }

                if (!in_array($profileField->internal_name, $safeAttributes, true)) {
                    if ($profileField->visible && $profile->scenario !== Profile::SCENARIO_REGISTRATION) {
                        $profileField->editable = false;
                    } else {
                        continue;
                    }
                }

                if (in_array($profileField->internal_name, $syncAttributes, true)) {
                    $profileField->editable = false;
                }

                $fieldDefinition = $profileField->fieldType->getFieldFormDefinition($profile->user);

                if (isset($fieldDefinition[$profileField->internal_name]) && !empty($profileField->description)) {
                    $fieldDefinition[$profileField->internal_name]['hint'] = Yii::t(
                        $profileField->getTranslationCategory() ?: $profileFieldCategory->getTranslationCategory(),
                        $profileField->description,
                    );
                }

                $category['elements'] = array_merge($category['elements'], $fieldDefinition);

                $profileField->fieldType->loadDefaults($profile);
            }

            if (!empty($category['elements'])) {
                $definition['elements']['category_' . $profileFieldCategory->id] = $category;
            }
        }

        return $definition;
    }

    private function applyFieldOrder(array &$elements): void
    {
        $order = $this->getFieldOrder();

        if (isset($elements['User']['elements'])) {
            $elements['User']['elements'] = $this->sortElementsByOrder(
                $elements['User']['elements'],
                $order,
                'User.',
            );
        }

        if (isset($elements['Password']['elements'])) {
            $elements['Password']['elements'] = $this->sortElementsByOrder(
                $elements['Password']['elements'],
                $order,
                'Password.',
            );
        }

        if (isset($elements['GroupUser']['elements'])) {
            $elements['GroupUser']['elements'] = $this->sortElementsByOrder(
                $elements['GroupUser']['elements'],
                $order,
                'GroupUser.',
            );
        }

        if (isset($elements['Profile']['elements'])) {
            foreach ($elements['Profile']['elements'] as $categoryKey => $category) {
                if (!isset($category['elements'])) {
                    continue;
                }
                $elements['Profile']['elements'][$categoryKey]['elements'] = $this->sortElementsByOrder(
                    $category['elements'],
                    $order,
                    'Profile.',
                );
            }
        }
    }

    private function sortElementsByOrder(array $elements, array $order, string $prefix): array
    {
        $ordered = [];
        $remaining = $elements;

        foreach ($order as $key => $position) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $fieldKey = substr($key, strlen($prefix));
            if (array_key_exists($fieldKey, $remaining)) {
                $ordered[$fieldKey] = $remaining[$fieldKey];
                unset($remaining[$fieldKey]);
            }
        }

        foreach ($remaining as $fieldKey => $definition) {
            $ordered[$fieldKey] = $definition;
        }

        return $ordered;
    }

    private function applyFieldHints(array &$elements): void
    {
        $hints = $this->getFieldHints();

        foreach (['User', 'Password', 'GroupUser'] as $modelKey) {
            if (!isset($elements[$modelKey]['elements'])) {
                continue;
            }
            foreach ($elements[$modelKey]['elements'] as $fieldKey => &$definition) {
                $hintKey = $modelKey . '.' . $fieldKey;
                if (!empty($hints[$hintKey])) {
                    $definition['hint'] = $hints[$hintKey];
                }
            }
            unset($definition);
        }

        if (isset($elements['Profile']['elements'])) {
            foreach ($elements['Profile']['elements'] as $categoryKey => &$category) {
                if (!isset($category['elements'])) {
                    continue;
                }
                foreach ($category['elements'] as $fieldKey => &$definition) {
                    $hintKey = 'Profile.' . $fieldKey;
                    if (!empty($hints[$hintKey])) {
                        $definition['hint'] = $hints[$hintKey];
                    }
                }
                unset($definition);
            }
            unset($category);
        }
    }

    private function getFieldOrder(): array
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('advancedregistrationlogic');
        $raw = (string) $module->settings->get('fieldOrder', '');

        $order = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $index = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $order[$line] = $index;
            $index++;
        }

        return $order;
    }

    private function getFieldHints(): array
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('advancedregistrationlogic');
        $raw = (string) $module->settings->get('fieldHints', '');

        $hints = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('|', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || $value === '') {
                continue;
            }
            $hints[$key] = $value;
        }

        return $hints;
    }

    private function getGroupCategoryMap(): array
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('advancedregistrationlogic');
        $raw = (string) $module->settings->get('groupCategoryMap', '');

        $map = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$group, $category] = array_pad(explode('|', $line, 2), 2, '');
            $group = trim($group);
            $category = trim($category);
            if ($group === '' || $category === '') {
                continue;
            }
            $map[$group] = $category;
        }

        return $map;
    }
}
