<?php

$g_count = 0;

function run($worker, $data) {
    global $g_count;
    $g_count++;

    echo "Worker {$worker->index} current: {$g_count}\r\n";
    usleep(100000);
}