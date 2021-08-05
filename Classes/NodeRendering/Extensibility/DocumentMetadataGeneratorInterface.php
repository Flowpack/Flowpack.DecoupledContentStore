<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility;

use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheValues;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Mvc\Controller\ControllerContext;

/**
 * Hook to deeply integrate into the rendering process; at the point where every enumerated document is rendered.
 * This is BEFORE the root metadata is stored in the cache; so you can enrich the cache with additional metadata.
 */
interface DocumentMetadataGeneratorInterface
{
    /**
     * Generate additional metadata for a rendered $node - to be stored in the Cache.
     *
     * usually you call return $cacheValues->withMetadata('key', $value) inside this method. Be sure to return the modified cache values passed in.
     */
    public function generateMetadata(NodeInterface $node, array $arguments, ControllerContext $controllerContext, DocumentNodeCacheValues $cacheValues): DocumentNodeCacheValues;
}