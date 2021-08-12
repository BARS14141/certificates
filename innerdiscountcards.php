<?

class InnerDiscountCards extends DiscountCards
{
    protected static $ruleXml = 'inner_discount_program';
    protected static $cardDescr = 'inner_discount_card';
    protected static $cardsCondition = 'WHERE DiscountType = 1';

    public function setRuleForDiscount()
    {
        foreach($this->arCards as &$card){
            $card['RULE'] = current($this->arRules)['ID'];
        }
    }
}