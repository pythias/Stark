<?php


namespace Stark\Daemon;


class Daemon {
    private $_master = null;
    private $_worker = null;

    public function __construct(IProducer $producer = null, IConsumer $consumer = null) {
        if ($consumer == null) {
            exit("Consumer cant be null!\n");
        }

        $this->_worker = new Worker();
        $this->_worker->setProducer($producer);
        $this->_worker->setConsumer($consumer);

        $this->_master = new Master();
        $this->_master->setWorker($this->_worker);
    }

    public function start() {
        $this->_master->start();
    }

    /**
     * 设定Daemon名称
     *
     * @param $name
     * @return Daemon
     */
    public function setName($name) {
        $this->_master->setName($name);
        return $this;
    }

    /**
     * 管理后台绑定的IP
     *
     * @param $host
     * @return Daemon
     */
    public function setHost($host) {
        $this->_master->setHost($host);
        return $this;
    }

    /**
     * 管理后台绑定的端口
     *
     * @param $port
     * @return Daemon
     */
    public function setPort($port) {
        $this->_master->setPort($port);
        return $this;
    }

    /**
     * Worker的数量
     *
     * @param $workerCount
     * @return Daemon
     */
    public function setWorkerCount($workerCount) {
        $this->_master->setWorkerCount($workerCount);
        return $this;
    }

    /**
     * 设定运行目录
     *  日志：$workingDirectory/logs/*
     *  数据：$workingDirectory/data/*
     *  运行时文件：$workingDirectory/run/*
     *
     * @param string $workingDirectory
     * @return Daemon
     */
    public function setWorkingDirectory($workingDirectory) {
        $this->_master->setWorkingDirectory($workingDirectory);
        return $this;
    }

    /**
     * 连续没有数据x秒后重启进程（秒）
     *
     * @param $seconds
     * @return Daemon
     */
    public function setMaxIdleSeconds($seconds) {
        $this->_worker->setMaxIdleSeconds($seconds);
        return $this;
    }

    /**
     * 设定进程运行次数
     * 运行x次后退出，重新开启新进程
     *
     * @param int $maxRunCount
     * @return Daemon
     */
    public function setMaxRunCount($maxRunCount) {
        $this->_worker->setMaxRunCount($maxRunCount);
        return $this;
    }

    /**
     * 设定进程最长运行时间（秒）
     * 运行x秒后退出，重新开启新进程
     *
     * @param int $maxRunSeconds
     * @return Daemon
     */
    public function setMaxRunSeconds($maxRunSeconds) {
        $this->_worker->setMaxRunSeconds($maxRunSeconds);
        return $this;
    }

    /**
     * 设定空闲时sleep的时间（秒）
     *
     * @param float $emptySleepSeconds
     * @return Daemon
     */
    public function setEmptySleepSeconds($emptySleepSeconds) {
        $this->_worker->setEmptySleepSeconds($emptySleepSeconds);
        return $this;
    }

    /**
     * 设定心跳时间（秒）
     *
     * @param float $heartbeat
     * @return Daemon
     */
    public function setHeartbeat($heartbeat) {
        $this->_worker->setHeartbeat($heartbeat);
        return $this;
    }
}