<?php


namespace Stark\Daemon\Consumer;


use Stark\Daemon\IConsumer;
use Stark\Daemon\Worker;

abstract class AbstractConsumer implements IConsumer {

    abstract public function consume(Worker $worker, $data);

    public function initialize(Worker $worker) {
        // TODO: Implement initialize() method.
    }

    public function finalize(Worker $worker) {
        // TODO: Implement finalize() method.
    }
}