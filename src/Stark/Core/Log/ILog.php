<?php
namespace Stark\Core\Log;

interface ILog {
    public function log($message, $level = Level::DEBUG);
}