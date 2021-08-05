<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * Represents contents of the top-level Fusion Cache identifier for a rendered node
 *
 * @Flow\Proxy(false)
 */
final class DocumentNodeCacheValues implements \JsonSerializable
{

    /**
     * the root cache identifier which contains the actual content (possibly nested)
     * @var string
     */
    protected $rootIdentifier;

    /**
     * the currently rendered URL
     *
     * @var string
     */
    protected $url;

    /**
     * @var array
     */
    protected $metadata;

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

    public static function fromJsonString($jsonString): self
    {
        $tmp = json_decode($jsonString, true);
        if (!is_array($tmp)) {
            throw new \Exception('DocumentNodeCacheValues cannot be constructed from: ' . $jsonString);
        }
        return new self($tmp['rootIdentifier'], $tmp['url'], $tmp['metadata']);
    }

    /**
     * @return string
     */
    public function getRootIdentifier(): string
    {
        return $this->rootIdentifier;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }


    public function jsonSerialize()
    {
        return ['rootIdentifier' => $this->rootIdentifier, 'url' => $this->url, 'metadata' => $this->metadata];
    }

    /**
     * add additional metadata
     *
     * @param string $key
     * @param $value
     * @return $this
     */
    public function withMetadata(string $key, $value): self
    {
        $metadata = $this->metadata;
        $metadata[$key] = $value;
        return new self($this->rootIdentifier, $this->url, $metadata);
    }


}