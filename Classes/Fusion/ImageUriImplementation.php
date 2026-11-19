<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Fusion;

use Flowpack\DecoupledContentStore\AssetChangeContentReleaseHandler;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Neos\Fusion\ImageUriImplementation as NeosImageUriImplementation;

/**
 * Tags the cache segment with the rendered asset, so that replacing the asset also flushes pages which render it
 * without referencing it in one of their own nodes (e.g. a teaser showing another page's image). Neos only flushes
 * the nodes which reference the asset, {@see AssetChangeContentReleaseHandler}.
 */
class ImageUriImplementation extends NeosImageUriImplementation
{
    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    public function evaluate()
    {
        $result = parent::evaluate();

        $asset = $this->getAsset();
        if ($asset instanceof AssetVariantInterface) {
            $asset = $asset->getOriginalAsset();
        }
        $assetIdentifier = (string) $this->persistenceManager->getIdentifierByObject($asset);
        $this->runtime->addCacheTag(AssetChangeContentReleaseHandler::cacheTagForAsset($assetIdentifier));

        return $result;
    }
}
