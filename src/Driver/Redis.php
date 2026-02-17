<?php

namespace Webman\RateLimiter\Driver;

use RedisException;
use support\Redis as RedisClient;
use Workerman\Worker;

class Redis implements DriverInterface
{
    /**
     * Lua 脚本：仅延长过期时间，绝不缩短
     * 返回 hash key 实际的过期时间戳，用于更新本地缓存
     *
     * KEYS[1] = hash key
     * ARGV[1] = 期望的过期时间戳
     */
    protected const LUA_EXTEND_EXPIRE = <<<'LUA'
local new_expire = tonumber(ARGV[1])
local ttl = redis.call('TTL', KEYS[1])
if ttl < 0 then
    redis.call('EXPIREAT', KEYS[1], new_expire)
    return new_expire
end
local now = tonumber(redis.call('TIME')[1])
local current_expire = now + ttl
if new_expire > current_expire then
    redis.call('EXPIREAT', KEYS[1], new_expire)
    return new_expire
end
return current_expire
LUA;

    /**
     * @param Worker|null $worker
     * @param string $connection
     */
    public function __construct(?Worker $worker, protected string $connection)
    {
    }

    /**
     * @throws RedisException
     */
    public function increase(string $key, int $ttl = 24 * 60 * 60, $step = 1): int
    {
        static $hashKeyExpireAtMap = [];
        $connection = RedisClient::connection($this->connection);

        $expireTime = $this->getExpireTime($ttl);

        $segmentDays = max(1, (int)ceil($ttl / 86400));
        $segmentSeconds = $segmentDays * 86400;

        $expireDayStart = strtotime(date('Y-m-d', $expireTime));
        $segmentStart = (int)(floor($expireDayStart / $segmentSeconds) * $segmentSeconds);
        $segmentEnd = $segmentStart + $segmentSeconds;

        $hashKey = 'rate-limiter-' . date('Y-m-d', $segmentStart);
        $field = $key . '-' . $expireTime . '-' . $ttl;

        // 绝大多数请求仅执行 HINCRBY（1 次 Redis 调用）
        $count = $connection->hIncrBy($hashKey, $field, $step);

        // 仅在本地缓存的过期时间不足时，才通过 Lua 脚本原子检查并延长过期时间
        if ($segmentEnd > ($hashKeyExpireAtMap[$hashKey] ?? 0)) {
            $actualExpireAt = (int)$connection->eval(static::LUA_EXTEND_EXPIRE, 1, $hashKey, $segmentEnd);
            $hashKeyExpireAtMap[$hashKey] = $actualExpireAt;

            // 清理已过期的缓存条目
            $now = time();
            foreach ($hashKeyExpireAtMap as $k => $v) {
                if ($v <= $now) {
                    unset($hashKeyExpireAtMap[$k]);
                }
            }
        }

        return (int)$count;
    }

    /**
     * 计算当前窗口的过期时间点（整数取模，避免浮点精度问题）
     *
     * @param int $ttl
     * @return int
     */
    protected function getExpireTime(int $ttl): int
    {
        $time = time();
        $remainder = $time % $ttl;
        return $remainder === 0 ? $time : $time + ($ttl - $remainder);
    }
    
}
