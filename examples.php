<?php

namespace Test;

use \Bitrix\Highloadblock\HighloadBlockTable,
    \Bitrix\Main\Loader,
    \Bitrix\Main,
    \Bitrix\Main\Localization\Loc as Loc,
    \Bitrix\Main\Config\Option,
    \Bitrix\Main\Context,
    \Bitrix\Main\Application;


/**
 * Пример 1. Работа с highload
 * Класс-помошник(пару методов) из модуля, который используется на сайте-конструкторе,
 * где можно создать свой сайт. Настройки этих сайтов хранятся в highload блоке.
 *
 */
class Helper
{
    public static $settingsHBlockID = xxx; // Id Highload с настройками

     /**
     * Returns site info of users
     * 
     * @param array $users user ids
     * 
     * @return array
     */
    public static function getSubscribeProducts($users)
    {
        $entity_data_class = self::getHBlockClassById(self::$settingsHBlockID);
        $rsData = $entity_data_class::getList(array(
            "select" => array("UF_TIME", "UF_PAYED_TO", "UF_ADMIN_ID"),
            "order" => array("ID" => "ASC"),
            "filter" => array("UF_ADMIN_ID" => $users)
        ));

        $data = [];

        while ($arData = $rsData->Fetch()) {
            $el = [];
            $el['USER_ID'] = $arData['UF_ADMIN_ID'];
            //...
            $data[] = $el;
        }

        if (empty($data)) return false;
        return $data;
    }

    /**
     * Gets highloadblock class by id of a table
     * 
     * @param integer $id
     * 
     * @return object
     */
    public static function getHBlockClassById($id)
    {
        Loader::includeModule("highloadblock");
        Loader::includeModule("iblock");

        $hlblock = HighloadBlockTable::getById($id)->fetch();
        $entity = HighloadBlockTable::compileEntity($hlblock);

        return $entity->getDataClass();
    }
}


/**
 * Пример 2. Работа с orm
 * 
 * Нужно было объединить данные из нескольких инфоблоков в одну таблицу + кастомный фильтр
 */
class CustomListComponent extends CBitrixComponent
{
    public function onPrepareComponentParams($arParams): array {}
    public function executeComponent() {
        //...
        $this->fetchList();
        //...
    }

    public function fetchList()
    {
        $filter = $this->getFilter();

        switch ($this->arParams['LIST_TYPE']) {
            case 'PERSPECTIVE':
                $this->fetchPerspective($filter);
                break;
            //...
            default:
                return false;
        }
    }
    public function fetchPerspective($filter)
    {
        $answer = &$this->arResult;
        Loader::includeModule("highloadblock");
        Loader::includeModule("iblock");
        $hlblock = HighloadBlockTable::getList(
            [
                'filter' => ['=NAME' => 'xxx']
            ]
        )->fetch();
        $entityEPV = HighloadBlockTable::compileEntity($hlblock);

        $hlblock = HighloadBlockTable::getList(
            [
                'filter' => ['=NAME' => 'xxx']
            ]
        )->fetch();
        $entityEP = HighloadBlockTable::compileEntity($hlblock);

        $propFilter = ['LOGIC' => 'OR'];
        $filterHasProps = false;
        foreach ($filter['ITEMS'] as $item) {
            $itemFilter = ['LOGIC' => 'AND'];
            foreach ($item['PROPERTIES'] as $prop) {
                $sign = '=';
                switch ($prop['VALUE']['SIGN']) {
                    case 'more':
                        $sign = '>';
                        break;
                    case 'less':
                        $sign = '<';
                        break;
                    default:
                        break;
                }
                if ($prop['VALUE']['SIGN'] == 'range' && is_numeric($prop['VALUE']['VAL_FROM']) && is_numeric($prop['VALUE']['VAL_TO'])) {
                    $itemFilter[] = [
                        '=PROP.ID' => $prop['ID'],
                        [
                            'LOGIC' => 'OR',
                            [
                                'LOGIC' => 'AND',
                                ['>=PROP_VALUE.UF_VALUE_MIN' => $prop['VALUE']['VAL_FROM']],
                                ['<=PROP_VALUE.UF_VALUE_MIN' => $prop['VALUE']['VAL_TO']],
                            ],
                            [
                                'LOGIC' => 'AND',
                                ['>=PROP_VALUE.UF_VALUE_MAX' => $prop['VALUE']['VAL_FROM']],
                                ['<=PROP_VALUE.UF_VALUE_MAX' => $prop['VALUE']['VAL_TO']],
                            ],
                            [
                                'LOGIC' => 'AND',
                                ['>=PROP_VALUE.UF_VALUE' => $prop['VALUE']['VAL_FROM']],
                                ['<=PROP_VALUE.UF_VALUE' => $prop['VALUE']['VAL_TO']],
                            ],
                        ]
                    ];
                }
                else {
                    $itemFilter[] = [
                        '=PROP.ID' => $prop['ID'],
                        [
                            'LOGIC' => 'AND',
                            [
                                'LOGIC' => 'OR',
                                [
                                    'LOGIC' => 'AND',
                                    $sign.'PROP_VALUE.UF_VALUE_MIN' => $prop['VALUE']['VAL'],
                                    '!PROP_VALUE.UF_VALUE_MIN' => false
                                ],
                                [
                                    'LOGIC' => 'AND',
                                    $sign.'PROP_VALUE.UF_VALUE_MAX' => $prop['VALUE']['VAL'],
                                    '!PROP_VALUE.UF_VALUE_MAX' => false
                                ],
                                [
                                    'LOGIC' => 'AND',
                                    $sign.'PROP_VALUE.UF_VALUE' => $prop['VALUE']['VAL'],
                                    '!PROP_VALUE.UF_VALUE' => false
                                ],
                            ]
                        ]
                    ];
                }
                if (!$filterHasProps) $filterHasProps = true;
            }
            $propFilter[] = $itemFilter;
        }


        /* Получаем фильтр для подразделов */
        $topSections = \Bitrix\Iblock\SectionTable::getList(array(
            'filter' => array(
                'IBLOCK_ID' => IBLOCK_ID_EQUIPMENT,
                'ACTIVE' => 'Y',
                '=ID' => $filter['SECTIONS'],
            ),
            'select' =>  array(
                'ID',
                'LEFT_MARGIN',
                'RIGHT_MARGIN',
                'DEPTH_LEVEL',
            ),
        ));
        $sectionFilter = [
            'LOGIC' => 'OR'
        ];
        foreach ($filter['SECTIONS'] as $section) {
            $sectionFilter[] = ['=IBLOCK_SECTION.ID' => $section];
        }
        while ($arSection = $topSections->fetch()) {
            $sectionFilter[] = [
                '>IBLOCK_SECTION.LEFT_MARGIN' => $arSection['LEFT_MARGIN'],
                '<IBLOCK_SECTION.RIGHT_MARGIN' => $arSection['RIGHT_MARGIN'],
                '>=IBLOCK_SECTION.DEPTH_LEVEL' => $arSection['DEPTH_LEVEL'],
            ];
        }

        $projectClass = \Bitrix\Iblock\Iblock::wakeUp(IBLOCK_ID_SHIP)->getEntityDataClass();
        $projectQuery = new \Bitrix\Main\Entity\Query($projectClass);
        $projectQuery->setSelect(array('ID', 'EQ_VAL' => 'EQUIPMENT.VALUE', 'NAME'));
        $projectQuery->setFilter(array('ACTIVE' => 'Y', '!EQUIPMENT.VALUE' => false, 'IBLOCK_ID' => IBLOCK_ID_SHIP));

        $nav = new \Bitrix\Main\UI\PageNavigation("nav");
        $nav->allowAllRecords(true)
            ->setPageSize($this->arParams['ELEMENTS_PER_PAGE'])
            ->initFromUri();


        $finalFilter = [
            'IBLOCK_ID' => IBLOCK_ID_EQUIPMENT,
            'ACTIVE' => 'Y',
            '!PROJECT.ID' => false,
            $sectionFilter,
            $propFilter
        ];
        if (!empty($filter['PROJECTS'])) {
            $finalFilter['=PROJECT.ID'] = $filter['PROJECTS'];
        }

        $runtime = [
            'PROJECT' => [
                'data_type' => \Bitrix\Main\Entity\Base::getInstanceByQuery($projectQuery),
                'reference' => [
                    '=this.ID' => 'ref.EQ_VAL',
                ],
                'join_type' => 'inner'
            ]
        ];
        if ($filterHasProps) {
            $runtime['PROP_VALUE'] = [
                'data_type' => $entityEPV,
                'reference' => [
                    '=this.ID' => 'ref.UF_ITEM_ID'
                ],
                'join_type' => 'inner'
            ];
            $runtime['PROP'] = [
                'data_type' => $entityEP,
                'reference' => [
                    '=this.PROP_VALUE.UF_EQUIPMENT_PROP' => 'ref.ID',
                ],
                'join_type' => 'inner'
            ];
        }
        $elements = \Bitrix\Iblock\Iblock::wakeUp(IBLOCK_ID_EQUIPMENT)->getEntityDataClass()::getList([
            'select' => ['ID', 'NAME', 'PROJECT_NAME' => 'PROJECT.NAME'],
            'filter' => $finalFilter,
            'cache' => [
                'ttl' => 3600,
                'cache_joins' => true
            ],
            'count_total' => true,
            'offset' => $nav->getOffset(),
            'limit' => $nav->getLimit(),
            'runtime' => $runtime
        ]);
        $nav->setRecordCount($elements->getCount());
        $this->arResult['NAV_OBJECT'] = $nav;

        while ($item = $elements->fetch()) {
            if (empty($answer[$item['ID']])) {
                $line = [
                    'EQUIPMENT_ID' => $item['ID'],
                    'EQUIPMENT_NAME' => $item['NAME'],
                    'PROJECTS' => [
                        0 => [
                            'NAME' => $item['PROJECT_NAME']
                        ]
                    ]
                ];
                $answer[$item['ID']] = $line;
            }
            else {
                $answer[$item['ID']]['PROJECTS'][] = [
                    'NAME' => $item['PROJECT_NAME']
                ];
            }
        }
        unset($line);

        $this->arResult['ITEMS'] = $answer;
    }
}



/**
 * Пример 3. Sale
 * 
 * Часть кода работы с заказами кастомной интеграции с б24 
 * (как пример, что работал с заказами)
 */


if ($_REQUEST['auth']['domain'] == "*" && 
    $_REQUEST['auth']['application_token'] == '*')
    $success_auth = true;
if ($success_auth){

    $rest = new B24RestApi();
    $changes = false;
    $crmId = (int)$_REQUEST['data']['FIELDS']['ID'];

    $params = [
        'order' => ["STAGE_ID" => "ASC"],
        'filter' => ["ID" => $crmId],
        'select' => ["ID", "TITLE", "STAGE_ID", "COMMENTS", "OPPORTUNITY", "CONTACT_ID", "UF_*",]
    ];
    $isOrderExist = $rest->sendQuery("crm.deal.list", $params);

    if (!empty($isOrderExist["result"]) && $isOrderExist["result"][0]['UF_CRM_*']) {
        $crmOrder = $isOrderExist["result"][0];
    }
    else {
        //...
    }

    $realOrderId = (int)$crmOrder['UF_CRM_*'];
    $order = Sale\Order::load($realOrderId);
    $arOrderVals = $order->getFields()->getValues();

    $statuses = [
        //...
    ];

    if ($arOrderVals["STATUS_ID"] != $statuses[$crmOrder["STAGE_ID"]] && isset($statuses[$crmOrder["STAGE_ID"]])){
        $order->setField('STATUS_ID', $statuses[$crmOrder["STAGE_ID"]]);
        $changes = true;
    }

    if ($changes) $order->save(); // Если есть изменения - сохраняем заказ
}