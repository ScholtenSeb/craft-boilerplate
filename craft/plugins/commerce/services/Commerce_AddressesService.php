<?php
namespace Craft;

/**
 * Address service.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.plugins.commerce.services
 * @since     1.0
 */
class Commerce_AddressesService extends BaseApplicationComponent
{
    /**
     * @param int $id
     *
     * @return Commerce_AddressModel|null
     */
    public function getAddressById($id)
    {
        $result = Commerce_AddressRecord::model()->findById($id);

        if ($result) {
            return Commerce_AddressModel::populateModel($result);
        }

        return null;
    }

    /**
     * @param $id
     *
     * @return array
     */
    public function getAddressesByCustomerId($id)
    {
        $record = Commerce_CustomerRecord::model()->with('addresses')->findByAttributes(['id' => $id]);

        return Commerce_AddressModel::populateModels($record->addresses);
    }

    /**
     * @param Commerce_AddressModel $addressModel
     *
     * @return bool
     * @throws Exception
     */
    public function saveAddress(Commerce_AddressModel $addressModel)
    {
        if ($addressModel->id) {
            $addressRecord = Commerce_AddressRecord::model()->findById($addressModel->id);

            if (!$addressRecord) {
                throw new Exception(Craft::t('No address exists with the ID “{id}”',
                    ['id' => $addressModel->id]));
            }
        } else {
            $addressRecord = new Commerce_AddressRecord();
        }

        $addressRecord->firstName = $addressModel->firstName;
        $addressRecord->lastName = $addressModel->lastName;
        $addressRecord->address1 = $addressModel->address1;
        $addressRecord->address2 = $addressModel->address2;
        $addressRecord->city = $addressModel->city;
        $addressRecord->zipCode = $addressModel->zipCode;
        $addressRecord->phone = $addressModel->phone;
        $addressRecord->alternativePhone = $addressModel->alternativePhone;
        $addressRecord->businessName = $addressModel->businessName;
        $addressRecord->businessTaxId = $addressModel->businessTaxId;
        $addressRecord->countryId = $addressModel->countryId;

        if (!empty($addressModel->stateValue)) {
            if (is_numeric($addressModel->stateValue)) {
                $addressRecord->stateId = $addressModel->stateId = $addressModel->stateValue;
            } else {
                $addressRecord->stateName = $addressModel->stateName = $addressModel->stateValue;
            }
        } else {
            $addressRecord->stateId = $addressModel->stateId;
            $addressRecord->stateName = $addressModel->stateName;
        }

        $addressRecord->validate();
        $addressModel->addErrors($addressRecord->getErrors());

        if (!$addressModel->hasErrors()) {

            //raising event
            $event = new Event($this, [
                'address' => $addressModel
            ]);
            $this->onBeforeSaveAddress($event);

            if ($event->performAction){
                $addressRecord->save(false);
            }else{
                return false;
            }

            // Now that we have a record ID, save it on the model
            $addressModel->id = $addressRecord->id;

            //raising event
            $event = new Event($this, [
                'address' => $addressModel
            ]);
            $this->onSaveAddress($event);

            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $id
     *
     * @return bool
     */
    public function deleteAddressById($id)
    {
        return (bool)Commerce_AddressRecord::model()->deleteByPk($id);
    }

    /**
     * Event: before saving and address
     * Event params: address(Commerce_AddressModel)
     *
     * @param \CEvent $event
     *
     * @throws \CException
     */
    public function onBeforeSaveAddress(\CEvent $event)
    {
        $params = $event->params;
        if (empty($params['address']) || !($params['address'] instanceof Commerce_AddressModel)) {
            throw new Exception('onBeforeSaveAddress event requires "address" param with Commerce_AddressModel instance');
        }
        $this->raiseEvent('onBeforeSaveAddress', $event);
    }

    /**
     * Event: after saving an address.
     * Event params: addres(Commerce_AddressModel)
     *
     * @param \CEvent $event
     *
     * @throws \CException
     */
    public function onSaveAddress(\CEvent $event)
    {
        $params = $event->params;
        if (empty($params['address']) || !($params['address'] instanceof Commerce_AddressModel)) {
            throw new Exception('onSaveAddress event requires "address" param with Commerce_AddressModel instance');
        }
        $this->raiseEvent('onSaveAddress', $event);
    }

}
