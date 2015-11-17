Examples
==========

### 1.Just run

A single function callback example, config file:config_1.ini, script file:run_1.php
```
php src/Stark/run.php -f tests/simple_callback/config_1.ini
```

### 2.With init and complete
An example with init run & complete callback function, config file:config_2.ini, script file:run_2.php
```
php src/Stark/run.php -f tests/simple_callback/config_2.ini
```

### 3.With redis queue
```
# Normal MQ
php src/Stark/run.php -f tests/queue_redis/redis.ini

# With priority
php src/Stark/run.php -f tests/queue_redis/redis_with_priority.ini
```