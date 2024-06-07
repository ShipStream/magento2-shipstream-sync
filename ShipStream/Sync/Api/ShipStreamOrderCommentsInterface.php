<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);
namespace ShipStream\Sync\Api;
interface ShipStreamOrderCommentsInterface
{
    /**
     * Retrieve array of columns in order flat table.
     *
     * @param string $orderIncrementId
     * @param string $orderStatus
     * @param string $comment
     * @return string
     */
    public function addComment($orderIncrementId, $orderStatus, $comment);
}