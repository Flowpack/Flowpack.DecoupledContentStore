<?php

namespace Flowpack\DecoupledContentStore\Aspects;

use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Service\AssetService;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Neos\Neos\Fusion\Helper\CachingHelper;
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
 * 3) Additional Bug: The default implementation of ContentCacheFlusher::registerAssetChange() does not flush the cache of the parent
 *    nodes until and including the parent document node of all nodes that use that asset. It only flushes the cache of the
 *    node that uses the asset. This is changed here.
 *
 * 4) Note: We also directly commit the cache flush and trigger the incremental rendering afterwards to prevent a race condition with
 *    node enumeration during incremental rendering.
 *
 * @Flow\Aspect
 * @Flow\Scope("singleton")
 */
class FixedAssetHandlingInContentCacheFlusherAspect
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;

    /**
     * @Flow\Inject
     * @var CachingHelper
     */
    protected CachingHelper $cachingHelper;

    /**
     * @Flow\Inject
     * @var ContentReleaseManager
     */
    protected $contentReleaseManager;

    /**
     * WHY:
     *   The default implementation of ContentCacheFlusher::registerAssetChange() does not flush the cache of the parent
     *   nodes until and including the parent document node of all nodes that use that asset.
     *
     * What we want to accomplish:
     * When an asset is updated (including "replaced"), we want to
     *   1. flush the cache of the updated asset (with and without workspace hash)
     *   2. flush the cache of all parent nodes until (including) the parent document node of all nodes that use that
     *      asset (assetUsage)
     *   3. Commit cache flushes to prevent race condition with node enumeration during incremental rendering
     *   3. trigger incremental rendering
     */
    /**
     * @Flow\Around("method(Neos\Neos\Fusion\Cache\ContentCacheFlusher->registerAssetChange())")
     */
    public function registerAssetChange(JoinPointInterface $joinPoint)
    {
        /* @var AssetInterface $asset */
        $asset = $joinPoint->getMethodArgument('asset');

        if (!$asset->isInUse()) {
            return;
        }

        /* @var $contentCacheFlusher ContentCacheFlusher */
        $contentCacheFlusher = $joinPoint->getProxy();

        // 1. flush asset tag without workspace hash
        $assetIdentifier = $this->persistenceManager->getIdentifierByObject($asset);

        $assetCacheTag = "AssetDynamicTag_" . $assetIdentifier;

        // WHY: ContentCacheFlusher has no public api to flush tags directly
        $tagsToFlush = ObjectAccess::getProperty($contentCacheFlusher, 'tagsToFlush', true);
        $tagsToFlush[$assetCacheTag] = sprintf('which were tagged with "%s" because asset "%s" has changed.', $assetCacheTag, $assetIdentifier);
        ObjectAccess::setProperty($contentCacheFlusher, 'tagsToFlush', $tagsToFlush, true);

        $usageReferences = $this->assetService->getUsageReferences($asset);

        foreach ($usageReferences as $assetUsage) {
            // get node that uses the asset
            $context = $this->_contextFactory->create(
                [
                    'workspaceName' => $assetUsage->getWorkspaceName(),
                    'dimensions' => $assetUsage->getDimensionValues(),
                    'invisibleContentShown' => true,
                    'removedContentShown' => true]
            );

            $node = $context->getNodeByIdentifier($assetUsage->getNodeIdentifier());

            // We need this for cache tag generation
            $workspaceHash = $this->cachingHelper->renderWorkspaceTagForContextNode($context->getWorkspaceName());

            // 1. flush asset with workspace hash
            $assetCacheTagWithWorkspace = "AssetDynamicTag_" . $workspaceHash . "_" . $assetIdentifier;

            // WHY: ContentCacheFlusher has no public api to flush tags directly
            $tagsToFlush = ObjectAccess::getProperty($contentCacheFlusher, 'tagsToFlush', true);
            $tagsToFlush[$assetCacheTagWithWorkspace] = sprintf('which were tagged with "%s" because asset "%s" has changed.', $assetCacheTagWithWorkspace, $assetIdentifier);
            ObjectAccess::setProperty($contentCacheFlusher, 'tagsToFlush', $tagsToFlush, true);

            // 2. flush all nodes on path to parent document node (a bit excessive, but for now it works)
            $currentNode = $node;
            while ($currentNode->getParent() !== null) {
                // flush node cache
                $contentCacheFlusher->registerNodeChange($node);

                // if document node, stop
                if ($currentNode->getNodeType()->isOfType('Neos.Neos:Document')) {
                    break;
                }

                // go to parent node
                $currentNode = $currentNode->getParent();
            }
        }

        // 3. commit cache flushes
        // We need force the commit here because we run into a race condition otherwise, where the incremental rendering
        // is starting node enumeration before the cache flushes are actually committed.
        $contentCacheFlusher->shutdownObject();

        // 4. trigger incremental rendering
        $this->contentReleaseManager->startIncrementalContentRelease();
    }
}
