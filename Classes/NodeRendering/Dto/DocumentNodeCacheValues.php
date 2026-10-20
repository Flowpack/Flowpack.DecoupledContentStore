<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Dto;

use Exception;
use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * Represents contents of the top-level Fusion Cache identifier for a rendered node
 */
#[Flow\Proxy(false)]
final readonly class DocumentNodeCacheValues implements JsonSerializable
{
    /**
     * the root cache identifier which contains the actual content (possibly nested)
     */
    private string $rootIdentifier;

    /**
     * the currently rendered URL
     */
    private string $url;

    private array $metadata;

    private function __construct(string $rootIdentifier, string $url, array $metadata)
    {
        $this->rootIdentifier = $rootIdentifier;
        $this->url = $url;
        $this->metadata = $metadata;
    }

    public static function empty(): self
    {
        return new self('', '', []);
    }

    public static function create(string $rootIdentifier, string $url): self
    {
        return new self($rootIdentifier, $url, []);
    }

    /**
     * @throws Exception
     */
    public static function fromJsonString(string $jsonString): self
    {
        $tmp = json_decode($jsonString, true);
        if (!is_array($tmp)) {
            throw new Exception('DocumentNodeCacheValues cannot be constructed from: ' . $jsonString);
        }
        return new self($tmp['rootIdentifier'], $tmp['url'], $tmp['metadata']);
    }

    public function getRootIdentifier(): string
    {
        return $this->rootIdentifier;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function jsonSerialize(): array
    {
        return ['rootIdentifier' => $this->rootIdentifier, 'url' => $this->url, 'metadata' => $this->metadata];
    }

    /**
     * Add additional metadata.
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $metadata = $this->metadata;
        $metadata[$key] = $value;
        return new self($this->rootIdentifier, $this->url, $metadata);
    }
}
