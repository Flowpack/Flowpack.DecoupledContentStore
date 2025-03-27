<?php

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Exception;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class RedisClientManager
{

    /**
     * @Flow\InjectConfiguration("redisContentStores")
     * @var array
     */
    protected $configuration;

    /**
     * @var array<\Redis>
     */
    protected array $redisInstances = [];

    /**
     * @throws Exception
     */
    protected function connect(RedisInstanceIdentifier $redisInstanceIdentifier): \Redis
    {
        $instanceConfig = $this->configuration[$redisInstanceIdentifier->getIdentifier()];
        $redis = new \Redis();
        $connected = false;
        try {
            $connected = $redis->connect($instanceConfig['hostname'], $instanceConfig['port'] ?? 6379, $instanceConfig['timeout'] ?? 0) && $redis->auth([$instanceConfig['username'] ?? "default", $instanceConfig['password'] ?? ""]) && $redis->select($instanceConfig['database'] ?? 0);
        } catch (\Exception $e) {
            throw new Exception(sprintf('Could not connect to Redis server %s:%d. Detailed reason: see nested exception.', $instanceConfig['hostname'], $instanceConfig['port']), 1630323312, $e);
        }
        if (!$connected) {
            throw new Exception(sprintf('Could not connect to Redis server %s:%d', $instanceConfig['hostname'], $instanceConfig['port']), 1467385687);
        }

        return $redis;
    }

    /**
     * Get the Redis instance and check for an existing connection
     *
     * @return \Redis
     */
    public function getRedis(RedisInstanceIdentifier $redisInstanceIdentifier): \Redis
    {
        if (!isset($this->redisInstances[$redisInstanceIdentifier->getIdentifier()])) {
            $this->redisInstances[$redisInstanceIdentifier->getIdentifier()] = $this->connect($redisInstanceIdentifier);
        }
        $redis = $this->redisInstances[$redisInstanceIdentifier->getIdentifier()];
        try {
            $pong = $redis->ping();
            if ($pong === false) {
                $redis = $this->redisInstances[$redisInstanceIdentifier->getIdentifier()] = $this->connect($redisInstanceIdentifier);
            }
        } catch (\RedisException $e) {
            $redis = $this->redisInstances[$redisInstanceIdentifier->getIdentifier()] = $this->connect($redisInstanceIdentifier);
        }
        return $redis;
    }

    public function getPrimaryRedis(): \Redis
    {
        return $this->getRedis(RedisInstanceIdentifier::primary());
    }

    public function getRetentionCount(RedisInstanceIdentifier $redisInstanceIdentifier): int
    {
        if (!isset($this->configuration[$redisInstanceIdentifier->getIdentifier()]['contentReleaseRetentionCount'])) {
            throw new \RuntimeException('Did not find a configured contentReleaseRetentionCount for Redis ' . $redisInstanceIdentifier->getIdentifier());
        }
        return $this->configuration[$redisInstanceIdentifier->getIdentifier()]['contentReleaseRetentionCount'];
    }
}
