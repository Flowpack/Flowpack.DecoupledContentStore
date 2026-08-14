<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class RenderedDocumentFromContentCache
{
    /**
     * @var string
     */
    protected $fullContent;

    /**
     * @var DocumentNodeCacheValues
     */
    protected $documentNodeCacheValues;

    /**
     * @var bool
     */
    protected $isComplete;

    /**
     * @var string
     */
    protected $incompleteReason;

    private function __construct(
        string $fullContent,
        DocumentNodeCacheValues $documentNodeCacheValues,
        bool $isComplete,
        string $incompleteReason
    ) {
        $this->fullContent = $fullContent;
        $this->documentNodeCacheValues = $documentNodeCacheValues;
        $this->isComplete = $isComplete;
        $this->incompleteReason = $incompleteReason;
    }

    public static function createIncomplete(string $reason): self
    {
        return new self('', DocumentNodeCacheValues::empty(), false, $reason);
    }

    public static function createWithFullContent(
        string $fullContent,
        DocumentNodeCacheValues $documentNodeCacheValues
    ): self {
        return new self($fullContent, $documentNodeCacheValues, true, '');
    }

    public function getFullContent(): string
    {
        return $this->fullContent;
    }

    public function getUrl(): string
    {
        return $this->documentNodeCacheValues->getUrl();
    }

    public function getLegacyUrlKey(): string
    {
        return 'url--' . str_replace('.', '%2E', urlencode($this->documentNodeCacheValues->getUrl()));
    }

    public function getLegacyMetadataKey(): string
    {
        return $this->getLegacyUrlKey() . '%2Emetadata%2Ejson';
    }

    public function getMetadata(): array
    {
        return $this->documentNodeCacheValues->getMetadata();
    }

    /**
     * @throws \JsonException
     */
    public function getLegacyMetadataString(): string
    {
        return isset($this->documentNodeCacheValues->getMetadata()['louisMetadata'])
            ? json_encode($this->documentNodeCacheValues->getMetadata()['louisMetadata'], JSON_THROW_ON_ERROR)
            : '';
    }

    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    public function getIncompleteReason(): string
    {
        return $this->incompleteReason;
    }
}
