<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Model;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Zend_Db_Select;
use Psr\Log\LoggerInterface;

class ShipStreamOrderFields implements \ShipStream\Sync\Api\ShipStreamOrderFieldsInterface
{
    protected $orderCollectionFactory;
    protected $filterBuilder;
    protected $searchCriteriaBuilder;
    protected $filterGroupBuilder;

    /**
     * Retrieve array of columns in order flat table.
     *
     * @param null|object|array $filters
     * @param array $cols
     * @return array
     */
    public function __construct(
        CollectionFactory $orderCollectionFactory,
        FilterBuilder $filterBuilder,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterGroupBuilder $filterGroupBuilder,
        LoggerInterface $logger,
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->filterBuilder = $filterBuilder;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->filterGroupBuilder = $filterGroupBuilder;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function selectFields($filtersInput)
    {
        try {
            $data = new \stdClass();
            $data->result = $filtersInput;
            $params = json_decode($data->result, true);
             // Extract filters and columns from parameters
            $filters = $params['filters'] ?? [];
            $cols = $filters['cols'] ?? [];
            // Create the order collection
            $collection = $this->orderCollectionFactory->create();
            // Apply date range filter if specified
            if (!empty($filters['updated_at'])) {
                $dateRange = $filters['updated_at'];
                if (isset($dateRange['from']) && isset($dateRange['to'])) {
                    $collection->addFieldToFilter('updated_at', ['from' => $dateRange['from'], 'to' => $dateRange['to']]);
                }
            }
            // Apply status filter if specified
            if (!empty($filters['status']) && !empty($filters['status']['in'])) {
                $collection->addFieldToFilter('status', ['in' => $filters['status']['in']]);
            }
            // Set the specific columns to be selected
            $collection->getSelect()->reset(Zend_Db_Select::COLUMNS)->columns($cols);
            // Iterate over the collection and gather the data
            $orders = [];
            foreach ($collection as $order) {
                $orders[] = $order->getData();
            }
            return json_encode($orders);
        } catch (Exception $e) {
            $this->logger->info("Error in ShipStreamOrderFields: ".$e->getMessage());
        }
    }
}
