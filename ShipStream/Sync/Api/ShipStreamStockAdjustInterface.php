<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Api;

interface ShipStreamStockAdjustInterface
{
  /**
   * Check configuration method.
   * @param int|string $productSku - product entity id or SKU
   * @param float $delta
   * @return bool
   */
    public function stockAdjust($productSku, $delta);
}
