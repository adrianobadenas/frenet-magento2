<?php
/**
 * Frenet Shipping Gateway
 *
 * @category Frenet
 * @package Frenet\Shipping
 * @author Tiago Sampaio <tiago@tiagosampaio.com>
 * @link https://github.com/tiagosampaio
 * @link https://tiagosampaio.com
 *
 * Copyright (c) 2019.
 */

declare(strict_types = 1);

namespace Frenet\Shipping\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Frenet\ObjectType\Entity\Shipping\Quote\ServiceInterface as QuoteServiceInterface;

/**
 * Class Frenet
 *
 * @package Frenet\Shipping\Model\Shipping\Carrier
 */
class Frenet extends AbstractCarrierOnline implements CarrierInterface
{
    /**
     * @var string
     */
    const CARRIER_CODE = 'frenetshipping';

    /**
     * @var string
     */
    const STR_SEPARATOR = ' - ';
    
    /**
     * @var string
     */
    protected $_code = self::CARRIER_CODE;
    
    /**
     * @var array
     */
    private $errors = [];
    
    /**
     * @var null
     */
    private $result;
    
    /**
     * @var \Frenet\Shipping\Api\CalculatorInterface
     */
    private $calculator;

    /**
     * @var \Frenet\Shipping\Model\TrackingInterface
     */
    private $trackingService;

    /**
     * @var \Frenet\Shipping\Model\ServiceFinderInterface
     */
    private $serviceFinder;
    
    /**
     * @var \Frenet\Shipping\Model\Config
     */
    private $config;
    
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Xml\Security $xmlSecurity,
        \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Frenet\Shipping\Api\CalculatorInterface $calculator,
        \Frenet\Shipping\Model\TrackingInterface $trackingService,
        \Frenet\Shipping\Model\ServiceFinderInterface $serviceFinder,
        \Frenet\Shipping\Model\Config $config,
        array $data = []
    ) {
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );

        $this->trackingService = $trackingService;
        $this->calculator = $calculator;
        $this->serviceFinder = $serviceFinder;
        $this->config = $config;
    }
    
    /**
     * Collect and get rates
     *
     * @param RateRequest $request
     *
     * @return \Magento\Framework\DataObject|bool|null
     * @api
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->canCollectRates()) {
            $errorMessage = $this->getErrorMessage();
            $this->_logger->debug("Frenet canCollectRates: " . $errorMessage);
            
            return $errorMessage;
        }
        
        /** @var array $results */
        if (!$results = $this->calculator->getQuote($request)) {
            return $this->result;
        }
        
        $this->prepareResult($results);
        
        return $this->result;
    }
    
    /**
     * Checks if shipping method is correctly configured
     *
     * @return bool
     */
    public function canCollectRates()
    {
        /** Validate carrier active flag */
        if (!$this->config->isActive()) {
            return false;
        }
        
        /** @var int $store */
        $store = $this->getStore();
        
        /** Validate origin postcode */
        if (!$this->config->getOriginPostcode($store)) {
            return false;
        }
        
        /** Validate frenet token */
        if (!$this->config->getToken()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Make this module compatible with older versions of Magento 2.
     *
     * @param \Magento\Framework\DataObject $request
     *
     * @return $this|bool|\Magento\Framework\DataObject
     */
    public function proccessAdditionalValidation(\Magento\Framework\DataObject $request)
    {
        return $this->processAdditionalValidation($request);
    }
    
    /**
     * Processing additional validation (quote data) to check if carrier applicable.
     *
     * @param \Magento\Quote\Model\Quote\Address\RateRequest $request
     *
     * @return $this|bool|\Magento\Framework\DataObject
     */
    public function processAdditionalValidation(\Magento\Framework\DataObject $request)
    {
        /** Validate request items data */
        if (empty($this->getAllItems($request))) {
            $this->errors[] = __('There is no items in this order');
        }
        
        /** Validate destination postcode */
        if (!$request->getDestPostcode()) {
            $this->errors[] = __('Please inform the destination postcode');
        }

        /** Validate destination postcode */
        if (!((int) $this->normalizePostcode($request->getDestPostcode()))) {
            $this->errors[] = __('Please inform a valid postcode');
        }
        
        if (!empty($this->errors)) {
            /** @var \Magento\Quote\Model\Quote\Address\RateResult\Error $error */
            $error = $this->_rateErrorFactory->create([
                'carrier' => $this->_code,
                'carrier_title' => $this->config->getCarrierConfig('title'),
                'error_message' => implode(', ', $this->errors)
            ]);
            
            $this->debugErrors($error);
            
            return $error;
        }
        
        return $this;
    }
    
    /**
     * Get allowed shipping methods
     *
     * @return array
     * @api
     */
    public function getAllowedMethods()
    {
        return [self::CARRIER_CODE => $this->config->getCarrierConfig('name')];
    }

    /**
     * Get tracking
     *
     * @param string|string[] $trackingNumbers
     * @return Result
     */
    public function getTracking($trackingNumbers)
    {
        if (!is_array($trackingNumbers)) {
            $trackingNumbers = [$trackingNumbers];
        }

        $this->prepareTracking($trackingNumbers);

        return $this->_result;
    }

    /**
     * @param string[] $trackingNumbers
     * @return \Magento\Shipping\Model\Tracking\Result
     */
    private function prepareTracking(array $trackingNumbers)
    {
        /** @var \Magento\Shipping\Model\Tracking\Result $result */
        $result = $this->_trackFactory->create();

        /** @var string $trackingNumber */
        foreach ($trackingNumbers as $trackingNumber) {
            /** @var \Frenet\ObjectType\Entity\Shipping\Info\ServiceInterface $service */
            $service = $this->serviceFinder->findByTrackingNumber($trackingNumber);
            $serviceCode = $service ? $service->getServiceCode() : null;

            /** @var \Magento\Shipping\Model\Tracking\Result\Status $status */
            $status = $this->_trackStatusFactory->create();
            $status->setCarrier(self::CARRIER_CODE);
            $status->setCarrierTitle($this->getConfigData('title'));
            $status->setTracking($trackingNumber);
            $status->setPopup(1);
            $status->setTrackSummary($this->prepareTrackingInformation($status, $trackingNumber, $serviceCode));
            $result->append($status);
        }

        $this->_result = $result;

        return $result;
    }

    /**
     * @param \Magento\Shipping\Model\Tracking\Result\Status $status
     * @param string $trackingNumber
     * @param string $shippingServiceCode
     * @return string
     */
    private function prepareTrackingInformation($status, $trackingNumber, $shippingServiceCode)
    {
        /** @var \Frenet\ObjectType\Entity\Tracking\TrackingInfoInterface $trackingInfo */
        $trackingInfo = $this->trackingService->track($trackingNumber, $shippingServiceCode);

        $events = $trackingInfo->getTrackingEvents();

        if (empty($events)) {
            return null;
        }

        /** @var \Frenet\ObjectType\Entity\Tracking\TrackingInfo\EventInterface $event */
        $event = end($events);

        $status->setStatus($event->getEventDescription());
        $status->setDeliveryLocation($event->getEventLocation());
        $status->setShippedDate($event->getEventDatetime());
        $status->setService($event->getTrackingInfo()->getServiceDescription());
    }

    /**
     * @inheritdoc
     */
    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
    {
        return $this;
    }
    
    /**
     * @param array $items
     *
     * @return $this
     */
    private function prepareResult(array $items = [])
    {
        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $this->result = $this->_rateFactory->create();
        
        /** @var QuoteServiceInterface $item */
        foreach ($items as $item) {
            if ($item->isError()) {
                continue;
            }
            
            $deliveryTime = (int) $item->getDeliveryTime();
            $additionalLeadTime = (int) $this->config->getAdditionalLeadTime();
            
            $item->setData(QuoteServiceInterface::FIELD_DELIVERY_TIME, $deliveryTime + $additionalLeadTime);
            
            $title = $this->prepareMethodTitle(
                $item->getCarrier(),
                $item->getServiceDescription(),
                $item->getDeliveryTime()
            );
            
            $method = $this->prepareMethod(
                $title,
                $item->getServiceCode(),
                $item->getServiceDescription(),
                $item->getShippingPrice(),
                $item->getShippingPrice()
            );
            
            $this->result->append($method);
        }
        
        return $this;
    }
    
    /**
     * @param string $method
     * @param string $code
     * @param string $title
     * @param float  $price
     * @param float  $cost
     *
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    private function prepareMethod($method, $code, $title, $price, $cost)
    {
        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $methodInstance */
        $methodInstance = $this->_rateMethodFactory->create();
        $methodInstance->setCarrier($this->_code)
            ->setCarrierTitle($this->config->getCarrierConfig('title'))
            ->setMethod($method)
            ->setMethodTitle($title)
            ->setMethodDescription($code)
            ->setPrice($price)
            ->setCost($cost);
        
        return $methodInstance;
    }
    
    /**
     * @param string $carrier
     * @param string $description
     * @param int    $leadTime
     *
     * @return string
     */
    private function prepareMethodTitle($carrier, $description, $leadTime = 0)
    {
        $title = __('%1' . self::STR_SEPARATOR . '%2', $carrier, $description);

        if ($this->config->canShowShippingForecast()) {
            $message = str_replace('{{d}}', (int) $leadtime, $this->config->getShippingForecast());
            $title .= self::STR_SEPARATOR . $message;
        }

        return $title;
    }

    /**
     * @param string $postcode
     *
     * @return string|string[]|null
     */
    private function normalizePostcode($postcode)
    {
        $postcode = preg_replace('/[^0-9]/', null, $postcode);
        $postcode = str_pad($postcode, 8, '0', STR_PAD_LEFT);
        return $postcode;
    }
}
