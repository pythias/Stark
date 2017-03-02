<?php
namespace Stark\Daemon;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Master extends \Stark\Core\Options {
    private $_processorCount = 0;
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
    protected $_workingDirectory = '/tmp';
    protected $_heartbeat = 2.0;
    protected $_maxWorkerCount = 1;
    protected $_maxRunCount = 0;
    protected $_maxRunSeconds = 0;
    protected $_maxIdleSeconds = 0;
    protected $_emptySleepSeconds = 0;
    protected $_memoryLimit = '1024M';
    protected $_config = array();
    protected $_logLevel = Logger::INFO;

    /*Daemon*/
    private $_daemonSocketFile;
    private $_daemonSocket;
    private $_workerStatuses = array();
    private $_workerClients = array();
    private $_worker;
    private $_missingWorkers = array();

    /*Admin*/
    private $_adminSocket;
    private $_adminClients = array();

    const UNIX_PATH_MAX = 108;
    const COMMAND_FROM_ADMIN = 0;
    const COMMAND_FROM_WORKER = 1;
    const WORKER_START_INTERVAL_SECONDS = 1;
    const ADMIN_DATA_INTERVAL_SECONDS = 0.01;

    const WORKER_PAYLOAD_INDEX = 0;
    const WORKER_PAYLOAD_PID = 1;
    const WORKER_PAYLOAD_PORT = 2;
    const WORKER_PAYLOAD_DATA = 3;
    
    public function start() {
        ini_set('memory_limit', $this->_memoryLimit);

        $this->_initialize();

        $this->_daemonIsRunning();

        //init process env
        $this->_initializeProc();

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

    private function _initializeProc() {
        \Stark\Core\System::runInBackground();

        $this->_pid = posix_getpid();
        $this->_createPidFile();
        \Stark\Core\System::setProcTitle($this->_pid, "daemon '{$this->_name}'");

        $this->_processorCount = \Stark\Core\System::getProcNumber();
        if ($this->_processorCount > 2) {
            \Stark\Core\System::setAffinity($this->_pid, "1-{$this->_processorCount}");
        }
    }

    private function _initialize() {
        $this->_initializeWorkingDirectory();
        $this->_initializeLog();
        $this->_initializeFiles();
        $this->_checkParameters();
    }

    private function _initializeLog() {
        $logFile = "{$this->_workingDirectory}/logs/{$this->_name}-" . date('Y-m-d') . ".log";

        $this->_log = new Logger($this->_name);
        $this->_log->pushHandler(new StreamHandler($logFile, $this->_logLevel));
    }

    private function _resetLog() {
        $logFile = "{$this->_workingDirectory}/logs/{$this->_name}-" . date('Y-m-d') . ".log";
        $this->_log->popHandler();
        $this->_log->pushHandler(new StreamHandler($logFile, $this->_logLevel));
    }

    private function _initializeWorkingDirectory() {
        if (empty($this->_workingDirectory)) {
            $this->_workingDirectory = '/tmp';
        }

        $this->_workingDirectory = rtrim($this->_workingDirectory, '/');

        if (is_dir($this->_workingDirectory) == false) {
            if (mkdir($this->_workingDirectory, 0777, true) == false) {
                $this->_exit("Unable to create working directory: {$this->_workingDirectory}");
            }
        }

        if (is_writable($this->_workingDirectory) == false) {
            $this->_exit("Unable to write working directory: {$this->_workingDirectory}");
        }
    }

    private function _initializeFiles() {
        $this->_pidFile = "{$this->_workingDirectory}/{$this->_name}.pid";
        $this->_daemonSocketFile = "{$this->_workingDirectory}/{$this->_name}.sock";
        
        if (strlen($this->_daemonSocketFile) > self::UNIX_PATH_MAX) {
            $this->_exit("Socket path {$this->_daemonSocketFile} is too long");
        }
    }

    private function _checkParameters() {
        if ($this->_consumer == null) {
            $this->_exit("Can't run daemon without callback");
        }
    }

    private function _daemonIsRunning() {
        if (file_exists($this->_pidFile) == false) {
            return;
        }

        $lastPid = file_get_contents($this->_pidFile);
        $processor = new \Stark\Model\Processor($lastPid);
        
        if ($processor->pid == false) {
            unlink($this->_pidFile);
        } else {
            //TODO: pid+name
            $this->_exit("Daemon '{$this->_name}' is already running");
        }
    }

    private function _createPidFile() {
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
        $this->_worker->emptySleepSeconds = $this->_emptySleepSeconds;
        $this->_worker->config = $this->_config;
    }

    private function _startWorkers() {
        for ($i = 0; $i < $this->_maxWorkerCount; $i++) {
            $this->_createWorker($i);
        }
    }
    
    private function _createWorker($index) {
        $currentMicroTime = microtime(true);

        if (isset($this->_workerStatuses[$index]) == false) {
            $this->_workerStatuses[$index] = new \Stark\Daemon\Status();
        }

        if (($currentMicroTime - $this->_workerStatuses[$index]->startTime) < self::WORKER_START_INTERVAL_SECONDS) {
            $this->_log->addError("Worker {$index} cannt start right now");
            return false;
        }

        // Reset logs
        $this->_resetLog();
        
        $this->_workerStatuses[$index]->startTime = $currentMicroTime;
        $this->_workerStatuses[$index]->lastActiveTime = $currentMicroTime;
        
        $forkPid = pcntl_fork();

        if ($forkPid == -1) {
            $this->_exit("Unable to fork worker {$index}");
        }
        
        if ($forkPid) {
            $this->_workerStatuses[$index]->pid = $forkPid;
        } else {
            socket_close($this->_daemonSocket);
            socket_close($this->_adminSocket);

            $pid = posix_getpid();
            if ($this->_processorCount > 2) {
                \Stark\Core\System::setAffinity($pid, "2-{$this->_processorCount}");
            }
            \Stark\Core\System::setProcTitle($pid, "daemon '{$this->_name}' worker {$index}");

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

    private function _exit($message) {
        if ($this->_log) {
            $this->_log->addInfo($message);
        }

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
        $this->_log->addInfo("Ending daemon: {$this->_name}");

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
        $this->_log->addInfo("Starting daemon: {$this->_name}");

        $time = microtime(true);
        $lastWorkerTime = $time;
        $lastAdminTime = $time;
        
        while (true) {
            $now = microtime(true);

            if (($now - $lastAdminTime) > self::ADMIN_DATA_INTERVAL_SECONDS) {
                $this->_acceptConnection($this->_adminSocket, $this->_adminClients);
                $this->_checkAdminCommands();

                $lastAdminTime = $now;
            }

            $this->_acceptConnection($this->_daemonSocket, $this->_workerClients);

            if (($now - $lastWorkerTime) > $this->_heartbeat) {
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
                $this->_log->addError("Worker [{$key}] client [{$client}] is not reachable");
                unset($this->_workerClients[$key]);
                continue;
            }
        }
    }

    private function _checkWorkerStatus() {
        $this->_missingWorkers = array();
        
        foreach ($this->_workerStatuses as $index => $worker) {
            $processor = new \Stark\Model\Processor($worker->pid);

            if ($processor->ppid != $this->_pid) {
                $this->_log->addError("Worker {$index}[{$worker->pid}] is gone");
                $this->_missingWorkers[] = $index;
                continue;
            }

            if ($processor->state == 'Z') {
                $this->_log->addError("Worker {$index}[{$worker->pid}] is zombie processor");
                $this->_missingWorkers[] = $index;
                $processor->quit();
                continue;
            }
        }
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
                $commandHandle = '';
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
    }

    private function _restartWorkerHandler($client, $arguments = array()) {
        $this->_log->addInfo("Restarting worker {$arguments[self::WORKER_PAYLOAD_INDEX]}");

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
        $this->_workerStatuses[$workerIndex]->update($data);

        foreach ($this->_workerClients as $key => &$clientInfo) {
            if ($clientInfo['client'] == $client) {
                $clientInfo['index'] = $workerIndex;
                break;
            }
        }

        return true;
    }

    private function _shutdownAdminHandler($client, $arguments = array(), $from = 0, $commandId = 0) {
        $this->_log->addInfo('Received command: shutdown');
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
                                
        foreach ($this->_workerStatuses as $childInfo) {
            $totalCount += $childInfo->totalCount;
            $totalTime += $childInfo->totalTime;
            $totalMemory += $childInfo->memory;
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
