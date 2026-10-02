<?php

namespace Enggarasmoro\LaravelErrorAlert;

use Illuminate\Cache\RedisStore;

final class RedisBacklogRelease
{
    /**
     * Consume one reservation and decrement its counter in a single Redis
     * command. A worker killed during this command cannot strand a marker.
     *
     * @param  mixed  $cache
     * @param  string  $generationKey
     * @param  string  $counterKey
     * @param  int  $expiresAt
     * @return bool  Whether this cache uses the Redis implementation.
     */
    public static function releaseIfSupported($cache, $generationKey, $counterKey, $expiresAt)
    {
        $store = is_object($cache) && method_exists($cache, 'getStore')
            ? $cache->getStore()
            : $cache;
        if (! $store instanceof RedisStore) {
            return false;
        }
        // Jobs queued before the hash-tagged key rollout must use the legacy
        // lock path; their two keys may occupy different Redis Cluster slots.
        if (preg_match('/\{[0-9a-f]{40}\}$/', $counterKey) !== 1
            || strpos($generationKey, $counterKey.':generation:') !== 0) {
            return false;
        }

        $script = <<<'LUA'
if redis.call('exists', KEYS[1]) == 0 then
    return 0
end
if tonumber(ARGV[1]) > 0 and tonumber(ARGV[1]) <= tonumber(ARGV[2]) then
    redis.call('del', KEYS[1])
    return 0
end
if redis.call('exists', KEYS[2]) == 0 then
    redis.call('del', KEYS[1])
    return 0
end
local count = tonumber(redis.call('get', KEYS[2]))
if count == nil or count <= 0 then
    redis.call('del', KEYS[1])
    return 0
end
redis.call('decr', KEYS[2])
redis.call('del', KEYS[1])
return 1
LUA;

        $connection = $store->connection();
        if (! is_object($connection) || ! is_callable([$connection, 'eval'])) {
            throw new \RuntimeException('Redis cache connection does not support atomic backlog release.');
        }
        $result = call_user_func_array([$connection, 'eval'], [
            $script,
            2,
            $store->getPrefix().$generationKey,
            $store->getPrefix().$counterKey,
            (int) $expiresAt,
            time(),
        ]);
        if (! is_numeric($result)) {
            throw new \RuntimeException('Redis backlog release did not complete.');
        }

        return true;
    }
}
