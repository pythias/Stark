Stark
==========

`Stark` is a library for running php code as multi-process daemon. 

Requires
--------

* PHP 5.4 or Higher
* A POSIX compatible operating system (Linux, OSX, BSD)
* POSIX and PCNTL extensions for PHP
* Redis extensions 

Features
--------

* Simple Callbacks
* Message Queue Processing
* Daemon Monitoring
* Automatic Restart

Usage
--------

We just need only one script file and one config file. The php script file defines the callback functions. The ini config file defines daemon's enviroments. You can use this command to start you daemon:
```
php bin/stark -f [ini_config_file]
```

Daemon configuration
--------------------

* main.name : A string specifying the daemon's unique name
* main.host : A string specifying the bind ip address of monitoring server
* main.port : An integer value specifying the bind port of monitoring server.
* main.working_dir : A path to a directory where the daemon should put its log file and socket file.
* run.script_file : The file that defines the callback funtions.
* run.memory_limit : Specified as the php shorthand notation for bytes (see [the manual](http://php.net/manual/en/faq.using.php#faq.using.shorthandbytes) ). This will be set as `memory_limit` via `ini_set`
* worker.count : An integer value specifying the number of worker.
* worker.max_run_count : The maximum number of runs, after the number reached the worker will restart every time.
* worker.max_run_seconds : An integer value in seconds, specifying the maximum time the worker will restart after the time arrives.
* worker.max_idle_seconds : An integer value in seconds, specifying the longest idle time, the worker will restart after the time arrives.
* worker.empty_sleep_seconds : An float value in seconds, specifying the sleep time when the worker cannt get data.
* MQ Support
    - queue.type : Queue service type, includes "Redis"
    - queue.host : Queue service ip address
    - queue.port : Queue service bind port
    - queue.key : The key of queue in service, you can use multiple keys separated by commas to supporting priority MQ.

An example configuration ini file:
```ini
[main]
name = "config_1"
host = "127.0.0.1"
port = 9003
working_dir = "/tmp"

[run]
script_file = "run_1.php"
memory_limit = "1024M"

[worker]
count = 3
max_run_count = 10000
max_run_seconds = 3600
max_idle_seconds = 60
empty_sleep_seconds = 0.1
```

An example callback file:
```php
<?php

$g_count = 0;

function run($worker, $data) {
    global $g_count;
    $g_count++;

    echo "Worker {$worker->index} current: {$g_count}\r\n";
    usleep(100000);
}
```
