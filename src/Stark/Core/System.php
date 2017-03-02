<?php
namespace Stark\Core;

class System {
    const OS_TYPE_MAC_OS = 'Darwin';
    const OS_TYPE_LINUX  = 'Linux';

    private static $_osType = '';
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

    public static function getOSType() {
        if (self::$_osType) {
            return self::$_osType;
        }

        self::$_osType = ucfirst(strtolower(php_uname('s')));
        return self::$_osType;
    }

    public static function isMac() {
        if (self::$_isMac !== null) {
            return self::$_isMac;
        }

        self::$_isMac = (self::getOSType() == self::OS_TYPE_MAC_OS);

        return self::$_isMac;
    }

    public static function isLinux() {
        if (self::$_isLinux !== null) {
            return self::$_isLinux;
        }

        self::$_isLinux = (self::getOSType() == self::OS_TYPE_LINUX);

        return self::$_isLinux;
    }

    public static function getProcNumber() {
        if (function_exists('shell_exec') == false) {
            return false;
        }

        if (self::isMac()) {
            return intval(shell_exec("sysctl -a|grep cpu|grep ncpu|awk -F': ' '{print $2}'"));
        }

        //return intval(shell_exec('grep "^physical id" /proc/cpuinfo|wc -l'))
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

    public static function getLocalIp($device = '') {
        if (function_exists('shell_exec') == false) {
            return '127.0.0.1';
        }

        if ($device == '') {
            // Linux and other OS use eth0 as default device
            $device = self::isLinux() ? 'eth0' : 'en0';
        }

        $checkDeviceCommand = "(ifconfig {$device} >> /dev/null 2>&1 || (echo false && exit 1))";
        $awkCommand = 'awk \'/inet / {ipstr = $0;gsub("addr:", "", ipstr);split(ipstr, ip, " ");print ip[2]}\'';
        $ip = trim(shell_exec("{$checkDeviceCommand} && (ifconfig {$device} | {$awkCommand})"));

        if ($ip && $ip != 'false') {
            return $ip;
        }

        return '127.0.0.1';
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