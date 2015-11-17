<?php

function run($worker, $data) {
    if ($data === false) {
        return false;
    }

    //Process redis queue data
    echo "Got a data from redis queue:{$data}\r\n";
    return true;
}