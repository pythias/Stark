<?php
namespace Stark\Daemon;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Stark\Core\System;
use Stark\Model\Processor;
use Stark\Model\Status;
use Stark\Utils\Unit;

class Master {
    private $_pid;
    private $_pidFile;
    private $_startTime = 0.0;

    /**
     * @var Logger
     */
    private $_log;

    /*Options*/
    private $_name = 'daemon';
    private $_host = '127.0.0.1';
    private $_port = '9003';
    private $_workingDirectory = '/tmp';
    private $_workerCount = 3;
    private $_memoryLimit = '1024M';
    private $_logLevel = Logger::INFO;

    /**
     * @var Worker
     */
    private $_worker;

    /*Daemon*/
    private $_sockFile;
    private $_socket;
    private $_workerStatuses = [];
    private $_workerClients = [];
    private $_missingWorkers = [];

    private $_logDir = '';
    private $_runDir = '';
    private $_dataDir = '';

    /*Admin*/
    private $_adminSocket;
    private $_adminClients = [];

    const UNIX_PATH_MAX = 108;
    const COMMAND_FROM_ADMIN = 0;
    const COMMAND_FROM_WORKER = 1;
    const WORKER_START_INTERVAL_SECONDS = 1;
    const ADMIN_DATA_INTERVAL_SECONDS = 0.01;
    const HEARTBEAT = 2;

    const WORKER_PAYLOAD_INDEX = 0;
    const WORKER_PAYLOAD_PID = 1;
    const WORKER_PAYLOAD_PORT = 2;
    const WORKER_PAYLOAD_DATA = 3;

    public function start() {
        ini_set('memory_limit', $this->_memoryLimit);

        $this->_initDirs();
        $this->_initLog();
        $this->_initFiles();

        if ($this->_stillRunning()) {
            $this->_exit("Daemon '{$this->_name}' is already running");
        }

        $this->_initProcess();
        $this->_initSignal();

        //sample worker
        $this->_checkWorker();

        //start daemon
        $this->_startTime = microtime(true);

        $this->_startMasterServer();
        $this->_startAdminServer();
        $this->_startWorkers();

        $this->_loop();
    }

    private function _initLog() {
        $logFile = "{$this->_logDir}/{$this->_name}-" . date('Y-m-d') . ".log";

        $this->_log = new Logger($this->_name);
        $this->_log->pushHandler(new StreamHandler($logFile, $this->_logLevel));
    }

    private function _resetLog() {
        $logFile = "{$this->_logDir}/{$this->_name}-" . date('Y-m-d') . ".log";
        $this->_log->popHandler();
        $this->_log->pushHandler(new StreamHandler($logFile, $this->_logLevel));
    }

    private function _initDirs() {
        if (empty($this->_workingDirectory)) {
            $this->_workingDirectory = '/tmp';
        }

        $this->_workingDirectory = rtrim($this->_workingDirectory, '/');
        $this->_logDir = $this->_workingDirectory . "/logs";
        $this->_dataDir = $this->_workingDirectory . "/data";
        $this->_runDir = $this->_workingDirectory . "/run";

        $this->_createDir($this->_workingDirectory);
        $this->_createDir($this->_logDir);
        $this->_createDir($this->_dataDir);
        $this->_createDir($this->_runDir);
    }

    private function _createDir($dir) {
        if (is_dir($dir) == false) {
            if (mkdir($dir, 0777, true) == false) {
                $this->_exit("Unable to create working directory: {$dir}");
            }
        }

        if (is_writable($dir) == false) {
            $this->_exit("Unable to write working directory: {$dir}");
        }
    }

    private function _initFiles() {
        $this->_pidFile = "{$this->_runDir}/{$this->_name}.pid";
        $this->_sockFile = "{$this->_runDir}/{$this->_name}.sock";
        
        if (strlen($this->_sockFile) > self::UNIX_PATH_MAX) {
            $this->_exit("Socket path {$this->_sockFile} is too long");
        }
    }

    private function _stillRunning() {
        if (file_exists($this->_pidFile) == false) {
            return false;
        }

        $lastPid = file_get_contents($this->_pidFile);
        $processor = new Processor($lastPid);
        if ($processor->isAlive()) {
            return true;
        }

        unlink($this->_pidFile);
        return false;
    }

    private function _initProcess() {
        System::becomeDaemon();

        $this->_pid = posix_getpid();

        if (file_put_contents($this->_pidFile, $this->_pid) == false) {
            $this->_exit("Unable to write pid file '{$this->_pidFile}'");
        }
    }

    private function _initSignal() {
        declare(ticks = 1);

        pcntl_signal(SIGCHLD, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTERM, array($this, '_quit'));
        pcntl_signal(SIGHUP, array($this, '_quit'));
    }

    private function _exit($message) {
        if ($this->_log) {
            $this->_log->info($message);
        }

        exit("{$message}\r\n");
    }

    private function _checkWorker() {
        if ($this->_worker == null) {
            $this->_exit("Worker cant be empty.");
        }

        $this->_worker->name = $this->_name;
        $this->_worker->log = $this->_log;
        $this->_worker->dataDir = $this->_dataDir;
        $this->_worker->sockFile = $this->_sockFile;
        $this->_worker->masterPid = $this->_pid;
    }

    private function _startWorkers() {
        for ($i = 0; $i < $this->_workerCount; $i++) {
            $this->_createWorker($i);
        }
    }

    private function _createWorker($index) {
        $currentMicroTime = microtime(true);
        if (isset($this->_workerStatuses[$index]) == false) {
            $this->_workerStatuses[$index] = new Status();
        }

        /** @var Status $status */
        $status = $this->_workerStatuses[$index];

        if (($currentMicroTime - $status->getRoundStartTime()) < self::WORKER_START_INTERVAL_SECONDS) {
            $this->_log->error("Worker {$index} cant start right now");
            return false;
        }

        // Reset logs
        $this->_resetLog();
        $status->setRoundStartTime($currentMicroTime);

        $forkPid = pcntl_fork();

        if ($forkPid == -1) {
            $this->_exit("Unable to fork worker {$index}");
        }
        
        if ($forkPid) {
            $status->setPid($forkPid);
        } else {
            socket_close($this->_socket);
            socket_close($this->_adminSocket);

            $pid = posix_getpid();

            $this->_worker->pid = $pid;
            $this->_worker->index = $index;
            $this->_worker->start();
        }
    }

    private function _startMasterServer() {
        if (file_exists($this->_sockFile)) {
            unlink($this->_sockFile);
        }

        $this->_socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if (!$this->_socket) {
            $this->_exit('Unable to create daemon socket'); 
        }

        if (!socket_bind($this->_socket, $this->_sockFile)) {
            $this->_exit('Unable to bind daemon socket');
        }
        
        if (!socket_listen($this->_socket)) {
            $this->_exit("Unable to listen daemon socket");
        }

        if (!socket_set_nonblock($this->_socket)) {
            $this->_exit("Unable to set daemon socket option");
        }
    }

    private function _startAdminServer() {
        $this->_adminSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (!$this->_adminSocket) {
            $this->_exit('Unable to create admin socket');
        }

        if (!socket_set_option($this->_adminSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->_exit('Unable to set option on admin socket');
        }
        
        if (!socket_bind($this->_adminSocket, $this->_host, $this->_port)) {
            $this->_exit('Unable to bind admin socket');
        }

        if (!socket_listen($this->_adminSocket)) {
            $this->_exit('Unable to listen admin socket');
        }

        if (!socket_set_nonblock($this->_adminSocket)) {
            $this->_exit("Unable to set admin socket option");
        }
    }

    private function _quit() {
        $this->_log->info("Ending daemon: {$this->_name}");

        foreach ($this->_workerClients as $processClient) {
            $this->_sendCommandToWorker($processClient['client'], 'quit');
        }

        if (is_resource($this->_socket)) {
            socket_close($this->_socket);
        }

        if (is_resource($this->_adminSocket)) {
            socket_close($this->_adminSocket);
        }

        foreach ($this->_adminClients as $client) {
            socket_close($client['client']);
        }

        $this->_removeFiles();
        exit;
    }

    private function _removeFiles() {
        unlink($this->_pidFile);
        unlink($this->_sockFile);

        for ($i = 0; $i < $this->_workerCount; $i++) {
            $statusFile = "{$this->_dataDir}/{$this->_name}-{$i}.status";
            if (file_exists($statusFile)) {
                unlink($statusFile);
            }
        }
    }

    private function _loop() {
        $this->_log->info("Starting daemon: {$this->_name}");

        $time = microtime(true);
        $lastWorkerTime = $time;
        $lastAdminTime = $time;
        
        while (true) {
            $now = microtime(true);

            $this->_acceptConnection($this->_adminSocket, $this->_adminClients);
            $this->_acceptConnection($this->_socket, $this->_workerClients);

            if (($now - $lastAdminTime) > self::ADMIN_DATA_INTERVAL_SECONDS) {
                //$this->_processAdminCommands();
                $lastAdminTime = $now;
            }

            $this->_processAdminCommands();

            if (($now - $lastWorkerTime) > self::HEARTBEAT) {
                $this->_sendHeartbeatToWorkers();
                $this->_checkWorkerStatus();

                foreach ($this->_missingWorkers as $index) {
                    $this->_createWorker($index);    
                }

                $lastWorkerTime = $now;
            }

            usleep(1000);
        }
    }

    private function _acceptConnection($socket, &$clients) {
        $client = @socket_accept($socket);
        if ($client === false) {
            return;
        }

        if ($client == false) {
            $socketError = socket_last_error($socket);
            $this->_log->error("Client connect failed, socket:{$socket}, error:{$socketError}");
            return;
        }

        socket_set_nonblock($client);
        socket_getsockname($client, $ip, $port);

        $clients[(int)$client] = array(
            'client' => $client,
            'ip' => $ip,
            'port' => $port,
            'index' => -1,
        );

        $this->_log->info("Client connected, {$client}, clients: " . count($clients));
    }

    private function _sendHeartbeatToWorkers() {
        foreach ($this->_workerClients as $key => $clientInfo) {
            $client = $clientInfo['client'];

            if ($this->_sendCommandToWorker($client, 'status') === false) {
                $this->_log->error("No.{$key} worker is not reachable");
                unset($this->_workerClients[$key]);
                continue;
            }
        }
    }

    private function _checkWorkerStatus() {
        $this->_missingWorkers = array();

        /** @var Status $status */

        foreach ($this->_workerStatuses as $index => $status) {
            $pid = $status->getPid();

            $processor = new Processor($pid);

            if ($processor->ppid != $this->_pid) {
                $this->_log->error("No.{$index} worker has gone, pid:{$pid}.");
                $this->_missingWorkers[] = $index;
                continue;
            }

            if ($processor->state == 'Z') {
                $this->_log->error("No.{$index} worker is a zombie processor, pid:{$pid}.");
                $this->_missingWorkers[] = $index;
                $processor->quit();
                continue;
            }
        }
    }

    private function _processAdminCommands() {
        $offlineClients = array();

        foreach ($this->_adminClients as $key => $clientInfo) {
            $client = $clientInfo['client'];
            $request = Protocol::read($client);

            if (empty($request)) {
                if (is_resource($client)) {
                    $errorCode = socket_last_error($client);
                    
                    if ($errorCode != SOCKET_EAGAIN) {
                        $offlineClients[] = $key;
                    }
                } else {
                    $offlineClients[] = $key;
                }
            } else {
                $this->_log->info("Admin request", $request);
                $this->_responseCommand($client, $request, self::COMMAND_FROM_ADMIN);
            }
        }
        
        foreach ($offlineClients as $key) {
            if (is_resource($this->_adminClients[$key])) {
                socket_close($this->_adminClients[$key]);
            }

            unset($this->_adminClients[$key]);
        }

        return true;
    }

    private function _sendCommandToWorker($client, $command, $arguments = array()) {
        $length = Protocol::write($client, $command, $arguments);
        if ($length === false) {
            return false;
        }

        $responseValue = Protocol::read($client, false);
        if (empty($responseValue) === false) {
            $this->_responseCommand($client, $responseValue, self::COMMAND_FROM_WORKER);
        }

        return true;
    }

    private function _responseCommand($client, $request, $from = 0) {
        if (is_array($request)) {
            $command = array_shift($request);
            $arguments = $request;
        } else {
            $command = $request;
            $arguments = array();
        }

        switch ($from) {
            case self::COMMAND_FROM_WORKER:
                $commandHandle = "_{$command}WorkerHandler";
                break;

            case self::COMMAND_FROM_ADMIN:
                $commandHandle = "_{$command}AdminHandler";
                break;
            
            default:
                $commandHandle = '';
                Protocol::sendLine($client, 'Wrong client.');
                break;
        }

        if (method_exists($this, $commandHandle)) {
            return call_user_func_array(array($this, $commandHandle), array($client, $arguments, $from));
        }

        if ($from == self::COMMAND_FROM_ADMIN) {
            Protocol::sendError($client, "ERR unknown command '{$command}'");
        }
        return false;
    }

    private function _restartWorkerHandler($client, $arguments = array()) {
        $workerIndex = $arguments[self::WORKER_PAYLOAD_INDEX];
        $this->_log->info("No.{$workerIndex} worker is restarting.");

        foreach ($this->_workerClients as $key => $clientInfo) {
            if ($clientInfo['index'] == $workerIndex) {
                unset($this->_workerClients[$key]);
                break;
            }
        }

        return true;
    }

    private function _statusWorkerHandler($client, $arguments = array()) {
        $workerIndex = $arguments[self::WORKER_PAYLOAD_INDEX];
        $status = unserialize($arguments[self::WORKER_PAYLOAD_DATA]);
        $this->_workerStatuses[$workerIndex] = $status;

        foreach ($this->_workerClients as $key => &$clientInfo) {
            if ($clientInfo['client'] == $client) {
                $clientInfo['index'] = $workerIndex;
                break;
            }
        }

        return true;
    }

    private function _shutdownAdminHandler($client, $arguments = array(), $from = 0, $commandId = 0) {
        $this->_sendResponseToManager($client, 'OK');
        $this->_quit();
        return true;
    }

    private function _quitAdminHandler($client, $arguments = array(), $from = 0, $commandId = 0) {
        $this->_sendResponseToManager($client, 'OK');
        usleep(100000);
        socket_close($client);
        return true;
    }

    private function _infoAdminHandler($client, $arguments = array(), $from = 0, $commandId = 0) {
        $totalCount = 0;
        $totalSeconds = 0;
        $totalMemory = 0;
        $totalWorkerCount = 0;

        /** @var Status $status */
        foreach ($this->_workerStatuses as $index => $status) {
            $totalCount += $status->getAllCount();
            $totalSeconds += $status->getAllSeconds();
            $totalMemory += $status->getRoundMemoryUsed();
            $totalWorkerCount += $status->getRounds();
        }

        $totalMemoryHuman = Unit::humanizeBytes($totalMemory);
        $totalQPS = $totalSeconds ? $totalCount / $totalSeconds : 0;
        $totalQPS = round($totalQPS, 2);

        $startedAt = date('Y-m-d H:i:s', $this->_startTime);
        $uptimeSeconds = floor(time() - $this->_startTime);
        $uptimeHuman = Unit::humanizeSeconds($uptimeSeconds);
        $version = '2.0.1';

        $output = <<<STATUS
# Master
name:{$this->_name}
version:{$version}
started_at:{$startedAt}
uptime_in_seconds:{$uptimeSeconds}
uptime_human:{$uptimeHuman}

# Memory
used_memory:{$totalMemory}
used_memory_human:{$totalMemoryHuman}

# Worker
worker_max:{$this->_workerCount}
worker_total:{$totalWorkerCount}

# Stats
run_count:{$totalCount}
run_qps:{$totalQPS}

STATUS;

        return $this->_sendResponseToManager($client, $output);
    }

    private function _sendResponseToManager($client, $response) {
        if (is_array($response)) {
            $length = Protocol::sendMultiBulk($client, $response);
            $this->_log->info("Admin response, sent: {$length}", $response);
        } else {
            $length = Protocol::sendBulk($client, $response);
            $this->_log->info("Admin response, sent: {$length}, response: {$response}");
        }

        return true;
    }

    /**
     * @param Worker $worker
     */
    public function setWorker($worker) {
        $this->_worker = $worker;
    }

    /**
     * @param string $name
     */
    public function setName($name) {
        $this->_name = $name;
    }

    /**
     * @param string $host
     */
    public function setHost($host) {
        $this->_host = $host;
    }

    /**
     * @param string $port
     */
    public function setPort($port) {
        $this->_port = $port;
    }

    /**
     * @param string $workingDirectory
     */
    public function setWorkingDirectory($workingDirectory) {
        $this->_workingDirectory = $workingDirectory;
    }

    /**
     * @param int $workerCount
     */
    public function setWorkerCount($workerCount) {
        $this->_workerCount = $workerCount;
    }

    /**
     * @param string $memoryLimit
     */
    public function setMemoryLimit($memoryLimit) {
        $this->_memoryLimit = $memoryLimit;
    }

    /**
     * @param int $logLevel
     */
    public function setLogLevel($logLevel) {
        $this->_logLevel = $logLevel;
    }
}
