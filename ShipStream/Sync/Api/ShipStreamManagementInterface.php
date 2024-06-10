<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Api;

interface ShipStreamManagementInterface
{
    /**
     * GET for ShipStream api
     *
     * @return string
     */
    public function syncInventory();
    /**
     * POST for ShipStream api
     * @param string $path
     * @param string $value
     * @param string $source
     * @param string $source_code
     * @param string $stock
     * @return string|int|float|null|bool
     */
    public function setConfig($path, $value, $source, $source_code, $stock);
}
