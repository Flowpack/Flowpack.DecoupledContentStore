<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheKey;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheValues;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;
use Neos\Cache\Backend\AbstractBackend;
use Neos\Cache\Backend\RedisBackend;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Package\Exception\UnknownPackageException;
use Neos\Flow\Package\PackageManager;
use Neos\Fusion\Core\Cache\ContentCache;

/**
 * @Flow\Scope("singleton")
 */
class RedisContentCacheReader
{

    /**
     * @Flow\Inject
     * @var StringFrontend
     */
    protected $contentCache;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\InjectConfiguration(path="cache.applicationIdentifier", package="Neos.Flow")
     * @var string
     */
    protected $applicationIdentifier;

    protected ?string $scriptSha1 = null;
    protected ?\Redis $redis = null;

    public function tryToExtractRenderingForEnumeratedNodeFromContentCache(DocumentNodeCacheKey $documentNodeCacheKey
    ): RenderedDocumentFromContentCache {
        /**
         * @see AbstractBackend::setCache()
         */
        $identifierPrefix = md5($this->applicationIdentifier) . ':';

        $redis = $this->getRedis();

        $serializedCacheValues = $redis->get($documentNodeCacheKey->fullyQualifiedRedisKeyName($identifierPrefix));
        if ($serializedCacheValues === false) {
            return RenderedDocumentFromContentCache::createIncomplete(
                'No Redis Key "' . $documentNodeCacheKey->redisKeyName() . '" found.'
            );
        }
        $documentNodeCacheValues = DocumentNodeCacheValues::fromJsonString($serializedCacheValues);
        $res = $this->executeRedisCall([$documentNodeCacheValues->getRootIdentifier(), $identifierPrefix]);
        [$content, $error] = $res;

        if (strlen($error) > 0) {
            return RenderedDocumentFromContentCache::createIncomplete($error);
        }
        return RenderedDocumentFromContentCache::createWithFullContent($content, $documentNodeCacheValues);
    }

    protected function executeRedisCall(array $params): array
    {
        $redis = $this->getRedis();

        $this->prepareRedisScript();
        try {
            // starting with Lua 7, eval_ro can be used.
            $res = $redis->evalSha($this->scriptSha1, $params);

            $error = $redis->getLastError();
            if ($error !== null) {
                throw new \RuntimeException('Redis error: ' . $error);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('Redis script execution error: ' . $e->getMessage());
        }

        if (!is_array($res) || count($res) !== 2) {
            throw new \RuntimeException('Result is no array of length 2, but: ' . count($res));
        }

        return $res;
    }

    protected function prepareRedisScript(): void
    {
        $redis = $this->getRedis();

        try {
            // Load script into redis if it is not already loaded
            $scriptExists = false;
            if ($this->scriptSha1) {
                $scriptExists = $redis->script('exists', $this->scriptSha1)[0] ?? false;
            }
            if (!$scriptExists) {
                $script = $this->buildRedisScript();
                $this->scriptSha1 = $redis->script('load', $script);
            }
        } catch (\RedisException $e) {
            throw new \RuntimeException('Redis Error: ' . $e->getMessage());
        }
    }

    protected function buildRedisScript(): string
    {
        $maxNestLevel = ContentCache::MAXIMUM_NESTING_LEVEL;
        $contentCacheStartToken = ContentCache::CACHE_SEGMENT_START_TOKEN;
        $contentCacheEndToken = ContentCache::CACHE_SEGMENT_END_TOKEN;
        $contentCacheMarker = ContentCache::CACHE_SEGMENT_MARKER;

        return "
            local rootIdentifier = ARGV[1]
            local identifierPrefix = ARGV[2]

            local function readContentCacheRecursively(identifier, depth)
                depth = depth or 1

                if depth > ${maxNestLevel} then
                    -- Return an error in this case
                    return '', 'Maximum Nesting Level Reached'
                end

                local content = redis.call('GET', identifierPrefix .. 'Neos_Fusion_Content:entry:' .. identifier)
                if not content then
                    return '', identifierPrefix .. 'Neos_Fusion_Content:entry:' .. identifier .. ' not found'
                end

                local error = nil
                content = string.gsub(content, '${contentCacheStartToken}${contentCacheMarker}([a-z0-9]+)${contentCacheEndToken}${contentCacheMarker}', function(id)
                        local str
                        local errMsg
                        str, errMsg = readContentCacheRecursively(id, depth + 1)

                        if errMsg then
                            error = errMsg
                        end

                        return str
                    end
                )

                if error then
                    return nil, error
                else
                    return content, nil
                end
            end

            local content, error = readContentCacheRecursively(rootIdentifier)
            if not error then
                error = ''
            end

            if not content then
                content = ''
            end

            return {content, error}
        ";
    }

    /**
     * @throws UnknownPackageException
     */
    protected function getRedis(): \Redis
    {
        if ($this->redis) {
            return $this->redis;
        }

        $packageManager = $this->objectManager->get(PackageManager::class);
        $flowPackage = $packageManager->getPackage('Neos.Flow');
        preg_match('/^(\d+\.\d+)/', $flowPackage->getInstalledVersion(), $versionMatches);
        $flowMajorVersion = (int)($versionMatches[1] ?? '0');

        $backend = $this->contentCache->getBackend();

        if ($flowMajorVersion >= 8 && $backend instanceof RedisBackend) {
            $reflProp = new \ReflectionProperty(RedisBackend::class, 'redis');
            $reflProp->setAccessible(true);
            $this->redis = $reflProp->getValue($backend);
            return $this->redis;
        }

        if (get_class($backend) === 'Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend') {
            $reflProp = new \ReflectionProperty(
                \Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend::class,
                'redis'
            );
            $reflProp->setAccessible(true);
            $this->redis = $reflProp->getValue($backend);
            return $this->redis;
        }

        throw new \RuntimeException(
            'The cache backend for "Neos_Fusion_Content" must be an OptimizedRedisCacheBackend, but is ' . get_class(
                $backend
            ), 1622570000
        );
    }
}
