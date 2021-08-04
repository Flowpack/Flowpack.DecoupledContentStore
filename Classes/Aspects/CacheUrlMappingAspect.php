<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Aspects;

use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheKey;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheValues;
use Flowpack\DecoupledContentStore\NodeRendering\Render\RenderExceptionExtractor;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Fusion\Core\Cache\CacheSegmentParser;
use Neos\Utility\ObjectAccess;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * @Flow\Aspect
 * @Flow\Scope("singleton")
 */
class CacheUrlMappingAspect
{

    /**
     * @var array
     */
    protected $currentEvaluateContext;

    /**
     * @var \Neos\Flow\Mvc\Controller\ControllerContext
     */
    protected $controllerContext;

    /**
     * @Flow\Inject
     * @var \Neos\Fusion\Core\Cache\ContentCache
     */
    protected $contentCache;

    /**
     * @var \Neos\Cache\Frontend\StringFrontend
     */
    protected $contentCacheFrontend;

    /**
     * @Flow\InjectConfiguration(path="publishing.urlBlacklistRegex")
     * @var string|false
     */
    protected $urlBlacklistRegex = false;

    /**
     * @var ContentReleaseLogger
     */
    protected $contentReleaseLogger;

    /**
     * @Flow\Before("method(Neos\Fusion\Core\Cache\RuntimeContentCache->postProcess())")
     */
    public function getCurrentEvaluateAndControllerContext(JoinPointInterface $joinPoint)
    {
        $this->currentEvaluateContext = $joinPoint->getMethodArgument('evaluateContext');
        /** @var \Neos\Fusion\Core\Cache\RuntimeContentCache $runtimeContentCache */
        $runtimeContentCache = $joinPoint->getProxy();
        /** @var \Neos\Fusion\Core\Runtime $runtime */
        $runtime = ObjectAccess::getProperty($runtimeContentCache, 'runtime', true);
        $this->controllerContext = $runtime->getControllerContext();
    }

    /**
     * @Flow\After("method(Neos\Fusion\Core\Cache\ContentCache->processCacheSegments())")
     */
    public function storeRootCacheIdentifier(JoinPointInterface $joinPoint)
    {
        if (!isset($this->currentEvaluateContext['cacheIdentifierValues']['node']) || !$this->currentEvaluateContext['cacheIdentifierValues']['node'] instanceof NodeInterface) {
            return;
        }

        /** @var NodeInterface $node */
        $node = $this->currentEvaluateContext['cacheIdentifierValues']['node'];
        if (!$node->getContext()->getWorkspace()->isPublicWorkspace()) {
            return;
        }

        $url = $this->getCurrentUrl();

        $storeCacheEntries = $joinPoint->getMethodArgument('storeCacheEntries');
        // Do not create mapping (and check for consistency of root identifier) if storage of entries was disabled (e.g. exception was catched)
        if (!$storeCacheEntries) {
            $content = $joinPoint->getMethodArgument('content');
            $extractedExceptionDto = RenderExceptionExtractor::extractRenderingException($content);
            throw new Exception('Cache was disabled for ' . $url . ' with node ' . $node->getContextPath() . ', but no exception was handled by the publishing. This could be caused by a missing publishing aware @exceptionHandler in Fusion.' . ($extractedExceptionDto !== null ? "\nException extracted from output: {$extractedExceptionDto}" : ''), 1539156004);
        }

        $content = $joinPoint->getMethodArgument('content');
        $randomCacheMarker = ObjectAccess::getProperty($this->contentCache, 'randomCacheMarker', true);

        $parser = new CacheSegmentParser($content, $randomCacheMarker);
        // The last segment is (for now) always the root path (if it's cached)
        $segments = $parser->getCacheSegments();
        $lastSegment = end($segments);
        $rootIdentifier = $lastSegment['identifier'];
        $rootTags = explode(',', $lastSegment['metadata']);
        $rootTags = $this->sanitizeTags($rootTags);

        $logger = $this->contentReleaseLogger;
        if ($logger === null) {
            throw new \RuntimeException('TODO Logger not found - should never happen');
        }
        if ($this->urlIsMatchingBlacklist($url)) {
            $logger->info(sprintf('Skipping URL %s, because it matches the blacklist %s', $url, $this->urlBlacklistRegex));

            return;
        }

        if ($rootIdentifier === null) {
            throw new Exception('Could not find root cache identifier for ' . $url . ', possible rendering error?', 1491394849);
        }

        $logger->debug('Mapping URL ' . $url . ' to ' . $rootIdentifier . ' with tags ' . implode(', ', $rootTags));

        $arguments = $this->getCurrentArguments($node);
        $rootKey = DocumentNodeCacheKey::fromNodeAndArguments($node, $arguments);
        $rootCacheValues = DocumentNodeCacheValues::create($rootIdentifier, $url);
        $this->contentCacheFrontend->set($rootKey->redisKeyName(), json_encode($rootCacheValues), $rootTags);
    }

    /**
     * @return string
     */
    protected function getCurrentUrl(): string
    {
        /** @var \Neos\Flow\Mvc\ActionRequest $actionRequest */
        $actionRequest = $this->controllerContext->getRequest();
        $httpRequest = $actionRequest->getHttpRequest();
        $url = $httpRequest->getUri();
        $url = $url->withQuery('');

        return (string)$url;
    }

    /**
     * @param NodeInterface $node
     */
    protected function getCurrentArguments(NodeInterface $node): array
    {
        /** @var \Neos\Flow\Mvc\ActionRequest $actionRequest */
        $actionRequest = $this->controllerContext->getRequest();
        $arguments = $actionRequest->getArguments();
        unset($arguments['node']);
        return $arguments;
    }

    /**
     * @param array $tags
     * @return array
     */
    protected function sanitizeTags($tags)
    {
        foreach ($tags as $key => $value) {
            $tags[$key] = strtr($value, '.:', '_-');
        }
        return $tags;
    }

    private function urlIsMatchingBlacklist(string $url): bool
    {
        if (!is_string($this->urlBlacklistRegex)) {
            // no blacklist configured; so we allow all URLs.
            return false;
        }
        return preg_match($this->urlBlacklistRegex, $url) === 1;
    }

    public function setContentReleaseLoggerForThisRendering(ContentReleaseLogger $contentReleaseLogger): void
    {
        $this->contentReleaseLogger = $contentReleaseLogger;
    }

    public function resetContentReleaseLogger(): void
    {
        $this->contentReleaseLogger = null;
    }
}
