<?php

namespace Commerce\Adjusters;

use Craft\Commerce_AddressModel;
use Craft\Commerce_LineItemModel;
use Craft\Commerce_OrderAdjustmentModel;
use Craft\Commerce_OrderModel;
use Craft\Commerce_TaxRateModel;
use Craft\Commerce_TaxZoneModel;

/**
 * Tax Adjustments
 *
 * Class Commerce_TaxAdjuster
 *
 * @package Commerce\Adjusters
 */
class Commerce_TaxAdjuster implements Commerce_AdjusterInterface
{
    const ADJUSTMENT_TYPE = 'Tax';

    /**
     * @param Commerce_OrderModel $order
     * @param Commerce_LineItemModel[] $lineItems
     *
     * @return \Craft\Commerce_OrderAdjustmentModel[]
     */
    public function adjust(Commerce_OrderModel &$order, array $lineItems = [])
    {
        $shippingAddress = \Craft\craft()->commerce_addresses->getAddressById($order->shippingAddressId);

        $adjustments = [];
        $taxRates = \Craft\craft()->commerce_taxRates->getAllTaxRates([
            'with' => ['taxZone', 'taxZone.countries', 'taxZone.states.country'],
        ]);

        /** @var Commerce_TaxRateModel $rate */
        foreach ($taxRates as $rate) {
            if ($adjustment = $this->getAdjustment($order, $lineItems, $shippingAddress, $rate)) {
                $adjustments[] = $adjustment;
            }
        }

        return $adjustments;
    }

    /**
     * @param Commerce_OrderModel $order
     * @param Commerce_LineItemModel[] $lineItems
     * @param Commerce_AddressModel $address
     * @param Commerce_TaxRateModel $taxRate
     *
     * @return Commerce_OrderAdjustmentModel|false
     */
    private function getAdjustment(Commerce_OrderModel $order, array $lineItems, Commerce_AddressModel $address = null, Commerce_TaxRateModel $taxRate)
    {
        $zone = $taxRate->taxZone;

        //preparing model
        $adjustment = new Commerce_OrderAdjustmentModel;
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = $taxRate->name;
        $adjustment->description = $taxRate->rate * 100 . '%' . ($taxRate->include ? ' inc' : '');
        $adjustment->orderId = $order->id;
        $adjustment->optionsJson = $taxRate->attributes;

        //checking address
        if (!$this->matchAddress($address, $zone)) {
            if ($taxRate->include) {
                //excluding taxes included in price
                foreach ($lineItems as $item) {
                    if ($item->taxCategoryId == $taxRate->taxCategoryId) {
                        $taxableAmount = $item->getTaxableSubtotal($taxRate->taxable);
                        $item->tax += -($taxableAmount - ($taxableAmount / (1 + $taxRate->rate)));
                    }
                }
            }

            return false;
        }

        //checking items tax categories
        $itemsMatch = false;
        foreach ($lineItems as $item) {

            if ($item->taxCategoryId == $taxRate->taxCategoryId) {
                if (!$taxRate->include) {
                    $itemTax = $taxRate->rate * $item->getTaxableSubtotal($taxRate->taxable);
                } else {
                    $itemTax = ($item->getTaxableSubtotal($taxRate->taxable) - ($item->getTaxableSubtotal($taxRate->taxable) / (1 + $taxRate->rate)));
                }

                $adjustment->amount += $itemTax;

                if (!$taxRate->include) {
                    $item->tax += $itemTax;
                }else{
                    $item->taxIncluded += $itemTax;
                }

                $itemsMatch = true;
            }
        }

        return $itemsMatch ? $adjustment : false;
    }

    /**
     * @param Commerce_AddressModel $address
     * @param Commerce_TaxZoneModel $zone
     *
     * @return bool
     */
    private function matchAddress(Commerce_AddressModel $address = null, Commerce_TaxZoneModel $zone)
    {
        //when having no address check default tax zones only
        if (!$address) {
            return $zone->default;
        }

        if ($zone->countryBased) {
            $countryIds = $zone->getCountryIds();

            if (in_array($address->countryId, $countryIds)) {
                return true;
            }
        } else {
            foreach ($zone->states as $state) {
                if ($state->country->id == $address->countryId && $state->name == $address->getStateText()) {
                    return true;
                }
            }
        }

        return false;
    }
}
