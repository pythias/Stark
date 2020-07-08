<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Stark\Daemon\Consumer\AbstractConsumer;
use Stark\DaemonFactory;
use Stark\Daemon\Worker;

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

$daemon = DaemonFactory::consumeRedisWithPriority(new MyConsumer(), "127.0.0.1", "9004", ["queue-1", "queue-2"]);
$daemon->setWorkerCount(4);
$daemon->setPort(9103);
$daemon->setName("consumer-redis-priority");
$daemon->start();
