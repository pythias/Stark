<?php

namespace Stark;

use Stark\Daemon\Daemon;
use Stark\Daemon\IConsumer;
use Stark\Daemon\IProducer;
use Stark\Daemon\Producer\RedisQueue;

class DaemonFactory {
    /**
     * @param IConsumer $consumer
     * @return Daemon
     */
    public static function consumerOnly(IConsumer $consumer) {
        return new Daemon(null, $consumer);
    }

    /**
     * @param IProducer $producer
     * @param IConsumer $consumer
     * @return Daemon
     */
    public static function normal(IProducer $producer, IConsumer $consumer) {
        return new Daemon($producer, $consumer);
    }

    /**
     * 消费Redis队列
     *
     * @param IConsumer $consumer
     * @param string $host
     * @param integer $port
     * @param string $key
     * @return Daemon
     */
    public static function consumeRedis(IConsumer $consumer, $host, $port, $key) {
        $producer = new RedisQueue();
        $producer->setHost($host);
        $producer->setPort($port);
        $producer->setKey($key);
        return new Daemon($producer, $consumer);
    }

    /**
     * 支持优先级的消费队列
     *
     * @param IConsumer $consumer
     * @param string $host
     * @param integer $port
     * @param array $keys
     * @return Daemon
     */
    public static function consumeRedisWithPriority(IConsumer $consumer, $host, $port, $keys) {
        $producer = new RedisQueue();
        $producer->setHost($host);
        $producer->setPort($port);
        $producer->setKeys($keys);
        return new Daemon($producer, $consumer);
    }
}