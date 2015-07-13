<?php
require_once __DIR__ . '/../../vendor/autoload.php';

$options = getopt("f:h::", array());

if (isset($options['h']) || isset($options['f']) === false) {
    show_help($argv);
    exit;
}

$file = $options['f'];
if (file_exists($file) === false) {
    exit_by_error("Config file '{$file}' is not exists");
}

try {
    $daemon = new \Stark\Daemon\Master(get_options($file));
    $daemon->start();
} catch (Exception $e) {
    exit_by_error($e->getMessage());
}

function show_help($argv) {
    echo 'Usage: php ' . $argv[0] . ' [options] [-f] <file> [--] [args...] ' . "\n";
    echo '  -f config file' . "\n";
}

function exit_by_error($error) {
    exit("{$error}\n");
}

function include_run_file($dir, $run_file) {
    if (file_exists($run_file) == false) {
        $run_file = "./" . $dir . "/" . $run_file;
    }

    if (file_exists($run_file) == false) {
        exit_by_error("Run file '{$run_file}' is not exists");
    }

    require_once $run_file;

    //必须存在run回调方法
    if (function_exists("run") == false) {
        exit_by_error("Run file '{$run_file}' is invalid");
    }
}

function get_options($file) {
    $config = parse_ini_file($file, true);
    if (empty($config)) {
        exit_by_error("Config file '{$file}' is invalid");
    }

    include_run_file(dirname($file), get_option_value($config, 'run.script_file'));

    return array(
        'consumer' => array(
            'class' => '\\Stark\\Daemon\\Consumer\\Callback',
            'options' => array(
                'init' => 'init',
                'run' => 'run',
                'complete' => 'complete',
            ),
        ),
        'master' => array(
            'name' => get_option_value($config, 'main.name', 'Stark_' . time()),
            'host' => get_option_value($config, 'main.host', '127.0.0.1'),
            'port' => get_option_value($config, 'main.port', 9003),
            'maxWorkerCount' => get_option_value($config, 'worker.count', 1),
            'maxRunCount' => get_option_value($config, 'worker.max_run_count', 10000),
            'maxRunSeconds' => get_option_value($config, 'worker.max_run_seconds', 3600),
            'maxIdleSeconds' => get_option_value($config, 'worker.max_idle_seconds', 60),
            'maxIdleSeconds' => get_option_value($config, 'worker.max_idle_seconds', 60),
            'memoryLimit' => get_option_value($config, 'run.memory_limit', '1024M'),
        ),
    );
}

function get_option_value($config, $key, $default = false) {
    $keys = explode('.', $key);
    $value = $config;
    foreach ($keys as $key) {
        if (isset($value[$key]) === false) {
            return $default;
        }

        $value = $value[$key];
    }

    return $value;
}

