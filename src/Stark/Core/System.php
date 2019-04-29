<?php
namespace Stark\Core;

class System {
    const OS_TYPE_MAC = 'Darwin';
    const OS_TYPE_LINUX  = 'Linux';

    private static $_isMac = null;
    private static $_isLinux = null;

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

    public static function isMac() {
        return PHP_OS == self::OS_TYPE_MAC;
    }

    public static function isLinux() {
        return PHP_OS == self::OS_TYPE_LINUX;
    }

    public static function getProcNumber() {
        if (function_exists('shell_exec') == false) {
            if (is_file('/proc/cpuinfo')) {
                $cpuinfo = file_get_contents('/proc/cpuinfo');
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                return count($matches[0]);
            }

            return false;
        }

        if (self::isMac()) {
            return intval(shell_exec("sysctl -a|grep cpu|grep ncpu|awk -F': ' '{print $2}'"));
        }

        return intval(shell_exec("nproc"));
    }

    public static function setAffinity($pid, $mask = '1-32') {
        if (function_exists('shell_exec') == false) {
            return false;
        }

        if (self::isMac()) {
            return false;
        }

        return shell_exec("taskset -cp {$mask} " . intval($pid));
    }

    public static function setProcTitle($pid, $title) {
        //TODO
    }

    public static function getLocalIp() {
        return getHostByName(getHostName());
    }

    public static function getSizeBytes($size) {
        $size = trim($size);
        if ($size == '') {
            return 0;
        }
        
        $unit = strtolower(substr($size, -1, 1));
        $power = 0;
        switch($unit) {
            case 'p':
                $power = 5;
                break;
            case 't':
                $power = 4;
                break;
            case 'g':
                $power = 3;
                break;
            case 'm':
                $power = 2;
                break;
            case 'k':
                $power = 1;
                break;
        }

        return substr($size, 0, -1) * pow(1024, $power);
    }
}