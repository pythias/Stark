<?php
namespace Stark\Core;

class System {
    const OS_TYPE_MAC = 'Darwin';
    const OS_TYPE_LINUX  = 'Linux';

    public static function becomeDaemon() {
        $pid = pcntl_fork();
        if ($pid == -1) {
            die("Unable to fork\r\n");
        }
        else if ($pid) {
            exit;
        }
        
        posix_setsid();
        usleep(100 * 1000);
        
        $pid = pcntl_fork();
        if ($pid == -1) {
            die("Unable to fork\r\n");
        }
        else if ($pid) {
            exit;
        }
    }

    public static function isMac() {
        return PHP_OS == self::OS_TYPE_MAC;
    }

    public static function isLinux() {
        return PHP_OS == self::OS_TYPE_LINUX;
    }
}