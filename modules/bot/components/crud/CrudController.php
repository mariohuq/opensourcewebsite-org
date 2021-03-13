<?php

namespace app\modules\bot\components\crud;

use Yii;
use app\modules\bot\components\Controller;
use app\components\helpers\ArrayHelper;
use app\modules\bot\components\crud\services\IntermediateFieldService;
use app\modules\bot\components\crud\services\ModelRelationService;
use app\modules\bot\components\helpers\Emoji;
use app\modules\bot\components\helpers\PaginationButtons;
use yii\data\Pagination;
use app\modules\bot\components\request\Request;
use app\modules\bot\components\response\ResponseBuilder;
use app\modules\bot\components\crud\rules\FieldInterface;
use app\modules\bot\components\crud\services\BackRouteService;
use app\modules\bot\components\crud\services\EndRouteService;
use yii\base\DynamicModel;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\web\BadRequestHttpException;

/**
 * Class CrudController
 *
 * @package app\modules\bot\components
 */
abstract class CrudController extends Controller
{
    const FIELD_NAME_RELATION = 'relationAttributeName';
    const FIELD_NAME_MODEL_CLASS = 'modelClass';
    const FIELD_NAME_ATTRIBUTE = 'attributeName';
    const FIELD_EDITING_ATTRIBUTES = 'editingAttributes';
    const FIELD_NAME_ID = 'id';
    const VALUE_NO = 'NO';

    /** @var BackRouteService */
    public $backRoute;
    /** @var EndRouteService */
    public $endRoute;
    /** @var ModelRelationService */
    public $modelRelation;
    /** @var IntermediateFieldService */
    public $field;
    /** @var array */
    public $rule;
    /** @var array */
    public $attributes;
    /** @var string */
    public $attributeName;
    /** @var object */
    public $model;
    /** @var array */
    private $manyToManyRelationAttributes;
    /** @var array */
    protected $updateAttributes;

    /** @inheritDoc */
    public function __construct($id, $module, $config = [])
    {
        $this->backRoute = Yii::createObject([
            'class' => BackRouteService::class,
            'state' => $module->getBotUserState(),
            'controller' => $this,
        ]);
        $this->endRoute = Yii::createObject([
            'class' => EndRouteService::class,
            'state' => $module->getBotUserState(),
            'controller' => $this,
        ]);
        $this->modelRelation = Yii::createObject([
            'class' => ModelRelationService::class,
            'controller' => $this,
        ]);
        $this->field = Yii::createObject([
            'class' => IntermediateFieldService::class,
            'state' => $module->getBotUserState(),
            'controller' => $this,
        ]);

        parent::__construct($id, $module, $config);
    }

    /** @inheritDoc */
    public function bindActionParams($action, $params)
    {
        if (!method_exists(self::class, $action->actionMethod)) {
            $this->backRoute->make($action->id, $params);
            $this->endRoute->make($action->id, $params);
            $this->field->set(self::FIELD_NAME_ID, null);
        } elseif (!strcmp($action->actionMethod, 'actionUpdate')) {
            $this->backRoute->make($action->id, $params);
            $this->field->reset();
        }

        $this->rule = $this->rules() ?? [];
        $this->attributes = $this->rule['attributes'] ?? [];

        return parent::bindActionParams($action, $params);
    }

    /**
     * @return array
     */
    public function actionCreate()
    {
        $this->field->reset();
        $attributeName = array_key_first($this->attributes);

        return $this->generateResponse($attributeName);
    }

    /**
     * [
     * 'keywords'      => [
     *  'component' => [
     *      'class'      => ExplodeStringField::class,
     *      'attributes' => [
     *          'delimiters' => [',', '.', "\n"],
     *      ],
     * ],
     * 'location'      => [
     *      'component' => LocationToArrayField::class,
     * ],
     *
     * @param $config
     *
     * @return FieldInterface|null
     * @throws InvalidConfigException
     */
    private function createAttributeComponent($config)
    {
        if (isset($config['component'])) {
            $component = $config['component'];
            $objectParams = [];
            if (is_array($component) && isset($component['class'])) {
                $objectParams['class'] = $component['class'];
                $objectParams = array_merge($objectParams, $component['attributes'] ?? []);
            } else {
                $objectParams['class'] = $component;
            }
            /** @var FieldInterface $object */
            $object = Yii::createObject($objectParams, [$this, $config]);

            return $object;
        }

        return null;
    }

    /**
     * @return array
     */
    public function getEditingAttributes()
    {
        return $this->field->get(self::FIELD_EDITING_ATTRIBUTES, []);
    }

    /**
     * @param string $attributeName
     */
    public function addEditingAttribute(string $attributeName)
    {
        $attributes = $this->getEditingAttributes();
        $attributes[$attributeName] = [];

        return $this->field->set(self::FIELD_EDITING_ATTRIBUTES, $attributes);
    }

    /**
     * Enter Attribute
     *
     * @param string|null $a Attribute name
     * @param int|null $id
     * @param string|null $text
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function actionEnA(string $a = null, int $id = null, string $text = null)
    {
        $this->attributeName = $attributeName = &$a;

        if (!$attributeRule = $this->getAttributeRule($attributeName)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if ($relationRule = $this->getRelationRule($attributeName)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if ($id && (!$this->model = $this->getModel($id))) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if (!$this->model) {
            $this->model = $this->createModel();
        }

        if ($text == self::VALUE_NO) {
            $text = null;
        }

        /* @var ActiveRecord $model */
        $component = $this->createAttributeComponent($attributeRule);

        if ($component instanceof FieldInterface) {
            $text = $component->prepare($text);
        }

        if (is_array($text)) {
            $this->model->setAttributes($text);

            if (!$model->validate($component->getFields())) {
                return $this->generateResponse($attributeName);
            }

            if ($this->model->isNewRecord) {
                $this->field->set($text);
            } else {
                $this->model->save();
            }
        } else {
            $this->model->setAttribute($attributeName, $text);

            if (!$this->model->validate($attributeName)) {
                return $this->generateResponse($attributeName);
            }

            if ($this->model->isNewRecord) {
                $this->field->set($attributeName, $text);
            } else {
                $this->model->save();
            }
        }

        if ($this->model->isNewRecord) {
            $nextAttribute = $this->getNextKey($this->attributes, $attributeName);

            if (isset($nextAttribute)) {
                return $this->generateResponse($nextAttribute);
            }

            return $this->save();
        }

        return $this->actionUpdate($this->model->id);
    }

    /**
     * Set Attribute
     *
     * @param string|null $a Attribute name
     * @param int|null $id
     * @param int $p Page number
     * @param null $i Attribute id
     * @param null $v Attribute value
     * @param null $text User Message
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function actionSA(string $a = null, int $id = null, int $p = 1, $i = null, $v = null, $text = null)
    {
        $this->attributeName = $attributeName = &$a;

        if (!$attributeRule = $this->getAttributeRule($attributeName)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if (!$relationRule = $this->getRelationRule($attributeName)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if ($id && (!$this->model = $this->getModel($id))) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if ($text == self::VALUE_NO) {
            $shouldRemove = true;
            $text = null;
        } else {
            $shouldRemove = false;
        }

        $attributeRule = $this->getAttributeRule($attributeName);
        $relationAttributes = $relationRule['attributes'];

        [
            $primaryRelation, $secondaryRelation, $thirdRelation,
        ] = $this->modelRelation->getRelationAttributes($relationRule);

        $isValidRequest = false;

        $component = $this->createAttributeComponent($attributeRule);

        if ($component instanceof FieldInterface) {
            $text = $component->prepare($text);
        }

        $editableRelationId = null;

        if (isset($relation) && (isset($v) || isset($text))) {
            $relationAttributeName = $this->field->get(self::FIELD_NAME_RELATION, null);
            if (!$relationAttributeName && $secondaryRelation) {
                $isValidRequest = true;
                $relationData = $this->field->get($attributeName, [[]]);
                if (!$text && !$v) {
                    $relationData = [
                        [$secondaryRelation[0] => null],
                    ];
                } elseif (is_array($text)) {
                    $relationData = $text;
                } else {
                    $relationData[] = $text;
                }
                $relationData = $this->modelRelation->prepareRelationData($attributeName, $relationData);
                $this->field->set($attributeName, $relationData);
            } else {
                if (!array_key_exists($relationAttributeName, $relationAttributes)) {
                    return $this->getResponseBuilder()
                        ->answerCallbackQuery()
                        ->build();
                }
                $relationAttribute = $relationAttributes[$relationAttributeName];
                if ($v) {
                    if (in_array($relationAttributeName, $thirdRelation)) {
                        $relationModel = $this->modelRelation->getThirdModel($attributeRule, $v);
                    } elseif (in_array($relationAttributeName, $secondaryRelation)) {
                        $relationModel = $this->modelRelation->getSecondModel($attributeRule, $v);
                    } elseif (in_array($relationAttributeName, $primaryRelation)) {
                        $relationModel = $this->modelRelation->getFirstModel($attributeRule, $v);
                    }
                } elseif ($text && ($field = ($relationAttribute[2] ?? null))) {
                    $relationQuery = call_user_func([$relationAttribute[0], 'find']);
                    $queryConditions = [];
                    if (is_array($field)) {
                        foreach ($field as $item) {
                            $queryConditions[$item] = $text;
                        }
                        $queryConditions['OR'] = $queryConditions;
                    } else {
                        $queryConditions[$field] = $text;
                    }
                    $relationModel = $relationQuery->where($queryConditions)->one();
                }
            }
            if (isset($relationModel)) {
                $relationData = $this->field->get($attributeName, [[]]);
                if (preg_match('|v_(\d+)|', $i, $match)) {
                    $id = $match[1];
                } elseif ($i) {
                    $model = $this->getRuleModel($relation, $i);
                    foreach ($relationData as $key => $relationItem) {
                        if (!is_array($relationItem)) {
                            continue;
                        }
                        if ($relationItem[$primaryRelation[0]] == $model->{$primaryRelation[0]}
                            && $relationItem[$secondaryRelation[0]] == $model->{$secondaryRelation[0]}) {
                            $id = $key;
                            break;
                        }
                    }
                }
                if (isset($id) && isset($relationData[$id])) {
                    $item = $relationData[$id];
                    unset($relationData[$id]);
                } else {
                    $item = array_pop($relationData);
                }
                if (empty($item)) {
                    foreach ($relationData as $key => $relationItem) {
                        if (!is_array($relationItem)) {
                            continue;
                        }
                        if ($relationItem[$relationAttributeName] == $v) {
                            $item = $relationItem;
                            unset($relationData[$key]);
                            break;
                        }
                    }
                }
                $relationAttributesCount = count($relationAttributes);
                $isManyToOne = $relationAttributesCount == 1;
                $item[$relationAttributeName] = $relationModel->id;
                $relationData[] = $item;
                $relationData = array_filter(
                    $relationData,
                    function ($val) {
                        return $val;
                    }
                );
                $relationData = array_values($relationData);
                $editableRelationId = 'v_' . array_key_last($relationData);
                if ($isManyToOne && ($modelField = array_key_first($relationAttributes))) {
                    $this->field->set($modelField, $relationModel->id);
                }
                $this->field->set($attributeName, $relationData);

                $nextRelationAttributeName = $this->getNextKey($relationAttributes, $relationAttributeName);
                $this->field->set(self::FIELD_NAME_RELATION, $nextRelationAttributeName);

                if (!isset($nextRelationAttributeName)) {
                    $isValidRequest = true;
                }
            }
        }
        if ($shouldRemove) {
            $this->field->set($attributeName, []);
            $isValidRequest = true;
        }
        if ($isValidRequest) {
            $isEdit = !is_null($this->field->get(self::FIELD_NAME_ID, null));

            if ($config['samePageAfterAdd'] ?? false) {
                $nextAttribute = $attributeName;
            } else {
                $nextAttribute = $this->getNextKey($this->attributes, $attributeName);
            }

            if (isset($nextAttribute) && !$isEdit) {
                return $this->generateResponse($nextAttribute);
            }

            $prevAttribute = $this->getPrevKey($this->attributes, $attributeName);

            if (isset($prevAttribute)) {
                $model = $this->getFilledModel($this->rule);
                $model->save();

                return $this->generateResponse($prevAttribute);
            }

            return $this->save();
        }

        return $this->generatePrivateResponse($attributeName, [
            'page' => $p,
            'editableRelationId' => $editableRelationId,
        ]);
    }

    /**
     * Add Attribute
     *
     * @param string|null $a Attribute name
     * @param int $id = null
     * @param int|null $p Page
     *
     * @return array
     */
    public function actionAA(string $a = null, int $id = null, int $p = null)
    {
        $this->attributeName = $attributeName = &$a;

        if (!$attributeRule = $this->getAttributeRule($attributeName)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if ($id && (!$this->model = $this->getModel($id))) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if (!$this->model) {
            $this->model = $this->createModel();
        }

        Yii::warning('actionAA. $p: ' . $p);
        if (!isset($p)) {
            $relationRule = $this->getRelationRule($attributeName);

            [, $secondaryRelation] = $this->modelRelation->getRelationAttributes($relationRule);
            $this->field->set(self::FIELD_NAME_RELATION, $secondaryRelation[0]);
            $attributeValue = $this->field->get($attributeName, [[]]);
            $attributeLastItem = end($attributeValue);

            if (!empty($attributeLastItem)) {
                $attributeValue[] = [];
            }

            $this->field->set($attributeName, $attributeValue);
        } else {
            $this->field->set(self::FIELD_NAME_RELATION, null);
        }

        return $this->generatePrivateResponse($attributeName, [
            'page' => $p ?? 1,
        ]);
    }

    /**
     * Edit/Show Attribute (Step 1)
     *
     * @param string|null $a Attribute name
     * @param int|null $id
     * @param string|null $text
     *
     * @return array
     */
    public function actionEA(string $a = null, int $id = null, string $text = null)
    {
        $this->attributeName = $attributeName = &$a;

        if (!$attributeRule = $this->getAttributeRule($attributeName)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if ($id && (!$this->model = $this->getModel($id))) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if (!$this->model) {
            $this->model = $this->createModel();
        }

        if ($text) {
            if ($text == self::VALUE_NO) {
                $text = null;
            }

            /* @var ActiveRecord $model */
            $component = $this->createAttributeComponent($attributeRule);

            if ($component instanceof FieldInterface) {
                $text = $component->prepare($text);
            }

            if (is_array($text)) {
                $this->model->setAttributes($text);

                if (!$model->validate($component->getFields())) {
                    return $this->generateResponse($attributeName);
                }

                if ($this->model->isNewRecord) {
                    $this->field->set($text);
                } else {
                    $this->model->save();
                }
            } else {
                $this->model->setAttribute($attributeName, $text);

                if (!$this->model->validate($attributeName)) {
                    return $this->generateResponse($attributeName);
                }

                if ($this->model->isNewRecord) {
                    $this->field->set($attributeName, $text);
                } else {
                    $this->model->save();
                }
            }

            if ($this->model->isNewRecord) {
                $nextAttribute = $this->getNextKey($this->attributes, $attributeName);

                if (isset($nextAttribute)) {
                    return $this->generateResponse($nextAttribute);
                }

                return $this->save();
            }

            return $this->actionUpdate($this->model->id);
        }

        return $this->generateResponse($attributeName);
    }

    /**
     * Button Callback
     *
     * @param string $a Attribute name
     * @param int $i Button number
     * @param int $id Model id
     *
     * @return array
     * @throws InvalidConfigException
     * @throws Throwable
     */
    public function actionBC(string $a = null, int $i = 0, int $id = null)
    {
        $this->attributeName = $attributeName = &$a;

        if (!$attributeRule = $this->getAttributeRule($attributeName)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if ($id && (!$this->model = $this->getModel($id))) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if (!$this->model) {
            $this->model = $this->createModel();
        }

        /** @var ActiveRecord $model */
        $this->model = call_user_func($attributeRule['buttons'][$i]['callback'], $this->model);

        if (!$this->model) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if ($this->model->isNewRecord) {
            $this->field->set($this->model->getAttributes());
        } else {
            $this->model->save();
        }

        if ($this->model->isNewRecord) {
            $nextAttribute = $this->getNextKey($this->attributes, $attributeName);

            if (isset($nextAttribute)) {
                return $this->generateResponse($nextAttribute);
            }

            return $this->save();
        }

        return $this->actionUpdate($this->model->id);
    }

    /**
     * Clear Attribute
     *
     * @return array
     */
    public function actionCA()
    {
        $attributeName = $this->field->get(self::FIELD_NAME_ATTRIBUTE, null);

        if (isset($attributeName)) {
            $config = $this->getAttributeRule($attributeName);
            $isAttributeRequired = $config['isRequired'] ?? true;

            if (!$isAttributeRequired) {
                $this->field->set($attributeName, null);

                $isEdit = !is_null($this->field->get(self::FIELD_NAME_ID, null));

                if ($isEdit) {
                    return $this->save();
                }

                return $this->generateResponse($attributeName);
            }
        }

        return $this->getResponseBuilder()
            ->answerCallbackQuery()
            ->build();
    }

    /**
     * Show Attribute
     *
     * @param string $a Attribute name
     *
     * @return array
     */
    public function actionShA($a)
    {
        $attributeName = $a;
        $isEdit = !is_null($this->field->get(self::FIELD_NAME_ID, null));
        if (($relation = $this->modelRelation->getRelationRule($this->getAttributeRule($attributeName))) && count($relation['attributes']) > 1) {
            $relationAttributes = $relation['attributes'];
            array_shift($relationAttributes);
            $relationAttributeName = $this->field->get(self::FIELD_NAME_RELATION, null);
            if (isset($relationAttributeName)) {
                $prevRelationAttributeName = $this->getPrevKey($relationAttributes, $relationAttributeName);
                $this->field->set(self::FIELD_NAME_RELATION, $prevRelationAttributeName);

                return $this->generatePrivateResponse($attributeName);
            }
        }
        if (!$isEdit) {
            return $this->generateResponse($attributeName);
        } else {
            $response = $this->onCancel(
                $this->modelClass,
                $this->field->get(self::FIELD_NAME_ID, null)
            );
            $this->field->reset();

            return $response;
        }
    }

    /**
     * Next Attribute
     *
     * @return array
     * @throws BadRequestHttpException
     */
    public function actionNA()
    {
        $attributeName = $this->field->get(self::FIELD_NAME_ATTRIBUTE, null);
        if (isset($attributeName)) {
            $isEdit = !is_null($this->field->get(self::FIELD_NAME_ID, null));
            if (($relation = $this->modelRelation->getRelationRule($this->getAttributeRule($attributeName))) && count($relation['attributes']) > 1) {
                $relationAttributes = $relation['attributes'];
                array_shift($relationAttributes);
                $relationAttributeName = $this->field->get(self::FIELD_NAME_RELATION, null);
                if (isset($relationAttributeName)) {
                    $relationData = $this->field->get($attributeName, [[]]);
                    $item = array_pop($relationData);
                    if (!empty($item[$relationAttributeName] ?? null)) {
                        $nextRelationAttributeName = $this->getNextKey($relationAttributes, $relationAttributeName);
                        $this->field->set(self::FIELD_NAME_RELATION, $nextRelationAttributeName);

                        return $this->generatePrivateResponse($attributeName);
                    }

                    return $this->getResponseBuilder()
                        ->answerCallbackQuery()
                        ->build();
                }
            }
            $nextAttributeName = $this->getNextKey($this->attributes, $attributeName);
            $isAttributeRequired = $this->getAttributeRule($attributeName)['isRequired'] ?? true;
            if (!$isAttributeRequired || !empty($this->field->get($attributeName, null))) {
                if (isset($nextAttributeName) && !$isEdit) {
                    return $this->generateResponse($nextAttributeName);
                } else {
                    return $this->save();
                }
            }
        }

        return $this->getResponseBuilder()
            ->answerCallbackQuery()
            ->build();
    }

    /**
     * Previous Attribute
     *
     * @return array
     */
    public function actionPA()
    {
        $attributeName = $this->field->get(self::FIELD_NAME_ATTRIBUTE, null);
        if (isset($attributeName)) {
            $modelId = $this->field->get(self::FIELD_NAME_ID, null);
            $isEdit = !is_null($modelId);
            $config = $this->getAttributeRule($attributeName);
            $thirdRelation = [];
            if (($relation = $this->modelRelation->getRelationRule($config)) && count($relation['attributes']) > 1) {
                $relationAttributes = $relation['attributes'];
                array_shift($relationAttributes);
                $relationAttributeName = $this->field->get(self::FIELD_NAME_RELATION, null);
                [, , $thirdRelation] = $this->modelRelation->getRelationAttributes($relation);
                if (isset($relationAttributeName) && !in_array($relationAttributeName, $thirdRelation)) {
                    $prevRelationAttributeName = $this->getPrevKey($relationAttributes, $relationAttributeName);
                    $this->field->set(self::FIELD_NAME_RELATION, $prevRelationAttributeName);
                    if (!($config['createRelationIfEmpty'] ?? false) || $this->modelRelation->filledRelationCount($attributeName)) {
                        return $this->generatePrivateResponse($attributeName);
                    } else {
                        $relationAttributeName = null;
                    }
                }
            }
            if ($thirdRelation && $relationAttributeName) {
                $prevAttributeName = $attributeName;
            } else {
                $prevAttributeName = $this->getPrevKey($this->attributes, $attributeName);
            }
            if (isset($prevAttributeName) && !$isEdit) {
                return $this->generateResponse($prevAttributeName);
            } else {
                $response = $this->onCancel(
                    $this->modelClass,
                    $this->field->get(self::FIELD_NAME_ID, null)
                );
                $this->field->reset();

                return $response;
            }
        }

        return $this->getResponseBuilder()
            ->answerCallbackQuery()
            ->build();
    }

    /**
     * Remove Attribute
     *
     * @param $i int Item Primary Id
     *
     * @return array
     */
    public function actionRA($i)
    {
        $attributeName = $this->field->get(self::FIELD_NAME_ATTRIBUTE, null);

        if (isset($attributeName)) {
            if (($relation = $this->modelRelation->getRelationRule($this->getAttributeRule($attributeName))) && count($relation['attributes']) > 1) {
                [, $secondaryRelation] = $this->modelRelation->getRelationAttributes($relation);
                $relationAttributeName = $this->field->get(self::FIELD_NAME_RELATION, null);

                $items = $this->field->get($attributeName, []);
                if (preg_match('|v_(\d+)|', $i, $match)) {
                    unset($items[$match[1]]);
                } else {
                    $model = $this->getRuleModel($relation, $i);
                    if ($model) {
                        $model->delete();
                    }
                }
                $items = array_values($items);
                $this->field->set($attributeName, $items);

                return $this->generateResponse($attributeName);
            }
        }

        return $this->getResponseBuilder()
            ->answerCallbackQuery()
            ->build();
    }

    /**
     * Edit Relation Attribute
     *
     * @param $i int Item Primary Id
     *
     * @return array
     */
    public function actionERA($i)
    {
        $attributeName = $this->field->get(self::FIELD_NAME_ATTRIBUTE, null);
        if (isset($attributeName)) {
            if (($relation = $this->modelRelation->getRelationRule($this->getAttributeRule($attributeName))) && count($relation['attributes']) > 1) {
                [
                    $primaryRelation, $secondaryRelation, $thirdRelation,
                ] = $this->modelRelation->getRelationAttributes($relation);
                $relationAttributeName = $this->field->get(self::FIELD_NAME_RELATION, null);
                if (!isset($relationAttributeName)) {
                    $this->field->set(self::FIELD_NAME_RELATION, $primaryRelation[0]);
                    $items = $this->field->get($attributeName, []);
                    foreach ($items as $key => $item) {
                        if (!$item) {
                            unset($items[$key]);
                            continue;
                        }
                        if ($item[$secondaryRelation[0]] == $i) {
                            unset($items[$key]);
                            $items[] = $item;
                            break;
                        }
                    }
                    $this->field->set($attributeName, $items);
                    if ($thirdRelation) {
                        $this->field->set(self::FIELD_NAME_RELATION, $thirdRelation[0]);
                    }

                    return $this->generatePrivateResponse($attributeName, [
                        'editableRelationId' => $i,
                    ]);
                }
            }
        }

        return $this->getResponseBuilder()
            ->answerCallbackQuery()
            ->build();
    }

    /**
     * @param string $attributeName
     * @param array $params
     * @param array $options
     *
     * @return helpers\MessageText
     */
    private function renderAttribute(string $attributeName, array $params = [], array $options = [])
    {
        $relationAttributeName = ArrayHelper::getValue($options, 'relationAttributeName', null);
        $attributeRule = $this->getAttributeRule($attributeName);

        if (isset($attributeRule['view'])) {
            $view = $attributeRule['view'];
        } elseif ($relationAttributeName && ($attributeRule['enableAddButton'] ?? false)) {
            $view = $attributeName . '/set-' . $relationAttributeName;
        } else {
            $view = 'set-' . $attributeName;
        }

        return $this->render($view, $params);
    }

    /**
     * @return string
     */
    public function getModelClass($rule = null)
    {
        if (!$rule) {
            return $this->rule['model'] ?? null;
        }

        if ($this->rule != $rule) {
            Yii::warning('getModelClass: ' . $this->rule['model']);
        }

        return $rule['model'] ?? null;
    }

    /**
     * @param string $className
     *
     * @return string
     */
    public function getModelName($modelClass = null)
    {
        if (!$modelClass) {
            $modelClass = $this->modelClass;
        }

        if ($this->modelClass != $modelClass) {
            Yii::warning('getModelName: ' . $modelClass);
        }
        // \yii\helpers\StringHelper::basename($modelClass));
        $parts = explode('\\', $modelClass);

        return strtolower(array_pop($parts));
    }

    protected function rules()
    {
        return [];
    }

    /**
     * @param string $className
     * @param int|null $id
     *
     * @return array
     */
    protected function onCancel(string $className, ?int $id)
    {
        return $this->getResponseBuilder()
            ->answerCallbackQuery()
            ->build();
    }

    /**
     * @param ActiveRecord $model
     * @param string $attributeName
     * @param array $manyToManyRelationAttributes
     *
     * @param array $options ['config' => [values of attribute]]
     *
     * @return mixed
     * @throws InvalidConfigException
     */
    private function fillModel($model, $attributeName, &$manyToManyRelationAttributes, $options)
    {
        $attributeRule = ArrayHelper::getValue($options, 'config', []);
        $state = $this->getState();

        if (false) {//(!$ignoreEditingAttributes && !$editingAttributes) {
            $editingAttributes = $this->getEditingAttributes();
            unset($editingAttributes[$attributeName]);

            foreach ($editingAttributes as $field => $config) {
                $attributeRule = array_merge($attributeRule, $this->rule['attributes'][$field]);
                $this->fillModel($model, $field, $manyToManyRelationAttributes, [
                    'config' => $attributeRule,
                    'editingAttributes' => $editingAttributes,
                ]);
            }
        }

        $component = $this->createAttributeComponent($attributeRule);

        if ($component instanceof FieldInterface) {
            $fields = $component->getFields();
            foreach ($fields as $field) {
                $this->fillModel($model, $field, $manyToManyRelationAttributes, [
                    'config' => $attributeRule['component'],
                    'ignoreEditingAttributes' => true,
                ]);
            }
        }
        if ($state->isIntermediateFieldExists($attributeName)) {
            $relation = $this->modelRelation->getRelationRule($attributeRule);
            if (!empty($relation)) {
                $relationAttributes = $relation['attributes'];
                if (count($relationAttributes) > 1) {
                    $manyToManyRelationAttributes[] = $attributeName;

                    return $model;
                }
                if (count($relation) == 1) {
                    $relationValue = $this->field->get($attributeName, [[]]);
                    $model->setAttributes($relationValue[0]);
                }
            } else {
                $value = $this->field->get($attributeName, null);
                $model->setAttribute($attributeName, $value ?? null);
            }
        }

        return $model;
    }

    /**
     * @param ActiveRecord $model
     * @param string $attributeName
     * @param array $manyToManyRelationAttributes
     *
     * @param array $options ['config' => [values of attribute]]
     *
     * @return mixed
     * @throws InvalidConfigException
     */
    private function fillRelationModel($model, $attributeName, &$manyToManyRelationAttributes, $options)
    {
        $attributeRule = ArrayHelper::getValue($options, 'config', []);
        $state = $this->getState();

        $component = $this->createAttributeComponent($attributeRule);

        if ($component instanceof FieldInterface) {
            $fields = $component->getFields();
            foreach ($fields as $field) {
                $this->fillModel($model, $field, $manyToManyRelationAttributes, [
                    'config' => $attributeRule['component'],
                ]);
            }
        }

        if ($state->isIntermediateFieldExists($attributeName)) {
            $relation = $this->modelRelation->getRelationRule($attributeRule);
            if (!empty($relation)) {
                $relationAttributes = $relation['attributes'];
                if (count($relationAttributes) > 1) {
                    $manyToManyRelationAttributes[] = $attributeName;

                    return $model;
                }
                if (count($relation) == 1) {
                    $relationValue = $this->field->get($attributeName, [[]]);
                    $model->setAttributes($relationValue[0]);
                }
            } else {
                $value = $this->field->get($attributeName, null);
                $model->setAttribute($attributeName, $value ?? null);
            }
        }

        return $model;
    }

    /**
     * @param string $class
     * @param string|array $field
     * @param string $value
     *
     * @return mixed|null
     */
    private function findOrCreateRelationModel($class, $field, $value)
    {
        $conditions = [];
        if (is_array($field)) {
            foreach ($field as $item) {
                $conditions[$item] = $value;
            }
            $conditions['OR'] = $conditions;
        } else {
            $conditions[$field] = $value;
        }
        $relationModel = call_user_func([$class, 'findOne'], $conditions);
        if (!$relationModel) {
            $relationModel = new $class($conditions);
            if (!$relationModel->save()) {
                return null;
            }
        }

        return $relationModel;
    }

    /**
     * @param array $config
     *
     * @return array
     */
    private function getAttributeBehaviors($config)
    {
        $behaviors = [];
        $behaviorId = uniqid();
        foreach ($config['behaviors'] ?? [] as $behaviorName => $behaviorValue) {
            $behaviors[$behaviorId . $behaviorName] = $behaviorValue;
        }

        return $behaviors;
    }

    /**
     * @param array $rule
     *
     * @return array
     */
    private function getRuleBehaviors($rule)
    {
        $behaviors = [];
        foreach ($rule['attributes'] as $attributeConfig) {
            $behaviors = array_merge($behaviors, $this->getAttributeBehaviors($attributeConfig));
        }

        return $behaviors;
    }

    /**
     * @param array $rule
     *
     * @return ActiveRecord
     * @throws InvalidConfigException
     */
    private function getFilledModel()
    {
        $manyToManyRelationAttributes = [];
        /* @var ActiveRecord $model */
        if ($this->model->isNewRecord) {
            foreach ($this->attributes as $attributeName => $attributeRule) {
                $this->fillModel($this->model, $this->attributeName, $manyToManyRelationAttributes, [
                    'config' => $this->getAttributeRule($this->attributeName),
                ]);
            }

            $this->model->attachBehaviors($this->getRuleBehaviors($this->rule));
        } else {
            $this->fillModel($this->model, $this->attributeName, $manyToManyRelationAttributes, [
                'config' => $this->getAttributeRule($this->attributeName),
            ]);
        }

        $this->manyToManyRelationAttributes = $manyToManyRelationAttributes;

        return $model;
    }

    /**
     * Return model for main relation
     * protected function rules()
     * {
     *      return [
     *      [
     *          'model' => Model::class,
     *          'relation' => [
     *              'model' => RelationModel::class,
     *              'attributes' => [
     *                  'company_id' => [Model::class, 'id'],
     *                  'user_id' => [DynamicModel::class, 'id'],
     *              ],
     *              'component' => [
     *              'class' => CurrentUserFieldComponent::class,
     *          ],
     *      ],
     * }
     *
     * @param ActiveRecord $model
     * @param array $config
     *
     * @return ActiveRecord|null
     * @throws InvalidConfigException
     */
    public function createRelationModel($model, $config)
    {
        $relation = $config['relation'] ?? [];
        if ($relation) {
            [
                $primaryRelation, $secondaryRelation,
            ] = $this->modelRelation->getRelationAttributes($relation);
            $secondaryFieldData = null;
            if (new $secondaryRelation[2] instanceof DynamicModel) {
                $component = $this->createAttributeComponent($relation);
                $secondaryFieldData = $component->prepare('');
            }
            /** @var ActiveRecord $relationModel */
            $relationModel = new $relation['model'];
            $relationModel->setAttributes([
                $primaryRelation[0] => $model->id,
                $secondaryRelation[0] => $secondaryFieldData,
            ]);

            return $relationModel;
        }

        return null;
    }

    /**
     * @return array
     * @throws InvalidConfigException
     * @throws Throwable
     */
    private function save()
    {
        $model = $this->getFilledModel($this->rule);
        $rule = $this->rule;
        $isNew = $model->isNewRecord;

        if ($model->validate()) {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                if ($model->save()) {
                    isset($model->cross_rate_on) ? Yii::warning('cross_rate_on: ' . $model->cross_rate_on) : null;
                    //Yii::warning('delivery_radius: ' . $model->delivery_radius);
                    $relationModel = $this->createRelationModel($model, $this->rule);
                    if ($relationModel && !$relationModel->save()) {
                        throw new \Exception('not possible to save ' . $relationModel->formName() . ' because ' . serialize($relationModel->getErrors()));
                    }
                    foreach ($this->manyToManyRelationAttributes as $attributeName) {
                        $relation = $this->modelRelation->getRelationRule($this->getAttributeRule($attributeName));
                        $relationModelClass = $relation['model'];

                        [
                            $primaryRelation, $secondaryRelation, $thirdRelation,
                        ] = $this->modelRelation->getRelationAttributes($relation);

                        $attributeValues = $this->field->get($attributeName, []);
                        $appendedIds = [];
                        foreach ($attributeValues as $attributeValue) {
                            if (!$attributeValue) {
                                continue;
                            }
                            $useDynamicModel = false;
                            if (new $secondaryRelation[2] instanceof DynamicModel) {
                                $useDynamicModel = true;
                                if (!is_array($attributeValue)) {
                                    $text = $attributeValue;
                                    $attributeValue = [];
                                    $attributeValue[$secondaryRelation[0]] = $text;
                                }
                            } elseif (!is_array($attributeValue)) {
                                if (isset($secondaryRelation[3])
                                    && ($relationModel = $this->findOrCreateRelationModel(
                                        $secondaryRelation[2],
                                        $secondaryRelation[3],
                                        $attributeValue
                                    ))) {
                                    $attributeValue = [];
                                    $attributeValue[$secondaryRelation[0]] = $relationModel->id;
                                } else {
                                    continue;
                                }
                            }
                            $conditions = [
                                $primaryRelation[0] => $model->getAttribute(
                                    $primaryRelation[1]
                                ),
                            ];
                            if (!$useDynamicModel) {
                                $conditions[$secondaryRelation[0]] = $attributeValue[$secondaryRelation[0]];
                            }
                            /** @var ActiveRecord $relationModel */
                            $relationModel = call_user_func(
                                [$relationModelClass, 'findOne'],
                                $conditions
                            );
                            if ($relationModel) {
                                if (is_array($attributeValue) && !$attributeValue[$secondaryRelation[0]]) {
                                    $relationModel->delete();
                                    continue;
                                }
                            } else {
                                if (is_array($attributeValue) && !$attributeValue[$secondaryRelation[0]]) {
                                    continue;
                                }
                                $relationModel = Yii::createObject([
                                    'class' => $relationModelClass,
                                ]);
                            }
                            $relationModel->setAttribute(
                                $primaryRelation[0],
                                $model->getAttribute($primaryRelation[1])
                            );
                            foreach ($attributeValue as $name => $value) {
                                $relationModel->setAttribute($name, $value);
                            }
                            try {
                                if (!$relationModel->save()) {
                                    throw new \Exception('not possible to save ' . $relationModel->formName() . ' because ' . serialize($relationModel->getErrors()));
                                }
                            } catch (\yii\db\Exception $exception) {
                                Yii::error('Row in ' . $relationModelClass . ' was not added with attributes ' . serialize($attributeValue) . ' because ' . $exception->getMessage());
                            }
                            $appendedIds[] = $relationModel->id;
                        }

                        if (!$isNew && ($relation['removeOldRows'] ?? null)) {
                            /* @var ActiveQuery $query */
                            $query = call_user_func([$relationModelClass, 'find'], []);
                            $itemsToDelete = $query->where([
                                'NOT IN',
                                $primaryRelation[1],
                                $appendedIds,
                            ])->andWhere([$primaryRelation[0] => $model->id])->all();
                            foreach ($itemsToDelete as $itemToDelete) {
                                $itemToDelete->delete();
                            }
                        }
                    }
                    $this->field->reset();
                    $transaction->commit();

                    return $this->actionView($model->id);
                }
            } catch (\Exception $e) {
                Yii::warning($e);
                $transaction->rollBack();
            }
        }

        return $this->getResponseBuilder()
            ->answerCallbackQuery()
            ->build();
    }

    /**
     * @param ActiveRecord $model
     * @param string $attributeName
     */
    private function getModelDataForAttribute($model, $attributeName)
    {
        $relation = $this->modelRelation->getRelationRule($this->getAttributeRule($attributeName));
        $data = '';
        if ($relation) {
            $data = [];
            [$primaryRelation, $secondaryRelation] = $this->modelRelation->getRelationAttributes($relation);
            if (!$secondaryRelation) {
                $attributeName = $primaryRelation[0];
                $data[] = [$attributeName => $model->$attributeName];
            }
        } else {
            $data = $model->$attributeName;
        }

        return $data;
    }

    /**
     * @param array $assocArray
     * @param $element
     *
     * @return mixed|null
     */
    private function getNextKey(array $assocArray, $element)
    {
        $keys = array_keys($assocArray);
        $nextKey = $keys[array_search($element, $keys) + 1] ?? null;

        if (isset($assocArray[$nextKey]['hidden'])) {
            if (isset($assocArray[$nextKey]['behaviors'])) {
                $model = $this->getFilledModel($this->rule);
                $model->validate();
                $data = $this->getModelDataForAttribute($model, $nextKey);
                if ($data) {
                    $this->field->set($nextKey, $data);
                }
            }
            $nextKey = $this->getNextKey($assocArray, $nextKey);
        }

        return $nextKey;
    }

    /**
     * @param array $assocArray
     * @param $element
     *
     * @return mixed|null
     */
    private function getPrevKey(array $assocArray, $element)
    {
        $keys = array_keys($assocArray);
        $prevKey = $keys[array_search($element, $keys) - 1] ?? null;

        if (isset($assocArray[$prevKey]['hidden'])) {
            $prevKey = $this->getPrevKey($assocArray, $prevKey);
        }

        return $prevKey;
    }

    /**
     * @param string $attributeName
     * @param string $relationAttributeName
     * @param array $buttons
     * @param array $options
     *
     * @return array
     */
    public function prepareButtons(string $attributeName, string $relationAttributeName = null, array $buttons = [], array $options = [])
    {
        $attributeRule = $this->getAttributeRule($attributeName);
        $relationRule = $this->getRelationRule($attributeName);
        $isRequiredAttribute = $attributeRule['isRequired'] ?? true;
        $isFirstAttribute = !strcmp($attributeName, array_key_first($this->attributes));
        $isNewModel = isset($this->model->id);

        if (!$relationAttributeName) {
            if ($attributeButtonRules = $attributeRule['buttons'] ?? []) {
                foreach ($attributeButtonRules as $attributeButtonRule) {
                    if (isset($attributeButtonRule['hideMode']) && $attributeButtonRule['hideMode']) {
                        continue;
                    }

                    if ((!$isNewModel && !($attributeButtonRule['editMode'] ?? true))
                        || ($isNewModel && !($attributeButtonRule['createMode'] ?? true))) {
                        continue;
                    }

                    if (isset($attributeButtonRule['callback'])) {
                        $attributeButtonRule['callback_data'] = self::createRoute('b-c', [
                            'a' => $attributeName,
                            'i' => $buttonKey,
                        ]);

                        unset($attributeButtonRule['callback']);
                    } else {
                        $attributeButtonRule['callback_data'] = self::createRoute('404');
                    }

                    $buttons[][] = $attributeButtonRule;
                }
            }

            if (!$isAttributeRequired) {
                $buttons[][] = [
                    'text' => Yii::t('bot', $isNewModel ? 'SKIP' : 'NO'),
                    'callback_data' => self::createRoute($relationRule ? 's-a' : 'en-a', [
                        'a' => $attributeName,
                        'id' => $this->model->id ?? null,
                        'text' => self::VALUE_NO,
                    ]),
                ];
            }
        }

        $isEmpty = ArrayHelper::getValue($options, 'isEmpty', false);
        $editableRelationId = ArrayHelper::getValue($options, 'editableRelationId', null);

        if ($isNewModel && !$isFirstAttribute) {
            $rowButtons[] = [
                'text' => Emoji::BACK,
                'callback_data' => self::createRoute('p-a'),
            ];

            $rowButtons[] = [
                'text' => Emoji::END,
                'callback_data' => $this->endRoute->get(),
            ];
        } else {
            $rowButtons[] = [
                'text' => Emoji::BACK,
                'callback_data' => $this->backRoute->get(),
            ];
        }

        $editingAttributes = $this->getEditingAttributes();
        if ($editingAttributes && ($prevAttribute = $this->getPrevKey($editingAttributes, $attributeName))) {
            $systemButtons['back']['callback_data'] = $this->createAttributeRoute($prevAttribute, $modelId);
        }

        if ($relationRule = $this->getRelationRule($attributeName)) {
            [, $secondRelation, $thirdRelation] = $this->modelRelation->getRelationAttributes($relationRule);
        }

        if (($attributeRule['enableDeleteButton'] ?? false) && (!isset($relation) || count($relationRule['attributes']) == 1)) {
            if (!$isRequiredAttribute && !$isEmpty) {
                $rowButtons[] = [
                    'text' => Emoji::DELETE,
                    'callback_data' => self::createRoute('c-a'),
                ];
            }
        } elseif ($attributeRule['enableAddButton'] ?? false) {
            $rowButtons[] = [
                'text' => Emoji::ADD,
                'callback_data' => self::createRoute('a-a', [
                    'a' => $attributeName,
                ]),
            ];
        }

        if ($relationAttributeName && in_array($relationAttributeName, $thirdRelation)) {
            $systemButtons['delete'] = [
                'text' => Emoji::DELETE,
                'callback_data' => self::createRoute('r-a', [
                    'i' => $editableRelationId,
                ]),
            ];
        }

        $buttons[] = $rowButtons;

        if ($relationAttributeName) {
            unset($systemButtons['add']);
        }

        if (($config['createRelationIfEmpty'] ?? false) && $this->modelRelation->filledRelationCount($attributeName) <= 1) {
            unset($systemButtons['delete']);
        }

        $systemButtons = array_values($systemButtons);

        return array_merge($buttons, [$systemButtons]);
    }

    public function createAttributeRoute(string $attributeName, int $id = null)
    {
        if ($id) {
            $routeParams = [
                'id' => $id,
                'a' => $attributeName,
            ];
        } else {
            $routeParams = [
                'a' => $attributeName,
            ];
        }

        return $this->controller::createRoute($id ? 'e-a' : 'sh-a', $routeParams);
    }

    /**
     * @param string $attributeName
     * @param array $options
     *
     * @return array
     */
    private function generatePrivateResponse(string $attributeName, array $options = [])
    {
        $attributeRule = $this->getAttributeRule($attributeName);
        $page = ArrayHelper::getValue($options, 'page', 1);
        $editableRelationId = ArrayHelper::getValue($options, 'editableRelationId', null);

        $state = $this->getState();
        $state->setName(self::createRoute('s-a', [
            'a' => $attributeName,
            'p' => $page,
        ]));
        $this->field->set(self::FIELD_NAME_ATTRIBUTE, $attributeName);

        $relationAttributeName = $this->field->get(self::FIELD_NAME_RELATION, null);
        $modelId = $this->field->get(self::FIELD_NAME_ID, null);

        $isEdit = !is_null($modelId);
        $relation = $this->modelRelation->getRelationRule($attributeRule);
        $attributeValues = $this->field->get($attributeName, []);
        [
            $primaryRelation,
            $secondaryRelation,
            $thirdRelation,
        ] = $this->modelRelation->getRelationAttributes($relation);
        $relationModel = null;

        if ($editableRelationId && in_array($relationAttributeName, $thirdRelation)) {
            if (preg_match('|v_(\d+)|', $editableRelationId, $match)) {
                $id = $attributeValues[$match[1]][$secondaryRelation[0]] ?? null;
            } else {
                $model = $this->getRuleModel($relation, $editableRelationId);
                $id = $model->{$secondaryRelation[0]};
            }
            $relationModel = call_user_func([$secondaryRelation[2], 'findOne'], $id);
        }

        if (isset($relationAttributeName)
            && ($relationAttribute = $relation['attributes'][$relationAttributeName])) {
            if (!strcmp($this->getModelName($relationAttribute[0]), $this->modelName)) {
                $nextAttribute = $this->getNextKey($this->attributes, $attributeName);

                if (isset($nextAttribute)) {
                    return $this->generateResponse($nextAttribute);
                }
            }
            /* @var ActiveQuery $query */
            $query = call_user_func([$relationAttribute[0], 'find'], []);
            $valueAttribute = $relationAttribute[1];

            if (is_array($valueAttribute)) {
                $itemButtons = [];
            } else {
                $itemButtons = PaginationButtons::buildFromQuery(
                    $query,
                    function (int $page) use ($attributeName) {
                        return self::createRoute('s-a', [
                            'a' => $attributeName,
                            'p' => $page,
                        ]);
                    },
                    function ($key, ActiveRecord $model) use ($editableRelationId, $attributeName, $valueAttribute) {
                        return [
                            'text' => $model->getLabel(),
                            'callback_data' => self::createRoute('s-a', [
                                'a' => $attributeName,
                                'v' => $model->getAttribute($valueAttribute),
                                'i' => $editableRelationId,
                            ]),
                        ];
                    },
                    $page
                );
            }

            $isEmpty = empty($attributeValues);

            $buttons = $this->prepareButtons($attributeName, $relationAttributeName, $itemButtons, [
                'isEmpty' => $isEmpty,
                'editableRelationId' => $editableRelationId,
            ]);

            $model = $this->getFilledModel($this->rule);

            return $this->getResponseBuilder()
                ->editMessageTextOrSendMessage(
                    $this->renderAttribute($attributeName,
                        [
                            'model' => $model,
                            'relationModel' => $relationModel,
                        ],
                        [
                            'relationAttributeName' => $relationAttributeName,
                        ]
                    ),
                    $buttons,
                    [
                        'disablePreview' => true,
                    ]
                )
                ->build();
        }

        $isAttributeRequired = $attributeRule['isRequired'] ?? true;
        $rule = $this->rule;

        $itemButtons = PaginationButtons::buildFromArray(
            $attributeValues,
            function (int $page) use ($attributeName) {
                return self::createRoute('a-a', [
                    'a' => $attributeName,
                    'p' => $page,
                ]);
            },
            function ($key, $item) use ($rule, $relation, $modelId, $isAttributeRequired, $secondaryRelation, $attributeValues) {
                try {
                    if ($modelId) {
                        $model = $this->modelRelation->getMainModel(
                            $relation,
                            $modelId,
                            $item[$secondaryRelation[0]],
                        );
                    } else {
                        $model = $this->modelRelation->fillModel($relation['model'], $item);
                    }
                    if ($model) {
                        $label = $model->getLabel();
                        $id = $model->id;
                    } else {
                        $label = $item;
                    }
                    if (!$id) {
                        $id = 'v_' . $key;
                    }
                } catch (\Exception $e) {
                    Yii::warning($e);
                    if (is_array($item)) {
                        return [];
                    }
                    $label = $item;
                    $id = 'v_' . $key;
                }
                $buttonParams = $this->prepareButton($relation, [
                    'text' => $label,
                    'callback_data' => self::createRoute('e-r-a', [
                        'i' => $id,
                    ]),
                ]);

                return array_merge(
                    [$buttonParams],
                    (is_array($item) && count($item) > 1) || (count($attributeValues) == 1 && $isAttributeRequired)
                        ? []
                        : [
                        [
                            'text' => Emoji::DELETE,
                            'callback_data' => self::createRoute('r-a', [
                                'i' => $id,
                            ]),
                        ],
                    ]
                );
            },
            $page
        );

        if (!($config['showRowsList'] ?? false)) {
            $itemButtons = [];
        }

        $isEmpty = empty($items);

        $buttons = $this->prepareButtons($attributeName, null, $itemButtons, [
            'isEmpty' => $isEmpty,
        ]);

        $model = $this->getFilledModel($this->rule);

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->renderAttribute($attributeName, [
                    'model' => $model,
                ]),
                $buttons
            )
            ->build();
    }

    /**
     * @param string $attributeName
     * @param int $p Page
     *
     * @return array
     */
    private function generateResponse(string $attributeName, int $p = 1)
    {
        $this->attributeName = $attributeName;

        if ($this->relationRule) {
            if (isset($this->relationRule['model']) && ($this->attributeRule['showRowsList'] ?? false)) {
                $relationAttributeRefColumn = $this->relationAttributes[$this->relationAttributeName][1];

                $relationModels = call_user_func(
                    [$this->relationModelClass, 'findAll'],
                    [$this->relationAttributeName => $this->model->getAttribute($relationAttributeRefColumn)]
                );

                $value = [];
                /* @var ActiveRecord $relationModel */
                foreach ($relationModels as $relationModel) {
                    $relationItem = [];
                    foreach ($this->relationAttributes as $relationAttributeName => $relationAttribute) {
                        $relationItem[$relationAttributeName] = $relationModel->getAttribute($relationAttributeName);
                    }
                    $value[] = $relationItem;
                }
                Yii::warning('Value');
                Yii::warning($value);
            }

            if (!$this->relationModelClass || ($this->attributeRule['showRowsList'] ?? false)) {
                if ($this->relationModelClass) {
                    $relationAttributeRefColumn = $this->relationAttributes[$this->relationAttributeName][1];

                    $query = call_user_func(
                        [$this->relationModelClass, 'find'],
                        [$this->relationAttributeName => $this->model->getAttribute($relationAttributeRefColumn)]
                    );
                } else {
                    $relationModelClass = $this->relationAttributes[$this->relationAttributeName][0];
                    $relationAttributeRefColumn = $this->relationAttributes[$this->relationAttributeName][1];

                    $query = call_user_func(
                        [$relationModelClass, 'find']
                    );
                }
exit();
Yii::warning($query);
$model = $this->getRuleModel($this->relationRule, 1);
                    $itemButtons = PaginationButtons::buildFromQuery($query,
                        function (int $page) use ($attributeName) {
                            return self::createRoute('s-a', [
                                'a' => $attributeName,
                                'p' => $page,
                            ]);
                        },
                        function ($key, ActiveRecord $model) use ($editableRelationId, $attributeName, $valueAttribute) {
                            return [
                                'text' => $model->getLabel(),
                                'callback_data' => self::createRoute('s-a', [
                                    'a' => $attributeName,
                                    'v' => $model->getAttribute($valueAttribute),
                                    'i' => $editableRelationId,
                                ]),
                            ];
                        },
                        $page
                    );
                    Yii::warning($itemButtons);
            }

            if (($this->attributeRule['createRelationIfEmpty'] ?? false) && !$this->modelRelation->filledRelationCount($attributeName)) {
                $response = $this->actionAA($attributeName);
                Yii::warning('generateResponse. AA.');
            } else {
                $page = $p;
                $editableRelationId = null;//ArrayHelper::getValue($options, 'editableRelationId', null);

                $this->getState()->setName(self::createRoute('s-a', [
                    'a' => $attributeName,
                    'p' => $page,
                ]));

                $attributeValues = $this->field->get($attributeName, []);

                [
                    $primaryRelation,
                    $secondaryRelation,
                    $thirdRelation,
                ] = $this->modelRelation->getRelationAttributes($this->relationRule);

                $relationModel = null;

                if ($editableRelationId && in_array($this->relationAttributeName, $thirdRelation)) {
                    if (preg_match('|v_(\d+)|', $editableRelationId, $match)) {
                        $id = $attributeValues[$match[1]][$secondaryRelation[0]] ?? null;
                    } else {
                        $model = $this->getRuleModel($this->relationRule, $editableRelationId);
                        $id = $model->{$secondaryRelation[0]};
                    }
                    $relationModel = call_user_func([$secondaryRelation[2], 'findOne'], $id);
                }

                if (isset($this->relationAttributeName)
                    && ($relationAttribute = $this->relationAttributes[$this->relationAttributeName])) {
                        Yii::warning('$relationAttribute[0]: ' . $relationAttribute[0]);
                    //if (!strcmp($this->getModelName($relationAttribute[0]), $this->modelName)) {
                    //    $nextAttribute = $this->getNextKey($this->attributes, $attributeName);
//
//                        if (isset($nextAttribute)) {
//                            return $this->generateResponse($nextAttribute);
//                        }
//                    }
exit();
                    /* @var ActiveQuery $query */
                    $query = call_user_func([$relationAttribute[0], 'find'], []);
                    $valueAttribute = $relationAttribute[1];
Yii::warning($query);
                    if (is_array($valueAttribute)) {
                        $itemButtons = [];
                    } else {
                        $itemButtons = PaginationButtons::buildFromQuery($query,
                            function (int $page) use ($attributeName) {
                                return self::createRoute('s-a', [
                                    'a' => $attributeName,
                                    'p' => $page,
                                ]);
                            },
                            function ($key, ActiveRecord $model) use ($editableRelationId, $attributeName, $valueAttribute) {
                                return [
                                    'text' => $model->getLabel(),
                                    'callback_data' => self::createRoute('s-a', [
                                        'a' => $attributeName,
                                        'v' => $model->getAttribute($valueAttribute),
                                        'i' => $editableRelationId,
                                    ]),
                                ];
                            },
                            $page
                        );
                    }

                    $isEmpty = empty($attributeValues);

                    $buttons = $this->prepareButtons($attributeName, $this->relationAttributeName, $itemButtons, [
                        'isEmpty' => $isEmpty,
                        'editableRelationId' => $editableRelationId,
                    ]);

                    $model = $this->getFilledModel($this->rule);

                    return $this->getResponseBuilder()
                        ->editMessageTextOrSendMessage(
                            $this->renderAttribute($attributeName,
                                [
                                    'model' => $model,
                                    'relationModel' => $relationModel,
                                ],
                                [
                                    'relationAttributeName' => $this->relationAttributeName,
                                ]
                            ),
                            $buttons,
                            [
                                'disablePreview' => true,
                            ]
                        )
                        ->build();
                }

                $isAttributeRequired = $attributeRule['isRequired'] ?? true;
                $rule = $this->rule;

                $itemButtons = PaginationButtons::buildFromArray(
                    $attributeValues,
                    function (int $page) use ($attributeName) {
                        return self::createRoute('a-a', [
                            'a' => $attributeName,
                            'p' => $page,
                        ]);
                    },
                    function ($key, $item) use ($rule, $relation, $modelId, $isAttributeRequired, $secondaryRelation, $attributeValues) {
                        try {
                            if ($modelId) {
                                $model = $this->modelRelation->getMainModel(
                                    $relation,
                                    $modelId,
                                    $item[$secondaryRelation[0]],
                                );
                            } else {
                                $model = $this->modelRelation->fillModel($relation['model'], $item);
                            }
                            if ($model) {
                                $label = $model->getLabel();
                                $id = $model->id;
                            } else {
                                $label = $item;
                            }
                            if (!$id) {
                                $id = 'v_' . $key;
                            }
                        } catch (\Exception $e) {
                            Yii::warning($e);
                            if (is_array($item)) {
                                return [];
                            }
                            $label = $item;
                            $id = 'v_' . $key;
                        }
                        $buttonParams = $this->prepareButton($relation, [
                            'text' => $label,
                            'callback_data' => self::createRoute('e-r-a', [
                                'i' => $id,
                            ]),
                        ]);

                        return array_merge(
                            [$buttonParams],
                            (is_array($item) && count($item) > 1) || (count($attributeValues) == 1 && $isAttributeRequired)
                                ? []
                                : [
                                [
                                    'text' => Emoji::DELETE,
                                    'callback_data' => self::createRoute('r-a', [
                                        'i' => $id,
                                    ]),
                                ],
                            ]
                        );
                    },
                    $page
                );

                if (!($config['showRowsList'] ?? false)) {
                    $itemButtons = [];
                }

                $isEmpty = empty($items);

                $buttons = $this->prepareButtons($attributeName, null, $itemButtons, [
                    'isEmpty' => $isEmpty,
                ]);

                $model = $this->getFilledModel($this->rule);

                return $this->getResponseBuilder()
                    ->editMessageTextOrSendMessage(
                        $this->renderAttribute($attributeName, [
                            'model' => $model,
                        ]),
                        $buttons
                    )
                    ->build();
            }
// prepare buttons
                $attributeRule = $this->getAttributeRule($attributeName);
                $relationRule = $this->getRelationRule($attributeName);
                $isRequiredAttribute = $attributeRule['isRequired'] ?? true;
                $isFirstAttribute = !strcmp($attributeName, array_key_first($this->attributes));
                $isNewModel = isset($this->model->id);

                if (!$relationAttributeName) {
                    if ($attributeButtonRules = $attributeRule['buttons'] ?? []) {
                        foreach ($attributeButtonRules as $attributeButtonRule) {
                            if (isset($attributeButtonRule['hideMode']) && $attributeButtonRule['hideMode']) {
                                continue;
                            }

                            if ((!$isNewModel && !($attributeButtonRule['editMode'] ?? true))
                                || ($isNewModel && !($attributeButtonRule['createMode'] ?? true))) {
                                continue;
                            }

                            if (isset($attributeButtonRule['callback'])) {
                                $attributeButtonRule['callback_data'] = self::createRoute('b-c', [
                                    'a' => $attributeName,
                                    'i' => $buttonKey,
                                ]);

                                unset($attributeButtonRule['callback']);
                            } else {
                                $attributeButtonRule['callback_data'] = MenuController::createRoute();
                            }

                            $buttons[][] = $attributeButtonRule;
                        }
                    }

                    if (!$isAttributeRequired) {
                        $buttons[][] = [
                            'text' => Yii::t('bot', $isNewModel ? 'SKIP' : 'NO'),
                            'callback_data' => self::createRoute($relationRule ? 's-a' : 'en-a', [
                                'a' => $attributeName,
                                'id' => $this->model->id ?? null,
                                'text' => self::VALUE_NO,
                            ]),
                        ];
                    }
                }

                $isEmpty = ArrayHelper::getValue($options, 'isEmpty', false);
                $editableRelationId = ArrayHelper::getValue($options, 'editableRelationId', null);

                if ($isNewModel && !$isFirstAttribute) {
                    $rowButtons[] = [
                        'text' => Emoji::BACK,
                        'callback_data' => self::createRoute('p-a'),
                    ];

                    $rowButtons[] = [
                        'text' => Emoji::END,
                        'callback_data' => $this->endRoute->get(),
                    ];
                } else {
                    $rowButtons[] = [
                        'text' => Emoji::BACK,
                        'callback_data' => $this->backRoute->get(),
                    ];
                }

                $editingAttributes = $this->getEditingAttributes();
                if ($editingAttributes && ($prevAttribute = $this->getPrevKey($editingAttributes, $attributeName))) {
                    $systemButtons['back']['callback_data'] = $this->createAttributeRoute($prevAttribute, $modelId);
                }

                if ($relationRule = $this->getRelationRule($attributeName)) {
                    [, $secondRelation, $thirdRelation] = $this->modelRelation->getRelationAttributes($relationRule);
                }

                if (($attributeRule['enableDeleteButton'] ?? false) && (!isset($relation) || count($relationRule['attributes']) == 1)) {
                    if (!$isRequiredAttribute && !$isEmpty) {
                        $rowButtons[] = [
                            'text' => Emoji::DELETE,
                            'callback_data' => self::createRoute('c-a'),
                        ];
                    }
                } elseif ($attributeRule['enableAddButton'] ?? false) {
                    $rowButtons[] = [
                        'text' => Emoji::ADD,
                        'callback_data' => self::createRoute('a-a', [
                            'a' => $attributeName,
                        ]),
                    ];
                }

                if ($relationAttributeName && in_array($relationAttributeName, $thirdRelation)) {
                    $systemButtons['delete'] = [
                        'text' => Emoji::DELETE,
                        'callback_data' => self::createRoute('r-a', [
                            'i' => $editableRelationId,
                        ]),
                    ];
                }

                $buttons[] = $rowButtons;

                if ($relationAttributeName) {
                    unset($systemButtons['add']);
                }

                if (($config['createRelationIfEmpty'] ?? false) && $this->modelRelation->filledRelationCount($attributeName) <= 1) {
                    unset($systemButtons['delete']);
                }

                $systemButtons = array_values($systemButtons);

                return array_merge($buttons, [$systemButtons]);

                // TODO
        } else {
            $this->getState()->setName(self::createRoute('e-a', [
                'a' => $attributeName,
                'id' => $this->model->id ?? null,
            ]));

            if ($attributeButtonRules = $this->attributeRule['buttons'] ?? []) {
                foreach ($attributeButtonRules as $buttonKey => $attributeButtonRule) {
                    if ($this->model->isNewRecord && !($attributeButtonRule['createMode'] ?? true)) {
                        continue;
                    }

                    if (!$this->model->isNewRecord && !($attributeButtonRule['editMode'] ?? true)) {
                        continue;
                    }

                    if (isset($attributeButtonRule['hideMode']) && $attributeButtonRule['hideMode']) {
                        continue;
                    }

                    if (isset($attributeButtonRule['callback'])) {
                        $attributeButtonRule['callback_data'] = self::createRoute('b-c', [
                            'a' => $attributeName,
                            'i' => $buttonKey,
                            'id' => $this->model->id ?? null,
                        ]);
                    } elseif (isset($attributeButtonRule['item'])) {
                        $attributeButtonRule['callback_data'] = self::createRoute('b-c', [
                            'a' => $attributeButtonRule['item'],
                            'i' => $buttonKey,
                            'id' => $this->model->id ?? null,
                        ]);
                    } else {
                        $attributeButtonRule['callback_data'] = self::createRoute('404');
                    }

                    $buttons[][] = [
                        'text' => $attributeButtonRule['text'],
                        'callback_data' => $attributeButtonRule['callback_data'],
                    ];
                }
            }

            $isRequiredAttribute = $this->attributeRule['isRequired'] ?? true;

            if (!$isRequiredAttribute) {
                $buttons[][] = [
                    'text' => Yii::t('bot', $this->model->isNewRecord ? 'SKIP' : 'NO'),
                    'callback_data' => self::createRoute($this->relationRule ? 's-a' : 'e-a', [
                        'a' => $attributeName,
                        'id' => $this->model->id ?? null,
                        'text' => self::VALUE_NO,
                    ]),
                ];
            }

            $isFirstAttribute = !strcmp($attributeName, array_key_first($this->attributes));

            if ($this->model->isNewRecord && !$isFirstAttribute) {
                $rowButtons[] = [
                    'text' => Emoji::BACK,
                    'callback_data' => self::createRoute('e-a', [
                        'a' => $this->getPrevKey($this->attributes, $attributeName),
                    ]),
                ];

                $rowButtons[] = [
                    'text' => Emoji::END,
                    'callback_data' => $this->endRoute->get(),
                ];
            } else {
                $rowButtons[] = [
                    'text' => Emoji::BACK,
                    'callback_data' => $this->backRoute->get(),
                ];
            }

            $buttons[] = $rowButtons;

            $response = $this->getResponseBuilder()
                ->editMessageTextOrSendMessage(
                    $this->renderAttribute($attributeName, [
                        'model' => $this->model,
                    ]),
                    $buttons,
                    [
                        'disablePreview' => true,
                    ]
                )
                ->build();
        }

        return $response;
    }

    /**
     * @param array $rule
     *
     * @return string
     */
    public function getModelClassByRule($rule)
    {
        if ($this->rule['model'] != $rule['model']) {
            Yii::warning('getModelClassByRule: ' . $rule['model']);
        }

        return $rule['model'];
    }

    /**
     * @return object|null
     */
    private function createModel()
    {
        try {
            $object = Yii::createObject([
                'class' => $this->modelClass,
            ]);

            if ($object instanceof ActiveRecord) {
                return $object;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array $rule
     * @param int $id
     *
     * @return ActiveRecord|null
     */
    public function getRuleModel(array $rule, int $id)
    {
        Yii::warning('getRuleModel: ModelClass:' . $this->getModelClass($rule));
        Yii::warning('getRuleModel: Id:' . $id);

        $model = call_user_func([$this->getModelClass($rule), 'findOne'], $id);

        return $model ?? null;
    }

    /**
     * @param int $id
     *
     * @return ActiveRecord|null
     */
    abstract public function getModel(int $id);

    /**
     * @param array $rule
     * @param int $id
     *
     * @return ActiveRecord|null
     */
    public function getRelationModel(array $rule, int $id)
    {
        $model = call_user_func([$this->getModelClass($rule), 'findOne'], $id);

        return $model ?? null;
    }

    /**
     * @param string|null $attributeName
     *
     * @return array
     */
    public function getAttributeRule(string $attributeName = null)
    {
        if (!$attributeName) {
            $attributeName = $this->attributeName;
        }

        if ($attributeName && isset($this->attributes[$attributeName])) {
            $attributeRule = $this->attributes[$attributeName];
            $attributeRule['name'] = $attributeName;
        } else {
            $attributeRule = [];
        }

        return $attributeRule;
    }

    /**
     * @param string|null $attributeName
     *
     * @return array
     */
    public function getRelationRule(string $attributeName = null)
    {
        return $this->getAttributeRule($attributeName ?? $this->attributeName)['relation'] ?? [];
    }

    /**
     * @param string|null $attributeName
     *
     * @return array
     */
    public function getRelationModelClass(string $attributeName = null)
    {
        return $this->getAttributeRule($attributeName ?? $this->attributeName)['relation']['model'] ?? null;
    }

    /**
     * @param string|null $attributeName
     *
     * @return array
     */
    public function getRelationAttributes(string $attributeName = null)
    {
        return $this->getAttributeRule($attributeName ?? $this->attributeName)['relation']['attributes'] ?? [];
    }

    /**
     * @param string|null $attributeName
     *
     * @return array
     */
    public function getRelationAttributeName(string $attributeName = null)
    {
        return isset($this->getAttributeRule($attributeName ?? $this->attributeName)['relation']['model']) ? array_key_first($this->relationAttributes) : null;
    }

    /**
     * @param $modelName
     * @param $model
     *
     * @return array
     */
    private function getKeyboard($modelName, $model)
    {
        $getKeyboardMethodName = "get" . ucfirst($modelName) . "Keyboard";
        if (method_exists($this, $getKeyboardMethodName)) {
            $keyboard = call_user_func([$this, $getKeyboardMethodName], $model);
        }

        return $keyboard ?? [];
    }

    /**
     * @param string $attributeName
     *
     * @return boolean
     */
    private function canSkipAttribute(string $attributeName)
    {
        $config = $this->getAttributeRule($attributeName);
        $isRequired = $config['isRequired'] ?? true;
        $isEmptyAttribute = empty($this->field->get($attributeName, null));

        return !$isRequired || !$isEmptyAttribute;
    }

    /**
     * Search 'buttonFunction' attribute inside config array
     * and run function
     *
     * @param array $config
     * @param array $buttonParams
     *
     * @return array
     */
    private function prepareButton(array $config, array $buttonParams)
    {
        if ($buttonFunction = ($config['buttonFunction'] ?? null)) {
            $buttonParams = call_user_func($buttonFunction, $buttonParams);
        }

        return $buttonParams;
    }

    /**
    * @param string $a Attribute name
     *
     * @return array
     */
    public function getAttributeButton(string $a)
    {
        $attributeName = $a;
    }

    public function action404()
    {
        return $this->getResponseBuilder()
            ->answerCallbackQuery()
            ->build();
    }
}
