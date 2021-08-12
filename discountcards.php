<?

class DiscountCards
{

    protected $connection;
    protected static $ruleXml = 'discount_program:%:%';
    protected static $cardDescr = 'discount_card';
    protected static $cardsCondition = 'WHERE DiscountType = 0';
    protected $arCards = [];
    protected $arRules = [];
    protected $arCoupons = [];
    protected $arCardToAdd = [];
    protected $arCouponToUpdate = [];
    protected $arCouponsToDelete = [];
    CONST TABLE_NAME = 'loyalty_cards';


    public function __construct()
    {
        $this->connection = \Bitrix\Main\Application::getConnection('extdb');
    }

    public static function getCardDescr()
    {
        return static::$cardDescr;
    }

    public static function getRuleXml()
    {
        return static::$ruleXml;
    }
   
    public static function getCardCondition()
    {
        return static::$cardsCondition ?: '';
    }

    public static function getDiscountCardsRules()
    {
        return array_merge(self::GetRules(), InnerDiscountCards::GetRules());
    }

    public function setRuleForDiscount()
    {
        foreach($this->arCards as &$card){
            foreach($this->arRules as $rule){
                if(($card['TOTAL'] >= $rule['min']) && (($card['TOTAL'] < $rule['max']) || !$rule['max'])){
                    $card['RULE'] = $rule['ID'];
                    break;
                }
            }
        }
    }

    public function siftCards()
    {
        foreach($this->arCards as $id_card => $card){
            if(
                isset($this->arCoupons[$id_card])
                && isset($this->arCoupons[$id_card]['DISCOUNT_ID'])
            ){
                if($this->arCoupons[$id_card]['DISCOUNT_ID'] == $card['RULE']){
                    continue;
                }
                if(isset($card['RULE'])){
                    $this->arCoupons[$id_card]['NEW_DISCOUNT_ID'] = $card['RULE'];
                    $this->arCouponToUpdate[] = $this->arCoupons[$id_card];
                }
            } elseif(
                $card['ID_CARD']
                && $card['RULE']
            ){
                $this->arCardToAdd[] = $card;
            }
        }
    }

    public function getCouponToDelete()
    {
        foreach($this->arCoupons as $coupon){
            if(!isset($this->arCards[$coupon['COUPON']])){
                $this->arCouponsToDelete[] = $coupon;
            }
        }
    }

    public function addNewCoupons()
    {
        foreach($this->arCardToAdd as $card){
            $rsAdd = \Bitrix\Sale\Internals\DiscountCouponTable::add([
                'DISCOUNT_ID' => $card['RULE'],
                'COUPON' => $card['ID_CARD'],
                'TYPE' => \Bitrix\Sale\Internals\DiscountCouponTable::TYPE_MULTI_ORDER,
                'MAX_USE' => 0,
                'USER_ID' => 0,
                'DESCRIPTION' => static::getCardDescr(),
            ]);
            if ($rsAdd->isSuccess())
            {
                file_put_contents(__DIR__ . '/log/RecalcDiscountCards.txt', date("Y-m-d H:i:s") . ' Added: '. $card['ID_CARD'] . "\n", FILE_APPEND);
            } else {
                $errors = $rsAdd->getErrorMessages();
                file_put_contents(__DIR__ . '/log/RecalcDiscountCards.txt', date("Y-m-d H:i:s") . ' ERROR adding: '. $card['ID_CARD'] . ' ' . print_r($errors, true) . "\n", FILE_APPEND);
            }
        }
    }

    public function updateCoupons()
    {
        foreach($this->arCouponToUpdate as $coupon){
            $rsUpdate = \Bitrix\Sale\Internals\DiscountCouponTable::update(
                $coupon['ID'],
                [
                'DISCOUNT_ID' => $coupon['NEW_DISCOUNT_ID'],
            ]);
            if (!$rsUpdate->isSuccess())
            {
                $errors = $rsUpdate->getErrorMessages();
                file_put_contents(__DIR__ . '/log/RecalcDiscountCards.txt', date("Y-m-d H:i:s") . ' ERROR updating: '. $coupon['COUPON'] . ' ' . print_r($errors, true) . "\n", FILE_APPEND);
            } else {
                file_put_contents(__DIR__ . '/log/RecalcDiscountCards.txt', date("Y-m-d H:i:s") . ' Updated: '. $coupon['COUPON'] . ' ' . $coupon['DISCOUNT_ID']. ' -> ' . $coupon['NEW_DISCOUNT_ID'] . "\n", FILE_APPEND);
            }
        }
    }

    public function deleteCoupons()
    {
        foreach($this->arCouponsToDelete as $coupon){
            $rsDelete = \Bitrix\Sale\Internals\DiscountCouponTable::delete($coupon['ID']);
            if (!$rsDelete->isSuccess())
            {
                $errors = $rsDelete->getErrorMessages();
                file_put_contents(__DIR__ . '/log/RecalcDiscountCards.txt', date("Y-m-d H:i:s") . ' ERROR deleting: '. $coupon['COUPON'] . ' ' . print_r($errors, true) . "\n", FILE_APPEND);
            } else {
                file_put_contents(__DIR__ . '/log/RecalcDiscountCards.txt', date("Y-m-d H:i:s") . ' Deleted: '. $coupon['COUPON'] . "\n", FILE_APPEND);
            }
        }
    }

    public function RecalcDiscounts()
    {
        $this->arCards = $this->GetCards();
        if (!$this->arRules = $this->GetRules()) {
            return false;
        }
        $this->arCoupons = $this->GetCoupons();

        // сопоставляем дисконтные карты с уровнями скидки
        $this->setRuleForDiscount();

        // отсеиваем карты, которые уже заведены и у которых уровень скидки установлен верно
        $this->siftCards();

        // выбираем купоны для удаления, которых нет в таблице
        $this->getCouponToDelete();
        
        // добавляем новые карты
        $this->addNewCoupons();

        // обновляем купоны
        $this->updateCoupons();

        // удаляем лишние купоны
        $this->deleteCoupons();
    }

    public static function OnSalePaymentSetFieldHandler(\Bitrix\Main\Event $ENTITY)
    {
        if (is_string($value = $ENTITY->getParameter('VALUE')) && is_string($old_value = $ENTITY->getParameter('OLD_VALUE'))) {
            $orderId = $ENTITY->getParameter('ENTITY')->getField('ORDER_ID');
            $coupon = \Bitrix\Sale\Internals\OrderCouponsTable::getList(array(
                'select' => array('COUPON'),
                'filter' => array('=ORDER_ID' => $orderId)
            ))->fetch()['COUPON'];
            if ($coupon) {
                $description = \Bitrix\Sale\Internals\DiscountCouponTable::getList(array(
                    'select' => array('DESCRIPTION'),
                    'filter' => array('=COUPON' => $coupon)
                    ))->fetch()['DESCRIPTION'];
                    if(static::checkDescription($description)){
                        $price = \Bitrix\Sale\Order::getList(array(
                            'select' => array('PRICE'),
                            'filter' => array('ID' => $orderId)
                        ))->fetch()['PRICE'];
                        if($value == 'Y' && $old_value == 'N'){
                            static::changeSumm($coupon, $price);
                        } elseif($value == 'N' && $old_value == 'Y'){
                            static::changeSumm($coupon, -$price);
                        }
                }
            }
        }
    }

    public static function checkDescription($description)
    {
        return (($description == DiscountCards::getCardDescr()) || ($description == InnerDiscountCards::getCardDescr()));
    }

    public static function changeSumm($id_card, $summ)
    {
        $rs = (new static)->connection->query("
            UPDATE " . static::TABLE_NAME . " SET SUM_IM = IFNULL(SUM_IM, 0) + $summ WHERE ID_CARD = $id_card;
        ");
    }

    public function GetCoupons()
    {
        if (!$description = static::getCardDescr()) {
            return false;
        }
        $rs = \Bitrix\Sale\Internals\DiscountCouponTable::getList([
            'filter' => [
                'DESCRIPTION' => $description
            ]
        ]);
        $arCoupons = [];
        while($coupon = $rs->fetch()){
            $arCoupons[$coupon['COUPON']] = $coupon;
        }
        return $arCoupons;
    }

    public function GetCards()
    {
        $rs = $this->connection->query("
            SELECT `ID_CARD`, `SUM_R22`, `SUM_IM`, (IFNULL(`SUM_R22`, 0) + IFNULL(SUM_IM,0)) as TOTAL
            FROM " . static::TABLE_NAME . "
            " . static::getCardCondition() . "
        ");
        $arDiscCards = [];
        while ($row = $rs->fetch()){
            $arDiscCards[$row['ID_CARD']] = $row;
        }
        return $arDiscCards;
    }

    public static function GetRules()
    {
        if (!$xml = static::getRuleXml()) {
            return false;
        }
        $rs = \CSaleDiscount::GetList(
            [],
            [
                '~XML_ID' => $xml
            ],
            false,
            false,
            ['ID', 'XML_ID']
        );
        $arRange = [];
        while($rule = $rs->fetch()){
            $range = explode(':', $rule['XML_ID']);
            $arRange[$rule['ID']] = ['ID' => $rule['ID'], 'min' => $range[1], 'max' => $range[2]];
        }
        return $arRange;
    }

}