<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
namespace ShipStream\Sync\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class InstallData implements InstallDataInterface
{
    /**
     * Installs DB schema for a module
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        // Add "Ready to Ship" order status and assign it to the "Processing" state
        // Add "Failed to Submit" order status and assign it to the "Processing" state
        // Submit order to ShipStream when status transitions to Ready to Ship
        $installer = $setup;
        $installer->startSetup();
        $data[] = ['status' => 'ready_to_ship', 'label' => 'Ready to Ship'];
        $data[] = ['status' => 'failed_to_submit', 'label' => 'Failed to Submit'];
        $data[] = ['status' => 'submitted', 'label' => 'Submitted'];
        $setup->getConnection()->insertArray($setup->getTable('sales_order_status'), ['status', 'label'], $data);
        $setup->getConnection()->insertArray(
            $setup->getTable('sales_order_status_state'),
            ['status', 'state', 'is_default','visible_on_front'],
            [
            ['ready_to_ship','processing', '0', '1'],
            ['failed_to_submit', 'processing', '0', '1'],
            ['submitted', 'processing', '0', '1']
            ]
        );
        $setup->endSetup();
    }
}
