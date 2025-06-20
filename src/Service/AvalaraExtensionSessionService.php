<?php

namespace AvalaraExtension\Service;


use MoptAvalara6\Bootstrap\Form;
use Symfony\Component\HttpFoundation\Session\Session;

class AvalaraExtensionSessionService extends Session
{
  private bool $isHeadless;

  public function __construct()
  {
    parent::__construct();
  }

  private function isHeadless(Mixed $adapter)
  {
    if (!isset($this->isHeadless)) {
      $this->isHeadless = $adapter->getPluginConfig(Form::HEADLESS_MODE);
    }

    return $this->isHeadless;
  }

  public function setValue(string $name, mixed $value, Mixed $adapter): void
  {
    if (!$this->isHeadless($adapter)) {
      parent::set($name, $value);
    }
  }

  public function getValue(string $name, Mixed $adapter): mixed
  {
    if (!$this->isHeadless($adapter)) {
      return parent::get($name);
    }
    return null;
  }

}
