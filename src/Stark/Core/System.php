<?php
namespace Stark\Core;

class System {
    public static function runInBackground() {
        $pid = pcntl_fork();
        if ($pid == -1) die("Unable to fork\r\n");
        elseif ($pid) exit;
        
        posix_setsid();
        usleep(100000);
        
        $pid = pcntl_fork();
        if ($pid == -1) die("Unable to fork\r\n");
        elseif ($pid) exit;           
    }

    public static function getStatus($pid) {
        $file = "/proc/{$pid}/status";

        if (file_exists($file) === false) {
            return false;
        }

        $data = array();
        $lines = file($file);
        foreach ($lines as $line) {
            $line = trim($line);
            list($name, $value) = explode(':', $line);
            $data[trim($name)] = trim($value);
        }

        return $data;
    }

    public static function setAffinity($pid, $mask = '1-32') {
        return shell_exec("taskset -cp {$mask} {$pid}");
    }

    public static function setProcTitle($pid, $title) {
        //TODO
    }

    public static function getLocalIp() {
        //TODO
        return '0.0.0.0';
    }
}