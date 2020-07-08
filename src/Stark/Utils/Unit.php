<?php


namespace Stark\Utils;


class Unit {
    private static $byteUnits = [
        'p' => 5,
        't' => 4,
        'g' => 3,
        'm' => 2,
        'k' => 1,
    ];

    public static function stringToBytes($size) {
        $size = trim($size);
        if ($size == '') {
            return 0;
        }

        $unit = strtolower(substr($size, -1, 1));
        $power = isset(self::$byteUnits[$unit]) ? self::$byteUnits[$unit] : 0;
        return substr($size, 0, -1) * pow(1024, $power);
    }

    public static function humanizeBytes($bytes) {
        foreach (self::$byteUnits as $unit => $power) {
            if ($bytes > pow(1024, $power)) {
                return sprintf("%0.2f %sB", $bytes / pow(1024, $power), strtoupper($unit));
            }
        }

        return $bytes . ' bytes';
    }

    public static function humanizeSeconds($seconds) {
        $humanize = '';
        $days = floor($seconds / 86400);
        if ($days > 0) {
            $humanize .= $days . ' days ';
        }

        $seconds = $seconds - $days * 86400;
        $hours = floor($seconds / 3600);
        if ($hours > 0) {
            $humanize .= $hours . ' hours ';
        }

        $seconds = $seconds - $hours * 3600;
        $minutes = floor($seconds / 60);
        if ($minutes > 0) {
            $humanize .= $minutes . ' minutes ';
        }

        $seconds = $seconds - $minutes * 60;
        $humanize .= round($seconds, 3) . ' seconds';
        return $humanize;
    }
}