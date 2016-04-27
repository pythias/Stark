<?php
namespace Stark\Core;

class System {
    const OS_TYPE_MAC_OS = 'Darwin';
    const OS_TYPE_LINUX  = 'Linux';

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

    public static function getOSType() {
        return ucfirst(strtolower(php_uname('s')));
    }

    public static function getProcNumber() {
        $osType = self::getOSType();
        if ($osType == self::OS_TYPE_MAC_OS) {
            return intval(shell_exec("sysctl -a|grep cpu|grep ncpu|awk -F': ' '{print $2}'"));
        }

        //return intval(shell_exec('grep "^physical id" /proc/cpuinfo|wc -l'))
        return intval(shell_exec("nproc"));
    }

    public static function setAffinity($pid, $mask = '1-32') {
        $osType = self::getOSType();
        if ($osType == self::OS_TYPE_MAC_OS) {
            return false;
        }

        return shell_exec("taskset -cp {$mask} {$pid}");
    }

    public static function setProcTitle($pid, $title) {
        //TODO
    }

    public static function getLocalIp($device = '') {
        if ($device == '') {
            // Linux and other OS use eth0 as default device
            $device = 'eth0';
            $osType = self::getOSType();

            if ($osType == self::OS_TYPE_MAC_OS) {
                $device = 'en0';
            }
        }

        $checkDeviceCommand = "(ifconfig {$device} >> /dev/null 2>&1 || (echo false && exit 1))";
        $awkCommand = 'awk \'/inet / {ipstr = $0;gsub("addr:", "", ipstr);split(ipstr, ip, " ");print ip[2]}\'';
        $ip = trim(shell_exec("{$checkDeviceCommand} && (ifconfig {$device} | {$awkCommand})"));

        if ($ip && $ip != 'false') {
            return $ip;
        }

        return '0.0.0.0';
    }

    public static function getSizeBytes($size) {
        $size = trim($size);
        if ($size == '') {
            return 0;
        }
        
        $last = strtolower($size[strlen($size) - 1]);
        switch($last) {
            case 'g':
                $size *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $size *= 1024 * 1024;
                break;
            case 'k':
                $size *= 1024;
                break;
        }

        return $size;
    }
}