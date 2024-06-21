<?php

/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace ShipStream\Sync\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagFactory;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceInterfaceFactory;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryApi\Api\Data\StockInterfaceFactory;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class Data extends AbstractHelper
{
    protected $scopeConfig;
    /**
     * @var SourceInterfaceFactory
     */
    private $sourceFactory;
    /**
     * @var SourceRepositoryInterface
     */
    private $sourceRepository;
    /**
     * @var StockInterfaceFactory
     */
    private $stockFactory;
    /**
     * @var StockRepositoryInterface
     */
    private $stockRepository;

    protected $logger;
    protected $curl;

    const XML_PATH_SHIPSTREAM_GENERAL = 'shipstream/general/';
    const XML_PATH_SHIPSTREAM_SOURCE = 'source_section/custom_group/';

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        FlagFactory $flagFactory,
        SourceInterfaceFactory $sourceFactory,
        SourceRepositoryInterface $sourceRepository,
        StockInterfaceFactory $stockFactory,
        StockRepositoryInterface $stockRepository,
        Curl $curl,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->scopeConfig = $scopeConfig;
        $this->flagFactory = $flagFactory;
        $this->sourceFactory = $sourceFactory;
        $this->sourceRepository = $sourceRepository;
        $this->stockFactory = $stockFactory;
        $this->stockRepository = $stockRepository;
        $this->curl = $curl;
        $this->logger = $logger;
    }

    // created a dinamyc function instead of multiple functions that does the action but for different properties
    public function isPropertyEnabled($property)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHIPSTREAM_GENERAL . $property,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    public function setConfig($flagCode, $value, $source_name, $source_code, $stock_name)
    {
        if (!$this->isPropertyEnabled('realtime_sync')) {
            $this->logger->error('Real time sync not enabled. Please check the config.');
            return false;
        }

        $flag_code = 'ShipStream_Sync/' . $flagCode;
        $flag = $this->flagFactory->create(['data' => ['flag_code' => $flag_code]]);
        $flag->loadSelf();
        // the if statement does the same thing, deleted
        $flag->setFlagData($value);
        $flag->save();
        if ($source_name == null) {
            return true;
        }

        //Create source and stock by reading the ShipStream Sync integration config namespace
        /** @var SourceInterface $source */
        $source = $this->sourceFactory->create();
        $source->setSourceCode($source_code);
        $source->setName($source_name);
        $source->setEnabled(true);
        $source->setCountryId('US');
        $source->setPostcode('00001');
        $source->setUseDefaultCarrierConfig(true);

        try {
            $this->sourceRepository->save($source);
        } catch (Exception $e) {
            $this->logger->error("Error on set config" . $e->getMessage());
            throw new \Exception('Failed to save source: ' . $e->getMessage());
            return false;
        }

        //Create stock
        /** @var StockInterface $stock */
        $stock = $this->stockFactory->create(); // Instantiate a stock object
        $stock->setName($stock_name);

        try {
            $this->stockRepository->save($stock);
        } catch (Exception $e) {
            $this->logger->error("Error on set config" . $e->getMessage());
            throw new \Exception('Failed to save stock: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    public function getConfig($flagCode)
    {
        $flag_code = 'ShipStream_Sync/' . $flagCode;
        $flag = $this->flagFactory->create(['data' => ['flag_code' => $flag_code]]);
        $flag->loadSelf();
        if ($flag->getFlagData()) {
            return $flag->getFlagData();
        }
        return false;
    }

    /**
     * Perform request to the warehouse API
     *
     * @param string $method
     * @param array $data
     * @return mixed
     */
    public function callback($method, $data = [])
    {
        if (!$this->isPropertyEnabled('realtime_sync')) {
            $this->logger->error('Real time sync not enabled. Please check the config.');
            return false;
        }

        try {
            $apiUrl = urldecode($this->getConfig('warehouse_api_url'));
            if (empty($apiUrl)) {
                $this->logger->error('The warehouse API URL is required.');
                return false;
            }
            if (false === strpos($apiUrl, '{{method}}')) {
                $this->logger->error('The warehouse API URL format is not valid.');
                return false;
            }
            $apiUrl = str_replace('{{method}}', $method, $apiUrl);
            // Append query parameters
            // Prepare data to be sent in the POST request and do the request
            $response = $this->prepareDataAndDoRequest($apiUrl, $data);

            $this->logger->error(" Callback Method: " . $method . " Response " . $response);

            if ($method == 'syncOrder') {
                // Use a regular expression to extract the JSON part
                if(empty($response)) {
                    $this->logger->error("No JSON string found in the output.\n");
                    return false;
                }

                preg_match('/\{"status":"[^"]+"\}/', $response, $matches);
                // Check if we found a match and decode the JSON part
                if (empty($matches)) {
                    $this->logger->error("No JSON string found in the output.\n");
                    return false;
                }
                $jsonString = $matches[0];
                $decodedJson = json_decode($jsonString);
                if ($decodedJson->status) {
                    // Output the status from the JSON string
                    return $decodedJson->status;
                }
            }
            return json_decode($response, true);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    private function prepareDataAndDoRequest($apiUrl, $data)
    {
        $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $this->curl->setOption(CURLOPT_TIMEOUT, 300); // Wait up to 30 seconds for a response
        $this->curl->setOption(CURLOPT_VERBOSE, true);
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 120);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $this->curl->setOption(CURLOPT_HTTPHEADER, $headers);

        if (!empty($data)) {
            $separator = strpos($apiUrl, '?') === false ? '?' : '&';
            $apiUrl .= $separator . http_build_query($data, '', '&');
        }

        $this->curl->get($apiUrl);
        return $this->curl->getBody();
    }
}