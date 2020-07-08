<?php


namespace Stark\Daemon\Producer;


use Stark\Daemon\IProducer;
use Stark\Daemon\Worker;

abstract class AbstractProducer implements IProducer {
    abstract public function produce(Worker $worker);

    public function initialize(Worker $worker) {
        // TODO: Implement initialize() method.
    }

    public function finalize(Worker $worker) {
        // TODO: Implement finalize() method.
    }
}