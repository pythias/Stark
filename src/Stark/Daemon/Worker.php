<?php
namespace Stark\Daemon;

class Worker {
    private $_totalCount = 0;
    private $_totalTime = 0;
    private $_totalQPS = 0;

    private $_workerStartTime = 0;
    private $_queueStartTime = 0;
    private $_currentCount = 0;
    private $_currentRunTime = 0;
    private $_currentQPS = 0;
    private $_masterLastActiveTime = 0;
    private $_lastActiveTime = 0;

    private $_daemonSocket;
    private $_pause = false;
    private $_run = true;
    private $_socketPort = 0;
    private $_started = false;

    private $_memoryMaxBytes = 0;

    //主进程过来的参数
    public $index;
    public $log;
    public $daemonSocketFile;
    public $pid;
    public $masterPid;    

    //配置参数
    public $maxRunSeconds = 0;
    public $maxRunCount = 0;
    public $maxIdleSeconds = 60;
    public $emptySleepSeconds = 0.1;
    public $heartbeat = 2.0;
    public $consumer = null;
    public $queue = null;

    //系统参数
    public $config = array();
    
    public function start() {
        $this->_initialize();
        $this->_startLoop();
        $this->_finalize();

        exit;
    }

    private function _initialize() {
        $this->_setupSignal();

        if ($this->queue) {
            $this->queue->init($this);
        }

        $this->consumer->init($this);
        
        $this->_connectMasterSocket();
        
        $this->_workerStartTime = microtime(true);
        $this->_lastActiveTime = $this->_workerStartTime;
        $this->_currentCount = 0;
        $this->_currentRunTime = 0;
        $this->_currentQPS = 0;
        $this->_masterLastActiveTime = 0;
        $this->_memoryMaxBytes = \Stark\Core\System::getSizeBytes(ini_get('memory_limit')) * 0.8;

        $this->_started = true;
        $this->log->addInfo("Worker {$this->index} is started");
    }

    private function _startLoop() {
        while ($this->_run) {
            $this->_queueStartTime = microtime(true);

            if ($this->_checkStatus() == false) {
                break;
            }

            $this->_receiveCommands();

            if ($this->_pause) {
                usleep($this->emptySleepSeconds * 1000 * 1000);
                continue;
            }

            if ($this->_doQueue() === false) {
                usleep($this->emptySleepSeconds * 1000 * 1000);
            }
        }
    }

    private function _finalize() {
        @socket_close($this->_daemonSocket);

        if ($this->queue) {
            $this->queue->complete($this);
        }
        
        $this->consumer->complete($this);

        $this->log->addInfo("Worker {$this->index} is completed");
    }

    private function _setupSignal() {
        declare(ticks = 1);

        pcntl_signal(SIGCHLD, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTERM, array($this, '_quit'));
        pcntl_signal(SIGHUP, array($this, '_quit'));
    }
      
    private function _checkStatus() {
        $reason = $this->_checkHealth();

        if ($reason !== true) {
            $this->_restart($reason);
            return false;
        }
        
        return true;
    }
    
    private function _checkHealth() {
        $runTime = $this->_queueStartTime - $this->_workerStartTime;
        if ($this->maxRunSeconds > 0 && $runTime > $this->maxRunSeconds) {
            return "Run time limit [{$runTime}] reached";
        }

        if ($this->maxRunCount > 0 && $this->_currentCount >= $this->maxRunCount) {
            return "Queue limit [{$this->_currentCount}] reached";
        }

        $idelTime = $this->_queueStartTime - $this->_lastActiveTime;
        if ($this->maxIdleSeconds > 0 && $idelTime > $this->maxIdleSeconds) {
            return "Idel time limit [{$idelTime}] reached";
        }

        if ($this->heartbeat > 0 && $this->_masterLastActiveTime > 0 && ($this->_queueStartTime - $this->_masterLastActiveTime) > $this->heartbeat) {
            $processor = new \Stark\Model\Processor($this->masterPid);
        
            if ($processor->pid == false) {
                //TODO: pid+name
                return "Master processor [{$this->masterPid}] has gone";
            }
        }

        $memoryUsage = memory_get_usage();
        if ($this->_memoryMaxBytes > 0 && $memoryUsage > $this->_memoryMaxBytes) {
            return 'Memory limit [$memoryUsage] will reached';
        }

        return true;
    }

    private function _restart($reason = false) {
        if ($reason != false) {
            $this->log->addInfo("No.{$this->index} worker restart: {$reason}");
        }

        $this->_sendResponse('restart', $reason);
        $this->_statusCommandHandle(); //TODO:汇报失败时从文件恢复状态
        $this->_quit();
    }

    private function _quit() {
        $this->_run = false;
    }
    
    private function _getStatus() {
        return array(
            'lastActiveTime' => $this->_lastActiveTime,
            'totalCount' => $this->_totalCount,
            'totalTime' => $this->_totalTime,
            'totalQPS' => $this->_totalQPS,
            'memory' => memory_get_peak_usage(true),
        );
    }
    
    private function _doQueue() {
        $queueBeginTime = microtime(true);
        $data = null;

        if ($this->queue) {
            $data = $this->queue->pop($this);
            if ($data === false) {
                return false;
            }
        }

        $runResult = $this->consumer->run($this, $data);

        $queueEndTime = microtime(true);
        $this->_lastActiveTime = $queueBeginTime;
        $this->_currentCount++;
        $this->_currentRunTime += $queueEndTime - $queueBeginTime;
        $this->_currentQPS = $this->_currentCount / $this->_currentRunTime;
        $this->_totalCount++;
        $this->_totalTime += $queueEndTime - $queueBeginTime;
        $this->_totalQPS = $this->_totalCount / $this->_totalTime;
        
        return $runResult;
    }

    private function _connectMasterSocket() {
        if (is_resource($this->_daemonSocket)) {
            socket_close($this->_daemonSocket);
        }

        $this->_daemonSocket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        
        if (!$this->_daemonSocket) {
            $this->_exit('Unable to create daemon socket'); 
        }

        if (!@socket_connect($this->_daemonSocket, $this->daemonSocketFile)) {
            $this->_exit('Unable to connect daemon socket');
        }

        if (!socket_set_nonblock($this->_daemonSocket)) {
            $this->_exit("Unable to set daemon socket option");
        }

        socket_getsockname($this->_daemonSocket, $ip, $this->_socketPort);
    }

    private function _exit($message) {
        $this->log->addInfo($message);
        
        if ($this->_started) {
            $this->_finalize();
        }

        exit("{$message}\r\n");
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
        $this->_restart("Worker {$this->index} received command: quit");
        return true;
    }

    private function _restartCommandHandle($arguments = array()) {
        $this->_restart("Worker {$this->index} received command: restart");
        return true;
    }

    private function _pauseCommandHandle($arguments = array()) {
        if ($this->_pause) {
            $this->log->addInfo("Worker {$this->index} received command: pause");
            $this->_pause = true;
        }

        return true;
    }

    private function _resumeCommandHandle($arguments = array()) {
        if ($this->_pause) {
            $this->log->addInfo("Worker {$this->index} received command: resume");
            $this->_pause = false;
        }

        return true;
    }

    private function _statusCommandHandle($arguments = array()) {
        $this->_updateMasterActiveTime();
        $status = $this->_getStatus();
        return $this->_sendResponse('status', json_encode($status));
    }

    private function _sendResponse($command, $response = '') {
        $multiBulk = array($command, $this->index, $this->pid, $this->_socketPort, $response);
        $result = Protocol::sendMultiBulk($this->_daemonSocket, $multiBulk);

        if ($result === false) {
            $errorCode = socket_last_error($this->_daemonSocket);
            
            if ($errorCode === SOCKET_EPIPE || $errorCode === SOCKET_EAGAIN) {
                $this->log->addInfo("Worker {$this->index} socket error, reconnecting");
                $this->_connectMasterSocket();                    
            }
        }

        return $result;
    }
}