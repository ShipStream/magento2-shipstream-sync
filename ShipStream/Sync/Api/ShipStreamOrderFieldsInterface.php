<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ShipStream\Sync\Api;

interface ShipStreamOrderFieldsInterface
{
	/**
     * Retrieve array of columns in order flat table.
     *
     * @param string $filters
     * @return string
     */
    public function selectFields($filters);
}

