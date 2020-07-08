<?php
namespace Stark\Daemon;

interface IRequest {
    public function initialize(Worker $worker);
    public function finalize(Worker $worker);
}