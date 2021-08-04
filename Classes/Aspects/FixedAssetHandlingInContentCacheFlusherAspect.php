<?php

namespace Flowpack\DecoupledContentStore\Aspects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Utility\ObjectAccess;


/**
 * The ContentCacheFlusher::registerAssetChange() has one (general-case) bug, which we patch here.
 *
 * 1) The Core Bug: we flush a dynamic cache tag `'AssetDynamicTag_' . $workspaceHash . '_' . $assetIdentifier`. However,
 *    the dynamic cache tag being stored in the system is just `'AssetDynamicTag_' . $assetIdentifier;`.
 *
 *    Intuitively, the fix makes sense, because an asset is something global and not just bound to a single workspace.
 *
 *    The error has been introduced in https://github.com/neos/neos-development-collection/commit/e5e6ef2130398f98eeb192b8fcc2a30350a38891,
 *    and by inspecting this commit one sees that dynamic tag handling has not been adjusted to contain a workspace hash.
 *
 * 1a) Related to the above bug, the dynamic cache tag is only added by default when using the Fusion object Neos.Neos:ConvertUris;
 *     but not when using Neos.Neos:ImageUri. This is fixed here in the package with the custom "ImageUriImplementation".
 *
 * 2a) NOTE: by default, the incremental rendering also breaks when a parent page changes, because the `root` Fusion Cache entry
 *     is by default also tagged with all parent pages of the page. This is fixed in `Root.fusion` of this package
 *     by the line `documentRendering.@cache.entryTags.2 >`. It is also mentioned here for completeness; so that one has a
 *     complete overview of the full issue.
 *
 * @Flow\Aspect
 * @Flow\Scope("singleton")
 */
class FixedAssetHandlingInContentCacheFlusherAspect
{

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Around("method(Neos\Neos\Fusion\Cache\ContentCacheFlusher->registerAssetChange())")
     */
    public function registerAssetChange(JoinPointInterface $joinPoint)
    {
        $asset = $joinPoint->getMethodArgument('asset');

        if (!$asset->isInUse()) {
            return;
        }

        // HINT: do not flush node where the asset is in use (because we have dynamic tags for this, and we are not allowed to flush documents)

        $assetIdentifier = $this->persistenceManager->getIdentifierByObject($asset);
        // @see RuntimeContentCache.addTag

        $tagName = 'AssetDynamicTag_' . $assetIdentifier;

        $contentCacheFlusher = $joinPoint->getProxy();

        $tagsToFlush = ObjectAccess::getProperty($contentCacheFlusher, 'tagsToFlush', true);
        $tagsToFlush[$tagName] = sprintf('which were tagged with "%s" because asset "%s" has changed.', $tagName, $assetIdentifier);
        ObjectAccess::setProperty($contentCacheFlusher, 'tagsToFlush', $tagsToFlush, true);
    }
}
