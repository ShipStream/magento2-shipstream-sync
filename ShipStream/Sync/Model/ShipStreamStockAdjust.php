<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ShipStream\Sync\Model;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;
use ShipStream\Sync\Model\Cron;

class ShipStreamStockAdjust implements \ShipStream\Sync\Api\ShipStreamStockAdjustInterface
{
	protected $productRepository;
    protected $sourceItemFactory;
    protected $sourceItemsSave;
    protected $sourceItemRepository;
	private $searchCriteriaBuilder;
    protected $logger;
	protected $cronHelper;
   public function __construct(
        ProductRepositoryInterface $productRepository,
        SourceItemInterfaceFactory $sourceItemFactory,
        SourceItemsSaveInterface $sourceItemsSave,
        SourceItemRepositoryInterface $sourceItemRepository,
		SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger,
		Cron $cronHelper
    ) {
        $this->productRepository = $productRepository;
        $this->sourceItemFactory = $sourceItemFactory;
        $this->sourceItemsSave = $sourceItemsSave;
        $this->sourceItemRepository = $sourceItemRepository;
		$this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
		 $this->cronHelper = $cronHelper;
    }
	/**
     * {@inheritdoc}
    */
	public function stockAdjust($productSku, $delta)
	{
		try {
				// Load the product by SKU
				$product = $this->productRepository->get($productSku);
				$sourceCode =  $this->cronHelper->getCurrentSourceCode();
				// Fetch the source item for the product and source
				$searchCriteria = $this->searchCriteriaBuilder
					->addFilter('sku', $productSku, 'eq')
					->addFilter('source_code', $sourceCode, 'eq')
					->create();
				$sourceItems = $this->sourceItemRepository->getList($searchCriteria)->getItems();

				if (empty($sourceItems)) {
					throw new NoSuchEntityException(__('No source item found for SKU: %1 at source: %2', $productSku, $sourceCode));
					return false;
				}
				// Assume single source item; adjust for multiple as needed
				if (!empty($sourceItems)) {
					$sourceItem = array_shift($sourceItems); // Assuming there's only one item per sku and source code
				}
				$oldQty = $sourceItem->getQuantity();
				$newQty = $oldQty + $delta;
				$sourceItem->setQuantity($newQty);
				$sourceItem->save();

				if ($newQty <= 0) {
					$sourceItem->setStatus(\Magento\InventoryApi\Api\Data\SourceItemInterface::STATUS_OUT_OF_STOCK);
				} else {
					$sourceItem->setStatus(\Magento\InventoryApi\Api\Data\SourceItemInterface::STATUS_IN_STOCK);
				}
				// Save the adjusted source item
			} catch (NoSuchEntityException $e) {
				$this->logger->error('Product not found: ' . $e->getMessage());
				return false;
			} catch (\Exception $e) {
				$this->logger->error('Error adjusting stock: ' . $e->getMessage());
				return false;
			}
        return true;
	}
}
