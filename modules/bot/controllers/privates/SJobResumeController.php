<?php

namespace app\modules\bot\controllers\privates;

use Yii;
use app\modules\bot\components\crud\CrudController;
use app\behaviors\SetAttributeValueBehavior;
use app\behaviors\SetDefaultCurrencyBehavior;
use app\models\Currency;
use app\models\Resume;
use app\models\User;
use app\models\JobResumeMatch;
use app\models\JobKeyword;
use app\models\JobResumeKeyword;
use app\modules\bot\components\crud\rules\ExplodeStringFieldComponent;
use app\modules\bot\components\crud\rules\LocationToArrayFieldComponent;
use app\modules\bot\components\helpers\ExternalLink;
use app\modules\bot\components\helpers\PaginationButtons;
use app\modules\bot\components\helpers\ListButtons;
use app\modules\bot\models\User as TelegramUser;
use app\modules\bot\components\helpers\Emoji;
use yii\data\Pagination;
use yii\db\ActiveRecord;
use yii\db\StaleObjectException;

/**
 * Class SJobResumeController
 *
 * @package app\modules\bot\controllers\privates
 */
class SJobResumeController extends CrudController
{
    protected $updateAttributes = [
        'name',
        'skills',
        'experiences',
        'expectations',
        'keywords',
        'min_hourly_rate',
        'remote_on',
        'location',
        'search_radius',
    ];

    /**
     * {@inheritdoc}
     */
    protected function rules()
    {
        return [
            'model' => Resume::class,
            'attributes' => [
                'name' => [],
                'skills' => [
                    'isRequired' => false,
                ],
                'experiences' => [
                    'isRequired' => false,
                ],
                'expectations' => [
                    'isRequired' => false,
                ],
                'keywords' => [
                    //'enableAddButton' = true,
                    'isRequired' => false,
                    'relation' => [
                        'model' => JobResumeKeyword::class,
                        'attributes' => [
                            'resume_id' => [Resume::class, 'id'],
                            'job_keyword_id' => [JobKeyword::class, 'id', 'keyword'],
                        ],
                        'removeOldRows' => true,
                    ],
                    'component' => [
                        'class' => ExplodeStringFieldComponent::class,
                        'attributes' => [
                            'delimiters' => [',', '.', "\n"],
                        ],
                    ],
                ],
                'currency' => [
                    'hidden' => true,
                    'behaviors' => [
                        'SetDefaultCurrencyBehavior' => [
                            'class' => SetDefaultCurrencyBehavior::class,
                            'telegramUser' => $this->getTelegramUser(),
                            'attributes' => [
                                ActiveRecord::EVENT_BEFORE_VALIDATE => ['currency_id'],
                                ActiveRecord::EVENT_BEFORE_INSERT => ['currency_id'],
                            ],
                        ],
                    ],
                    'relation' => [
                        'attributes' => [
                            'currency_id' => [Currency::class, 'id', 'code'],
                        ],
                    ],
                ],
                'min_hourly_rate' => [
                    'isRequired' => false,
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'Edit currency'),
                            'item' => 'currency',
                        ],
                    ],
                ],
                'remote_on' => [
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'YES'),
                            'callback' => function (Resume $model) {
                                $model->remote_on = Resume::REMOTE_ON;

                                return $model;
                            },
                        ],
                        [
                            'text' => Yii::t('bot', 'NO'),
                            'callback' => function (Resume $model) {
                                $model->remote_on = Resume::REMOTE_OFF;

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
                            'callback' => function (Resume $model) {
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
                'search_radius' => [
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'NO'),
                            'callback' => function (Resume $model) {
                                $model->search_radius = 0;

                                return $model;
                            },
                        ],
                    ],
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

        $resumesCount = $user->getResumes()->count();

        $pagination = new Pagination([
            'totalCount' => $resumesCount,
            'pageSize' => 9,
            'params' => [
                'page' => $page,
            ],
            'pageSizeParam' => false,
            'validatePage' => true,
        ]);

        $resumes = $user->getResumes()
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();

        $buttons = array_map(function ($resume) {
            return [
                [
                    'text' => ($resume->isActive() ? '' : Emoji::INACTIVE . ' ') . $resume->name,
                    'callback_data' => self::createRoute('view', [
                        'id' => $resume->id,
                    ]),
                ],
            ];
        }, $resumes);

        $buttons[] = PaginationButtons::build($pagination, function ($page) {
            return self::createRoute('index', [
                'page' => $page,
            ]);
        });

        $rowButtons[] = [
            'callback_data' => SJobController::createRoute(),
            'text' => Emoji::BACK,
        ];

        $rowButtons[] = [
            'callback_data' => MenuController::createRoute(),
            'text' => Emoji::MENU,
        ];

        $matchesCount = JobResumeMatch::find()
            ->joinWith('resume')
            ->andWhere([
                Resume::tableName() . '.user_id' => $user->id,
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
     * @param ActiveRecord[] $keywords
     *
     * @return string
     */
    private static function getKeywordsAsString($keywords)
    {
        $resultKeywords = [];

        foreach ($keywords as $keyword) {
            $resultKeywords[] = $keyword->keyword;
        }

        return implode(', ', $resultKeywords);
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

        if (!$resume = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $buttons[] = [
            [
                'text' => $resume->isActive() ? Emoji::STATUS_ON . ' ON' : Emoji::STATUS_OFF . ' OFF',
                'callback_data' => self::createRoute('set-status', [
                    'id' => $resume->id,
                ]),
            ],
        ];

        $matchesCount = $resume->getMatches()->count();

        if ($matchesCount) {
            $buttons[][] = [
                'callback_data' => self::createRoute('matches', [
                    'id' => $resume->id,
                ]),
                'text' => Emoji::OFFERS . ' ' . $matchesCount,
            ];
        }

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
                    'id' => $resume->id,
                ]),
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('view', [
                    'model' => $resume,
                    'keywords' => self::getKeywordsAsString($resume->getKeywordsRelation()->all()),
                    'locationLink' => ExternalLink::getOSMLink($resume->location_lat, $resume->location_lon),
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * @param int $id
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

        $buttons = array_map(function (string $attribute) use ($id, $model) {
            return [
                [
                    'text' => Yii::t('bot', $model->getAttributeLabel($attribute)),
                    'callback_data' => self::createRoute('e-a', [
                        'id' => $id,
                        'a' => $attribute,
                    ]),
                ],
            ];
        }, $this->updateAttributes);

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
                $this->render('view',[
                    'model' => $model,
                    'keywords' => self::getKeywordsAsString($model->getKeywordsRelation()->all()),
                    'locationLink' => ExternalLink::getOSMLink($model->location_lat, $model->location_lon),
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
    public function actionMatches($id, $page = 1)
    {
        $user = $this->getUser();

        $resume = $user->getResumes()
            ->where([
                'user_id' => $user->id,
                'id' => $id,
            ])
            ->one();

        if (!isset($resume)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $matchesQuery = $resume->getMatches();
        $matchesCount = $matchesQuery->count();

        if (!$matchesCount) {
            return $this->actionView($resume->id);
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

        $vacancy = $matchesQuery->offset($pagination->offset)
            ->limit($pagination->limit)
            ->one();

        $buttons[] = [
            [
                'text' => $resume->name,
                'callback_data' => self::createRoute('view', [
                    'id' => $resume->id,
                ]),
            ]
        ];

        $buttons[] = PaginationButtons::build($pagination, function ($page) use ($id) {
            return self::createRoute('matches', [
                'id' => $id,
                'page' => $page,
            ]);
        });

        $buttons[] = [
            [
                'callback_data' => self::createRoute('view', [
                    'id' => $resume->id,
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
                    'model' => $vacancy,
                    'company' => $vacancy->company,
                    'keywords' => self::getKeywordsAsString($vacancy->getKeywordsRelation()->all()),
                    'locationLink' => ExternalLink::getOSMLink($vacancy->location_lat, $vacancy->location_lon),
                    'languages' => array_map(function ($vacancyLanguage) {
                        return $vacancyLanguage->getLabel();
                    }, $vacancy->vacancyLanguagesRelation),
                    'user' => TelegramUser::findOne(['user_id' => $vacancy->user_id]),
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

        $matchesQuery = JobResumeMatch::find()
            ->joinWith('resume')
            ->andWhere([
                Resume::tableName() . '.user_id' => $user->id,
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

        $jobResumeMatch = $matchesQuery->offset($pagination->offset)
            ->limit($pagination->limit)
            ->one();
        $resume = $jobResumeMatch->resume;
        $vacancy = $jobResumeMatch->vacancy;

        $buttons[] = [
            [
                'text' => $resume->name,
                'callback_data' => self::createRoute('view', [
                    'id' => $resume->id,
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
                    'model' => $vacancy,
                    'company' => $vacancy->company,
                    'keywords' => self::getKeywordsAsString($vacancy->getKeywordsRelation()->all()),
                    'locationLink' => ExternalLink::getOSMLink($vacancy->location_lat, $vacancy->location_lon),
                    'languages' => array_map(function ($vacancyLanguage) {
                        return $vacancyLanguage->getLabel();
                    }, $vacancy->vacancyLanguagesRelation),
                    'user' => TelegramUser::findOne(['user_id' => $vacancy->user_id]),
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

        if (!$resume = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $resume->delete();

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

        if (!$resume = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $this->backRoute->make('view', compact('id'));
        $this->endRoute->make('view', compact('id'));

        if (!$resume->isActive() && ($notFilledFields = $resume->notPossibleToChangeStatus())) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery(
                    $this->render('status-error', compact('notFilledFields')),
                    true
                )
                ->build();
        }

        $resume->setAttributes([
            'status' => ($resume->isActive() ? Resume::STATUS_OFF : Resume::STATUS_ON),
        ]);

        $resume->save();

        return $this->actionView($resume->id);
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

            if ($model = $user->getResume($id)) {
                return $model;
            }
        }

        return null;
    }
}
