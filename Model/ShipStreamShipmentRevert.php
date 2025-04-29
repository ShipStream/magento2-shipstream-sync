<?php

declare(strict_types=1);

namespace ShipStream\Sync\Model;

use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Webapi\Exception as WebapiException;
use Psr\Log\LoggerInterface;

class ShipStreamShipmentRevert implements \ShipStream\Sync\Api\ShipStreamShipmentRevertInterface
{
    private OrderFactory $orderFactory;
    private ShipmentFactory $shipmentFactory;
    private ShipmentCollectionFactory $shipmentCollectionFactory;
    private Transaction $transaction;
    private LoggerInterface $logger;

    public function __construct(
        OrderFactory $orderFactory,
        ShipmentFactory $shipmentFactory,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        Transaction $transaction,
        LoggerInterface $logger
    ) {
        $this->orderFactory = $orderFactory;
        $this->shipmentFactory = $shipmentFactory;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->transaction = $transaction;
        $this->logger = $logger;
    }

    public function revert($orderIncrementId, $shipmentIncrementId, $data)
    {
        try {
            $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            if (!$order->getId()) {
                throw new WebapiException(
                    __('Order %1 does not exist.', $orderIncrementId),
                    0,
                    WebapiException::HTTP_NOT_FOUND
                );
            }

            $shipment = $this->shipmentFactory->create($order)->load($shipmentIncrementId, 'increment_id');
            if (!$shipment->getId()) {
                throw new WebapiException(
                    __('Shipment %1 does not exist.', $shipmentIncrementId),
                    0,
                    WebapiException::HTTP_NOT_FOUND
                );
            }

            if ($shipment->getOrderId() !== $order->getId()) {
                throw new WebapiException(
                    __('Shipment %1 does not belong to order %2.', $shipmentIncrementId, $orderIncrementId),
                    0,
                    WebapiException::HTTP_BAD_REQUEST
                );
            }

            foreach ($shipment->getAllItems() as $item) {
                /* @var \Magento\Sales\Model\Order\Shipment\Item $item */
                $orderItem = $order->getItemById($item->getOrderItemId());
                if ($orderItem) {
                    $newShipped = max(0, $orderItem->getQtyShipped() - $item->getQty());
                    $orderItem->setQtyShipped($newShipped);
                    $orderItem->setLockedDoShip(false);
                }
            }

            $this->transaction
                ->addObject($shipment)
                ->addObject($order);

            $shipment->delete();

            $shipmentCollection = $this->shipmentCollectionFactory->create()
                ->setOrderFilter((int)$order->getId());
            if ($shipmentCollection->getSize() > 0) {
                throw new WebapiException(
                    __('Order %1 has other shipments; cannot fully revert.', $orderIncrementId),
                    0,
                    WebapiException::HTTP_CONFLICT
                );
            }

            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->setStatus('submitted');
            $order->addStatusHistoryComment(
                __('Shipment %1 reverted.', $shipmentIncrementId),
                'submitted'
            );

            $this->transaction->save();

            return true;
        } catch (WebapiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error(__(
                'Error reverting shipment %1 for order %2: %3',
                $shipmentIncrementId,
                $orderIncrementId,
                $e->getMessage()
            ));
            throw new WebapiException(
                __('Failed to revert shipment. %1', $e->getMessage()),
                0,
                WebapiException::HTTP_INTERNAL_ERROR
            );
        }
    }
}
