<?php
namespace Stark\Daemon;

interface IWorkerEvent {
    public function init(\Stark\Daemon\Worker $worker);
    public function complete(\Stark\Daemon\Worker $worker);
}