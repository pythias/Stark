<?php
namespace Stark\Daemon\Queue;

class Redis extends Base {
    protected $_serverConfigs = false;
    protected $_queueKey = false;

    public function init(\Stark\Daemon\Worker $worker) {
        //连接服务器
    }

    public function pop(\Stark\Daemon\Worker $worker) {
        return '{"time":' . microtime(true) . '}';
    }

    public function complete(\Stark\Daemon\Worker $worker) {
        //关闭链接
    }
}