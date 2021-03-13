<?php

namespace app\modules\bot\components\crud\services;

use Yii;
use app\modules\bot\components\Controller;
use app\modules\bot\components\crud\CrudController;
use yii\base\DynamicModel;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;

/**
 * Class ModelRelationService
 *
 * @package app\modules\bot\components\crud\services
 */
class ModelRelationService
{
    /** @var Controller */
    public $controller;

    /**
     * @param $modelClass
     * @param $attributes
     *
     * @return ActiveRecord
     */
    public function fillModel($modelClass, $attributes)
    {
        if (!is_array($attributes)) {
            return null;
        }

        return new $modelClass($attributes);
    }

    /**
     * Возвращает связанные модели
     *
     * @param ActiveRecord $model
     * @param $attributeName
     *
     * @return ActiveRecord[]
     */
    public function findAll(ActiveRecord $model, $attributeName)
    {
        $relationRule = $this->getRelationRule($this->controller->getAttributeRule($attributeName));
        [$primaryRelation] = $this->getRelationAttributes($relationRule);

        return call_user_func([$relationRule['model'], 'findAll'], [$primaryRelation[0] => $model->id]);
    }

    /**
     * @param $attributeName
     * @param int|null $modelId
     *
     * @return int
     */
    public function filledRelationCount($attributeName, $modelId = null)
    {
        if (!$modelId) {
            $modelId = $this->controller->field->get(CrudController::FIELD_NAME_ID, null);
        }

        if ($modelId) {
            $model = $this->controller->getModel($modelId);
            $relationData = $this->findAll($model, $attributeName);
        } else {
            $relationData = $this->controller->field->get($attributeName, []);
            $relationData = $this->prepareRelationData($attributeName, $relationData);
        }

        return count($relationData);
    }

    /**
     * @param string $attributeName
     * @param array $relationData
     *
     * @return array
     */
    public function prepareRelationData($attributeName, $relationData)
    {
        $relationRule = $this->getRelationRule($this->controller->getAttributeRule($attributeName));
        [, , $thirdRelation] = $this->getRelationAttributes($relationRule);

        $relationData = array_filter(
            $relationData,
            function ($val) use ($thirdRelation) {
                if ($thirdRelation && !($val[$thirdRelation[0]] ?? false)) {
                    return false;
                }

                return $val;
            }
        );


        return array_values($relationData);
    }

    /**
     * @param array $attributeRule
     * @param int $id
     *
     * @return mixed
     */
    public function getFirstModel(array $attributeRule, int $id)
    {
        [$primaryRelation] = $this->getRelationAttributes($this->getRelationRule($attributeRule));
        if (!$primaryRelation) {
            return null;
        }

        return call_user_func([$primaryRelation[2], 'findOne'], $id);
    }

    /**
     * @param array $attributeRule
     * @param int $id
     *
     * @return mixed
     */
    public function getSecondModel(array $attributeRule, int $id)
    {
        [, $secondaryRelation] = $this->getRelationAttributes($this->getRelationRule($attributeRule));
        if (!$secondaryRelation) {
            return null;
        }

        return call_user_func([$secondaryRelation[2], 'findOne'], $id);
    }

    /**
     * @param array $attributeRule
     * @param int $id
     *
     * @return mixed
     */
    public function getThirdModel(array $attributeRule, int $id)
    {
        [, , $thirdRelation] = $this->getRelationAttributes($this->getRelationRule($attributeRule));
        if (!$thirdRelation) {
            return null;
        }

        return call_user_func([$thirdRelation[2], 'findOne'], $id);
    }

    /**
     * @param array $relationRule
     * @param int $id
     * @param int $secondId
     *
     * @return ActiveRecord
     * @throws Exception
     */
    public function getMainModel(array $relationRule, int $id, int $secondId)
    {
        [$primaryRelation, $secondaryRelation] = $this->getRelationAttributes($relationRule);
        $modelClass = $relationRule['model'] ?? null;
        $conditions = [];
        $conditions[$primaryRelation[0]] = $id;
        $conditions[$secondaryRelation[0]] = $secondId;
        $model = call_user_func([$modelClass, 'findOne'], $conditions);
        /* @var ActiveRecord $model */
        if (!$model) {
            throw new \Exception($modelClass . ' with params ' . serialize($conditions) . ' was not found');
        }

        return $model;
    }

    /**
     * @param $relationRule
     *
     * @return array [['column_id', 'ref_column_id', 'class'], ['sec_column_id','sec_ref_column_id', 'class', ?'field']]
     */
    public function getRelationAttributes(array $relationRule)
    {
        $modelClass = $this->controller->modelClass;
        $relationAttributes = $relationRule['attributes'] ?? [];
        $primaryRelation = [];
        $secondaryRelation = [];
        $thirdRelation = [];

        foreach ($relationAttributes as $relationKey => $relationAttribute) {
            if (strcmp($modelClass, $relationAttribute[0])) {
                if ($secondaryRelation) {
                    $thirdRelation = [];
                    $thirdRelation[] = $relationKey;
                    $thirdRelation[] = $relationAttribute[1];
                    $thirdRelation[] = $relationAttribute[0];
                    if (isset($relationAttribute[2])) {
                        $thirdRelation[] = $relationAttribute[2];
                    }
                } else {
                    $secondaryRelation = [];
                    $secondaryRelation[] = $relationKey;
                    $secondaryRelation[] = $relationAttribute[1];
                    $secondaryRelation[] = $relationAttribute[0];
                    if (isset($relationAttribute[2])) {
                        $secondaryRelation[] = $relationAttribute[2];
                    }
                }
            } else {
                $primaryRelation = [];
                $primaryRelation[] = $relationKey;
                $primaryRelation[] = $relationAttribute[1];
                $primaryRelation[] = $relationAttribute[0];
            }
        }

        if (!$primaryRelation) {
            $primaryRelation = $secondaryRelation;
            $secondaryRelation = [];
        }

        return [$primaryRelation, $secondaryRelation, $thirdRelation];
    }

    /**
     * @param array $attributeRule
     *
     * @return array
     */
    public function getRelationRule(array $attributeRule)
    {
        return $attributeRule['relation'] ?? [];
    }
}
