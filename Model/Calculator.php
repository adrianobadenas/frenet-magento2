<?php
/**
 * Frenet Shipping Gateway
 *
 * @category Frenet
 * @package Frenet\Shipping
 *
 * @author Tiago Sampaio <tiago@tiagosampaio.com>
 * @link https://github.com/tiagosampaio
 * @link https://tiagosampaio.com
 *
 * Copyright (c) 2020.
 */

declare(strict_types = 1);

namespace Frenet\Shipping\Model;

use Frenet\ObjectType\Entity\Shipping\Quote\Service;
use Frenet\Shipping\Model\Packages\PackageItem;
use Frenet\Shipping\Service\RateRequestProvider;
use Magento\Quote\Model\Quote\Address\RateRequest;

/**
 * Class Calculator
 *
 * @package Frenet\Shipping\Model
 */
class Calculator implements CalculatorInterface
{
    /**
     * @var CacheManager
     */
    private $cacheManager;

    /**
     * @var Packages\PackagesCalculator
     */
    private $packagesCalculator;

    /**
     * @var RateRequestProvider
     */
    private $rateRequestProvider;

    /**
     * Calculator constructor.
     *
     * @param CacheManager                $cacheManager
     * @param Packages\PackagesCalculator $packagesCalculator
     */
    public function __construct(
        CacheManager $cacheManager,
        Packages\PackagesCalculator $packagesCalculator,
        RateRequestProvider $rateRequestProvider
    ) {
        $this->cacheManager = $cacheManager;
        $this->packagesCalculator = $packagesCalculator;
        $this->rateRequestProvider = $rateRequestProvider;
    }

    /**
     * @inheritdoc
     */
    public function getQuote() : array
    {
        if ($result = $this->cacheManager->load()) {
            return $result;
        }

        /** @var Service[] $services */
        $services = $this->packagesCalculator->calculate();

        foreach ($services as $service) {
            $this->processService($service);
        }

        if ($services) {
            $this->cacheManager->save($services);
            return $services;
        }

        return [];
    }

    /**
     * @param Service $service
     *
     * @return Service
     */
    private function processService(Service $service) : Service
    {
        $service->setData(
            'service_description',
            str_replace('|', "\n", $service->getServiceDescription())
        );
        return $service;
    }
}
