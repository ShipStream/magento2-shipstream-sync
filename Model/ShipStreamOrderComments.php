<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);
namespace ShipStream\Sync\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class ShipStreamOrderComments implements \ShipStream\Sync\Api\ShipStreamOrderCommentsInterface
{
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;
    protected $session;
    protected $logger;
    protected $transaction;
    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;
    /**
     * Constructor to inject dependencies
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger,
        SessionManagerInterface $session,
        Transaction $transaction
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
        $this->session = $session;
        $this->transaction = $transaction;
    }

    /**
     * {@inheritdoc}
     */
    public function addComment($orderIncrementId, $orderStatus, $comment = null)
    {
        try {
            // Build the search criteria to find the order by increment ID
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $orderIncrementId, 'eq')
                ->create();
            // Retrieve the list of orders matching the search criteria
            $orderList = $this->orderRepository->getList($searchCriteria);
            // Check if any order was found
            if ($orderList->getTotalCount() > 0) {
                $order = array_values($orderList->getItems())[0];
                // Add the comment
                $statusHistory = $order->addStatusHistoryComment($comment);
                $statusHistory->setIsCustomerNotified(false);
                $statusHistory->setIsVisibleOnFront(false);
                $statusHistory->setStatus($orderStatus);
                $statusHistory->save();
                $order->addStatusHistory($statusHistory);
                $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
                $order->setStatus($orderStatus);
                $order->save();
                // Save the changes to the order
                $this->logger->info(__('Order history updated: %1 %2 %3 comment: %4', $orderIncrementId, $orderStatus, $order->getStatus(), $comment));
                return true;
            } else {
                // Handle the case where no order with that increment ID is found
                $this->logger->info(__("Order not found for increment ID : %1", $orderIncrementId));
                return false;
            }
        } catch (NoSuchEntityException $e) {
            // Handle the case where the order does not exist
            $this->logger->info(__("Order not found: %1", $e->getMessage()));
            return false;
        } catch (\Exception $e) {
            // Handle other exceptions
            $this->logger->info(__("An error occurred: %1", $e->getMessage()));
            return false;
        }
    }
}
