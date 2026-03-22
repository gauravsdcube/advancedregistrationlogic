<?php

/**
 * Advanced Registration Logic
 * @copyright Copyright (c) 2026 D Cube Consulting Ltd.
 * @license All rights reserved.
 */

use humhub\widgets\form\ActiveForm;
use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * @var $model \humhub\modules\advancedregistrationlogic\models\SettingsForm
 */
?>

<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('AdvancedregistrationlogicModule.base', 'Advanced Registration Logic') ?>
    </div>
    <div class="panel-body">
        <p><?= Yii::t('AdvancedregistrationlogicModule.base', 'Configure field order, descriptors, and group-to-category mapping for registration steps.') ?></p>
        <br/>

        <?php $form = ActiveForm::begin(); ?>

        <div class="mb-3">
            <?= $form->field($model, 'enableMultiStep')->checkbox()->hint(Yii::t(
                'AdvancedregistrationlogicModule.base',
                'Enable multi-step registration flow. When disabled, the default single-step form is used.',
            )) ?>
        </div>

        <div class="mb-3">
            <?= $form->field($model, 'fieldOrder')->textarea([
                'rows' => 8,
                'placeholder' => "User.username\nPassword.newPassword\nPassword.newPasswordConfirm\nGroupUser.group_id\nProfile.firstname",
            ])->hint(Yii::t(
                'AdvancedregistrationlogicModule.base',
                'One field per line. Use Model.field format (e.g. User.username, Password.newPassword, GroupUser.group_id, Profile.firstname).',
            )) ?>
        </div>

        <div class="mb-3">
            <?= $form->field($model, 'fieldHints')->textarea([
                'rows' => 8,
                'placeholder' => "GroupUser.group_id|Please select appropriate group...\nPassword.newPassword|Use at least 8 characters",
            ])->hint(Yii::t(
                'AdvancedregistrationlogicModule.base',
                'One per line: FieldKey|Description. FieldKey uses Model.field (e.g. GroupUser.group_id|... ).',
            )) ?>
        </div>

        <div class="mb-3">
            <?= $form->field($model, 'groupCategoryMap')->textarea([
                'rows' => 6,
                'placeholder' => "PPI Members|PPI Members\nProfessional Members|Professional Members",
            ])->hint(Yii::t(
                'AdvancedregistrationlogicModule.base',
                'One per line: Group Name|Profile Field Category Name. If empty, group name is used as category name.',
            )) ?>
        </div>

        <hr>
        <?= Button::save()->submit() ?>
        <?= Button::light(Yii::t('AdvancedregistrationlogicModule.base', 'Back to modules'))
            ->link(Url::to(['/admin/module']))
            ->cssClass('float-end') ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
