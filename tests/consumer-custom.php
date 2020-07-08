<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Stark\DaemonFactory;
use Stark\Daemon\Consumer\AbstractConsumer;
use Stark\Daemon\Worker;

class MyConsumer extends AbstractConsumer {
    /**
     * @var bool|Redis
     */
    private $_redis = false;

    private $_lastTime = 0;

    public function initialize(Worker $worker) {
        $this->_redis = new Redis();
        $this->_redis->connect("127.0.0.1", "9004", 5);

        parent::initialize($worker);
    }

    public function consume(Worker $worker, $data) {
        $time = microtime(true);
        $length0 = $this->_redis->rPush("queue-0", "0:{$time}");

        $time = microtime(true);
        $length1 = $this->_redis->rPush("queue-1", "1:{$time}");

        $time = microtime(true);
        $length2 = $this->_redis->rPush("queue-2", "2:{$time}");

        if ($time > ($this->_lastTime + 5)) {
            $this->_lastTime = $time;
            $worker->log->info("push, $length0, $length1, $length2");
        }

        usleep(rand(10000, 500000));
        return true;
    }
}

$daemon = DaemonFactory::consumerOnly(new MyConsumer());
$daemon->setWorkerCount(3);
$daemon->setPort(9104);
$daemon->setName("consumer-custom");
$daemon->start();