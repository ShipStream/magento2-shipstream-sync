<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Observer\Sales;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Status\History as OrderStatusHistory;
use Psr\Log\LoggerInterface;
use ShipStream\Sync\Helper\Data;

class OrderSaveCommitAfter implements ObserverInterface
{
    protected $orderRepository;
    protected $dataHelper;
    protected $logger;
    protected $session;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        Data $dataHelper,
        LoggerInterface $logger,
        SessionManagerInterface $session
    ) {
        $this->orderRepository = $orderRepository;
        $this->dataHelper = $dataHelper;
        $this->logger = $logger;
        $this->session = $session;
    }

    /**
     * Execute observer
     */
    public function execute(Observer $observer)
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $class = '';
        foreach ($backtrace as $trace) {
            if (!empty($trace['class']) && $trace['class'] == 'ShipStream\Sync\Model\ShipStreamOrderComments') {
                $class = $trace['class'];
                $this->logger->info(__('Class: %1 - Function: %2', $trace['class'], $trace['function']));
                break;
            }
        }
        if ($class == 'ShipStream\Sync\Model\ShipStreamOrderComments') {
            return;
        }
        /**
        * @var OrderStatusHistory $history
        */
        $history = $observer->getEvent()->getData('data_object');
        if ($history) {
            $order = $history;
            if ($order) {
                $this->logger->info(__('Order ID %1: %2', 2, $order->getStatus()));
                // More processing can be done here as needed
                if ($order->getIsVirtual()
                    && $order->getState() == \Magento\Sales\Model\Order::STATE_PROCESSING
                    && ($order->getStatus() == 'ready_to_ship' || $order->getStatus() == 'submitted')
                ) {
                    $order->addStatusHistoryComment('Changed order status to "Complete" as the order is virtual.')
                        ->setIsCustomerNotified(false);
                    $this->orderRepository->save($order);
                }
                if ($this->dataHelper->isSyncEnabled()
                    && !$order->getIsVirtual()
                    && $order->getState() == \Magento\Sales\Model\Order::STATE_PROCESSING
                    && $order->dataHasChangedFor('status')
                    && $order->getStatus() == 'ready_to_ship'
                ) {
                    try {
                        $response = $this->dataHelper->callback(
                            'syncOrder',
                            [
                                'increment_id' => $order->getIncrementId(),
                                'status' => $order->getStatus(),
                            ]
                        );

                        if (isset($response['error'])) {
                            $this->logger->error(springtf('Error in callback response: %s', $statusFrom['error']));
                        } elseif (isset($response['status'])) {
                            $order->setStatus($response['status']);
                            $order->save();
                        } elseif (isset($response['queued'])) {
                            if ($response['queued']) {
                                $this->logger->info('Order is queued for processing.');
                            } else {
                                $this->logger->info('Order is not queued for processing.');
                            }

                        } else {
                            $this->logger->error(__('Invalid response from callback.'));
                        }

                        $this->logger->info('Order status after callback: ' . $order->getStatus());
                    } catch (\Exception $e) {
                        $this->logger->critical($e);
                    }
                }
            } else {
                $this->logger->error(__('No order associated with the history.'));
            }
        } else {
            $this->logger->error(__('History object is null.'));
        }
    }
}
