<?php
namespace Craft;

use Commerce\Traits\Commerce_ModelRelationsTrait;
use Omnipay\Common\Currency;

/**
 * Order or Cart model.
 *
 * @property int $id
 * @property string $number
 * @property string $couponCode
 * @property float $itemTotal
 * @property float $totalPrice
 * @property float $totalPaid
 * @property float $baseDiscount
 * @property float $baseShippingCost
 * @property string $email
 * @property DateTime $dateOrdered
 * @property string $currency
 * @property DateTime $datePaid
 * @property string $lastIp
 * @property string $message
 * @property string $returnUrl
 * @property string $cancelUrl
 *
 * @property int $billingAddressId
 * @property int $shippingAddressId
 * @property int $shippingMethod
 * @property int $paymentMethodId
 * @property int $customerId
 * @property int $orderStatusId
 *
 * @property int $totalQty
 * @property int $totalWeight
 * @property int $totalHeight
 * @property int $totalLength
 * @property int $totalWidth
 * @property int $totalTax
 * @property int $totalShippingCost
 * @property int $totalDiscount
 * @property string $pdfUrl
 *
 * @property Commerce_OrderSettingsModel $type
 * @property Commerce_LineItemModel[] $lineItems
 * @property Commerce_AddressModel $billingAddress
 * @property Commerce_CustomerModel $customer
 * @property Commerce_AddressModel $shippingAddress
 * @property Commerce_OrderAdjustmentModel[] $adjustments
 * @property Commerce_PaymentMethodModel $paymentMethod
 * @property Commerce_TransactionModel[] $transactions
 * @property Commerce_OrderStatusModel $orderStatus
 * @property Commerce_OrderHistoryModel[] $histories
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.plugins.commerce.models
 * @since     1.0
 */
class Commerce_OrderModel extends BaseElementModel
{
    use Commerce_ModelRelationsTrait;

    /**
     * @var string
     */
    protected $elementType = 'Commerce_Order';

    /**
     * @var
     */
    private $_shippingAddress;

    /**
     * @var
     */
    private $_billingAddress;

    /**
     * @var array
     */
    private $_lineItems;

    /**
     * @var array
     */
    private $_orderAdjustments;

    /**
     * @return bool
     */
    public function isEditable()
    {
        // Still a cart, allow full editing.
        if(!$this->dateOrdered){
            return true;
        }else{
            return craft()->userSession->checkPermission('commerce-manageOrders');
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return substr($this->number, 0, 7);
    }

    /**
     * @return string
     */
    public function getShortNumber()
    {
        return substr($this->number, 0, 7);
    }

    /**
     * @inheritdoc
     * @return string
     */
    public function getLink()
    {
        return TemplateHelper::getRaw("<a href='" . $this->getCpEditUrl() . "'>" . substr($this->number, 0, 7) . "</a>");
    }

    /**
     * @inheritdoc
     * @return string
     */
    public function getCpEditUrl()
    {
        return UrlHelper::getCpUrl('commerce/orders/' . $this->id);
    }

    /**
     * Returns the URL to the order’s PDF invoice.
     *
     * @param string|null $option The option that should be available to the PDF template (e.g. “receipt”)
     *
     * @return string|null The URL to the order’s PDF invoice, or null if the PDF template doesn’t exist
     */
    public function getPdfUrl($option = null)
    {
        $url = null;

        // Make sure the template exists
        $template = craft()->commerce_settings->getSettings()->orderPdfPath;

        if ($template)
        {
            $paths = craft()->path;
            $templatesPath = $paths->getTemplatesPath();
            $paths->setTemplatesPath($paths->getSiteTemplatesPath());

            if (craft()->templates->doesTemplateExist($template))
            {
                $url = UrlHelper::getActionUrl("commerce/downloads/pdf?number={$this->number}".($option ? "&option={$option}" : null));
            }

            $paths->setTemplatesPath($templatesPath);
        }

        return $url;
    }

    /**
     * @return FieldLayoutModel
     */
    public function getFieldLayout()
    {
        return craft()->commerce_orderSettings->getOrderSettingByHandle('order')->getFieldLayout();
    }

    /**
     * @return bool
     */
    public function isLocalized()
    {
        return false;
    }

    /**
     * @return Commerce_CustomerModel|null
     */
    public function getCustomer()
    {
        if($this->customerId){
            return craft()->commerce_customers->getCustomerById($this->customerId);
        }
    }

    /**
     * Whether or not this order is made by a guest user.
     * @return bool
     */
    public function isGuest()
    {
        if($this->getCustomer()){
            return (bool) !$this->getCustomer()->userId;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function isPaid()
    {
        $currency = Currency::find(craft()->commerce_settings->getSettings()->defaultCurrency);
        $totalPaid = round($this->totalPaid, $currency->getDecimals());
        $totalPrice = round($this->totalPrice, $currency->getDecimals());
        return $totalPaid >= $totalPrice;
    }

    /**
     * Has the order got any items in it?
     *
     * @return bool
     */
    public function isEmpty()
    {
        return $this->getTotalQty() == 0;
    }

    /**
     * Total number of items.
     *
     * @return int
     */
    public function getTotalQty()
    {
        $qty = 0;
        foreach ($this->getLineItems() as $item) {
            $qty += $item->qty;
        }

        return $qty;
    }

    /**
     * @return float
     */
    public function getTotalTax()
    {
        $tax = 0;
        foreach ($this->getLineItems() as $item) {
            $tax += $item->tax;
        }

        return $tax;
    }

    /**
     * @return float
     */
    public function getTotalTaxIncluded()
    {
        $tax = 0;
        foreach ($this->getLineItems() as $item) {
            $tax += $item->taxIncluded;
        }

        return $tax;
    }

    /**
     * @return float
     */
    public function getTotalDiscount()
    {
        $discount = 0;
        foreach ($this->getLineItems() as $item) {
            $discount += $item->discount;
        }

        return $discount + $this->baseDiscount;
    }


    /**
     * @return float
     */
    public function getTotalShippingCost()
    {
        $shippingCost = 0;
        foreach ($this->getLineItems() as $item) {
            $shippingCost += $item->shippingCost;
        }

        return $shippingCost + $this->baseShippingCost;
    }

    /**
     * @return int
     */
    public function getTotalWeight()
    {
        $weight = 0;
        foreach ($this->getLineItems() as $item) {
            $weight += $item->qty * $item->weight;
        }

        return $weight;
    }

    /**
     * @return int
     */
    public function getTotalLength()
    {
        $value = 0;
        foreach ($this->getLineItems() as $item) {
            $value += $item->qty * $item->length;
        }

        return $value;
    }

    /**
     * @return int
     */
    public function getTotalWidth()
    {
        $value = 0;
        foreach ($this->getLineItems() as $item) {
            $value += $item->qty * $item->width;
        }

        return $value;
    }

    /**
     * Returns the total sale amount.
     * @return int
     */
    public function getTotalSaleAmount()
    {
        $value = 0;
        foreach ($this->getLineItems() as $item) {
            $value += $item->qty * $item->saleAmount;
        }

        return $value;
    }

    /**
     * @return int
     */
    public function getItemSubtotalWithSale()
    {
        $value = 0;
        foreach ($this->getLineItems() as $item) {
            $value += $item->getSubtotalWithSale();
        }

        return $value;
    }

    /**
     * @return int
     */
    public function getTotalHeight()
    {
        $value = 0;
        foreach ($this->getLineItems() as $item) {
            $value += $item->qty * $item->height;
        }

        return $value;
    }

    /**
     * @return Commerce_LineItemModel[]
     */
    public function getLineItems()
    {
        if(!$this->_lineItems){
            $this->_lineItems = craft()->commerce_lineItems->getAllLineItemsByOrderId($this->id);
        }

        return $this->_lineItems;
    }

    /**
     * @param Commerce_LineItemModel[] $lineItems
     */
    public function setLineItems($lineItems)
    {
        $this->_lineItems = $lineItems;
    }

    /**
     * @return Commerce_OrderAdjustmentModel[]
     */
    public function getAdjustments()
    {
        if(!$this->_orderAdjustments){
            $this->_orderAdjustments = craft()->commerce_orderAdjustments->getAllOrderAdjustmentsByOrderId($this->id);
        }

        return $this->_orderAdjustments;
    }

    /**
     * @return Commerce_AddressModel
     */
    public function getShippingAddress()
    {
        if (!isset($this->_shippingAddress)) {
            $this->_shippingAddress = craft()->commerce_addresses->getAddressById($this->shippingAddressId);
        }

        return $this->_shippingAddress;
    }

    /**
     * @param Commerce_AddressModel $address
     */
    public function setShippingAddress(Commerce_AddressModel $address)
    {
        $this->_shippingAddress = $address;
    }

    /**
     * @return Commerce_AddressModel
     */
    public function getBillingAddress()
    {
        if (!isset($this->_billingAddress)) {
            $this->_billingAddress = craft()->commerce_addresses->getAddressById($this->billingAddressId);
        }

        return $this->_billingAddress;
    }

    /**
     *
     * @param Commerce_AddressModel $address
     */
    public function setBillingAddress(Commerce_AddressModel $address)
    {
        $this->_billingAddress = $address;
    }

    /**
     * @return \Commerce\Interfaces\ShippingMethod|null
     */
    public function getShippingMethodId()
    {
        if($this->getShippingMethod()){
            return $this->getShippingMethod()->getId();
        };
    }

    /**
     * @return string|null
     */
    public function getShippingMethodHandle()
    {
        return $this->getAttribute('shippingMethod');
    }

    /**
     * @return int|null
     */
    public function getShippingMethod()
    {
        return craft()->commerce_shippingMethods->getShippingMethodByHandle($this->getShippingMethodHandle());
    }

    /**
     * @return Commerce_PaymentMethodModel|null
     */
    public function getPaymentMethod()
    {
        return craft()->commerce_paymentMethods->getPaymentMethodById($this->getAttribute('paymentMethodId'));
    }

    /**
     * @deprecated
     * @return bool
     */
    public function showAddress()
    {
        craft()->deprecator->log('Commerce_OrderModel::showAddress():removed', 'You should no longer use `cart.showAddress` in twig to determine whether to show the address form. Do your own check in twig like this `{% if cart.linItems|length > 0 %}`');

        return count($this->getLineItems()) > 0;
    }

    /**
     * @deprecated
     * @return bool
     */
    public function showPayment()
    {
        craft()->deprecator->log('Commerce_OrderModel::showPayment():removed', 'You should no longer use `cart.showPayment` in twig to determine whether to show the payment form. Do your own check in twig like this `{% if cart.linItems|length > 0 and cart.billingAddressId and cart.shippingAddressId %}`');

        return count($this->getLineItems()) > 0 && $this->billingAddressId && $this->shippingAddressId;
    }

    /**
     * @return array
     */
    protected function defineAttributes()
    {
        return array_merge(parent::defineAttributes(), [
            'id' => AttributeType::Number,
            'number' => AttributeType::String,
            'couponCode' => AttributeType::String,
            'itemTotal' => [
                AttributeType::Number,
                'decimals' => 4,
                'default' => 0
            ],
            'baseDiscount' => [
                AttributeType::Number,
                'decimals' => 4,
                'default' => 0
            ],
            'baseShippingCost' => [
                AttributeType::Number,
                'decimals' => 4,
                'default' => 0
            ],
            'totalPrice' => [
                AttributeType::Number,
                'decimals' => 4,
                'default' => 0
            ],
            'totalPaid' => [
                AttributeType::Number,
                'decimals' => 4,
                'default' => 0
            ],
            'email' => AttributeType::String,
            'dateOrdered' => AttributeType::DateTime,
            'datePaid' => AttributeType::DateTime,
            'currency' => AttributeType::String,
            'lastIp' => AttributeType::String,
            'message' => AttributeType::String,
            'returnUrl' => AttributeType::String,
            'cancelUrl' => AttributeType::String,
            'orderStatusId' => AttributeType::Number,
            'billingAddressId' => AttributeType::Number,
            'shippingAddressId' => AttributeType::Number,
            'shippingMethod' => AttributeType::String,
            'paymentMethodId' => AttributeType::Number,
            'customerId' => AttributeType::Number
        ]);
    }
}
