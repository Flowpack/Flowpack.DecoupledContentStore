<?php

namespace Flowpack\DecoupledContentStore\Core;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class RedisPruneService
{
    // go through all keys in the selected content store
    // check whether they are reserved keys or currently active or contain one of the currently registered releases ids
    // if not: delete
    const PRUNE_LUA_SCRIPT = '
        local contentStoreCurrent = redis.call("GET", ARGV[1] .. "contentStore:current")
        local contentStoreAllKeys = redis.call("KEYS", "*")
        local contentStoreRegisteredReleases = redis.call("ZRANGE", ARGV[1] .."contentStore:registeredReleases", 0, -1)
        local currentContentStoreStart = ARGV[1] .. "contentStore:" .. contentStoreCurrent

        local function table_contains_value(tab, val)
            for index,value in ipairs(tab) do
                if string.find(val, value) then
                    return true
                end
            end
            return false
        end

        for index,contentStoreKey in ipairs(contentStoreAllKeys) do
            if contentStoreKey ~= ARGV[1] .. "contentStore:current"
            and contentStoreKey ~= ARGV[1] .. "contentStore:registeredReleases"
            and contentStoreKey ~= ARGV[1] .. "contentStore:configEpoch"
            and string.sub(contentStoreKey, 1, string.len(currentContentStoreStart)) ~= currentContentStoreStart
            and not table_contains_value(contentStoreRegisteredReleases, contentStoreKey) then
                redis.call("DEL", contentStoreKey)
            end
        end
        ';

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;


    public function pruneRedisInstance(RedisInstanceIdentifier $redisInstanceIdentifier)
    {
        $prefix = $this->redisClientManager->getRedis($redisInstanceIdentifier)->getOption(\Redis::OPT_PREFIX);
        $this->redisClientManager->getRedis($redisInstanceIdentifier)->eval(self::PRUNE_LUA_SCRIPT, [$prefix]);
    }

}
