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
use ShipStream\Sync\Model\Cron;
use Psr\Log\LoggerInterface;

class ShipStreamManagement implements \ShipStream\Sync\Api\ShipStreamManagementInterface
{
	protected $productMetadata;
	protected $moduleList;
	protected $dataHelper;
	protected $cron;
	protected $logger;
	protected $scopeConfig;

    public function __construct(
			ProductMetadataInterface $productMetadata,
			ModuleListInterface $moduleList,
			Data $dataHelper,
			Cron $cron,
			LoggerInterface $logger,
			ScopeConfigInterface $scopeConfig
			)
			{
				$this->productMetadata = $productMetadata;
				$this->moduleList = $moduleList;
				$this->dataHelper = $dataHelper;
				$this->cron = $cron;
				$this->logger = $logger;
				$this->scopeConfig = $scopeConfig;
			}
	/**
     * {@inheritdoc}
     */
	public function syncInventory()
	{
		$result= $this->cron->syncInventory();
		if($result)
			return 'success';
		else
			return 'error';
	}
	/**
     * {@inheritdoc}
     */
	public function setConfig($path,$value,$source,$source_code,$stock)
	{
		return $this->dataHelper->setConfig($path,$value,$source,$source_code,$stock);
	}
}
