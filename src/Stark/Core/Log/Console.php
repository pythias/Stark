<?php
namespace Stark\Core\Log;

class Console extends Base {
    protected function _logMessage($message, $level) {
        echo date('r') . '|' . $message . "\r\n";
    }
}