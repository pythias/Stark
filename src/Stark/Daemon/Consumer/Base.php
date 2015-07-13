<?php
namespace Stark\Daemon\Consumer;

abstract class Base extends \Stark\Core\Options implements IConsumer {
    public function init(\Stark\Daemon\Worker $worker) {}
    abstract public function run(\Stark\Daemon\Worker $worker, $data);
    public function complete(\Stark\Daemon\Worker $worker) {}
}