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
     * @var string
     */
    protected $url;

    /**
     * @var bool
     */
    protected $isComplete;

    /**
     * @var string
     */
    protected $incompleteReason;

    /**
     * RenderedDocumentFromContentCache constructor.
     * @param string $fullContent
     * @param bool $isComplete
     */
    public function __construct(string $fullContent, string $url, bool $isComplete, string $incompleteReason)
    {
        $this->fullContent = $fullContent;
        $this->url = $url;
        $this->isComplete = $isComplete;
        $this->incompleteReason = $incompleteReason;
    }


    static public function createIncomplete(string $reason): self
    {
        return new self('', '',false, $reason);
    }

    static public function createWithFullContent(string $fullContent, DocumentNodeCacheValues $documentNodeCacheValues): self
    {
        return new self($fullContent, $documentNodeCacheValues->getUrl(), true, '');
    }

    public function getFullContent(): string
    {
        return $this->fullContent;
    }

    public function getUrl(): string
    {
        return $this->url;
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