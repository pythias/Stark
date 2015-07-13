<?php
namespace Stark\Daemon;

class Master extends \Stark\Core\Options {
    private $_pid;
    private $_pidFile;
    private $_startTime = 0.0;
    private $_log;
    private $_consumer = null;
    private $_queue = null;

    /*Options*/
    protected $_name = 'daemon';
    protected $_host = '127.0.0.1';
    protected $_port = '1980';
    protected $_workingDirectory = '/tmp/';
    protected $_heartbeat = 2.0;
    protected $_maxWorkerCount = 1;
    protected $_maxRunCount = 0;
    protected $_maxRunSeconds = 0;
    protected $_maxIdleSeconds = 0;
    protected $_memoryLimit = '1024M';

    /*Daemon*/
    private $_daemonSocketFile;
    private $_daemonSocket;
    private $_workerStatuses = array();
    private $_workerClients = array();
    private $_worker;

    /*Admin*/
    private $_adminSocket;
    private $_adminClients = array();

    const UNIX_PATH_MAX = 108;
    const COMMAND_FROM_ADMIN = 0;
    const COMMAND_FROM_WORKER = 1;
    const WORKER_START_INTERVAL_SECONDES = 1;
    const ADMIN_DATA_INTERVAL_SECONDES = 0.01;

    const WORKER_PAYLOAD_INDEX = 0;
    const WORKER_PAYLOAD_PID = 1;
    const WORKER_PAYLOAD_PORT = 2;
    const WORKER_PAYLOAD_DATA = 3;
    
    public function start() {
        ini_set('memory_limit', $this->_memoryLimit);
        $this->_checkEnviroments();
        $this->_initialize();

        $this->_daemonIsRunning();

        \Stark\Core\System::runInBackground();
        $this->_pid = posix_getpid();
        $this->_createPidFile();
        \Stark\Core\System::setAffinity($this->_pid);
        \Stark\Core\System::setProcTitle($this->_pid, "daemon '{$this->_name}'");

        //sample worker
        $this->_createSampleWorker();

        //start daemon
        $this->_startTime = microtime(true);
        $this->_setupSignal();
        $this->_createDaemonSocket();
        $this->_createAdminSocket();
        $this->_startWorkers();
        $this->_startLoop();
    }

    private function _checkEnviroments() {
        //PHP版本
        if (phpversion() < 5.3) {
            $this->_exit("PHP version require 5.3+");
        }

        //pcntl扩展
        if (function_exists('pcntl_fork') === false) {
            $this->_exit("'pcntl' extension is required");
        }
    }

    private function _initialize() {
        $this->_initializeWorkingDirectory();
        $this->_initializeFiles();
        $this->_checkParameters();
    }

    private function _initializeWorkingDirectory() {
        if (empty($this->_workingDirectory)) {
            $this->_workingDirectory = '/tmp/';
        }

        if (is_dir($this->_workingDirectory) == false) {
            if (mkdir($this->_workingDirectory, 0, true) == false) {
                $this->_exit("Unable to create working directory: {$this->_workingDirectory}");
            }
        }

        if (is_writable($this->_workingDirectory) == false) {
            $this->_exit("Unable to write working directory: {$this->_workingDirectory}");
        }
    }

    private function _initializeFiles() {
        $this->_pidFile = $this->_workingDirectory . $this->_name . '.pid';
        $this->_daemonSocketFile = $this->_workingDirectory . $this->_name . '.sock';
        
        if (strlen($this->_daemonSocketFile) > self::UNIX_PATH_MAX) {
            $this->_exit("Socket path {$this->_daemonSocketFile} is too long");
        }
    }

    private function _checkParameters() {
        if ($this->_log == null) {
            $this->_log = new \Stark\Core\Log\Console();    
        }

        if ($this->_consumer == null) {
            $this->_exit("Cannt run daemon without callback");
        }
    }

    private function _daemonIsRunning() {
        if (file_exists($this->_pidFile) == false) return;

        $lastPid = file_get_contents($this->_pidFile);
        $queueDaemonStatus = \Stark\Core\System::getStatus($lastPid);
        
        if ($queueDaemonStatus === false) {
            unlink($this->_pidFile);
        } else {
            //TODO 判断是否为当前Daemon
            $this->_exit("Daemon '{$this->_name}' is already running", \Stark\Core\Log\Level::INFO);
        }
    }

    private function _createPidFile() {
        $this->_pidFile = $this->_workingDirectory . $this->_name . '.pid';
        if (file_put_contents($this->_pidFile, $this->_pid) == false) {
            $this->_exit("Unable to write pid file '{$this->_pidFile}'");
        }
    }

    private function _createSampleWorker() {
        $this->_worker = new \Stark\Daemon\Worker();
        $this->_worker->log = $this->_log;
        $this->_worker->daemonSocketFile = $this->_daemonSocketFile;
        $this->_worker->maxWorkerCount = $this->_maxWorkerCount;
        $this->_worker->masterPid = $this->_pid;
        $this->_worker->heartbeat = $this->_heartbeat;
        $this->_worker->consumer = $this->_consumer;
        $this->_worker->queue = $this->_queue;
        $this->_worker->maxRunCount = $this->_maxRunCount;
        $this->_worker->maxRunSeconds = $this->_maxRunSeconds;
        $this->_worker->maxIdleSeconds = $this->_maxIdleSeconds;
    }

    private function _startWorkers() {
        for ($i = 0; $i < $this->_maxWorkerCount; $i++) {
            $this->_createWorker($i);
        }
    }
    
    private function _createWorker($index) {
        echo "Start worker {$index}\r\n";
        $currentMicroTime = microtime(true);

        if (isset($this->workerStatuses[$index]) == false) {
            $this->workerStatuses[$index] = new \Stark\Daemon\Status(); 
        }

        if (($currentMicroTime - $this->workerStatuses[$index]->startTime) < self::WORKER_START_INTERVAL_SECONDES) {
            $this->_log->log("Worker {$index} cannt start right now", \Stark\Core\Log\Level::ERROR);
            return false;
        }
        
        $this->workerStatuses[$index]->startTime = $currentMicroTime;
        $this->workerStatuses[$index]->lastActiveTime = $currentMicroTime;
        
        $forkPid = pcntl_fork();

        if ($forkPid == -1) {
            $this->_exit("Unable to fork worker {$index}");
        }
        
        if ($forkPid) {
            $this->workerStatuses[$index]->pid = $forkPid;
        } else {
            socket_close($this->_daemonSocket);
            socket_close($this->_adminSocket);

            $pid = posix_getpid();
            \Stark\Core\System::setAffinity($pid, '2-32');
            \Stark\Core\System::setProcTitle($pid, "daemon '{$this->_name}' worker {$index}");

            $this->workerStatuses[$index]->totalCpuU += $this->workerStatuses[$index]->cpuU;
            $this->workerStatuses[$index]->totalCpuS += $this->workerStatuses[$index]->cpuS;

            $this->_worker->pid = $pid; 
            $this->_worker->index = $index;
            $this->_worker->start();
        }
    }

    private function _setupSignal() {
        declare(ticks = 1);

        pcntl_signal(SIGCHLD, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTERM, array($this, '_quit'));
        pcntl_signal(SIGHUP, array($this, '_quit'));
    }

    private function _exit($message, $level = \Stark\Core\Log\Level::ERROR) {
        exit("{$message}\r\n");
    }

    private function _createDaemonSocket() {
        if (file_exists($this->_daemonSocketFile)) {
            unlink($this->_daemonSocketFile);
        }

        $this->_daemonSocket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if (!$this->_daemonSocket) {
            $this->_exit('Unable to create daemon socket'); 
        }

        if (!socket_bind($this->_daemonSocket, $this->_daemonSocketFile)) {
            $this->_exit('Unable to bind daemon socket');
        }
        
        if (!socket_listen($this->_daemonSocket)) {
            $this->_exit("Unable to listen daemon socket");
        }

        if (!socket_set_nonblock($this->_daemonSocket)) {
            $this->_exit("Unable to set daemon socket option");
        }
    }

    private function _createAdminSocket() {
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

        if ($this->_port == 0) {
            socket_getsockname($this->_adminSocket, $this->_host, $this->_port);
        }
        
        if (!socket_listen($this->_adminSocket)) {
            $this->_exit('Unable to listen admin socket');
        }

        socket_set_nonblock($this->_adminSocket);
    }

    private function _quit() {
        $this->_log->log("Ending daemon: {$this->_name}", \Stark\Core\Log\Level::INFO);

        foreach ($this->_workerClients as $processClient) {
            $this->_sendCommandToWorker($processClient['client'], 'quit');
        }

        if (is_resource($this->_daemonSocket)) {
            socket_close($this->_daemonSocket);
        }
        
        if (is_resource($this->_adminSocket)) {
            socket_close($this->_adminSocket);
        }

        foreach ($this->_adminClients as $client) {
            socket_close($client['client']);
        }
        
        unlink($this->_pidFile);
        unlink($this->_daemonSocketFile);
        
        exit;
    }
    
    private function _startLoop() {
        $this->_log->log("Starting daemon: {$this->_name}", \Stark\Core\Log\Level::INFO);

        $time = microtime(true);
        $lastWorkerTime = $time;
        $lastAdminTime = $time;
        
        while (true) {
            $now = microtime(true);

            if (($now - $lastAdminTime) > self::ADMIN_DATA_INTERVAL_SECONDES) {
                $this->_acceptConnection($this->_adminSocket, $this->_adminClients);
                $this->_checkAdminCommands();

                $lastAdminTime = $now;
            }

            $this->_acceptConnection($this->_daemonSocket, $this->_workerClients);

            if (($now - $lastWorkerTime) > $this->_heartbeat) {
                $this->_sendHeartbeatToWorkers();

                $missingWorkerList = $this->_checkWorkerStatus();

                if (empty($missingWorkerList) === false) {
                    foreach ($missingWorkerList as $index) {
                        $this->_createWorker($index);    
                    }
                }

                $lastWorkerTime = $now;
            }

            usleep(1000);
        }
    }

    private function _acceptConnection($socket, &$clients) {
        $newClient = @socket_accept($socket);

        if ($newClient) {
            socket_set_nonblock($newClient);
            socket_getsockname($newClient, $ip, $port);
            $clients[] = array(
                'client' => $newClient,
                'ip' => $ip,
                'port' => $port,
                'index' => -1,
            );
        }
    }

    private function _sendHeartbeatToWorkers() {
        foreach ($this->_workerClients as $key => $clientInfo) {
            $client = $clientInfo['client'];

            if ($this->_sendCommandToWorker($client, 'status') === false) {
                unset($this->_workerClients[$key]);
                continue;
            }
        }
    }

    private function _checkWorkerStatus() {
        $missingWorkerList = array();
        
        foreach ($this->workerStatuses as $index => $worker) {
            //TODO:检查子进程心跳，对于没有心跳或者心跳迟迟未到的处理
            //MAC系统没有PROC文件系统，会导致进程多启动
            $queueDaemonStatus = \Stark\Core\System::getStatus($worker->pid);

            if ($queueDaemonStatus === false || intval($queueDaemonStatus['PPid']) != $this->_pid) {
                $this->_log->log("Worker {$index} is gone", \Stark\Core\Log\Level::ERROR);
                $missingWorkerList[] = $index;
            }
        }

        return $missingWorkerList;
    }

    private function _checkAdminCommands() {
        $offlineClients = array();

        foreach ($this->_adminClients as $key => $clientInfo) {
            $client = $clientInfo['client'];
            $responseValue = Protocol::read($client);

            if (empty($responseValue)) {
                if (is_resource($client)) {
                    $errorCode = socket_last_error($client);
                    
                    if ($errorCode != SOCKET_EAGAIN) {
                        $offlineClients[] = $key;
                    }
                } else {
                    $offlineClients[] = $key;
                }
            } else {
                $this->_responseCommand($client, $responseValue, self::COMMAND_FROM_ADMIN);
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
        $write = Protocol::write($client, $command, $arguments);
        
        if ($write === false) return false;

        $responseValue = Protocol::read($client, false);
        if (empty($responseValue) === false) {
            $this->_responseCommand($client, $responseValue, self::COMMAND_FROM_WORKER);
        }

        return true;
    }

    private function _responseCommand($client, $responseValue, $from = 0) {
        if (is_array($responseValue)) {
            $command = array_shift($responseValue);
            $arguments = $responseValue;
        } else {
            $command = $responseValue;
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
                Protocol::sendLine($client, 'Wrong client.');
                break;
        }

        if (method_exists($this, $commandHandle)) {
            return call_user_func_array(array($this, $commandHandle), array($client, $arguments, $from));
        } else {
            if ($from == self::COMMAND_FROM_ADMIN) {
                Protocol::sendError($client, "ERR unknown command '{$command}'");
            }

            return false;
        }

        return true;
    }

    private function _restartWorkerHandler($client, $arguments = array()) {
        $this->_log->log("Restarting worker {$arguments[self::WORKER_PAYLOAD_INDEX]}");

        $workerIndex = $arguments[self::WORKER_PAYLOAD_INDEX];

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
        $data = json_decode($arguments[self::WORKER_PAYLOAD_DATA], true);
        $this->workerStatuses[$workerIndex]->update($data);

        foreach ($this->_workerClients as $key => &$clientInfo) {
            if ($clientInfo['client'] == $client) {
                $clientInfo['index'] = $workerIndex;
                break;
            }
        }

        return true;
    }

    private function _shutdownAdminHandler($client, $arguments = array(), $from = 0, $commandId = 0) {
        $this->_log->log('Received command: shutdown');
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
        $status = $this->_getStatus(true);
        return $this->_sendResponseToManager($client, $status);
    }

    private function _sendResponseToManager($client, $response) {
        if (is_array($response)) {
            Protocol::sendMultiBulk($client, $response);
        } else {
            Protocol::sendBulk($client, $response);
        }

        return true;
    }

    private function _getStatus($string = false) {
        $totalCount = 0;
        $totalTime = 0.0;
        $totalQPS = 0.0;
        $totalMemory = 0;
        $totalCpuU = 0;
        $totalCpuS = 0;
                                
        foreach ($this->workerStatuses as $childInfo) {
            $totalCount += $childInfo->totalCount;
            $totalTime += $childInfo->totalTime;
            $totalMemory += $childInfo->memory;
            $totalCpuU += $childInfo->cpuU + $childInfo->totalCpuU;
            $totalCpuS += $childInfo->cpuS + $childInfo->totalCpuS;
        }
        
        $totalQPS = $totalTime ? $totalCount / $totalTime : 0;
        
        $status = array(
            'host' => $this->_host,
            'port' => $this->_port,
            'daemon_name' => $this->_name,
            'start_time' => date('Y-m-d H:i:s', floor($this->_startTime)),
            'max_worker' => $this->_maxWorkerCount,
            'total_count' => $totalCount,
            'total_time' => $totalTime,
            'total_qps' => $totalQPS,
            'total_memory' => $totalMemory,
            'total_cpu_u' => $totalCpuU,
            'total_cpu_s' => $totalCpuS,
            'total_worker_client' => count($this->_workerClients),
            'total_manage_client' => count($this->_adminClients),
        );

        if ($string) {
            $output = "";

            foreach ($status as $key => $value) {
                $output .= "{$key}:{$value}\r\n";
            }

            return $output;
        }

        return $status;
    }

    protected function _setMasterOptions($options) {
        return $this->setOptions($options);
    }

    protected function _setQueueOptions($options) {
        return $this->_setClassOptionsByType($options, 'Queue');
    }

    protected function _setConsumerOptions($options) {
        return $this->_setClassOptionsByType($options, 'Consumer');
    }

    private function _setClassOptionsByType($options, $type) {
        if (empty($options['class'])) {
            return false;
        }

        $className = $options['class'];        
        if ($className[0] != '\\') {
            $className = "\\Stark\\Daemon\\{$type}\\{$className}";
        }

        if (class_exists($className) == false) {
            return false;
        }
        
        $property = '_' . lcfirst($type);
        $this->$property = new $className(isset($options['options']) ? $options['options'] : array());

        return true;
    }
}
