<?php
namespace Stark\Core\Log;

abstract class Base extends \Stark\Core\Options implements ILog {
    protected $_minLevel = Level::INFO;

    public function log($message, $level = Level::DEBUG) {
        if ($level >= $this->_minLevel) $this->_logMessage($message, $level);
    }

    abstract protected function _logMessage($message, $level);
}