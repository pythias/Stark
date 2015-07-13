<?php
namespace Stark\Daemon\Queue;

abstract class Base extends \Stark\Core\Options implements IQueue {
    public function init(\Stark\Daemon\Worker $worker) {}
    abstract public function pop(\Stark\Daemon\Worker $worker);
    public function complete(\Stark\Daemon\Worker $worker) {}
}