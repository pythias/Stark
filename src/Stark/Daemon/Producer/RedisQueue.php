<?php
namespace Stark\Daemon\Producer;

use Redis;
use Stark\Daemon\Worker;

class RedisQueue extends AbstractProducer {
    private $_host = '127.0.0.1';
    private $_port = '6379';
    private $_timeout = 2.0;
    private $_key = 'stark';
    private $_keys = [];
    private $_prioritySupported = false;

    /**
     * @var bool|Redis
     */
    private $_redis = false;

    public function initialize(Worker $worker) {
        $this->_redis = new Redis();
        $this->_redis->connect($this->_host, $this->_port, $this->_timeout);

        parent::initialize($worker);
    }

    public function produce(Worker $worker) {
        if ($this->_prioritySupported) {
            $value = $this->_redis->blPop($this->_keys, 1);
            return isset($value[1]) ? $value[1] : false;
        }

        return $this->_redis->lPop($this->_key);
    }

    public function finalize(Worker $worker) {
        if (PHP_VERSION >= 5.6) {
            $this->_redis->close();
        }

        parent::finalize($worker);
    }

    /**
     * @param string $host
     *
     * @return RedisQueue
     */
    public function setHost($host) {
        $this->_host = $host;
        return $this;
    }

    /**
     * @param string $port
     *
     * @return RedisQueue
     */
    public function setPort($port) {
        $this->_port = $port;
        return $this;
    }

    /**
     * @param string $key
     *
     * @return RedisQueue
     */
    public function setKey($key) {
        $this->_key = $key;
        $this->_prioritySupported = false;
        return $this;
    }

    /**
     * 设定多个Key
     * 支持优先处理
     *
     * @param array $keys
     *
     * @return RedisQueue
     */
    public function setKeys($keys) {
        $this->_keys = $keys;

        if (count($this->_keys) == 1) {
            $this->_key = $this->_keys[0];
            $this->_prioritySupported = false;
        } else if (count($this->_keys) > 1) {
            $this->_prioritySupported = true;
        }

        return $this;
    }
}