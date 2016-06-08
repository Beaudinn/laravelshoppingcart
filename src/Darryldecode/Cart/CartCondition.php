<?php namespace Darryldecode\Cart;
use Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Darryldecode\Cart\Helpers\Helpers;
use Darryldecode\Cart\Validators\CartConditionValidator;
use Larastores\Core\Classes\ConditionManager;
use System\Classes\ModelBehavior;
use October\Rain\Extension\Extendable;



/**
 * Created by PhpStorm.
 * User: darryl
 * Date: 1/15/2015
 * Time: 9:02 PM
 */

class CartCondition extends Extendable
{

    use \October\Rain\Support\Traits\Emitter;
    use \October\Rain\Database\Traits\DeferredBinding;

    public $classAttribute;

    /**
     * @var array Behaviors implemented by this controller.
     */
    public $implement;
    /**
     * @var string
     */
    private $alias;
    private $target;
    private $name;
    private $type;
    private $is_dynamic;
    private $exclude = [];
    //private $attributes = [];
    private $value;
    private $tax_class_id;
    private $class_name;
    private $condition_name;
    private $config_data;
    private $sort_order;

    public $exists;

    /**
     * @var array
     */
    public $attributes;

    public $conditionAlias;
    public $conditionClass;

    /**
     * the parsed raw value of the condition
     *
     * @var
     */
    private $parsedRawValue;

    /**
     * @param stirng $alias
     * @param array $args (name, type, target, value)
     * @throws InvalidConditionException
     */
    public function __construct($alias, array $args = [])
    {
        if( is_array($alias) ){

            $this->alias = 'deprecated';

            foreach($alias as $key => $value){

                if($key == 'order'){
                    $this->sort_order = $value;
                    continue;
                }

                $this->{$key} = $value;
            }

            if( Helpers::isMultiArray($alias) )
            {
                Throw new InvalidConditionException('Multi dimensional array is not supported.');
            }
            else
            {
                $this->validate($alias);
            }
            return;
        }

        $this->alias = $alias;

        //attributes
        //$this->attributes = $args;

        foreach($args as $key => $value){
            $this->attributes[$key] = $value;

            if($key == 'order'){
                $this->sort_order = $value;
                continue;
            }
            $this->{$key} = $value;
        }
        if ($class = $this->getConditionClass($alias)) {
            $this->applyConditionClass($class);
            $this->exists = false;
            $this->initConfigData($this);


            if($defaults = $this->getDefaults()){
                foreach($defaults as $key => $value){
                    $this->attributes[$key] = $value;
                    $this->{$key} = $value;
                }
            }
        }

        $configData = [];
        $fieldConfig = $this->getFieldConfig();

        $fields = isset($fieldConfig->fields) ? $fieldConfig->fields : [];

        foreach ($fields as $name => $config) {
            if ((!isset($this->{$name}) || !$this->{$name}) && (!isset($args[$name]) || !$args[$name])  ) {
                continue;
            }

            $configData[$name] = $this->{$name} ?: $args[$name];
            unset($this->{$name});
        }

        $this->config_data = $configData;

        if( Helpers::isMultiArray($args) )
        {
            Throw new InvalidConditionException('Multi dimensional array is not supported.');
        }
        else
        {
            $this->validate($args);
        }

    }


    protected function getConditionClass($alias = false)
    {

        if(!$alias)
            $alias = $this->alias;

        if ($this->conditionClass !== null) {
            return $this->conditionClass;
        }

        if (!$condition = ConditionManager::instance()->findByAlias($alias)) {
            return;
            throw new Exception('Unable to find condition with alias '. $alias);
        }

        return $this->conditionClass = $condition->class;
    }

    /**
     * Extends this class with the gateway class
     * @param  string $class Class name
     * @param  string $orderId Order id
     * @return boolean
     */
    public function applyConditionClass($class = null)
    {
        if (!$class) {
            $class = $this->class_name;
        }

        if (!$class) {
            return false;
        }

        if (!$this->isClassExtendedWith($class)) {
            $this->extendClassWith($class);
        }

        $this->class_name = $class;
        $this->condition_name = array_get($this->conditionDetails(), 'name', 'Unknown');


        return true;
    }

    public function setDefaults($data)
    {
        foreach($data as $key => $value){
            $this->attributes[$key] = $value;
            $this->{$key}  = $value;
        }
    }

    /**
     * the alias of class of the condition
     *
     * @return mixed
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * the target of where the condition is applied
     *
     * @return mixed
     */
    public function getTarget()
    {
        return $this->target;
    }

    /**
     * the name of the condition
     *
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * the type of the condition
     *
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * the type of the condition
     *
     * @return mixed
     */
    public function getDynamic()
    {
        return $this->is_dynamic;
    }

    public function getTaxId()
    {
        return $this->tax_class_id;
    }

    public function getSortOrder()
    {
        return $this->sort_order;
    }

    public function setSortOrder($order)
    {
        $this->sort_order = (int) $order;
    }

    /**
     * get the additional attributes of a condition
     *
     * @return array
     */
    public function getAttributes()
    {
        return (isset($this->attributes['attributes'])) ? $this->attributes['attributes'] : array();
    }

    public function getConfigData()
    {
        return $this->config_data;
    }



    /**
     * the value of this the condition
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * apply condition to total or subtotal
     *
     * @param $totalOrSubTotalOrPrice
     * @return float
     */
    public function applyCondition($totalOrSubTotalOrPrice)
    {
        return $this->apply($totalOrSubTotalOrPrice, $this->getValue());
    }

    /**
     * get the calculated value of this condition supplied by the subtotal|price
     *
     * @param $totalOrSubTotalOrPrice
     * @return mixed
     */
    public function getCalculatedValue($totalOrSubTotalOrPrice)
    {
        $this->apply($totalOrSubTotalOrPrice, $this->getValue());

        return $this->parsedRawValue;
    }

    /**
     * apply condition
     *
     * @param $totalOrSubTotalOrPrice
     * @param $conditionValue
     * @return float
     */
    protected function apply($totalOrSubTotalOrPrice, $conditionValue)
    {
        // if value has a percentage sign on it, we will get first
        // its percentage then we will evaluate again if the value
        // has a minus or plus sign so we can decide what to do with the
        // percentage, whether to add or subtract it to the total/subtotal/price
        // if we can't find any plus/minus sign, we will assume it as plus sign
        if( $this->valueIsPercentage($conditionValue) )
        {
            if( $this->valueIsToBeSubtracted($conditionValue) )
            {
                $value = Helpers::normalizePrice( $this->cleanValue($conditionValue) );

                $this->parsedRawValue = $totalOrSubTotalOrPrice * ($value / 100);

                $result = floatval($totalOrSubTotalOrPrice - $this->parsedRawValue);
            }
            else if ( $this->valueIsToBeAdded($conditionValue) )
            {
                $value = Helpers::normalizePrice( $this->cleanValue($conditionValue) );

                $this->parsedRawValue = $totalOrSubTotalOrPrice * ($value / 100);

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
            else
            {
                $value = Helpers::normalizePrice($conditionValue);

                $this->parsedRawValue = $totalOrSubTotalOrPrice * ($value / 100);

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
        }

        // if the value has no percent sign on it, the operation will not be a percentage
        // next is we will check if it has a minus/plus sign so then we can just deduct it to total/subtotal/price
        else
        {
            if( $this->valueIsToBeSubtracted($conditionValue) )
            {
                $this->parsedRawValue = Helpers::normalizePrice( $this->cleanValue($conditionValue) );

                $result = floatval($totalOrSubTotalOrPrice - $this->parsedRawValue);
            }
            else if ( $this->valueIsToBeAdded($conditionValue) )
            {
                $this->parsedRawValue = Helpers::normalizePrice( $this->cleanValue($conditionValue) );

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
            else
            {
                $this->parsedRawValue = Helpers::normalizePrice($conditionValue);

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
        }

        return $result;
    }

    /**
     * check if value is a percentage
     *
     * @param $value
     * @return bool
     */
    protected function valueIsPercentage($value)
    {
        return (preg_match('/%/', $value) == 1);
    }

    /**
     * check if value is a subtract
     *
     * @param $value
     * @return bool
     */
    protected function valueIsToBeSubtracted($value)
    {
        return (preg_match('/\-/', $value) == 1);
    }

    /**
     * check if value is to be added
     *
     * @param $value
     * @return bool
     */
    protected function valueIsToBeAdded($value)
    {
        return (preg_match('/\+/', $value) == 1);
    }

    /**
     * removes some arithmetic signs (%,+,-) only
     *
     * @param $value
     * @return mixed
     */
    protected function cleanValue($value)
    {
        return str_replace(array('%','-','+'),'',$value);
    }

    /**
     * validates condition arguments
     *
     * @param $args
     * @throws InvalidConditionException
     */
    protected function validate($args)
    {
        $rules = array(
            'alias' => 'required',
            'name' => 'required',
            'type' => 'required',
            'target' => 'required',
            'value' => 'required',
        );

        $rules = [];

        $validator = CartConditionValidator::make($args, $rules);

        if( $validator->fails() )
        {
            throw new InvalidConditionException($validator->messages()->first());
        }
    }
}