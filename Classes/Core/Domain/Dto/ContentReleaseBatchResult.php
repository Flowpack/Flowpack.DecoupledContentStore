<?php

namespace Flowpack\DecoupledContentStore\Core\Domain\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class ContentReleaseBatchResult
{
    /**
     * @var array key is a content release identifier (stringified); value is a value (depending on which method was asked)
     */
    protected array $results;

    private function __construct(array $results)
    {
        $this->results = $results;
    }


    public static function createFromArray(array $in): self
    {
        return new self($in);
    }

    public function getResultForContentRelease(ContentReleaseIdentifier $contentReleaseIdentifier)
    {
        return $this->results[$contentReleaseIdentifier->jsonSerialize()];
    }

}