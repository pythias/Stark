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

    public static function setAffinity($pid, $mask = '1-32') {
        return shell_exec("taskset -cp {$mask} {$pid}");
    }

    public static function setProcTitle($pid, $title) {
        //TODO
    }

    public static function getLocalIp($device = '') {
        $osType = ucfirst(strtolower(php_uname('s')));


        if (!$device)
        {
            // Linux and other OS use eth0 as default device
            $device = 'eth0';

            if ($osType == self::OS_TYPE_MAC_OS)
            {
                $device = 'en0';
            }
        }

        $checkDeviceCommand = "(ifconfig {$device} >> /dev/null 2>&1 || (echo false && exit 1))";
        $awkCommand = 'awk \'/inet / {ipstr = $0;gsub("addr:", "", ipstr);split(ipstr, ip, " ");print ip[2]}\'';
        $ip = trim(shell_exec("{$checkDeviceCommand} && (ifconfig {$device} | {$awkCommand})"));

        if ($ip && $ip != 'false')
        {
            return $ip;
        }

        return '0.0.0.0';
    }
}