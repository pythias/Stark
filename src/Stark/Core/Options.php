<?php
namespace Stark\Core;

class Options {
    public function __construct($options = array()) {
        $this->setOptions($options);
    }

    protected function setOptions($options) {
        if (is_array($options) == false && empty($options)) {
            return;
        }

        foreach ($options as $key => $value) {
            $methodName = '_set' . ucfirst($key) . 'Options';
            if (method_exists($this, $methodName)) {
                if (call_user_func_array(array($this, $methodName), array($value)) == false) {
                    throw new \Stark\Daemon\Exception\Options("Set option failed, option:{$key}");
                } else {
                    continue;
                }
            }

            $property = "_{$key}";
            if (isset($this->$property) == false) throw new \Stark\Daemon\Exception\Options("Set option failed, option:{$key}");
            $this->$property = $value; //TODO:value type
        }

        return true;
    }
}