<?php

namespace Flowpack\DecoupledContentStore\Resource\Target;

use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\Target\FileSystemSymlinkTarget;

class MultisiteFileSystemSymlinkTarget extends FileSystemSymlinkTarget
{

    /**
     * @var string
     */
    protected $overrideHttpBaseUri;

    /**
     * Override the baseUri of resource publishing targets
     *
     * This is needed because the rendering might be executed in a CLI request that doesn't have
     * a "current" base URI. It's also needed to render nodes for multiple sites.
     *
     * @param string $baseUri
     * @throws \Neos\Utility\Exception\PropertyNotAccessibleException
     */
    public static function injectBaseUriIntoRelevantResourcePublishingTargets(string $baseUri, ResourceManager $resourceManager)
    {
        // Make sure the base URI ends with a slash
        $baseUri = rtrim($baseUri, '/') . '/';

        $collections = $resourceManager->getCollections();
        /** @var \Neos\Flow\ResourceManagement\Collection $collection */
        foreach ($collections as $collection) {
            $target = $collection->getTarget();
            if ($target instanceof MultisiteFileSystemSymlinkTarget) {
                $target->setOverrideHttpBaseUri($baseUri);
            }
        }
    }

    /**
     * @return string
     */
    protected function getResourcesBaseUri()
    {
        if ($this->overrideHttpBaseUri === null) {
            return parent::getResourcesBaseUri();
        }
        return $this->overrideHttpBaseUri . $this->baseUri;
    }

    /**
     * @param string $overrideHttpBaseUri
     */
    public function setOverrideHttpBaseUri(string $overrideHttpBaseUri)
    {
        $this->overrideHttpBaseUri = $overrideHttpBaseUri;
    }

}