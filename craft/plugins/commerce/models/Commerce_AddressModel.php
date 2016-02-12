<?php
namespace Craft;

use Commerce\Traits\Commerce_ModelRelationsTrait;

/**
 * Customer address model.
 *
 * @property int $id
 * @property string $firstName
 * @property string $lastName
 * @property string $address1
 * @property string $address2
 * @property string $city
 * @property string $zipCode
 * @property string $phone
 * @property string $alternativePhone
 * @property string $businessName
 * @property string $businessTaxId
 * @property string $stateName
 * @property int $countryId
 * @property int $stateId
 *
 * @property Commerce_CountryModel $country
 * @property Commerce_StateModel $state
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.plugins.commerce.models
 * @since     1.0
 */
class Commerce_AddressModel extends BaseModel
{
    use Commerce_ModelRelationsTrait;

    /** @var int|string Either ID of a state or name of state if it's not present in the DB */
    public $stateValue;

    /**
     * @return string
     */
    public function getCpEditUrl()
    {
        return UrlHelper::getCpUrl('commerce/addresses/' . $this->id);
    }

    /**
     * @return string
     */
    public function getStateText()
    {
        return $this->stateName ? $this->stateName : ($this->stateId ? $this->getState()->name : '');
    }

    /**
     * @return string
     */
    public function getCountryText()
    {
        return $this->countryId ? $this->getCountry()->name : '';
    }

    /*
     * @return Commerce_StateModel|null
     */
    public function getState()
    {
        return craft()->commerce_states->getStateById($this->stateId);
    }

    /*
     * @return Commerce_CountryModel|null
     */
    public function getCountry()
    {
        return craft()->commerce_countries->getCountryById($this->countryId);
    }

    /**
     * @return string
     */
    public function getFullName()
    {
	    $firstName = trim($this->getAttribute('firstName'));
	    $lastName = trim($this->getAttribute('lastName'));

	    return $firstName.($firstName && $lastName ? ' ' : '').$lastName;
    }

    /**
     * @return array
     */
    protected function defineAttributes()
    {
        return [
            'id' => AttributeType::Number,
            'firstName' => AttributeType::String,
            'lastName' => AttributeType::String,
            'address1' => AttributeType::String,
            'address2' => AttributeType::String,
            'city' => AttributeType::String,
            'zipCode' => AttributeType::String,
            'phone' => AttributeType::String,
            'alternativePhone' => AttributeType::String,
            'businessName' => AttributeType::String,
            'businessTaxId' => AttributeType::String,
            'stateName' => AttributeType::String,
            'countryId' => AttributeType::Number,
            'stateId' => AttributeType::Number
        ];
    }
}
