<?php

/**
 * Advanced Registration Logic
 * @copyright Copyright (c) 2026 D Cube Consulting Ltd.
 * @license All rights reserved.
 */

namespace humhub\modules\advancedregistrationlogic;

use Yii;
use yii\helpers\Url;

class Module extends \humhub\components\Module
{
    public function getName()
    {
        return Yii::t('AdvancedregistrationlogicModule.base', 'Advanced Registration Logic');
    }

    public function getDescription()
    {
        return Yii::t(
            'AdvancedregistrationlogicModule.base',
            'Multi-step registration with field ordering and descriptors.',
        );
    }

    public function getConfigUrl(): string
    {
        return Url::to(['/advancedregistrationlogic/config']);
    }

    public function getVersion()
    {
        return '1.0.0';
    }
}
