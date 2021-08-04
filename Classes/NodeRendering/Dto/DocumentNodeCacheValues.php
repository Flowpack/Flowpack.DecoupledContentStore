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

    private function __construct(string $rootIdentifier, string $url)
    {
        $this->rootIdentifier = $rootIdentifier;
        $this->url = $url;
    }


    public static function create(string $rootIdentifier, string $url): self
    {
        return new self($rootIdentifier, $url);
    }

    public static function fromJsonString($jsonString): self
    {
        $tmp = json_decode($jsonString, true);
        if (!is_array($tmp)) {
            throw new \Exception('DocumentNodeCacheValues cannot be constructed from: ' . $jsonString);
        }
        return new self($tmp['rootIdentifier'], $tmp['url']);
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

    public function jsonSerialize()
    {
        return [
            'rootIdentifier' => $this->rootIdentifier,
            'url' => $this->url
        ];
    }


}