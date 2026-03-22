<?php

/**
 * Advanced Registration Logic
 * @copyright Copyright (c) 2026 D Cube Consulting Ltd.
 * @license All rights reserved.
 */

namespace humhub\modules\advancedregistrationlogic\controllers;

use humhub\modules\admin\components\Controller;
use humhub\modules\advancedregistrationlogic\models\SettingsForm;
use Yii;

class ConfigController extends Controller
{
    public function actionIndex()
    {
        $form = new SettingsForm();

        if ($form->load(Yii::$app->request->post()) && $form->save()) {
            $this->view->saved();
            return $this->redirect(['/advancedregistrationlogic/config']);
        }

        return $this->render('index', ['model' => $form]);
    }
}
