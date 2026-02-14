<?php

namespace Webman\RateLimiter\Driver;

use RedisException;
use support\Redis as RedisClient;
use Workerman\Worker;

class Redis implements DriverInterface
{
    /**
     * @param Worker|null $worker
     * @param string $connection
     * @throws RedisException
     */
    public function __construct(?Worker $worker, protected string $connection)
    {
        
    }
    
    /**
     * @throws RedisException
     */
    public function increase(string $key, int $ttl = 24 * 60 * 60, $step = 1): int
    {
      $redis = RedisClient::connection($this->connection);
      $now = time();
      $windowStart = $now - ($now % $ttl);
      $windowEnd = $windowStart + $ttl;
      $hashKey = "rate_limit:{$key}:{$windowStart}";

      try {
        $count = $redis->hIncrBy($hashKey, 'counter', (int)$step);

        if ($count == $step) {
          $redis->expireAt($hashKey, $windowEnd);
        }

        return $count;
      } catch (RedisException $e) {
        return 0;
      }
    }
}
