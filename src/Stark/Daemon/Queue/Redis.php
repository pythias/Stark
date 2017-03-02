<?php
namespace Stark\Daemon\Queue;

class Redis extends Base {
    protected $_host = '127.0.0.1';
    protected $_port = '6379';
    protected $_timeout = 2.0;
    private $_prioritySupported = false;
    /**
     * @var string|array
     */
    protected $_key = 'stark';

    /**
     * @var bool|\Redis
     */
    private $_redis = false;

    public function init(\Stark\Daemon\Worker $worker) {
        $this->_redis = new \Redis();
        $this->_redis->connect($this->_host, $this->_port, $this->_timeout);

        //支持优先级
        $keys = explode(' ', trim($this->_key));
        if (count($keys) > 1) {
            $this->_key = $keys;
            $this->_prioritySupported = true;
        }
    }

    public function pop(\Stark\Daemon\Worker $worker) {
        if ($this->_prioritySupported) {
            $value = $this->_redis->blPop($this->_key, 1);
            return isset($value[1]) ? $value[1] : false;
        }

        return $this->_redis->lPop($this->_key);
    }

    public function complete(\Stark\Daemon\Worker $worker) {
        $this->_redis->close();
    }
}