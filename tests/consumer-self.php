<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Stark\DaemonFactory;
use Stark\Daemon\Consumer\AbstractConsumer;
use Stark\Daemon\Worker;

class MyConsumer extends AbstractConsumer {
    private $_lastTime = 0;

    public function consume(Worker $worker, $data) {
        $time = time();
        if ($time > ($this->_lastTime + 5)) {
            $this->_lastTime = $time;
            $worker->log->info("Worker: {$worker->index}, count: {$worker->getStatus()->getRoundCount()}, time: {$time}");
        }

        usleep(rand(100, 1000));
        return true;
    }
}

$daemon = DaemonFactory::consumerOnly(new MyConsumer());
$daemon->setWorkerCount(3);
$daemon->setMaxRunCount(50000);
$daemon->setPort(9101);
$daemon->setName("consumer-self");
$daemon->setWorkingDirectory("/tmp");
$daemon->start();