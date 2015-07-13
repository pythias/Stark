<?php
namespace Stark\Daemon\Queue;

class Callback extends Base {
    protected $_init = '';
    protected $_pop = '';
    protected $_complete = '';

    public function init(\Stark\Daemon\Worker $worker) {
        if (function_exists($this->_init)) {
            call_user_func_array($this->_init, array($worker));
        }
    }

    public function pop(\Stark\Daemon\Worker $worker) {
        if (function_exists($this->_pop)) {
            return call_user_func_array($this->_pop, array($worker));
        }
        
        return false;
    }

    public function complete(\Stark\Daemon\Worker $worker) {
        if (function_exists($this->_complete)) {
            call_user_func_array($this->_complete, array($worker));
        }
    }
}