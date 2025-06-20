<?php

namespace AvalaraExtension\Adapter;

use AvalaraExtension\Service\AvalaraExtensionGetTaxService;
use Monolog\Logger;
use MoptAvalara6\Adapter\AdapterInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use MoptAvalara6\Bootstrap\Form;
use MoptAvalara6\MoptAvalara6;
use Avalara\AvaTaxClient;
use MoptAvalara6\Adapter\Factory\AbstractFactory;
use MoptAvalara6\Service\AbstractService;

/**
 * This is the adaptor for avalara's API
 *
 * @author Mediaopt GmbH
 * @package MoptAvalara6\Adapter\Factory
 */
class AvalaraExtensionAdapter implements AdapterInterface
{
  /**
   * @var string
   */
  const SEVICES_NAMESPACE = '\MoptAvalara6\Service\\';

  /**
   * @var string
   */
  const FACTORY_NAMESPACE = '\MoptAvalara6\Adapter\Factory\\';

  /**
   * @var string
   */
  const SERVICE_NAME = 'AvalaraSdkAdapter';

  /**
   * @var string
   */
  const PRODUCTION_ENV = 'production';

  /**
   * @var string
   */
  const SANDBOX_ENV = 'sandbox';

  /**
   * @var string
   */
  const MACHINE_NAME = 'localhost';

  /**
   * @var AvaTaxClient
   */
  protected $avaTaxClient;

  /**
   * @var string
   */
  protected $pluginName;

  /**
   * @var string
   */
  protected $pluginVersion;

  /**
   * @var SystemConfigService
   */
  private $systemConfigService;

  /**
   * @var Logger
   */
  private $logger;

  /**
   * @var AbstractService
   */
  private $services;

  /**
   * @var string|null
   */
  private $salesChannelId;

  /**
   * @param SystemConfigService $cachedConfigService
   * @param Logger $logger
   * @param string|null $salesChannelId
   */
  public function __construct(SystemConfigService $cachedConfigService, Logger $logger, $salesChannelId = null)
  {
    $this->systemConfigService = $cachedConfigService;
    $this->logger = $logger;
    $this->salesChannelId = $salesChannelId;
  }

  /**
   * @param array $credentials
   * @return AvaTaxClient
   * @throws \Exception
   */
  public function getAvaTaxClient(array $credentials = []): AvaTaxClient
  {
    if ($this->avaTaxClient !== null) {
      return $this->avaTaxClient;
    }

    if (empty($credentials)) {
      $credentials = [
        'accountNumber' => $this->getPluginConfig(Form::ACCOUNT_NUMBER_FIELD),
        'licenseKey' => $this->getPluginConfig(Form::LICENSE_KEY_FIELD),
        'isLiveMode' => $this->getPluginConfig(Form::IS_LIVE_MODE_FIELD),
      ];
    }

    $timeout = $this->getPluginConfig(Form::CONNECTION_TIMEOUT);
    $avaClient = new AvaTaxClient(
      MoptAvalara6::PLUGIN_NAME,
      MoptAvalara6::PLUGIN_VERSION,
      $this->getMachineName(),
      $this->getSDKEnv($credentials['isLiveMode']),
      ['timeout' => $timeout]
    );

    $avaClient->withSecurity($credentials['accountNumber'], $credentials['licenseKey']);
    $this->avaTaxClient = $avaClient;

    return $this->avaTaxClient;
  }

  /**
   * @param string $key
   * @return mixed
   */
  public function getPluginConfig($key)
  {
    return $this->systemConfigService->get($key, $this->salesChannelId);
  }

  /**
   * @return string
   */
  private function getSDKEnv($isLiveMode)
  {
    return $isLiveMode
      ? self::PRODUCTION_ENV
      : self::SANDBOX_ENV;
  }

  /**
   * @return string
   */
  private function getMachineName()
  {
    return self::MACHINE_NAME;
  }

  /**
   * Get service by type
   *
   * @param string $type
   * @return AbstractService
   */
  public function getService($type)
  {
    if ($type === 'AvalaraExtensionGetTaxService') {
      $this->services[$type] = new AvalaraExtensionGetTaxService($this, $this->logger);
    } else if (!isset($this->services[$type])) {
      $name = self::SEVICES_NAMESPACE . ucfirst($type);
      $this->services[$type] = new $name($this, $this->logger);
    }
    return $this->services[$type];
  }

  /**
   * return factory
   *
   * @param string $type
   * @return AbstractFactory
   */
  public function getFactory($type)
  {
    $name = self::FACTORY_NAMESPACE . ucfirst($type);

    return new $name($this);
  }
}
