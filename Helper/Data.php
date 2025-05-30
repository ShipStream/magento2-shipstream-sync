<?php
declare(strict_types=1);

namespace ShipStream\Sync\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\FlagFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use Magento\InventoryApi\Api\Data\SourceInterfaceFactory;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryApi\Api\Data\StockInterfaceFactory;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Data extends AbstractHelper
{
    private const XML_PATH_GENERAL     = 'shipstream/general/';
    private const FLAG_PREFIX         = 'ShipStream_Sync/';
    private const DEFAULT_COUNTRY     = 'US';
    private const DEFAULT_POSTCODE    = '00001';

    private FlagFactory               $flagFactory;
    private Curl                      $curl;
    private SourceInterfaceFactory    $sourceFactory;
    private SourceRepositoryInterface $sourceRepository;
    private StockInterfaceFactory     $stockFactory;
    private StockRepositoryInterface  $stockRepository;
    private SearchCriteriaBuilder     $searchCriteriaBuilder;
    private FilterBuilder             $filterBuilder;

    public function __construct(
        Context $context,
        FlagFactory $flagFactory,
        SourceInterfaceFactory $sourceFactory,
        SourceRepositoryInterface $sourceRepository,
        StockInterfaceFactory $stockFactory,
        StockRepositoryInterface $stockRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterBuilder $filterBuilder,
        Curl $curl
    ) {
        parent::__construct($context);
        $this->flagFactory = $flagFactory;
        $this->sourceFactory = $sourceFactory;
        $this->sourceRepository = $sourceRepository;
        $this->stockFactory = $stockFactory;
        $this->stockRepository = $stockRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->curl = $curl;
    }

    public function isSyncEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_GENERAL . 'realtime_sync',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isSendEmailEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_GENERAL . 'send_shipment_email',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Set a flag and optionally ensure a source and stock exist.
     *
     * @param string      $flagCode
     * @param mixed       $value
     * @param string|null $sourceName
     * @param string|null $sourceCode
     * @param string|null $stockName
     * @return bool
     */
    public function setConfig(
        string $flagCode,
        $value,
        ?string $sourceName = null,
        ?string $sourceCode = null,
        ?string $stockName = null
    ): bool {
        if (! $this->isSyncEnabled()) {
            $this->_logger->error('Real time sync not enabled. Please check the config.');
            return false;
        }

        $this->saveFlag($flagCode, $value);

        if ($sourceName && $sourceCode) {
            try {
                $this->ensureSourceExists($sourceCode, $sourceName);
            } catch (\Exception $e) {
                $this->_logger->error('Failed to ensure source: ' . $e->getMessage());
                throw new \RuntimeException('Source creation failed: ' . $e->getMessage());
            }
        }

        if ($stockName) {
            try {
                $this->ensureStockExists($stockName);
            } catch (\Exception $e) {
                $this->_logger->error('Failed to ensure stock: ' . $e->getMessage());
                throw new \RuntimeException('Stock creation failed: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Retrieve stored flag value
     *
     * @param string $flagCode
     * @return mixed|false
     */
    public function getConfig(string $flagCode)
    {
        $flag = $this->flagFactory->create(['data' => ['flag_code' => self::FLAG_PREFIX . $flagCode]]);
        $flag->loadSelf();
        return $flag->getFlagData() ?: false;
    }

    /**
     * Perform HTTP callback to warehouse API
     *
     * @param string $method
     * @param array  $data
     * @return mixed
     */
    public function callback(string $method, array $data = [])
    {
        if (! $this->isSyncEnabled()) {
            $this->_logger->error('Real time sync not enabled. Please check the config.');
            return false;
        }

        $apiUrl = (string) $this->getConfig('warehouse_api_url');
        if (empty($apiUrl) || strpos($apiUrl, '{{method}}') === false) {
            $this->_logger->error('The warehouse API URL is required and must contain {{method}} placeholder.');
            return false;
        }

        $url = str_replace('{{method}}', $method, $apiUrl);

        if (! empty($data)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
        }

        try {
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_TIMEOUT, 300);
            $this->curl->setOption(CURLOPT_VERBOSE, true);
            $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 120);
            $this->curl->setOption(CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
            ]);

            $this->curl->get($url);
            $response = $this->curl->getBody();
            $this->_logger->info("Callback {$method} response: {$response}");
            
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->_logger->error('Error in callback: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Save flag data
     *
     * @param string $flagCode
     * @param mixed  $value
     */
    private function saveFlag(string $flagCode, $value): void
    {
        $flag = $this->flagFactory->create(['data' => ['flag_code' => self::FLAG_PREFIX . $flagCode]]);
        $flag->loadSelf();
        $flag->setFlagData($value)->save();
    }

    /**
     * Ensure a source exists by name, create if missing
     *
     * @param string $code
     * @param string $name
     * @throws \Exception
     */
    private function ensureSourceExists(string $code, string $name): void
    {
        // Check by source name instead of code
        $filter = $this->filterBuilder
            ->setField('name')
            ->setValue($name)
            ->setConditionType('eq')
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilters([$filter])
            ->create();

        $list = $this->sourceRepository->getList($searchCriteria);
        if ($list->getTotalCount() === 0) {
            $source = $this->sourceFactory->create();
            $source->setSourceCode($code);
            $source->setName($name);
            $source->setEnabled(true);
            $source->setCountryId(self::DEFAULT_COUNTRY);
            $source->setPostcode(self::DEFAULT_POSTCODE);
            $source->setUseDefaultCarrierConfig(true);

            $this->sourceRepository->save($source);
        }
    }

    /**
     * Ensure a stock exists, create if missing
     *
     * @param string $name
     * @throws \Exception
     */
    private function ensureStockExists(string $name): void
    {
        $filter = $this->filterBuilder
            ->setField('name')
            ->setValue($name)
            ->setConditionType('eq')
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilters([$filter])
            ->create();

        $list = $this->stockRepository->getList($searchCriteria);
        if ($list->getTotalCount() === 0) {
            $stock = $this->stockFactory->create();
            $stock->setName($name);
            $this->stockRepository->save($stock);
        }
    }
}
