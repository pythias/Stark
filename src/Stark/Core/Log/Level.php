<?php
namespace Stark\Core\Log;

class Level {
    const OFF = PHP_INT_MAX;
    const FATAL = 5;
    const ERROR = 4;
    const WARN = 3;
    const INFO = 2;
    const DEBUG = 1;
    const TRACE = 0;
    const ALL = -1;
}