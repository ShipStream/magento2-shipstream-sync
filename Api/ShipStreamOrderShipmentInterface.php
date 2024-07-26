<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Api;

interface ShipStreamOrderShipmentInterface
{
    /**
     * @param  string         $orderIncrementId
     * @param  string|mixed[] $data
     * @return string|null
     */
    public function createWithTracking($orderIncrementId, $data);
}
