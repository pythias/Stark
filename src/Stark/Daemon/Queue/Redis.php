<?php
namespace Stark\Daemon\Queue;

class Redis extends Base {
    protected $_host = '127.0.0.1';
    protected $_port = '6379';
    protected $_timeout = 2.0;
    protected $_key = 'stark';
    private $_redis = false;

    public function init(\Stark\Daemon\Worker $worker) {
        $this->_redis = new \Redis();
        $this->_redis->connect($this->_host, $this->_port, $this->_timeout);
    }

    public function pop(\Stark\Daemon\Worker $worker) {
        return $this->_redis->lPop($this->_key);
    }

    public function complete(\Stark\Daemon\Worker $worker) {
        $this->_redis->close();
    }
}