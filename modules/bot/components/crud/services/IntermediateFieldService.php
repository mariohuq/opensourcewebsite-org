<?php

namespace app\modules\bot\components\crud\services;

use app\modules\bot\components\Controller;
use app\modules\bot\models\UserState;

/**
 * Class IntermediateFieldService
 *
 * @package app\modules\bot\components\crud\services
 */
class IntermediateFieldService
{
    const SAFE_ATTRIBUTE = 'safeAttribute';
    const SAFE_ATTRIBUTE_FLAG = 'safeAttributeFlag';

    /** @var Controller */
    public $controller;
    /** @var UserState */
    public $state;

    /**
     * @param string|array $attributeName
     * @param $value
     */
    public function set($attributeName, $value = '')
    {
        if (is_array($attributeName)) {
            $this->state->setIntermediateFields($attributeName);
        } else {
            $this->state->setIntermediateField($attributeName, $value);
        }
    }

    /**
     * @param string $attributeName
     * @param null $defaultValue
     *
     * @return mixed|null
     */
    public function get($attributeName, $defaultValue = null)
    {
        return $this->state->getIntermediateField($attributeName, $defaultValue);
    }

    public function reset()
    {
        $backRoute = $this->controller->backRoute->get();
        $endRoute = $this->controller->endRoute->get();
        $safeAttribute = $this->state->getIntermediateField(self::SAFE_ATTRIBUTE);
        $this->state->reset();
        $this->controller->backRoute->set($backRoute);
        $this->controller->endRoute->set($endRoute);
        $this->state->setIntermediateField(self::SAFE_ATTRIBUTE, $safeAttribute);
    }

    /**
     * remove flag after check
     */
    public function hasFlag()
    {
        $flag = $this->state->getIntermediateField(self::SAFE_ATTRIBUTE_FLAG, null);
        $this->state->setIntermediateField(self::SAFE_ATTRIBUTE_FLAG, null);

        return $flag;
    }

    /**
     * set flag for check in loop
     */
    public function enableFlag()
    {
        $this->state->setIntermediateField(self::SAFE_ATTRIBUTE_FLAG, true);
    }
}
