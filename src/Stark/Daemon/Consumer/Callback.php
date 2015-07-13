<?php
namespace Stark\Daemon\Consumer;

class Callback extends Base {
    protected $_init = '';
    protected $_run = '';
    protected $_complete = '';

    public function init(\Stark\Daemon\Worker $worker) {
        if (function_exists($this->_init)) {
            call_user_func_array($this->_init, array($worker));
        }
    }

    public function run(\Stark\Daemon\Worker $worker, $data) {
        if (function_exists($this->_run)) {
            return call_user_func_array($this->_run, array($worker, $data));
        }

        return false;
    }

    public function complete(\Stark\Daemon\Worker $worker) {
        if (function_exists($this->_complete)) {
            call_user_func_array($this->_complete, array($worker));
        }
    }
}