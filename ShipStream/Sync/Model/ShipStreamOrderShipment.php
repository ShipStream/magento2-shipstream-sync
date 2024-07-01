<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\TransactionFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventoryShipping\Model\SourceDeductionRequestFromShipmentFactory;
use Magento\InventorySourceDeductionApi\Model\SourceDeductionServiceInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Convert\Order as OrderConverter;
use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Psr\Log\LoggerInterface;
use ShipStream\Sync\Helper\Data;

class ShipStreamOrderShipment implements \ShipStream\Sync\Api\ShipStreamOrderShipmentInterface
{
    protected $orderRepository;
    protected $orderConverter;
    protected $shipmentRepository;
    protected $transactionFactory;
    protected $searchCriteriaBuilder;
    protected $trackFactory;
    protected $sourceDeductionService;
    protected $sourceDeductionRequestFactory;
    protected $logger;
    protected $sourceDeductionRequestsGenerator;
    protected $cronHelper;
    protected $dataHelper;
    protected $shipmentSender;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderConverter $orderConverter,
        ShipmentRepositoryInterface $shipmentRepository,
        TransactionFactory $transactionFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        TrackFactory $trackFactory,
        SourceDeductionServiceInterface $sourceDeductionService,
        SourceDeductionRequestFromShipmentFactory $sourceDeductionRequestsGenerator,
        LoggerInterface $logger,
        Cron $cronHelper,
        Data $dataHelper,
        ShipmentSender $shipmentSender
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderConverter = $orderConverter;
        $this->shipmentRepository = $shipmentRepository;
        $this->transactionFactory = $transactionFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->trackFactory = $trackFactory;
        $this->sourceDeductionService = $sourceDeductionService;
        $this->sourceDeductionRequestsGenerator = $sourceDeductionRequestsGenerator;
        $this->logger = $logger;
        $this->cronHelper = $cronHelper;
        $this->dataHelper = $dataHelper;
        $this->shipmentSender = $shipmentSender;
    }

    //Create shipment and update tracking number
    public function createWithTracking($orderIncrementId, $dataJson)
    {
        $result=[];
        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $orderIncrementId, 'eq')
                ->create();
            $orderList = $this->orderRepository->getList($searchCriteria);
            if ($orderList->getTotalCount() == 0) {
                $this->logger->error(__('Order does not exist.'));
                return null;
            }
            $order = current($orderList->getItems());
            $dataArray = json_decode($dataJson, true);
            $data = $dataArray['data'];
        } catch (\Exception $e) {
            $this->logger->error(__('Error processing order: %1', $e->getMessage()));
            return null;
        }
        if (!$order->canShip()) {
            $this->logger->error(__('Cannot do shipment for order.'));
            return null;
        }

        $itemsQty = [];
        if ($data['status'] != "complete") {
            $itemsQty = $this->_getShippedItemsQty($order, $data);
            if (sizeof($itemsQty) == 0) {
                $this->logger->info(__('Decimal qty is not allowed to ship in Magento'));
                return null;
            }
        }
        $comments = $this->_getCommentsData($order, $data);
        $shipment = $this->orderConverter->toShipment($order);
        foreach ($itemsQty as $orderItemId => $qty) {
            $orderItem = $order->getItemById($orderItemId);
            if ($orderItem) {
                $shipmentItem = $this->orderConverter->itemToShipmentItem($orderItem)->setQty($qty);
                $shipment->addItem($shipmentItem);
            }
        }
        try {
            foreach ($data['packages'] as $package) {
                foreach ($package['tracking_numbers'] as $trackingNumber) {
                    $track = $this->trackFactory->create()
                        ->setNumber($trackingNumber)
                        ->setCarrierCode($data['carrier'])
                        ->setTitle($data['service_description']);
                    $shipment->addTrack($track);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(__("Multi track : %1", $e->getMessage()));
        }
        $shipment->register();
        if ($comments) {
            $shipment->addComment($comments);
        }
        $sourceCode =  $this->cronHelper->getCurrentSourceCode();
        // Create the sales channel (or sales event) based on your business logic
        $salesChannel = [
                'type' => SalesChannelInterface::TYPE_WEBSITE,
                'code' => $order->getStore()->getWebsite()->getCode()
            ];
        $transaction = $this->transactionFactory->create();
        try {
            $shipment->getExtensionAttributes()->setSourceCode($sourceCode);
            $transaction->addObject($shipment)
                ->addObject($order)
                ->save();
            $this->logger->info(__('Shipment created successfully for order ID %1', $order->getIncrementId()));
        } catch (\Exception $e) {
            $this->logger->error(__('Error creating shipment:  %1', $e->getMessage()));
            return null;
        }
        $email = $this->dataHelper->isSendEmailEnabled();
        if ($email) {
            try {
                $this->shipmentSender->send($shipment);
                $shipment->setEmailSent(true);
                $shipment->save();
            } catch (\Exception $e) {
                $this->logger->error(__('Error sending shipment email: %1', $e->getMessage()));
            }
        }
        $result['shipment_increment_id'] = $shipment->getIncrementId();
        return json_encode($result, 1);
    }

    //Get shipped items
    protected function _getShippedItemsQty($order, $data)
    {
        $orderItems = $order->getAllVisibleItems();
        $itemShippedQty = [];
        $itemReference = [];
        // Map order items by their SKU or any unique identifier
        foreach ($orderItems as $orderItem) {
            $itemReference[$orderItem->getSku()] = $orderItem->getItemId();
        }
        // Aggregate quantities from the data provided by external sources or shipment data
        foreach ($data['packages'] as $package) {
            foreach ($package['items'] as $item) {
                // Using SKU to match, you can switch to other unique identifiers if needed
                $sku = $item['order_item_sku'];
                $itemId = $itemReference[$sku] ?? null;
                if ($itemId) {
                    if (isset($itemShippedQty[$itemId])) {
                        $itemShippedQty[$itemId] += floatval($item['quantity']);
                    } else {
                        $itemShippedQty[$itemId] = floatval($item['quantity']);
                    }
                }
            }
        }
        // Round fractional quantities and remove items with zero quantity
        foreach ($itemShippedQty as $itemId => $quantity) {
            $fraction = fmod($quantity, 1);
            $wholeNumber = intval($quantity);
            if ($fraction >= 0.9999) {
                $quantity = $wholeNumber + round($fraction);
            } else {
                $quantity = $wholeNumber;
            }
            $itemShippedQty[$itemId] = $quantity;
            if ($itemShippedQty[$itemId] == 0) {
                unset($itemShippedQty[$itemId]);
            }
        }
        return $itemShippedQty;
    }

    protected function _getCommentsData($order, $data)
    {
        $orderComments = [];
        // Get Item name & SKU from Magento order items
        $orderItemsData = $order->getAllVisibleItems(); // Use getAllVisibleItems to avoid child items of configurable or bundled products
        foreach ($orderItemsData as $orderItem) {
            $orderComments[$orderItem->getSku()]['sku'] = $orderItem->getSku();
            $orderComments[$orderItem->getSku()]['name'] = $orderItem->getName();
        }
        // Get lot data of order items
        foreach ($data['items'] as $item) {
            if (isset($orderComments[$item['sku']])) {
                foreach ($item['lot_data'] as $lot_data) {
                    $orderComments[$item['sku']]['lotdata'][] = $lot_data;
                }
            }
        }
        // Get collected data of packages from shipment packages
        foreach ($data['packages'] as $package) {
            // Mapping internal order_item_id & SKU to Magento SKU for collected data
            $orderItems = [];
            foreach ($package['items'] as $item) {
                $orderItems[$item['order_item_id']] = $item['sku'];
            }
            // Adding package data value under relevant Order Item
            foreach ($package['package_data'] as $packageData) {
                if (isset($orderItems[$packageData['order_item_id']])) {
                    $sku = $orderItems[$packageData['order_item_id']];
                    if (isset($orderComments[$sku])) {
                        $orderComments[$sku]['collected_data']['label'] = $packageData['label'];
                        $orderComments[$sku]['collected_data']['value'] = $packageData['value'];
                    }
                }
            }
        }
        // Format array to discard indexes
        $comments = array_values($orderComments);
        // Format comments data into yaml format if yaml plugin is configured
        if (function_exists('yaml_emit')) {
            return yaml_emit($comments);
        } else {
            return json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
}
