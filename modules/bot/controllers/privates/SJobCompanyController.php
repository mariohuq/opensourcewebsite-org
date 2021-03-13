<?php

namespace app\modules\bot\controllers\privates;

use Yii;
use app\modules\bot\components\crud\CrudController;
use app\models\Company;
use app\models\CompanyUser;
use app\modules\bot\components\helpers\Emoji;
use app\modules\bot\components\helpers\PaginationButtons;
use app\modules\bot\components\crud\rules\CurrentUserFieldComponent;
use yii\base\DynamicModel;
use yii\data\Pagination;
use yii\db\ActiveRecord;

/**
 * Class SJobCompanyController
 *
 * @package app\modules\bot\controllers\privates
 */
class SJobCompanyController extends CrudController
{
    protected $updateAttributes = [
        'name',
        'description',
        'address',
        'url',
    ];

    /** @inheritDoc */
    protected function rules()
    {
        return [
            'model' => Company::class,
            'relation' => [
                'model' => CompanyUser::class,
                'attributes' => [
                    'company_id' => [Company::class, 'id'],
                    'user_id' => [DynamicModel::class, 'id'],
                ],
                'component' => [
                    'class' => CurrentUserFieldComponent::class,
                ],
            ],
            'attributes' => [
                'name' => [],
                'description' => [
                    'isRequired' => false,
                ],
                'address' => [
                    'isRequired' => false,
                ],
                'url' => [
                    'isRequired' => false,
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

        $companiesCount = $user->getCompanies()->count();

        $pagination = new Pagination([
            'totalCount' => $companiesCount,
            'pageSize' => 9,
            'params' => [
                'page' => $page,
            ],
            'pageSizeParam' => false,
            'validatePage' => true,
        ]);

        $companies = $user->getCompanies()
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();

        $buttons = array_map(function ($company) {
            return [
                [
                    'text' => $company->name,
                    'callback_data' => self::createRoute('view', [
                        'id' => $company->id,
                    ]),
                ],
            ];
        }, $companies);

        $buttons[] = PaginationButtons::build($pagination, function ($page) {
            return self::createRoute('index', [
                'page' => $page,
            ]);
        });

        $buttons[] = [
            [
                'text' => Emoji::BACK,
                'callback_data' => SJobController::createRoute(),
            ],
            [
                'text' => Emoji::MENU,
                'callback_data' => MenuController::createRoute(),
            ],
            [
                'text' => Emoji::ADD,
                'callback_data' => self::createRoute('create'),
            ],
        ];

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

        if (!$company = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('view', [
                    'model' => $company,
                ]),
                [
                    [
                        [
                            'text' => Emoji::JOB_VACANCY . ' ' . Yii::t('bot', 'Vacancies'),
                            'callback_data' => SJobVacancyController::createRoute('index', [
                                'companyId' => $company->id,
                            ]),
                        ],
                    ],
                    [
                        [
                            'text' => Emoji::BACK,
                            'callback_data' => self::createRoute(),
                        ],
                        [
                            'text' => Emoji::MENU,
                            'callback_data' => MenuController::createRoute(),
                        ],
                        [
                            'text' => Emoji::EDIT,
                            'callback_data' => self::createRoute('update', [
                                'id' => $company->id,
                            ]),
                        ],
                    ],
                ],
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

        if (!$company = $this->getModel($id)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $company->delete();

        return $this->actionIndex();
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

            if ($model = $user->getCompany($id)) {
                return $model;
            }
        }

        return null;
    }
}
