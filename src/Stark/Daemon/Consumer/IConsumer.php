<?php
namespace Stark\Daemon\Consumer;

interface IConsumer extends \Stark\Daemon\IWorkerEvent {
    public function run(\Stark\Daemon\Worker $worker, $data);
}