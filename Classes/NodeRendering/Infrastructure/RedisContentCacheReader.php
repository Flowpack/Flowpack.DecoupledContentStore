<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheKey;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheValues;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Neos\Fusion\Core\Cache\ContentCache;
use Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend;

/**
 * @Flow\Scope("singleton")
 */
class RedisContentCacheReader
{

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClient;

    /**
     * @Flow\Inject
     * @var StringFrontend
     */
    protected $contentCache;

    public function tryToExtractRenderingForEnumeratedNodeFromContentCache(DocumentNodeCacheKey $documentNodeCacheKey): RenderedDocumentFromContentCache
    {
        $maxNestLevel = ContentCache::MAXIMUM_NESTING_LEVEL;
        $contentCacheStartToken = ContentCache::CACHE_SEGMENT_START_TOKEN;
        $contentCacheEndToken = ContentCache::CACHE_SEGMENT_END_TOKEN;
        $contentCacheMarker = ContentCache::CACHE_SEGMENT_MARKER;

        $redis = null;
        $backend = $this->contentCache->getBackend();
        if ($backend instanceof OptimizedRedisCacheBackend) {
            $reflProp = new \ReflectionProperty(OptimizedRedisCacheBackend::class, 'redis');
            $reflProp->setAccessible(true);
            $redis = $reflProp->getValue($backend);
        } else {
            throw new \RuntimeException('TODO: Cache backend must be OptimizedRedisCacheBackend.');
        }
        $serializedCacheValues = $redis->get($documentNodeCacheKey->fullyQualifiedRedisKeyName());
        if ($serializedCacheValues === false) {
            return RenderedDocumentFromContentCache::createIncomplete('No Redis Key "' . $documentNodeCacheKey->redisKeyName() . '" found.');
        }
        $documentNodeCacheValues = DocumentNodeCacheValues::fromJsonString($serializedCacheValues);

        $script = "
            local rootIdentifier = ARGV[1]
        
            local function readContentCacheRecursively(identifier, depth)
                depth = depth or 1

                if depth > ${maxNestLevel} then
                    -- Return an error in this case
                    return '', 'Maximum Nesting Level Reached'
                end

                local content = redis.call('GET', 'Neos_Fusion_Content:entry:' .. identifier)
                if not content then
                    return '', 'Neos_Fusion_Content:entry:' .. identifier .. ' not found'
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
        // starting with Lua 7, eval_ro can be used.
        $res = $redis->eval($script, [$documentNodeCacheValues->getRootIdentifier()], 0);
        $error = $redis->getLastError();
        if ($error !== null) {
            throw new \RuntimeException('Redis Error: ' . $error);
        }

        if (count($res) !== 2) {
            throw new \RuntimeException('Result is no array of length 2, but: ' . count($res));
        }
        $content = $res[0];
        $error = $res[1];

        if (strlen($error) > 0) {
            return RenderedDocumentFromContentCache::createIncomplete($error);
        }
        return RenderedDocumentFromContentCache::createWithFullContent($content, $documentNodeCacheValues);
    }
}