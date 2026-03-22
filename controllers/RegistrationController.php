<?php

/**
 * Advanced Registration Logic
 * @copyright Copyright (c) 2026 D Cube Consulting Ltd.
 * @license All rights reserved.
 */

namespace humhub\modules\advancedregistrationlogic\controllers;

use humhub\components\access\ControllerAccess;
use humhub\components\Controller;
use humhub\modules\advancedregistrationlogic\models\forms\RegistrationForm;
use humhub\modules\space\models\Space;
use humhub\modules\user\authclient\interfaces\ApprovalBypass;
use humhub\modules\user\models\Invite;
use humhub\modules\user\models\User;
use humhub\modules\user\services\InviteRegistrationService;
use humhub\modules\user\services\LinkRegistrationService;
use Throwable;
use Yii;
use yii\authclient\BaseClient;
use yii\authclient\ClientInterface;
use yii\base\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;

class RegistrationController extends Controller
{
    private const REGISTRATION_SESSION_KEY = 'advancedregistrationlogic.registration';
    private const REGISTRATION_TOKEN_KEY = 'advancedregistrationlogic.token';

    public $layout = "@humhub/modules/user/views/layouts/main";

    public $access = ControllerAccess::class;

    public function actionIndex()
    {
        $registration = new RegistrationForm();

        /**
         * @var BaseClient|null
         */
        $authClient = null;

        $token = Yii::$app->request->get('token')
            ?? Yii::$app->request->post('token')
            ?? Yii::$app->session->get(self::REGISTRATION_TOKEN_KEY);

        if ($token) {
            Yii::$app->session->set(self::REGISTRATION_TOKEN_KEY, $token);
            $inviteRegistrationService = new InviteRegistrationService($token);
            if (!$inviteRegistrationService->isValid()) {
                throw new HttpException(404, 'Invalid registration token!');
            }
            $inviteRegistrationService->populateRegistration($registration);
        } elseif (Yii::$app->session->has('authClient')) {
            $authClient = Yii::$app->session->get('authClient');
            $registration = $this->createRegistrationByAuthClient($authClient);
        } else {
            Yii::warning('Registration failed: No token (query) or authclient (session) found!', 'user');
            Yii::$app->session->setFlash('error', 'Registration failed.');
            return $this->redirect(['/user/auth/login']);
        }

        $module = Yii::$app->getModule('advancedregistrationlogic');
        $multiStepEnabled = (bool) $module->settings->get('enableMultiStep', true);
        $userModule = Yii::$app->getModule('user');

        if (!$multiStepEnabled) {
            $registration->setFormSingle();

            if ($registration->submitted('save') && $registration->register($authClient)) {
                Yii::$app->session->remove(self::REGISTRATION_TOKEN_KEY);
                Yii::$app->session->remove('authClient');

                if ($registration->getUser()->status === User::STATUS_ENABLED) {
                    $registration->getUser()->refresh();
                    Yii::$app->user->login($registration->getUser());
                    if (Yii::$app->request->getIsAjax()) {
                        return $this->htmlRedirect(Yii::$app->user->returnUrl);
                    }
                    return $this->redirect(Yii::$app->user->returnUrl);
                }

                return $this->render('@user/views/registration/success', [
                    'form' => $registration,
                    'needApproval' => ($registration->getUser()->status === User::STATUS_NEED_APPROVAL),
                ]);
            }

            return $this->render('index', [
                'hForm' => $registration,
                'showRegistrationForm' => $userModule->showRegistrationForm ?? true,
                'hasAuthClient' => $authClient !== null,
                'step' => 1,
                'maxStep' => 1,
                'token' => $token,
                'showStep' => false,
            ]);
        }

        $step = (int) Yii::$app->request->post('step', Yii::$app->request->get('step', RegistrationForm::STEP_ACCOUNT));
        $step = max(RegistrationForm::STEP_ACCOUNT, min($step, RegistrationForm::getMaxStep()));

        $state = $this->getRegistrationState();
        $firstIncompleteStep = $this->getFirstIncompleteStep($state);
        if ($step > $firstIncompleteStep) {
            return $this->redirect(array_filter([
                'index',
                'step' => $firstIncompleteStep,
                'token' => $token,
            ]));
        }

        $postedGroupId = Yii::$app->request->post('GroupUser')['group_id'] ?? null;
        if ($postedGroupId !== null && $postedGroupId !== '') {
            $postedGroupId = (int) $postedGroupId;
            $stateGroupId = $state['data']['GroupUser']['group_id'] ?? null;
            if ($stateGroupId === null || (int) $stateGroupId !== $postedGroupId) {
                $state['completed'][RegistrationForm::STEP_GROUP] = false;
                $state['completed'][RegistrationForm::STEP_PROFILE] = false;
                $state['data']['Profile'] = [];
            }
            $state['data']['GroupUser']['group_id'] = $postedGroupId;
            $this->saveRegistrationState($state);
        }

        $queryGroupId = Yii::$app->request->get('groupId');
        if ($queryGroupId !== null && $queryGroupId !== '') {
            $queryGroupId = (int) $queryGroupId;
            $stateGroupId = $state['data']['GroupUser']['group_id'] ?? null;
            if ($stateGroupId === null || (int) $stateGroupId !== $queryGroupId) {
                $state['completed'][RegistrationForm::STEP_GROUP] = false;
                $state['completed'][RegistrationForm::STEP_PROFILE] = false;
                $state['data']['Profile'] = [];
            }
            $state['data']['GroupUser']['group_id'] = $queryGroupId;
            $this->saveRegistrationState($state);
        }

        $groupId = $postedGroupId ?? $queryGroupId ?? $this->getGroupIdFromRequestOrState($state);
        if ($step === RegistrationForm::STEP_GROUP && ($groupId === null || $groupId === '')) {
            $defaultGroupId = $userModule?->getDefaultGroupId();
            if ($defaultGroupId) {
                $groupId = (int) $defaultGroupId;
            } else {
                $registrationGroups = \humhub\modules\user\models\Group::getRegistrationGroups();
                if (!empty($registrationGroups)) {
                    $groupId = (int) $registrationGroups[0]->id;
                }
            }
            if ($groupId) {
                $state['data']['GroupUser']['group_id'] = $groupId;
                $this->saveRegistrationState($state);
            }
        }

        $registration->setFormForStep($step, $groupId);
        $this->hydrateRegistrationFromState($registration, $state);
        if ($step === RegistrationForm::STEP_GROUP && $groupId !== null) {
            $registration->getGroupUser()->group_id = $groupId;
        }

        if ($step === RegistrationForm::STEP_GROUP && Yii::$app->request->post('step_refresh')) {
            $postedGroupId = Yii::$app->request->post('GroupUser')['group_id'] ?? null;
            $registration->setFormForStep($step, $postedGroupId ? (int) $postedGroupId : null);
            // Load posted values so changed group is captured before re-render.
            $registration->submitted();
            if ($postedGroupId !== null && $postedGroupId !== '') {
                $postedGroupId = (int) $postedGroupId;
                $registration->getGroupUser()->group_id = $postedGroupId;
            }
            $stateGroupId = $state['data']['GroupUser']['group_id'] ?? null;
            if ($postedGroupId !== null && $postedGroupId !== '' && (int) $postedGroupId !== (int) $stateGroupId) {
                // Reset completion and profile values when group changes.
                $state['completed'][RegistrationForm::STEP_GROUP] = false;
                $state['completed'][RegistrationForm::STEP_PROFILE] = false;
                $state['data']['Profile'] = [];
            }
            $this->storeStepData($registration, $state, $step);
            $this->saveRegistrationState($state);
            $registration->setFormForStep($step, $postedGroupId);
            $this->hydrateRegistrationFromState($registration, $state);
            if ($postedGroupId !== null) {
                $registration->getGroupUser()->group_id = (int) $postedGroupId;
            }
            return $this->render('index', [
                'hForm' => $registration,
                'showRegistrationForm' => $userModule->showRegistrationForm ?? true,
                'hasAuthClient' => $authClient !== null,
                'step' => $step,
                'maxStep' => RegistrationForm::getMaxStep(),
                'token' => $token,
                'showStep' => true,
            ]);
        }

        if ($registration->submitted('back')) {
            $this->storeStepData($registration, $state, $step);
            $this->saveRegistrationState($state);
            $previousStep = max(RegistrationForm::STEP_ACCOUNT, $step - 1);
            return $this->redirect(array_filter([
                'index',
                'step' => $previousStep,
                'token' => $token,
            ]));
        }

        if ($registration->submitted('save')) {
            $groupId = $this->getGroupIdFromRequestOrState($state);
            if ($this->validateRegistrationStep($registration, $step)) {
                $this->storeStepData($registration, $state, $step);
                $state['completed'][$step] = true;
                $this->saveRegistrationState($state);

                if ($step < RegistrationForm::STEP_PROFILE) {
                    return $this->redirect(array_filter([
                        'index',
                        'step' => $step + 1,
                        'token' => $token,
                    ]));
                }

                $this->hydrateRegistrationFromState($registration, $state);
                if ($registration->register($authClient)) {
                    Yii::$app->session->remove(self::REGISTRATION_SESSION_KEY);
                    Yii::$app->session->remove(self::REGISTRATION_TOKEN_KEY);
                    Yii::$app->session->remove('authClient');

                    if ($registration->getUser()->status === User::STATUS_ENABLED) {
                        $registration->getUser()->refresh();
                        Yii::$app->user->login($registration->getUser());
                        if (Yii::$app->request->getIsAjax()) {
                            return $this->htmlRedirect(Yii::$app->user->returnUrl);
                        }
                        return $this->redirect(Yii::$app->user->returnUrl);
                    }

                    return $this->render('@user/views/registration/success', [
                        'form' => $registration,
                        'needApproval' => ($registration->getUser()->status === User::STATUS_NEED_APPROVAL),
                    ]);
                }
            }
        }

        if ($step === RegistrationForm::STEP_GROUP) {
            $registration->setFormForStep($step, $groupId);
        }

        return $this->render('index', [
            'hForm' => $registration,
            'showRegistrationForm' => $userModule->showRegistrationForm ?? true,
            'hasAuthClient' => $authClient !== null,
            'step' => $step,
            'maxStep' => RegistrationForm::getMaxStep(),
            'token' => $token,
            'showStep' => true,
        ]);
    }

    public function actionByLink(?string $token = null, $spaceId = null)
    {
        $linkRegistrationService = new LinkRegistrationService($token, Space::findOne(['id' => (int) $spaceId]));

        if (!$linkRegistrationService->isEnabled()) {
            throw new ForbiddenHttpException('Registration is disabled!');
        }

        if ($token === null || !$linkRegistrationService->isValid()) {
            throw new BadRequestHttpException('Invalid token provided!');
        }

        $linkRegistrationService->storeInSession();

        $form = new Invite([
            'source' => Invite::SOURCE_INVITE_BY_LINK,
            'scenario' => Invite::SCENARIO_INVITE_BY_LINK_FORM,
        ]);
        if ($form->load(Yii::$app->request->post()) && $form->validate()) {
            if ($invite = $linkRegistrationService->convertToInvite($form->email)) {
                $invite->sendInviteMail();
            } else {
                $invite = new Invite(['email' => $form->email]);
            }
            return $this->render('@user/views/auth/register_success', ['model' => $invite]);
        }

        $userModule = Yii::$app->getModule('user');
        return $this->render('@user/views/registration/byLink', [
            'invite' => $form,
            'showRegistrationForm' => $userModule->showRegistrationForm ?? true,
            'showAuthClients' => true,
        ]);
    }

    protected function createRegistrationByAuthClient(ClientInterface $authClient): RegistrationForm
    {
        $attributes = $authClient->getUserAttributes();

        if (!isset($attributes['id'])) {
            throw new Exception("No user id given by authclient!");
        }

        $registration = new RegistrationForm(enablePasswordForm: false);

        if ($authClient instanceof ApprovalBypass) {
            $registration->enableUserApproval = false;
        }

        unset($attributes['id']);

        $registration->getUser()->setAttributes($attributes, false);
        $registration->getProfile()->setAttributes($attributes, false);

        return $registration;
    }

    private function getRegistrationState(): array
    {
        return Yii::$app->session->get(self::REGISTRATION_SESSION_KEY, [
            'data' => [],
            'completed' => [],
        ]);
    }

    private function saveRegistrationState(array $state): void
    {
        Yii::$app->session->set(self::REGISTRATION_SESSION_KEY, $state);
    }

    private function getFirstIncompleteStep(array $state): int
    {
        foreach ([RegistrationForm::STEP_ACCOUNT, RegistrationForm::STEP_GROUP, RegistrationForm::STEP_PROFILE] as $step) {
            if (empty($state['completed'][$step])) {
                return $step;
            }
        }

        return RegistrationForm::STEP_PROFILE;
    }

    private function getGroupIdFromRequestOrState(array $state): ?int
    {
        $postGroupId = Yii::$app->request->post('GroupUser')['group_id'] ?? null;
        if ($postGroupId !== null && $postGroupId !== '') {
            return (int) $postGroupId;
        }

        $stateGroupId = $state['data']['GroupUser']['group_id'] ?? null;
        if ($stateGroupId !== null && $stateGroupId !== '') {
            return (int) $stateGroupId;
        }

        return null;
    }

    private function hydrateRegistrationFromState(RegistrationForm $registration, array $state): void
    {
        $userData = $state['data']['User'] ?? [];
        $passwordData = $state['data']['Password'] ?? [];
        $groupData = $state['data']['GroupUser'] ?? [];
        $profileData = $state['data']['Profile'] ?? [];

        $registration->getUser()->setAttributes($userData, false);
        foreach ($passwordData as $attribute => $value) {
            $registration->getPassword()->$attribute = $value;
        }
        $registration->getGroupUser()->setAttributes($groupData, false);
        $registration->getProfile()->setAttributes($profileData, false);
    }

    private function storeStepData(RegistrationForm $registration, array &$state, int $step): void
    {
        switch ($step) {
            case RegistrationForm::STEP_ACCOUNT:
                $state['data']['User'] = array_merge(
                    $state['data']['User'] ?? [],
                    $this->extractModelAttributes(
                        $registration->getUser(),
                        $registration->getFieldNamesForModel('User'),
                    ),
                );
                if ($registration->getPassword() !== null) {
                    $state['data']['Password'] = array_merge(
                        $state['data']['Password'] ?? [],
                        $this->extractModelAttributes(
                            $registration->getPassword(),
                            $registration->getFieldNamesForModel('Password'),
                        ),
                    );
                }
                break;
            case RegistrationForm::STEP_GROUP:
                $state['data']['GroupUser'] = array_merge(
                    $state['data']['GroupUser'] ?? [],
                    $this->extractModelAttributes(
                        $registration->getGroupUser(),
                        $registration->getFieldNamesForModel('GroupUser'),
                    ),
                );
                $state['data']['Profile'] = array_merge(
                    $state['data']['Profile'] ?? [],
                    $this->extractModelAttributes(
                        $registration->getProfile(),
                        $registration->getFieldNamesForModel('Profile'),
                    ),
                );
                break;
            case RegistrationForm::STEP_PROFILE:
                $state['data']['Profile'] = array_merge(
                    $state['data']['Profile'] ?? [],
                    $this->extractModelAttributes(
                        $registration->getProfile(),
                        $registration->getFieldNamesForModel('Profile'),
                    ),
                );
                break;
        }
    }

    private function validateRegistrationStep(RegistrationForm $registration, int $step): bool
    {
        $isValid = true;

        if ($step === RegistrationForm::STEP_ACCOUNT) {
            $userFields = $registration->getFieldNamesForModel('User');
            if (!$registration->getUser()->validate($userFields)) {
                $isValid = false;
            }

            if ($registration->getPassword() !== null) {
                $passwordFields = $registration->getFieldNamesForModel('Password');
                if (!$registration->getPassword()->validate($passwordFields)) {
                    $isValid = false;
                }
            }

            return $isValid;
        }

        if ($step === RegistrationForm::STEP_GROUP) {
            $groupFields = $registration->getFieldNamesForModel('GroupUser');
            if (!$registration->getGroupUser()->validate($groupFields)) {
                $isValid = false;
            }

            $profileFields = $registration->getFieldNamesForModel('Profile');
            if (!empty($profileFields) && !$registration->getProfile()->validate($profileFields)) {
                $isValid = false;
            }

            return $isValid;
        }

        if ($step === RegistrationForm::STEP_PROFILE) {
            $profileFields = $registration->getFieldNamesForModel('Profile');
            if (!empty($profileFields) && !$registration->getProfile()->validate($profileFields)) {
                $isValid = false;
            }
        }

        return $isValid;
    }

    private function extractModelAttributes($model, array $fields): array
    {
        $data = [];

        foreach ($fields as $field) {
            $data[$field] = $model->$field ?? null;
        }

        return $data;
    }
}
