<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Transfer\Resource\Target;

use Neos\Flow\ResourceManagement\Collection;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\Target\FileSystemSymlinkTarget;
use Neos\Utility\Exception\PropertyNotAccessibleException;

final class MultisiteFileSystemSymlinkTarget extends FileSystemSymlinkTarget
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
     * @throws PropertyNotAccessibleException
     */
    public static function injectBaseUriIntoRelevantResourcePublishingTargets(
        string $baseUri,
        ResourceManager $resourceManager,
    ): void {
        // Make sure the base URI ends with a slash
        $baseUri = rtrim($baseUri, '/') . '/';

        $collections = $resourceManager->getCollections();
        /** @var Collection $collection */
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
    protected function getResourcesBaseUri(): string
    {
        if ($this->overrideHttpBaseUri === null) {
            return parent::getResourcesBaseUri();
        }
        return $this->overrideHttpBaseUri . $this->baseUri;
    }

    public function hasOverrideHttpBaseUri(): bool
    {
        return $this->overrideHttpBaseUri !== null;
    }

    /**
     * @param string $overrideHttpBaseUri
     */
    public function setOverrideHttpBaseUri(string $overrideHttpBaseUri): void
    {
        $this->overrideHttpBaseUri = $overrideHttpBaseUri;
    }
}
