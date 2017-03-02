<?php
namespace Stark\Model;

class Processor {
    public $pid;
    public $ppid;
    public $pcpu;
    public $pmem;
    public $time;
    public $state;
    public $args;

    public function __construct($pid) {
        $pid = intval($pid);

        if (function_exists('shell_exec') == false) {
            return;
        }

        $ps = trim(shell_exec("ps -eo pid,ppid,pcpu,pmem,time,state,args |grep '^[ ]*{$pid}'"));
        $info = preg_split('/\s+/', $ps);
        if (count($info) < 7) {
            return;
        }

        $this->pid = $pid;
        $this->ppid = $info[1];
        $this->pcpu = $info[2];
        $this->pmem = $info[3];
        $this->time = $info[4];
        $this->state = $info[5];
        $this->args = implode(' ', array_slice($info, 6));
    }

    public function quit($isChild = true) {
        posix_kill($this->pid, SIGKILL);

        if ($isChild) {
            $status = 0;
            while (pcntl_waitpid($this->pid, $status) != -1) { 
                pcntl_wexitstatus($status);
            }
        }
    }
}