<?php

/**
 * Advanced Registration Logic
 * @copyright Copyright (c) 2026 D Cube Consulting Ltd.
 * @license All rights reserved.
 */

namespace humhub\modules\advancedregistrationlogic;

use humhub\modules\user\controllers\RegistrationController as CoreRegistrationController;
use Yii;
use yii\base\ActionEvent;
use yii\helpers\Url;

class Events
{
    public static function onBeforeControllerAction(ActionEvent $event): void
    {
        $controller = $event->action->controller;

        if (!$controller instanceof CoreRegistrationController) {
            return;
        }

        if ($event->action->id !== 'index') {
            return;
        }

        $token = Yii::$app->request->get('token');
        $url = Url::to(array_filter([
            '/advancedregistrationlogic/registration/index',
            'token' => $token,
        ]));

        $event->isValid = false;
        Yii::$app->response->redirect($url)->send();
    }
}
