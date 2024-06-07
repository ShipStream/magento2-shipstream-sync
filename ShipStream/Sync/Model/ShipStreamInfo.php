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
class ShipStreamInfo implements \ShipStream\Sync\Api\ShipStreamInfoInterface
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
	public function infos($param)
	{
		try{
		$moduleName = 'ShipStream_Sync';
		$moduleInfo=[];
		$modules = $this->moduleList->getNames();
        foreach ($modules as $module) {
            if ($module == $moduleName) {
                $moduleInfo['setup_version']= $this->moduleList->getOne($module)['setup_version'];
				break;
            }
        }
		$result=[];
        $result['shipstream_sync_version']=isset($moduleInfo['setup_version']) ? $moduleInfo['setup_version'] : null;
		$version = $this->productMetadata->getVersion();
		$result['magento_edition']=$version;
		return json_encode($result);
		}
		catch(Exception $e)
		{
			$this->logger->info("Error in ShipStreamInfo : ".$e->getMessage());
		}
	}
}