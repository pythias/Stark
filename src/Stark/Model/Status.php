<?php


namespace Stark\Model;

use JsonSerializable;

class Status implements JsonSerializable {
    private $_pid = 0;
    private $_startTime = 0;
    private $_allCount = 0;
    private $_allFailureCount = 0;
    private $_allSeconds = 0.0;
    private $_roundStartTime = 0;
    private $_roundCount = 0;
    private $_roundFailureCount = 0;
    private $_roundSeconds = 0.0;
    private $_roundMemoryUsed = 0.0;
    private $_rounds = 0;
    private $_lastActiveTime = 0;

    public function __construct() {
        $this->_startTime = microtime(true);
    }

    public function getIdleSeconds() {
        return microtime(true) - $this->_lastActiveTime;
    }

    public function getCurrentSeconds() {
        return microtime(true) - $this->_roundStartTime;
    }

    public function newRound() {
        $this->_rounds++;
        $this->_roundCount = 0;
        $this->_roundSeconds  = 0.0;
        $this->_roundFailureCount = 0;
        $this->_roundStartTime = microtime(true);
        $this->_lastActiveTime = microtime(true);
    }

    public function runStart() {
        $this->_lastActiveTime = microtime(true);
    }

    public function runFinished($succeed = true) {
        $duration = microtime(true) - $this->_lastActiveTime;
        $this->_allCount++;
        $this->_allSeconds += $duration;
        $this->_roundCount++;
        $this->_roundSeconds += $duration;

        if ($succeed === false) {
            $this->_allFailureCount++;
            $this->_roundFailureCount++;
        }
    }

    public function fromJsonObject($array) {
        //
    }

    /**
     * @return int
     */
    public function getStartTime() {
        return $this->_startTime;
    }

    /**
     * @return int
     */
    public function getAllCount() {
        return $this->_allCount;
    }

    /**
     * @return float
     */
    public function getAllSeconds() {
        return $this->_allSeconds;
    }

    /**
     * @return int
     */
    public function getRoundStartTime() {
        return $this->_roundStartTime;
    }

    /**
     * @param int $roundStartTime
     */
    public function setRoundStartTime($roundStartTime) {
        $this->_roundStartTime = $roundStartTime;
    }

    /**
     * @return int
     */
    public function getRoundCount() {
        return $this->_roundCount;
    }

    /**
     * @return float
     */
    public function getRoundSeconds() {
        return $this->_roundSeconds;
    }

    /**
     * @return float
     */
    public function getRoundMemoryUsed() {
        return $this->_roundMemoryUsed;
    }

    /**
     * @return int
     */
    public function getRounds() {
        return $this->_rounds;
    }

    public function jsonSerialize() {
        return get_object_vars($this);
    }

    /**
     * @return int
     */
    public function getAllFailureCount() {
        return $this->_allFailureCount;
    }

    /**
     * @return int
     */
    public function getRoundFailureCount() {
        return $this->_roundFailureCount;
    }

    /**
     * @return int
     */
    public function getPid() {
        return $this->_pid;
    }

    /**
     * @param int $pid
     */
    public function setPid($pid) {
        $this->_pid = $pid;
    }

    /**
     * @param float $roundMemoryUsed
     */
    public function setRoundMemoryUsed($roundMemoryUsed) {
        $this->_roundMemoryUsed = $roundMemoryUsed;
    }
}