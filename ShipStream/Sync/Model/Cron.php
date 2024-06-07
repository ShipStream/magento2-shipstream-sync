<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Model;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use ShipStream\Sync\Helper\Data;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
class Cron
{
	protected $productMetadata;
	protected $connection;
	protected $moduleList;
	protected $dataHelper;
	protected $logger;
	protected $order;
	protected $scopeConfig;
        private $resourceConnection;
        private $storeManager;
        private $stockResolver;
        private $sourceItemRepository;
	private $searchCriteriaBuilder;
	private $sourceItemInterface;
        private $sourceItemFactory;
        private $sourceItemsSave;
	private $sourcesAssignedToStock;
    public function __construct(
			ProductMetadataInterface $productMetadata,
			ModuleListInterface $moduleList,
			Data $dataHelper,
			LoggerInterface $logger,
			ScopeConfigInterface $scopeConfig,
			Order $order,
			ResourceConnection $resourceConnection,
			StoreManagerInterface $storeManager,
			StockResolverInterface $stockResolver,
			StockItemRepositoryInterface $stockItemRepository,
			SourceItemInterface $sourceItemInterface,
			SourceItemRepositoryInterface $sourceItemRepository,
			SearchCriteriaBuilder $searchCriteriaBuilder,
			SourceItemInterfaceFactory $sourceItemFactory,
			SourceItemsSaveInterface $sourceItemsSave
			)
			{
				$this->productMetadata = $productMetadata;
				$this->moduleList = $moduleList;
				$this->dataHelper = $dataHelper;
				$this->logger = $logger;
				$this->scopeConfig = $scopeConfig;
				$this->order = $order;
				$this->resourceConnection = $resourceConnection->getConnection();
				$this->storeManager = $storeManager;
				$this->stockResolver = $stockResolver;
				$this->stockItemRepository = $stockItemRepository;
				$this->sourceItemInterface = $sourceItemInterface;
				$this->sourceItemRepository = $sourceItemRepository;
				$this->searchCriteriaBuilder = $searchCriteriaBuilder;
				$this->sourceItemFactory = $sourceItemFactory;
				$this->sourceItemsSave = $sourceItemsSave;
			}
  	/**
     * {@inheritdoc}
     */
	public function syncInventory()
	{
		return $this->fullInventorySync(FALSE);
	}
	/**
     * {@inheritdoc}
     */
	public function getSourceStockId()
	{
		$websiteCode = $this->storeManager->getWebsite()->getCode();
		$stock = $this->stockResolver->execute('website',$websiteCode);
		return $stock->getStockId();
	}
	/**
     * {@inheritdoc}
     */
	public function getCurrentSourceCode()
	{
		$stockId=$this->getSourceStockId();
		$tableName = $this->resourceConnection->getTableName('inventory_source_stock_link');
		$select = $this->resourceConnection->select()
			->from($tableName, 'source_code')
			->where('stock_id = ?', $stockId);
		$sourceCode = $this->resourceConnection->fetchOne($select); // fetchOne to get the first result directly
		//$this->logger->info("Source".$sourceCode);
		return $sourceCode;
	}
	/**
     * {@inheritdoc}
     */
	public function getSourceItemSku($sku,$sourceCode)
	{
		//$this->logger->info("sku".$sku.":"."source:".$sourceCode);
		$searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('sku', $sku, 'eq')
            ->addFilter('source_code', $sourceCode, 'eq')
            ->create();
        $sourceItems = $this->sourceItemRepository->getList($searchCriteria)->getItems();
        if (!empty($sourceItems)) {
            $sourceItem = array_shift($sourceItems); // Assuming there's only one item per sku and source code
            return $sourceItem;
        }
	}
	/**
     * {@inheritdoc}
     */
	public function _getSourceInventory()
	{
		try{
			$data=$this->dataHelper->callback('inventoryWithLock');
			return empty($data['skus']) ? [] : $data['skus'];
		}
		catch(Exception $e)
		{
				$this->logger->error("Error in sync inventory:".$e->getMessage());
		}
	}
	 /**
     * Synchronize Magento inventory with the warehouse inventory
     *
     * @return void
     * @throws Exception
     */
    public function fullInventorySync($sleep = TRUE)
    {
		if (!$this->dataHelper->isSyncEnabled()) {
            return;
        }
        if ($sleep) {
            sleep(random_int(0, 60)); // Avoid stampeding the server
        }
		try{
			$this->resourceConnection->beginTransaction();
			$this->logger->info('Beginning inventory sync..');
			}
			catch(Exception $e)
			{
				$this->logger->info($e->getMessage());
			}
				$_source = $this->_getSourceInventory();
			try {
            if (!empty($_source) && is_array($_source)) {
                foreach (array_chunk($_source, 5000, true) as $source) {
                    try {
							$this->logger->info("Inside Inventory update..");
							$target = $this->_getTargetInventory(array_keys($source));
                        // Get qty of order items that are in processing state and not submitted to shipstream
                        $processingQty = $this->_getProcessingOrderItemsQty(array_keys($source));
                        foreach ($_source as $sku => $qty) {
                            if (!isset($target[$sku])) {
                                continue;
                            }
                            $qty = floor(floatval($qty));
                            $syncQty = $qty;
                            if (isset($processingQty[$sku]['qty'])) {
                                $syncQty = floor($qty - floatval($processingQty[$sku]['qty']));
                            }
                            $targetQty = floatval($target[$sku]['qty']);
                            if ($syncQty == $targetQty) {
                                continue;
                            }
                            $this->logger->info("SKU: $sku remote qty is $qty and local is $targetQty and syncQty $syncQty");
							try {
								$sourceCode=$this->getCurrentSourceCode();
								$this->logger->info('First Source Code: ' . $sourceCode." item ".$target[$sku]['sourceItemId']);
								  /** @var SourceItemInterface $sourceItem */
								$sourceItem = $this->getSourceItemSku($sku, $sourceCode);
								$oldQty = $sourceItem->getQuantity();
								$sourceItem->setQuantity($syncQty);
								$sourceItem->save();
								if ($oldQty < 1 && $sourceItem->getStatus() === SourceItemInterface::STATUS_OUT_OF_STOCK && $syncQty > 0) {
									$sourceItem->setStatus(SourceItemInterface::STATUS_IN_STOCK);
								}
								$this->logger->info('Stock updated for SKU: ' . $sku . ' at source: ' . $sourceCode);
							} catch (\Exception $e) {
								$this->logger->error('Error updating stock for SKU: ' . $sku . ' with message: ' . $e->getMessage());
							}
                        }
                        $this->resourceConnection->commit();
						return TRUE;
                    } catch (\Exception $e) {
                        $this->resourceConnection->rollback();
                        throw $e;
                    }
                }
            }
        } catch (\Exception $exception) {
			$this->dataHelper->callback('unlockOrderImport');
            throw $exception;
        }
	}
	/**
     * Retrieve Magento inventory
     *
     * @param array $skus
     * @return array
     */
    protected function _getTargetInventory(array $skus)
    {
		try
		{
			//This code for update source and stock base
			$productTable = $this->resourceConnection->getTableName('catalog_product_entity');
			$sourceItemTable = $this->resourceConnection->getTableName('inventory_source_item');
			$columns = [
				'sku' => 'p.sku',
				'sourceItemId' => 'si.source_item_id',
				'source_code' => 'si.source_code',
				'qty' => 'si.quantity'
			];
			$select = $this->resourceConnection->select()->forUpdate(true)
				->from(['p' => $productTable], $columns)
				->joinInner(['si' => $sourceItemTable], 'p.sku = si.sku', [])
				->where("si.source_code = '".$this->getCurrentSourceCode()."' and si.sku IN (?)", $skus);
			return $this->resourceConnection->fetchAssoc($select);
		}
		catch(Exception $e)
		{
			  $this->logger->info($e->getMessage());
		}
	}
	 /**
     * Retrieve Magento order items qty that are in processing state and not submitted to shipstream
     * @param array $skus
     * @return mixed
     */
    protected function _getProcessingOrderItemsQty(array $skus)
    {
		 $orderStates = [$this->order::STATE_COMPLETE,
						$this->order::STATE_CLOSED,
						$this->order::STATE_CANCELED];
        $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $columns = [
            'sku' => 'soi.sku',
            'qty' => new \Zend_Db_Expr('GREATEST(0, SUM(soi.qty_ordered - soi.qty_canceled - soi.qty_refunded))')
        ];
        $select = $this->resourceConnection->select()->forUpdate(true)
            ->from(['soi' => $orderItemTable], $columns)
            ->join(['so' => $orderTable], 'so.entity_id = soi.order_id', [])
            ->where('so.state NOT IN (?)', $orderStates)
            ->where('so.status != ?', "submitted")
            ->where('so.state != ?', "holded")
            ->orWhere('so.hold_before_status != ?', "submitted")
            ->where('soi.sku IN (?)', $skus)
            ->where('soi.product_type = ?', 'simple')
            ->group('soi.sku');
        return $this->resourceConnection->fetchAssoc($select);
	}
}
