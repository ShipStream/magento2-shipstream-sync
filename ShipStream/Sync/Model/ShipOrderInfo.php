<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);
namespace ShipStream\Sync\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Psr\Log\LoggerInterface;

class ShipOrderInfo implements \ShipStream\Sync\Api\ShipOrderInfoInterface
{
    protected $shipmentRepository;
    protected $logger;
    protected $searchCriteriaBuilder;
    protected $orderRepository;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ShipmentRepositoryInterface $shipmentRepository,
        LoggerInterface $logger,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->orderRepository = $orderRepository;
        $this->shipmentRepository = $shipmentRepository;
        $this->logger = $logger;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function info($orderIncrementId)
    {
        $result = [];
        try {
            // Fetch the order using the increment ID
            $orderCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $orderIncrementId, 'eq')
                //->addFilter('status', 'ready_to_ship', 'eq') // Add status filter here
                ->create();
            $orderList = $this->orderRepository->getList($orderCriteria);
            $order = current($orderList->getItems());
            if (!$order) {
                throw new \Magento\Framework\Exception\NoSuchEntityException(__('No order found with increment ID %1', $orderIncrementId));
            }
            // Fetch the shipments for the retrieved order
            $shipmentCriteria = $this->searchCriteriaBuilder
                ->addFilter('order_id', $order->getEntityId(), 'eq')
                ->create();
            $shipmentData = [];
            //If no shipment created and only the orders data available
            $items = [];
            foreach ($order->getItems() as $item) {
                // Get product type
                $product = $item->getProduct();
                $productType = $product->getTypeId();
                $shipmentdata = $item->toArray();
                $shipmentdata['product_type'] = $productType;
                $items[] = $shipmentdata;
            }
            // Get shipping address details
            $shippingAddress = $order->getShippingAddress();
            $shippingAddressData = $shippingAddress ? [
                    'firstname' => $shippingAddress->getFirstname(),
                    'lastname' => $shippingAddress->getLastname(),
                    'street' => implode(' ', $shippingAddress->getStreet()),
                    'city' => $shippingAddress->getCity(),
                    'region' => $shippingAddress->getCity(),
                    'region_code' => $shippingAddress->getRegionCode(),
                    'country_id' => $shippingAddress->getCountryId(),
                    'phone' => $shippingAddress->getTelephone(),
                    'postcode' => $shippingAddress->getPostcode(),
                    // Add more address details as needed
                ] : null;
            // Get tracking information
            $tracks = [];
            // Get shipment comments
            $comments = [];
            $result[] = [
                    'order_increment_id' => $orderIncrementId,
                    'increment_id' => $orderIncrementId,
                    'items' => $items,
                    'status' => $order->getStatus(),
                    'shipping_method' => $order->getShippingMethod(),
                    'shipping_address' => $shippingAddressData,
                    'shipping_description' => $order->getShippingDescription(),
                    'tracks' => $tracks,
                    'comments' => $comments
                ];
            return json_encode($result, 1);
        } catch (\Exception $e) {
            $this->logger->info((string)__("Error in ShipOrderInfo: %1", $e->getMessage()));
            return json_encode($result, 1);
        }
    }
}
