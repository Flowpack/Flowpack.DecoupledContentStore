<?php

namespace Flowpack\DecoupledContentStore\Fusion;
use Flowpack\DecoupledContentStore\Aspects\FixedAssetHandlingInContentCacheFlusherAspect;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;


/**
 * Register rendered assets as dynamic cache tag.
 *
 * For full background of this change, {@see FixedAssetHandlingInContentCacheFlusherAspect}
 */
class ImageUriImplementation extends \Neos\Neos\Fusion\ImageUriImplementation
{

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    public function evaluate()
    {
        $result = parent::evaluate();

        $asset = $this->getAsset();

        if ($asset) {
            $this->runtime->addCacheTag('asset', $this->persistenceManager->getIdentifierByObject($asset));
        }

        return $result;
    }
}
