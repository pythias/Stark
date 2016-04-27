<?php

$g_count = 0;

function run($worker, $data) {
    global $g_count;
    $g_count++;

    $message = "Worker {$worker->index} current: {$g_count}";
    echo $message . PHP_EOL;
    $worker->log->addInfo($message);
    
    usleep(rand(100000, 500000));
}