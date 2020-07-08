<?php
namespace Stark\Daemon;

interface IProducer extends IRequest {
    /**
     * 生产者生长数据
     *
     * @param Worker $worker
     * @return mixed 结果
     */
    public function produce(Worker $worker);
}