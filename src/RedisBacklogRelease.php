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
     * @param  string  $counterGeneration
     * @param  int  $expiresAt
     * @return bool  Whether Redis handled the release atomically.
     */
    public static function releaseIfSupported($cache, $generationKey, $counterKey, $counterGeneration, $expiresAt)
    {
        $store = is_object($cache) && method_exists($cache, 'getStore')
            ? $cache->getStore()
            : $cache;
        if (! $store instanceof RedisStore) {
            return false;
        }
        if (! is_string($counterGeneration) || $counterGeneration === '') {
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
local counterGeneration = redis.call('get', KEYS[3])
if counterGeneration ~= ARGV[3] and counterGeneration ~= ARGV[4] then
    -- A framework or Redis client may serialize the cache value differently.
    -- Let the caller compare through Cache::get while holding its cache lock.
    return -1
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
        if (! is_callable([$connection, 'eval'])) {
            throw new \RuntimeException('Redis cache connection does not support atomic backlog release.');
        }
        $result = call_user_func_array([$connection, 'eval'], [
            $script,
            3,
            $store->getPrefix().$generationKey,
            $store->getPrefix().$counterKey,
            $store->getPrefix().$counterKey.':counter-generation',
            (int) $expiresAt,
            time(),
            $counterGeneration,
            serialize($counterGeneration),
        ]);
        if (! is_numeric($result)) {
            throw new \RuntimeException('Redis backlog release did not complete.');
        }

        return (int) $result !== -1;
    }
}
