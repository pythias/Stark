<?php
namespace Stark\Daemon;

interface IConsumer extends IRequest {
    /**
     * 消费者消费数据
     *
     * @param Worker $worker
     * @param mixed $data
     * @return bool
     */
    public function consume(Worker $worker, $data);
}