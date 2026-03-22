<?php

/**
 * Advanced Registration Logic
 * @copyright Copyright (c) 2026 D Cube Consulting Ltd.
 * @license All rights reserved.
 */

use humhub\components\Controller;
use humhub\modules\advancedregistrationlogic\Events;

return [
    'id' => 'advancedregistrationlogic',
    'class' => humhub\modules\advancedregistrationlogic\Module::class,
    'namespace' => 'humhub\modules\advancedregistrationlogic',
    'events' => [
        [
            'class' => Controller::class,
            'event' => Controller::EVENT_BEFORE_ACTION,
            'callback' => [Events::class, 'onBeforeControllerAction'],
        ],
    ],
];
