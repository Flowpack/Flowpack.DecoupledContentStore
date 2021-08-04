<?php
namespace Flowpack\DecoupledContentStore\Resource\Target;

use Neos\Flow\ResourceManagement\Target\FileSystemSymlinkTarget;

class MultisiteFileSystemSymlinkTarget extends FileSystemSymlinkTarget {

    /**
     * @var string
     */
    protected $overrideHttpBaseUri;

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