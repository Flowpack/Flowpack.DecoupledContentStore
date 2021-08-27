<?php

namespace Flowpack\DecoupledContentStore\Aspects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Fusion\Core\Runtime;
use Neos\Neos\Fusion\NodeUriImplementation;
use Neos\Utility\ObjectAccess;


/**
 * The {@see ContentCacheFlusher::registerNodeChange()} has one (general-case) bug related to Nodes:
 *
 * 1) The Core Bug: we NEVER flush the dynamic cache tag `'NodeDynamicTag' . '_' . $nodeIdentifier`.
 *
 *    This is PATCHED by this aspect.
 *
 * 2) This dynamic cache tag is only added when rendering links using {@see ConvertUrisImplementation}
 *    in Fusion, as only there, {@see Runtime::addCacheTag()} is called. It is MISSING for example
 *    in {@see NodeUriImplementation}.
 *
 *    This issue is NOT YET FIXED.
 *
 * @Flow\Aspect
 * @Flow\Scope("singleton")
 */
class FixedNodeLinkHandlingInContentCacheFlusherAspect
{

    /**
     * @Flow\After("method(Neos\Neos\Fusion\Cache\ContentCacheFlusher->registerNodeChange())")
     */
    public function registerNodeChange(JoinPointInterface $joinPoint)
    {
        $node = $joinPoint->getMethodArgument('node');
        $tagName = 'NodeDynamicTag_' . $node->getIdentifier();
        $contentCacheFlusher = $joinPoint->getProxy();
        $tagsToFlush = ObjectAccess::getProperty($contentCacheFlusher, 'tagsToFlush', true);
        $tagsToFlush[$tagName] = sprintf('which were tagged with "%s" because node "%s" has changed.', $tagName, $node->getIdentifier());
        ObjectAccess::setProperty($contentCacheFlusher, 'tagsToFlush', $tagsToFlush, true);
    }
}
