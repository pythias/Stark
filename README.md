# Stark

[![Latest Stable Version](https://poser.pugx.org/got/stark/v)](//packagist.org/packages/got/stark)
[![Total Downloads](https://poser.pugx.org/got/stark/downloads)](//packagist.org/packages/got/stark)
[![License](https://poser.pugx.org/got/stark/license)](//packagist.org/packages/got/stark)

`Stark` is a library for running php code as multi-process daemon. 

## Requires

* PHP 5.4 or Higher
* A POSIX compatible operating system (Linux, OSX, BSD)
* POSIX and PCNTL extensions for PHP
* Redis extensions 

## Features

* Simple Callbacks
* Message Queue Processing
* Daemon Monitoring
* Automatic Restart

## Examples

### Consumer Only

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Stark\DaemonFactory;
use Stark\Daemon\Consumer\AbstractConsumer;
use Stark\Daemon\Worker;

class MyConsumer extends AbstractConsumer {
    public function consume(Worker $worker, $data) {
        // ...
        return true;
    }
}

$daemon = DaemonFactory::consumerOnly(new MyConsumer());
$daemon->setWorkerCount(3);
$daemon->setMaxRunCount(50000);
$daemon->setPort(9101);
$daemon->setName("consumer-self");
$daemon->setWorkingDirectory("/tmp");
$daemon->start();
```

### Consumer Redis Queue

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Stark\Daemon\Consumer\AbstractConsumer;
use Stark\DaemonFactory;
use Stark\Daemon\Worker;

class MyConsumer extends AbstractConsumer {
    public function consume(Worker $worker, $data) {
        if ($data == false) {
            return false;
        }
        
        //... $data from redis queue-0
        return true;
    }
}

$daemon = DaemonFactory::consumeRedis(new MyConsumer(), "127.0.0.1", "9004", "queue-0");
$daemon->setWorkerCount(3);
$daemon->setMaxRunCount(500000);
$daemon->setPort(9102);
$daemon->setName("consumer-redis");
$daemon->start();

```

## Admin

```bash
# Status
redis-cli -h 127.0.0.1 -p 9102 info

# Shutdown
redis-cli -h 127.0.0.1 -p 9102 shutdown

```
