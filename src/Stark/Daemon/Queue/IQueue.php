<?php
namespace Stark\Daemon\Queue;

interface IQueue extends \Stark\Daemon\IWorkerEvent {
    public function pop(\Stark\Daemon\Worker $worker);
}