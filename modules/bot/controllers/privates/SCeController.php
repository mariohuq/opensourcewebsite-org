<?php

namespace app\modules\bot\controllers\privates;

use Yii;
use app\modules\bot\components\crud\CrudController;
use app\behaviors\SetAttributeValueBehavior;
use app\behaviors\SetDefaultCurrencyBehavior;
use app\modules\bot\components\crud\rules\ExplodeStringFieldComponent;
use app\modules\bot\components\crud\rules\LocationToArrayFieldComponent;
use app\modules\bot\components\crud\services\IntermediateFieldService;
use app\modules\bot\validators\RadiusValidator;
use app\modules\bot\components\helpers\Emoji;
use app\models\User;
use app\models\Currency;
use app\models\CurrencyExchangeOrder;
use app\models\CurrencyExchangeOrderMatch;
use app\models\CurrencyExchangeOrderSellingPaymentMethod;
use app\models\CurrencyExchangeOrderBuyingPaymentMethod;
use app\models\PaymentMethod;
use yii\data\Pagination;
use app\modules\bot\components\helpers\PaginationButtons;
use yii\db\ActiveRecord;

/**
 * Class SCeController
 *
 * @package app\modules\bot\controllers\privates
 */
class SCeController extends CrudController
{
    /**
     * {@inheritdoc}
     */
    protected function rules()
    {
        return [
            'model' => CurrencyExchangeOrder::class,
            'create' => [
                'sellingCurrency',
                'buyingCurrency',
                'selling_rate',
                'selling_currency_min_amount',
                'selling_currency_max_amount',
                'selling_cash_on',
                'sellingPaymentMethods',
                'buying_cash_on',
                'buyingPaymentMethods',
                'location',
                'delivery_radius',
                'user_id',
            ],
            'attributes' => [
                'sellingCurrency' => [
                    'view' => 'set-selling_currency',
                    'relation' => [
                        'attributes' => [
                            'selling_currency_id' => [Currency::class, 'id', 'code'],
                        ],
                    ],
                ],
                'buyingCurrency' => [
                    'view' => 'set-buying_currency',
                    'relation' => [
                        'attributes' => [
                            'buying_currency_id' => [Currency::class, 'id', 'code'],
                        ],
                    ],
                ],
                'selling_rate' => [
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'CROSS RATE'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                $model->cross_rate_on = CurrencyExchangeOrder::CROSS_RATE_ON;

                                return $model;
                            },
                        ],
                    ],
                ],
                'buying_rate' => [
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'CROSS RATE'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                $model->cross_rate_on = CurrencyExchangeOrder::CROSS_RATE_ON;

                                return $model;
                            },
                        ],
                    ],
                ],
                'selling_currency_min_amount' => [
                    'isRequired' => false,
                ],
                'selling_currency_max_amount' => [
                    'isRequired' => false,
                ],
                'selling_cash_on' => [
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'YES'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                $model->selling_cash_on = CurrencyExchangeOrder::CASH_ON;

                                return $model;
                            },
                        ],
                        [
                            'text' => Yii::t('bot', 'NO'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                $model->selling_cash_on = CurrencyExchangeOrder::CASH_OFF;

                                return $model;
                            },
                        ],
                    ],
                ],
                'sellingPaymentMethods' => [
                    'view' => 'set-selling_payment_methods',
                    'samePageAfterAdd' => true,
                    'enableAddButton' => true,
                    'showRowsList' => true,
                    'createRelationIfEmpty' => true,
                    'relation' => [
                        'model' => CurrencyExchangeOrderSellingPaymentMethod::class,
                        'attributes' => [
                            'order_id' => [CurrencyExchangeOrder::class, 'id'],
                            'payment_method_id' => [PaymentMethod::class, 'id', 'name', 'type'],
                        ],
                        'removeOldRows' => true,
                    ],
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'NEXT'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                return $model;
                            },
                        ],
                    ],
                ],
                'buying_cash_on' => [
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'YES'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                $model->buying_cash_on = CurrencyExchangeOrder::CASH_ON;

                                return $model;
                            },
                        ],
                        [
                            'text' => Yii::t('bot', 'NO'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                $model->buying_cash_on = CurrencyExchangeOrder::CASH_OFF;

                                return $model;
                            },
                        ],
                    ],
                ],
                'buyingPaymentMethods' => [
                    'view' => 'set-buying_payment_methods',
                    'samePageAfterAdd' => true,
                    'enableAddButton' => true,
                    'showRowsList' => true,
                    'createRelationIfEmpty' => true,
                    'relation' => [
                        'model' => CurrencyExchangeOrderBuyingPaymentMethod::class,
                        'attributes' => [
                            'order_id' => [CurrencyExchangeOrder::class, 'id'],
                            'payment_method_id' => [PaymentMethod::class, 'id', 'name', 'type'],
                        ],
                        'removeOldRows' => true,
                    ],
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'NEXT'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                return $model;
                            },
                        ],
                    ],
                ],
                'location' => [
                    'isRequired' => false,
                    'component' => LocationToArrayFieldComponent::class,
                    'buttons' => [
                        [
                            'hideMode' => !$this->getTelegramUser()->location_lat || !$this->getTelegramUser()->location_lon,
                            'text' => Yii::t('bot', 'MY LOCATION'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                $latitude = $this->getTelegramUser()->location_lat;
                                $longitude = $this->getTelegramUser()->location_lon;

                                if ($latitude && $longitude) {
                                    $model->location_lat = $latitude;
                                    $model->location_lon = $longitude;

                                    return $model;
                                }

                                return null;
                            },
                        ],
                    ],
                ],
                'delivery_radius' => [
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'NO'),
                            'callback' => function (CurrencyExchangeOrder $model) {
                                $model->delivery_radius = 0;

                                return $model;
                            },
                        ],
                    ],
                ],
                'cross_rate_on' => [
                    'hidden' => true,
                    'isRequired' => false,
                ],
                'user_id' => [
                    'hidden' => true,
                    'behaviors' => [
                        'SetAttributeValueBehavior' => [
                            'class' => SetAttributeValueBehavior::class,
                            'attributes' => [
                                ActiveRecord::EVENT_BEFORE_VALIDATE => ['user_id'],
                                ActiveRecord::EVENT_BEFORE_INSERT => ['user_id'],
                            ],
                            'attribute' => 'user_id',
                            'value' => $this->module->user->id,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param int $page
     *
     * @return array
     */
    public function actionIndex($page = 1)
    {
        $this->getState()->setName();
        $user = $this->getUser();

        $orderQuery = CurrencyExchangeOrder::find()
            ->where([
                'user_id' => $user->id,
            ])
            ->orderBy([
                'status' => SORT_DESC,
                'selling_currency_id' => SORT_ASC,
                'buying_currency_id' => SORT_ASC,
            ]);

        $aordersCount = $orderQuery->count();

        $pagination = new Pagination([
            'totalCount' => $aordersCount,
            'pageSize' => 9,
            'params' => [
                'page' => $page,
            ],
            'pageSizeParam' => false,
            'validatePage' => true,
        ]);

        $orders = $orderQuery->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();

        $buttons = array_map(function ($order) {
            return [
                [
                    'text' => ($order->isActive() ? '' : Emoji::INACTIVE . ' ') . $order->getTitle(),
                    'callback_data' => self::createRoute('view', [
                        'id' => $order->id,
                    ]),
                ],
            ];
        }, $orders);

        $buttons[] = PaginationButtons::build($pagination, function ($page) {
            return self::createRoute('index', [
                'page' => $page,
            ]);
        });

        $rowButtons[] = [
            'callback_data' => ServicesController::createRoute(),
            'text' => Emoji::BACK,
        ];

        $rowButtons[] = [
            'callback_data' => MenuController::createRoute(),
            'text' => Emoji::MENU,
        ];

        $rowButtons[] = [
            'callback_data' => self::createRoute('dev-index'),
            'text' => Emoji::DEVELOPMENT,
        ];

        $matchesCount = CurrencyExchangeOrderMatch::find()
            ->joinWith('order')
            ->andWhere([
                CurrencyExchangeOrder::tableName() . '.user_id' => $user->id,
            ])
            ->count();

        if ($matchesCount) {
            $rowButtons[] = [
                'callback_data' => self::createRoute('all-matches'),
                'text' => Emoji::OFFERS . ' ' . $matchesCount,
            ];
        }

        $rowButtons[] = [
            'callback_data' => self::createRoute('create'),
            'text' => Emoji::ADD,
        ];

        $buttons[] = $rowButtons;

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('index'),
                $buttons
            )
            ->build();
    }

    /**
     * @param int|null $id
     *
     * @return array
     */
    public function actionView($id = null)
    {
        $this->getState()->setName();
        $user = $this->getUser();

        if (!$order = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $buttons[] = [
            [
                'text' => $order->isActive() ? Emoji::STATUS_ON . ' ON' : Emoji::STATUS_OFF . ' OFF',
                'callback_data' => self::createRoute('set-status', [
                    'id' => $order->id,
                ]),
            ],
        ];

        $matchesCount = $order->getMatches()->count();

        if ($matchesCount) {
            $buttons[][] = [
                'text' => Emoji::OFFERS . ' ' . $matchesCount,
                'callback_data' => self::createRoute('matches', [
                    'orderId' => $order->id,
                ]),
            ];
        }

        $buttons[] = [
            [
                'text' => $order->getTitle() . ': ' . ($order->cross_rate_on ? Yii::t('bot', 'Cross rate') : (float)$order->selling_rate),
                'callback_data' => self::createRoute('e-a', [
                    'id' => $order->id,
                    'a' => 'selling_rate',
                ]),
            ],
        ];

        $buttons[] = [
            [
                'text' => $order->getReverseTitle() . ': ' . ($order->cross_rate_on ? Yii::t('bot', 'Cross rate') : (float)$order->buying_rate),
                'callback_data' => self::createRoute('e-a', [
                    'id' => $order->id,
                    'a' => 'buying_rate',
                ]),
            ],
        ];

        $buttons[] = [
            [
                'text' => Emoji::BACK,
                'callback_data' => self::createRoute('index'),
            ],
            [
                'text' => Emoji::MENU,
                'callback_data' => MenuController::createRoute(),
            ],
            [
                'text' => Emoji::EDIT,
                'callback_data' => self::createRoute('update', [
                    'id' => $order->id,
                ]),
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('view', [
                    'model' => $order,
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * @param int|null $id
     *
     * @return array
     */
    public function actionUpdate($id = null)
    {
        $this->getState()->setName();

        if (!$model = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $buttons[] = [
            [
                'text' => $model->sellingCurrency->code,
                'callback_data' => self::createRoute('update-2-1', [
                    'id' => $model->id,
                ]),
            ],
            [
                'text' => $model->buyingCurrency->code,
                'callback_data' => self::createRoute('update-2-2', [
                    'id' => $model->id,
                ]),
            ],
        ];

        if ($model->selling_cash_on || $model->buying_cash_on) {
            $buttons[] = [
                [
                    'text' => Yii::t('bot', $model->getAttributeLabel('location')),
                    'callback_data' => self::createRoute('e-a', [
                        'id' => $model->id,
                        'a' => 'location',
                    ]),
                ],
            ];

            $buttons[] = [
                [
                    'text' => Yii::t('bot', $model->getAttributeLabel('delivery_radius')),
                    'callback_data' => self::createRoute('e-a', [
                        'id' => $model->id,
                        'a' => 'delivery_radius',
                    ]),
                ],
            ];
        }

        $buttons[] = [
            [
                'text' => Emoji::BACK,
                'callback_data' => self::createRoute('view', [
                    'id' => $model->id,
                ]),
            ],
            [
                'text' => Emoji::DELETE,
                'callback_data' => self::createRoute('d', [
                    'id' => $model->id,
                ]),
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('view', [
                    'model' => $model,
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * @param int|null $id
     *
     * @return array
     */
    public function actionUpdate21($id = null)
    {
        $this->getState()->setName();
        $user = $this->getUser();

        if (!$order = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $buttons[] = [
            [
                'text' => Yii::t('bot', $order->getAttributeLabel('selling_currency_min_amount')) . ': ' . $order->getSellingCurrencyMinAmount(),
                'callback_data' => self::createRoute('e-a', [
                    'id' => $order->id,
                    'a' => 'selling_currency_min_amount',
                ]),
            ],
        ];

        $buttons[] = [
            [
                'text' => Yii::t('bot', $order->getAttributeLabel('selling_currency_max_amount')) . ': ' . $order->getSellingCurrencyMaxAmount(),
                'callback_data' => self::createRoute('e-a', [
                    'id' => $order->id,
                    'a' => 'selling_currency_max_amount',
                ]),
            ],
        ];

        $buttons[] = [
            [
                'text' => Yii::t('bot', $order->getAttributeLabel('selling_cash_on')),
                'callback_data' => self::createRoute('e-a', [
                    'id' => $order->id,
                    'a' => 'selling_cash_on',
                ]),
            ],
        ];

        $buttons[] = [
            [
                'text' => Yii::t('bot', 'Payment methods'),
                'callback_data' => self::createRoute('e-a', [
                    'id' => $order->id,
                    'a' => 'sellingPaymentMethods',
                ]),
            ],
        ];

        $buttons[] = [
            [
                'text' => Emoji::BACK,
                'callback_data' => self::createRoute('update', [
                    'id' => $order->id,
                ]),
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('view', [
                    'model' => $order,
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * @param int|null $id
     *
     * @return array
     */
    public function actionUpdate22($id = null)
    {
        $this->getState()->setName();
        $user = $this->getUser();

        if (!$order = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $buttons[] = [
            [
                'text' => Yii::t('bot', $order->getAttributeLabel('buying_cash_on')),
                'callback_data' => self::createRoute('e-a', [
                    'id' => $order->id,
                    'a' => 'buying_cash_on',
                ]),
            ],
        ];

        $buttons[] = [
            [
                'text' => Yii::t('bot', 'Payment methods'),
                'callback_data' => self::createRoute('e-a', [
                    'id' => $order->id,
                    'a' => 'buyingPaymentMethods',
                ]),
            ],
        ];

        $buttons[] = [
            [
                'text' => Emoji::BACK,
                'callback_data' => self::createRoute('update', [
                    'id' => $order->id,
                ]),
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('view', [
                    'model' => $order,
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionDevIndex()
    {
        $telegramUser = $this->getTelegramUser();

        //TODO PaginationButtons for orders

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('dev-index'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order'),
                            'text' => 'USD/THB',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order'),
                            'text' => 'USD/RUB',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order'),
                            'text' => Emoji::INACTIVE . ' ' . 'THB/RUB',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('offer'),
                            'text' => '<',
                        ],
                        [
                            'callback_data' => self::createRoute('offer'),
                            'text' => '1/3',
                        ],
                        [
                            'callback_data' => self::createRoute('offer'),
                            'text' => '>',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute(),
                            'text' => Emoji::BACK,
                        ],
                        [
                            'callback_data' => MenuController::createRoute(),
                            'text' => Emoji::MENU,
                        ],
                        [
                            'callback_data' => self::createRoute('offer'),
                            'text' => Emoji::OFFERS . ' ' . '3',
                        ],
                        [
                            'callback_data' => self::createRoute('order-create'),
                            'text' => Emoji::ADD,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrderCreate($step = 1)
    {
        //TODO make steps to create a order (maybe in separate actions)
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order-create'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-create'),
                            'text' => 'USD',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-create'),
                            'text' => 'THB',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-create'),
                            'text' => '<',
                        ],
                        [
                            'callback_data' => self::createRoute('order-create'),
                            'text' => '1/3',
                        ],
                        [
                            'callback_data' => self::createRoute('order-create'),
                            'text' => '>',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute(),
                            'text' => Emoji::BACK,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrder()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-status'),
                            'text' => 'Status: ON',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('offer'),
                            'text' => Emoji::OFFERS . ' ' . '3',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-rate'),
                            'text' => 'USD/THB: 30.0000',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-buying-rate'),
                            'text' => 'THB/USD: 0.3000',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute(),
                            'text' => Emoji::BACK,
                        ],
                        [
                            'callback_data' => MenuController::createRoute(),
                            'text' => Emoji::MENU,
                        ],
                        [
                            'callback_data' => self::createRoute('order-edit'),
                            'text' => Emoji::EDIT,
                        ],
                        [
                            'callback_data' => self::createRoute('order-remove'),
                            'text' => Emoji::DELETE,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrderStatus()
    {
        return $this->actionOrder();
    }

    /**
     * @return array
     */
    public function actionOrderEdit()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order-edit'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency'),
                            'text' => 'USD',
                        ],
                        [
                            'callback_data' => self::createRoute('order-buying-currency'),
                            'text' => 'THB',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency'),
                            'text' => 'Name',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-location'),
                            'text' => 'Location',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-delivery-radius'),
                            'text' => 'Delivery radius',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order'),
                            'text' => Emoji::BACK,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrderRemove()
    {
        return $this->actionIndex();
    }

    /**
     * @return array
     */
    public function actionOrderSellingRate()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order-selling-rate'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-rate'),
                            'text' => Yii::t('bot', 'Cross rate'),
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order'),
                            'text' => Emoji::BACK,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrderBuyingRate()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order-selling-rate'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-buying-rate'),
                            'text' => Yii::t('bot', 'Cross rate'),
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order'),
                            'text' => Emoji::BACK,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrderSellingCurrency()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order-selling-currency'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency'),
                            'text' => 'Min. amount: ∞',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency'),
                            'text' => 'Max. amount: 100.00',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-methods'),
                            'text' => 'Payment methods',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-edit'),
                            'text' => Emoji::BACK,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrderBuyingCurrency()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order-buying-currency'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-methods'),
                            'text' => 'Payment methods',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-edit'),
                            'text' => Emoji::BACK,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOffer()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('offer'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('offer-like'),
                            'text' => '👍 100',
                        ],
                        [
                            'callback_data' => self::createRoute('offer-like'),
                            'text' => '👎 10',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('offer'),
                            'text' => '<',
                        ],
                        [
                            'callback_data' => self::createRoute('offer'),
                            'text' => '1/3',
                        ],
                        [
                            'callback_data' => self::createRoute('offer'),
                            'text' => '>',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute(),
                            'text' => Emoji::BACK,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrderSellingCurrencyPaymentMethods()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order-selling-currency-payment-methods'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-method'),
                            'text' => 'Cash',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-method'),
                            'text' => 'Online System 1',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-method'),
                            'text' => 'Online System 2',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-method'),
                            'text' => 'Bank 1',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-method'),
                            'text' => 'Bank 2',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency'),
                            'text' => Emoji::BACK,
                        ],
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-method-add'),
                            'text' => Emoji::ADD,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrderSellingCurrencyPaymentMethod()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order-selling-currency-payment-method'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-methods'),
                            'text' => Emoji::BACK,
                        ],
                        [
                            'callback_data' => self::createRoute('order-selling-currency-payment-method'),
                            'text' => Emoji::DELETE,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionNoRequirements()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('no-requirements'),
                [
                    [
                        [
                            'callback_data' => ServicesController::createRoute(),
                            'text' => Emoji::BACK,
                        ],
                        [
                            'callback_data' => MenuController::createRoute(),
                            'text' => Emoji::MENU,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionOrderLocation()
    {
        //TODO save any location that will be sent
        $telegramUser = $this->getTelegramUser();

        if ($telegramUser->location_lat && $telegramUser->location_lon) {
            return $this->getResponseBuilder()
                ->sendLocation(
                    $telegramUser->location_lat,
                    $telegramUser->location_lon
                )
                ->editMessageTextOrSendMessage(
                    $this->render('order-location'),
                    [
                        [
                            [
                                'callback_data' => self::createRoute('order-edit'),
                                'text' => Emoji::BACK,
                            ],
                        ],
                    ]
                )
                ->build();
        }
    }

    /**
     * @return array
     */
    public function actionOrderDeliveryRadius()
    {
        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('order-delivery-radius'),
                [
                    [
                        [
                            'callback_data' => self::createRoute('order-edit'),
                            'text' => Emoji::BACK,
                        ],
                        [
                            'callback_data' => MenuController::createRoute(),
                            'text' => Emoji::MENU,
                        ],
                    ],
                ]
            )
            ->build();
    }

    /**
     * {@inheritdoc}
     */
    public function actionMatches($orderId, $page = 1)
    {
        $user = $this->getUser();

        $order = $user->getCurrencyExchangeOrders()
            ->where([
                'user_id' => $user->id,
                'id' => $orderId,
            ])
            ->one();

        if (!isset($order)) {
            return [];
        }

        $matchesQuery = $order->getMatches();
        $matchesCount = $matchesQuery->count();

        if (!$matchesCount) {
            return $this->actionView($order->id);
        }

        $pagination = new Pagination([
            'totalCount' => $matchesQuery->count(),
            'pageSize' => 1,
            'params' => [
                'page' => $page,
            ],
            'pageSizeParam' => false,
            'validatePage' => true,
        ]);

        $matchOrder = $matchesQuery->offset($pagination->offset)
            ->limit($pagination->limit)
            ->one();

        $buttons[] = [
            [
                'text' => $order->getTitle(),
                'callback_data' => self::createRoute('view', [
                    'id' => $order->id,
                ]),
            ]
        ];

        $buttons[] = PaginationButtons::build($pagination, function ($page) use ($orderId) {
            return self::createRoute('matches', [
                'orderId' => $orderId,
                'page' => $page,
            ]);
        });

        $buttons[] = [
            [
                'callback_data' => self::createRoute('view', [
                    'id' => $order->id,
                ]),
                'text' => Emoji::BACK,
            ],
            [
                'callback_data' => MenuController::createRoute(),
                'text' => Emoji::MENU,
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('match', [
                    'model' => $matchOrder,
                    'user' => TelegramUser::findOne(['user_id' => $matchOrder->user_id]),
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * {@inheritdoc}
     */
    public function actionAllMatches($page = 1)
    {
        $user = $this->getUser();

        $matchesQuery = CurrencyExchangeOrderMatch::find()
            ->joinWith('order')
            ->andWhere([
                CurrencyExchangeOrder::tableName() . '.user_id' => $user->id,
            ]);

        $matchesCount = $matchesQuery->count();

        if (!$matchesCount) {
            return $this->actionIndex();
        }

        $pagination = new Pagination([
            'totalCount' => $matchesQuery->count(),
            'pageSize' => 1,
            'params' => [
                'page' => $page,
            ],
            'pageSizeParam' => false,
            'validatePage' => true,
        ]);

        $currencyExchangeOrderMatch = $matchesQuery->offset($pagination->offset)
            ->limit($pagination->limit)
            ->one();
        $order = $currencyExchangeOrderMatch->order;
        $matchOrder = $currencyExchangeOrderMatch->matchOrder;

        $buttons[] = [
            [
                'text' => $order->getTitle(),
                'callback_data' => self::createRoute('view', [
                    'id' => $order->id,
                ]),
            ]
        ];

        $buttons[] = PaginationButtons::build($pagination, function ($page) {
            return self::createRoute('all-matches', [
                'page' => $page,
            ]);
        });

        $buttons[] = [
            [
                'callback_data' => self::createRoute('index'),
                'text' => Emoji::BACK,
            ],
            [
                'callback_data' => MenuController::createRoute(),
                'text' => Emoji::MENU,
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('match', [
                    'model' => $matchOrder,
                    'user' => TelegramUser::findOne(['user_id' => $matchOrder->user_id]),
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * Delete model
     *
     * @param int|null $id
     */
    public function actionD($id = null)
    {
        $user = $this->getUser();

        if (!$order = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $order->delete();

        return $this->actionIndex();
    }

    /**
     * @param int|null $id
     *
     * @return array
     */
    public function actionSetStatus($id = null)
    {
        $user = $this->getUser();

        if (!$order = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $this->backRoute->make('view', compact('id'));
        $this->endRoute->make('view', compact('id'));

        if (!$order->isActive() && ($notFilledFields = $order->notPossibleToChangeStatus())) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery(
                    $this->render('status-error', compact('notFilledFields')),
                    true
                )
                ->build();
        }

        $order->setAttributes([
            'status' => ($order->isActive() ? CurrencyExchangeOrder::STATUS_OFF : CurrencyExchangeOrder::STATUS_ON),
        ]);

        $order->save();

        return $this->actionView($order->id);
    }

    /**
     * @param int $id
     *
     * @return ActiveRecord|null
     */
    public function getModel($id = null)
    {
        if ($id) {
            $user = $this->getUser();

            if ($model = $user->getCurrencyExchangeOrder($id)) {
                return $model;
            }
        }

        return null;
    }
}
