<?php
namespace Stark\Daemon;

class Status {
    public $pid = 0;
    public $startTime = 0;
    public $lastActiveTime = 0;
    public $totalCount = 0;
    public $totalTime = 0;
    public $totalQPS = 0;
    public $totalCpuU = 0;
    public $totalCpuS = 0;
    public $memory = 0;
    public $ticks = 0;
    public $cpuU = 0;
    public $cpuS = 0;

    public function update($status) {
        if (is_array($status) === false) {
            return;
        }
        
        foreach ($status as $key => $value) {
            $this->$key = $value;
        }
    }
}