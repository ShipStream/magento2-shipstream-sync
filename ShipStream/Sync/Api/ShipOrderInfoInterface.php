<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Api;

interface ShipOrderInfoInterface
{
    /**
     * Check configuration method.
     *
     * @param  string $inc_id
     * @return string|mixed[]|array|int
     */
    public function info($inc_id);
}
