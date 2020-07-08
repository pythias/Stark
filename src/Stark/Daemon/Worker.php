<?php
namespace Stark\Daemon;

use Monolog\Logger;
use Stark\Model\Processor;
use Stark\Model\Status;
use Stark\Utils\Unit;

class Worker {
    private $_masterLastActiveTime = 0;
    private $_daemonSocket;
    private $_pause = false;
    private $_run = true;
    private $_socketPort = 0;
    private $_started = false;

    private $_memoryMaxBytes = 0;

    /**
     * @var Status
     */
    private $_status = null;
    private $_statusFile = "";

    //主进程过来的参数
    public $index;
    public $name;
    public $dataDir;
    public $sockFile;
    public $pid;
    public $masterPid;

    /**
     * @var Logger
     */
    public $log;

    //配置参数
    private $_maxRunSeconds = 3600;
    private $_maxRunCount = 10000;
    private $_maxIdleSeconds = 60;
    private $_emptySleepSeconds = 0.1;
    private $_heartbeat = 5.0;

    /**
     * @var IConsumer
     */
    private $_consumer = null;

    /**
     * @var IProducer
     */
    private $_producer = null;

    public function start() {
        $this->_reloadStatus();

        $this->_initialize();
        $this->_loop();
        $this->_finalize();

        $this->_storeStatus();
        exit;
    }

    private function _initialize() {
        $this->_setupSignal();

        if ($this->_producer == null && $this->_consumer == null) {
            exit;
        }

        if ($this->_producer != null) {
            $this->_producer->initialize($this);
        }

        if ($this->_consumer != null) {
            $this->_consumer->initialize($this);
        }

        $this->_connectMasterSocket();

        $this->_memoryMaxBytes = Unit::stringToBytes(ini_get('memory_limit')) * 0.8;
        $this->_started = true;
        $this->log->info("No.{$this->index} worker started");
    }

    private function _loop() {
        $this->_status->newRound();

        while ($this->_run) {
            $health = $this->_getHealth();
            if ($health['code'] != 0) {
                $this->_stop($health['message']);
                break;
            }

            $this->_receiveCommands();

            if ($this->_pause) {
                $this->_status->runStart(); // 暂停时忽略idle
                usleep($this->_emptySleepSeconds * 1000 * 1000);
                continue;
            }

            if ($this->_doQueue() === false) {
                usleep($this->_emptySleepSeconds * 1000 * 1000);
            }
        }
    }

    private function _doQueue() {
        $data = null;
        if ($this->_producer) {
            $data = $this->_producer->produce($this);
        }

        $this->_status->runStart();
        $result = $this->_consumer->consume($this, $data);
        $this->_status->runFinished($result);

        return $result;
    }

    private function _finalize() {
        @socket_close($this->_daemonSocket);

        if ($this->_producer != null) {
            $this->_producer->finalize($this);
        }

        if ($this->_consumer != null) {
            $this->_consumer->finalize($this);
        }

        $this->log->info("No.{$this->index} worker completed");
    }

    private function _setupSignal() {
        declare(ticks = 1);

        pcntl_signal(SIGCHLD, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTERM, array($this, '_quit'));
        pcntl_signal(SIGHUP, array($this, '_quit'));
    }

    private function _getHealth() {
        //TODO: 定义消息码和消息
        $currentRunSeconds = $this->_status->getCurrentSeconds();
        if ($this->_maxRunSeconds > 0 && $currentRunSeconds > $this->_maxRunSeconds) {
            return ['code' => 1, 'message' => "Run time limit [{$currentRunSeconds}] reached"];
        }

        $currentRunCount = $this->_status->getRoundCount();
        if ($this->_maxRunCount > 0 && $currentRunCount >= $this->_maxRunCount) {
            return ['code' => 1, 'message' => "Queue limit [{$currentRunCount}] reached"];
        }

        $idleSeconds = $this->_status->getIdleSeconds();
        if ($this->_maxIdleSeconds > 0 && $idleSeconds > $this->_maxIdleSeconds) {
            return ['code' => 1, 'message' => "Idle limit [{$idleSeconds}] reached"];
        }

        if ($this->_heartbeat > 0 && (time() - $this->_masterLastActiveTime) > $this->_heartbeat) {
            $processor = new Processor($this->masterPid);
            if (!$processor->isAlive()) {
                return ['code' => 1, 'message' => "Master processor [{$this->masterPid}] has gone"];
            }
        }

        $memoryUsage = memory_get_usage();
        if ($this->_memoryMaxBytes > 0 && $memoryUsage > $this->_memoryMaxBytes) {
            return ['code' => 1, 'message' => "Memory limit [$memoryUsage] will reached"];
        }

        return ['code' => 0, 'message' => 'healthy'];
    }

    private function _stop($message) {
        $this->log->info("No.{$this->index} worker, {$message}");
        $this->_sendResponse('restart', $message);
        $this->_quit();
    }

    private function _storeStatus() {
        if (!file_put_contents($this->_statusFile, serialize($this->_status))) {
            $this->log->error("No.{$this->index} worker status save failed, file:{$this->_statusFile}.");
        }
    }

    private function _reloadStatus() {
        $this->_statusFile = "{$this->dataDir}/{$this->name}-{$this->index}.status";
        if (file_exists($this->_statusFile)) {
            $content = file_get_contents($this->_statusFile);
            $this->_status = unserialize($content);
        } else {
            $this->_status = new Status();
        }

        $this->_status->setPid($this->pid);
    }

    private function _quit() {
        $this->_run = false;
    }

    private function _connectMasterSocket() {
        if (is_resource($this->_daemonSocket)) {
            socket_close($this->_daemonSocket);
        }

        $this->_daemonSocket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        
        if (!$this->_daemonSocket) {
            $this->_exit("No.{$this->index} worker unable to create daemon socket");
        }

        if (!@socket_connect($this->_daemonSocket, $this->sockFile)) {
            $this->_exit("No.{$this->index} worker unable to connect daemon socket");
        }

        if (!socket_set_nonblock($this->_daemonSocket)) {
            $this->_exit("No.{$this->index} worker unable to set daemon socket option");
        }

        socket_getsockname($this->_daemonSocket, $ip, $this->_socketPort);
    }

    private function _exit($message) {
        $this->log->info($message);
        
        if ($this->_started) {
            $this->_finalize();
        }

        exit();
    }

    private function _updateMasterActiveTime() {
        $this->_masterLastActiveTime = microtime(true);
    }
    
    private function _receiveCommands() {
        $commandValue = Protocol::read($this->_daemonSocket, false);

        if (empty($commandValue)) {
            return false;
        }

        return $this->_responseCommand($commandValue);
    }

    private function _responseCommand($commandValue) {
        if (is_array($commandValue)) {
            $command = array_shift($commandValue);
            $arguments = $commandValue;
        } else {
            $command = $commandValue;
            $arguments = array();
        }

        $commandHandle = "_{$command}CommandHandle";
        if (method_exists($this, $commandHandle) == false) return false;
        return call_user_func_array(array($this, $commandHandle), array($arguments));
    }

    private function _quitCommandHandle($arguments = array()) {
        $this->_stop("Received command: quit");
        return true;
    }

    private function _restartCommandHandle($arguments = array()) {
        $this->_stop("Received command: restart");
        return true;
    }

    private function _pauseCommandHandle($arguments = array()) {
        $this->log->info("No.{$this->index} worker received command: pause");
        $this->_pause = true;
        return true;
    }

    private function _resumeCommandHandle($arguments = array()) {
        $this->log->info("No.{$this->index} worker received command: resume");
        $this->_pause = false;
        return true;
    }

    private function _statusCommandHandle($arguments = array()) {
        $this->_updateMasterActiveTime();
        $this->_status->setRoundMemoryUsed(memory_get_peak_usage(true));
        return $this->_sendResponse('status', serialize($this->_status));
    }

    private function _sendResponse($command, $response = '') {
        $multiBulk = array($command, $this->index, $this->pid, $this->_socketPort, $response);
        $result = Protocol::sendMultiBulk($this->_daemonSocket, $multiBulk);

        if ($result === false) {
            $errorCode = socket_last_error($this->_daemonSocket);
            
            if ($errorCode === SOCKET_EPIPE || $errorCode === SOCKET_EAGAIN) {
                $this->log->info("No.{$this->index} worker socket error, reconnecting");
                $this->_connectMasterSocket();                    
            }
        }

        return $result;
    }

    /**
     * @param int $maxRunSeconds
     */
    public function setMaxRunSeconds($maxRunSeconds) {
        $this->_maxRunSeconds = $maxRunSeconds;
    }

    /**
     * @param int $maxRunCount
     */
    public function setMaxRunCount($maxRunCount) {
        $this->_maxRunCount = $maxRunCount;
    }

    /**
     * @param int $maxIdleSeconds
     */
    public function setMaxIdleSeconds($maxIdleSeconds) {
        $this->_maxIdleSeconds = $maxIdleSeconds;
    }

    /**
     * @param float $emptySleepSeconds
     */
    public function setEmptySleepSeconds($emptySleepSeconds) {
        $this->_emptySleepSeconds = $emptySleepSeconds;
    }

    /**
     * @param float $heartbeat
     */
    public function setHeartbeat($heartbeat) {
        $this->_heartbeat = $heartbeat;
    }

    /**
     * @param IConsumer $consumer
     */
    public function setConsumer($consumer) {
        $this->_consumer = $consumer;
    }

    /**
     * @param IProducer $producer
     */
    public function setProducer($producer) {
        $this->_producer = $producer;
    }

    /**
     * @return Status
     */
    public function getStatus() {
        return $this->_status;
    }
}