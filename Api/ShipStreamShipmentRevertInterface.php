<?php

namespace ShipStream\Sync\Api;

use Magento\Framework\Webapi\Exception as WebapiException;

interface ShipStreamShipmentRevertInterface
{
    /**
     * Revert a shipment and adjust order
     *
     * @param string $orderIncrementId
     * @param string $shipmentIncrementId
     * @param mixed $data
     * @return bool
     * @throws WebapiException
     */
    public function revert($orderIncrementId, $shipmentIncrementId, $data);
}