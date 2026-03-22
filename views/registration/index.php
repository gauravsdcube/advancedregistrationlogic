<?php

/**
 * Advanced Registration Logic
 * @copyright Copyright (c) 2026 D Cube Consulting Ltd.
 * @license All rights reserved.
 */

use humhub\helpers\Html;
use humhub\modules\advancedregistrationlogic\models\forms\RegistrationForm;
use humhub\modules\user\widgets\AuthChoice;
use humhub\widgets\form\ActiveForm;
use humhub\widgets\LanguageChooser;
use humhub\widgets\SiteLogo;

/**
 * @var $hForm RegistrationForm
 * @var $hasAuthClient bool
 * @var $showRegistrationForm bool
 * @var $step int
 * @var $maxStep int
 * @var $token string|null
 * @var $showStep bool
 */

$this->pageTitle = Yii::t('UserModule.auth', 'Create Account');
$step ??= RegistrationForm::STEP_ACCOUNT;
$maxStep ??= RegistrationForm::getMaxStep();
$token ??= null;
$showStep ??= true;

$formErrors = [];
foreach ($hForm->models as $model) {
    foreach ($model->getErrors() as $messages) {
        foreach ($messages as $message) {
            $formErrors[] = $message;
        }
    }
}
?>

<div id="user-registration" class="container<?= AuthChoice::getClientsCount() > 1 ? ' has-multiple-auth-buttons' : '' ?>">
    <?= SiteLogo::widget(['place' => SiteLogo::PLACE_LOGIN]) ?>
    <br/>
    <div id="create-account-form" class="panel panel-default animated bounceIn"
         data-has-auth-client="<?= $hasAuthClient ? '1' : '0' ?>">
        <div class="panel-heading">
            <?= Yii::t('UserModule.auth', '<strong>Account</strong> registration') ?>
        </div>
        <div class="panel-body">
            <?php if (!$hasAuthClient && AuthChoice::hasClients()): ?>
                <?= AuthChoice::widget(['showOrDivider' => $showRegistrationForm]) ?>
            <?php endif; ?>

            <?php if (!empty($formErrors)) : ?>
                <div class="alert alert-danger">
                    <?= Html::ul($formErrors, ['encode' => true]) ?>
                </div>
            <?php endif; ?>

            <?php if ($showStep) : ?>
                <div class="text-muted" style="margin-bottom:10px;" id="arl-step-indicator">
                    <?= Yii::t('UserModule.auth', 'Step {current} of {total}', [
                        'current' => $step,
                        'total' => $maxStep,
                    ]) ?>
                </div>
            <?php endif; ?>

            <?php if ($showRegistrationForm): ?>
                <?php $form = ActiveForm::begin(['id' => 'registration-form', 'enableClientValidation' => false]); ?>
                <?= Html::hiddenInput('ChooseLanguage[language]', Yii::$app->language) ?>
                <?= Html::hiddenInput('step', $step) ?>
                <?php if (!empty($token)) : ?>
                    <?= Html::hiddenInput('token', $token) ?>
                <?php endif; ?>
                <?php if ($step === RegistrationForm::STEP_GROUP): ?>
                    <?= Html::hiddenInput('step_refresh', '0') ?>
                <?php endif; ?>
                <?= $hForm->render($form); ?>
                <?php ActiveForm::end(); ?>
            <?php endif; ?>
        </div>
    </div>

    <?= LanguageChooser::widget(['vertical' => true, 'hideLabel' => true]) ?>
</div>

<style>
    #arl-step-indicator {
        font-size: 18px !important;
        font-weight: 600 !important;
    }
</style>

<script <?= Html::nonce() ?>>
    $(function () {
        // set cursor to login field
        $('#User_username').focus();

        // set user time zone val
        $('#user-time_zone').val(Intl.DateTimeFormat().resolvedOptions().timeZone);

        <?php if ($step === RegistrationForm::STEP_GROUP): ?>
        $('#GroupUser_group_id').on('change', function () {
            $('#registration-form input[name="step_refresh"]').val('1');
            $('#registration-form').submit();
        });
        <?php endif; ?>
    })

    // Shake panel after wrong validation
    <?php foreach ($hForm->models as $model) : ?>
    <?php if ($model->hasErrors()) : ?>
    $('#create-account-form').removeClass('bounceIn');
    $('#create-account-form').addClass('shake');
    $('#app-title').removeClass('fadeIn');
    <?php endif; ?>
    <?php endforeach; ?>

</script>
