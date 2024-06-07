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
	public function isSyncEnabled()
	{
		return $this->scopeConfig->isSetFlag(
			self::XML_PATH_SHIPSTREAM_GENERAL . 'realtime_sync',
			\Magento\Store\Model\ScopeInterface::SCOPE_STORE
		);
	}
	public function isSendEmailEnabled()
	{
		return $this->scopeConfig->isSetFlag(
			self::XML_PATH_SHIPSTREAM_GENERAL . 'send_shipment_email',
			\Magento\Store\Model\ScopeInterface::SCOPE_STORE
		);
	}
	public function setConfig($flagCode, $value, $source_name, $source_code, $stock_name)
	{
		if ($this->isSyncEnabled()) {
			$flag_code = 'ShipStream_Sync/' . $flagCode;
			$flag = $this->flagFactory->create(['data' => ['flag_code' => $flag_code]]);
			$flag->loadSelf();
			if (!$flag->getFlagData()) {
				$flag->setFlagData($value);
			} else {
				$flag->setFlagData($value);
			}
			$flag->save();
			if ($source_name == null) {
				return TRUE;
			}
			//return $flag_code;
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
				return FALSE;
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
				return FALSE;
			}
			return TRUE;
		} else {
			$this->logger->error('Real time sync not enabled. Please check the config.');
			return FALSE;
		}
	}
	public function getConfig($flagCode)
	{
		$flag_code = 'ShipStream_Sync/' . $flagCode;
		$flag = $this->flagFactory->create(['data' => ['flag_code' => $flag_code]]);
		$flag->loadSelf();
		if ($flag->getFlagData()) {
			return $flag->getFlagData();
		}
		return FALSE;
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
		if ($this->isSyncEnabled()) {
			try {
				$apiUrl = urldecode($this->getConfig('warehouse_api_url'));
				if (empty($apiUrl)) {
					$this->logger->error('The warehouse API URL is required.');
					return FALSE;
				}
				if (FALSE === strpos($apiUrl, '{{method}}')) {
					$this->logger->error('The warehouse API URL format is not valid.');
					return FALSE;
				}
				$apiUrl = str_replace('{{method}}', $method, $apiUrl);
				// Append query parameters
				// Prepare data to be sent in the POST request
				$this->curl->setOption(CURLOPT_RETURNTRANSFER, TRUE);
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
				$response = $this->curl->getBody();
				$this->logger->error(" Callback Method: " . $method . " Response " . $response);
				if ($method == 'syncOrder') {
					// Example combined output as a string
					$combinedOutput = $response;
					// Use a regular expression to extract the JSON part
					preg_match('/\{"status":"[^"]+"\}/', $combinedOutput, $matches);
					// Check if we found a match and decode the JSON part
					if (!empty($matches)) {
						$jsonString = $matches[0];
						$decodedJson = json_decode($jsonString);
						if ($decodedJson->status)
						// Output the status from the JSON string
						{
							//$this->logger->error("Status from JSON: " . $decodedJson->status . "\n");
							return $decodedJson->status;
						}
					} else {
						$this->logger->error("No JSON string found in the output.\n");
						return FALSE;
					}
				}
				return json_decode($response, TRUE);
			} catch (Exception $e) {
				$this->logger->error($e->getMessage());
			}
		} else {
			$this->logger->error('Real time sync not enabled. Please check the config.');
			return FALSE;
		}
	}
}
