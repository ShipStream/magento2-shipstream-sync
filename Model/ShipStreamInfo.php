<?php
declare(strict_types=1);

namespace ShipStream\Sync\Model;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Psr\Log\LoggerInterface;
use ShipStream\Sync\Api\ShipStreamInfoInterface;

class ShipStreamInfo implements ShipStreamInfoInterface
{
    private const MODULE_NAME = 'ShipStream_Sync';
    private const VERSION_KEY = 'setup_version';

    private ProductMetadataInterface $productMetadata;
    private ModuleListInterface $moduleList;
    private LoggerInterface $logger;

    public function __construct(
        ProductMetadataInterface $productMetadata,
        ModuleListInterface $moduleList,
        LoggerInterface $logger
    ) {
        $this->productMetadata = $productMetadata;
        $this->moduleList = $moduleList;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function infos($param): string
    {
        $data = [];

        try {
            $moduleInfo = $this->moduleList->getOne(self::MODULE_NAME);
            $data['shipstream_sync_version'] = $moduleInfo[self::VERSION_KEY] ?? null;
            $data['magento_edition'] = $this->productMetadata->getEdition() . ' ' . $this->productMetadata->getVersion();
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Error in %s::infos(): %s', __CLASS__, $e->getMessage())
            );
        }

        return json_encode($data);
    }
}
