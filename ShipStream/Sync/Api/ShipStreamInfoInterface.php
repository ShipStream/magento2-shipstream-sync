<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Api;
interface ShipStreamInfoInterface
{
  /**
	 * Check configuration method.
	 * @param string $param
	 * @return string
	 */
    public function infos($param);
}