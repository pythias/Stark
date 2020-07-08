<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Stark\Daemon\Worker;
use Stark\DaemonFactory;
use Stark\Daemon\Consumer\AbstractConsumer;
use Stark\Daemon\Producer\AbstractProducer;

class MyProducer extends AbstractProducer {
    public function produce(Worker $worker) {
        return "from-producer, time:" . microtime(true);
    }
}

class MyConsumer extends AbstractConsumer {
    private $_lastTime = 0;

    public function consume(Worker $worker, $data) {
        if ($data == false) {
            return false;
        }

        $time = time();
        if ($time > ($this->_lastTime + 5)) {
            $this->_lastTime = $time;
            $worker->log->info("Worker: {$worker->index}, data: {$data}");
        }

        usleep(rand(100, 1000));
        return true;
    }
}

$daemon = DaemonFactory::normal(new MyProducer(), new MyConsumer());
$daemon->setWorkerCount(2);
$daemon->setMaxRunCount(100000);
$daemon->setPort(9100);
$daemon->setName("consumer-with-producer");
$daemon->start();