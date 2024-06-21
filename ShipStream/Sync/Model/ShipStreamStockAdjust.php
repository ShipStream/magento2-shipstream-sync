<?php

/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace ShipStream\Sync\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;
use ShipStream\Sync\Model\Cron;
use Magento\Framework\App\ResourceConnection;

class ShipStreamStockAdjust implements \ShipStream\Sync\Api\ShipStreamStockAdjustInterface
{
    protected $productRepository;
    protected $sourceItemFactory;
    protected $sourceItemsSave;
    protected $sourceItemRepository;
    private $searchCriteriaBuilder;
    protected $logger;
    protected $cronHelper;
    private $resourceConnection;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        SourceItemInterfaceFactory $sourceItemFactory,
        SourceItemsSaveInterface $sourceItemsSave,
        SourceItemRepositoryInterface $sourceItemRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger,
        Cron $cronHelper,
        ResourceConnection $resourceConnection
    ) {
        $this->productRepository = $productRepository;
        $this->sourceItemFactory = $sourceItemFactory;
        $this->sourceItemsSave = $sourceItemsSave;
        $this->sourceItemRepository = $sourceItemRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
        $this->cronHelper = $cronHelper;
        $this->resourceConnection = $resourceConnection->getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function stockAdjust($productSku, $delta)
    {
        $this->resourceConnection->beginTransaction();
        try {
            // Load the product by SKU
            $product = $this->productRepository->get($productSku);
            $sourceCode = $this->cronHelper->getCurrentSourceCode();
            // Fetch the source item for the product and source
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('sku', $productSku, 'eq')
                ->addFilter('source_code', $sourceCode, 'eq')
                ->create();
            $sourceItems = $this->sourceItemRepository->getList($searchCriteria)->getItems();

            if (empty($sourceItems)) {
                throw new NoSuchEntityException(__('No source item found for SKU: %1 at source: %2', $productSku, $sourceCode));
            }
            // Assume single source item; adjust for multiple as needed
            $sourceItem = array_shift($sourceItems); // Assuming there's only one item per sku and source code
            $this->_lockStockItems($product->getId(), $sourceCode); // Lock the stock item by product ID
            $oldQty = $sourceItem->getQuantity();
            $newQty = $oldQty + $delta;
            $sourceItem->setQuantity($newQty);
            $sourceItem->save();
                
            $sourceItem->setStatus($newQty <= 0 ? SourceItemInterface::STATUS_OUT_OF_STOCK : SourceItemInterface::STATUS_IN_STOCK);
            $this->resourceConnection->commit();
        } catch (NoSuchEntityException $e) {
            $this->resourceConnection->rollback();
            $this->logger->error('Product not found: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->resourceConnection->rollback();
            $this->logger->error('Error adjusting stock: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    protected function _lockStockItems($productId = null, $sourceCode = null)
    {
        try {
            $tableName = $this->resourceConnection->getTableName('inventory_source_item');

            // Building the query to lock the stock item rows
            $select = $this->resourceConnection->select()
                ->from($tableName, 'source_item_id')
                ->forUpdate(true); // SQL "FOR UPDATE" to lock the selected rows

            if (is_numeric($productId)) {
                // Lock stock item by product ID
                $select->where('source_item_id = ?', $productId);
            } elseif (is_array($productId)) {
                // Lock multiple products if productId is an array
                $select->where('source_item_id IN (?)', $productId);
            }
            $select->where('source_code = ?', $sourceCode);
            // Execute the query which will lock the rows until the transaction is either committed or rolled back
            $this->resourceConnection->query($select)->closeCursor();
        } catch (\Exception $e) {
            throw new LocalizedException(__('Error locking stock items: %1', $e->getMessage()));
        }
    }
}
