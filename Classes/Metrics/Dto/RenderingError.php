<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Metrics\Dto;

use JsonSerializable;
use Flowpack\DecoupledContentStore\Metrics\NodeIdentifierExtractor;
use Neos\Flow\Annotations as Flow;

/**
 * One rendering error of a content release, split into the parts an external log aggregator can filter on.
 */
#[Flow\Proxy(false)]
final class RenderingError implements JsonSerializable
{
    private function __construct(
        public readonly string $message,
        public readonly ?string $node,
        public readonly ?string $nodeIdentifier,
        public readonly ?string $nodeUri,
    ) {}

    /**
     * RedisRenderingErrorManager stores one string per error, built as
     * "<exception message> - <json encoded additional data>". That string is what the Backend UI displays, so it
     * stays as it is and is parsed back apart here. An entry which does not match keeps its full text as message.
     */
    public static function fromRawEntry(string $rawEntry): self
    {
        if (preg_match('/^(.*) - (\[]|\{.*})$/s', $rawEntry, $matches) !== 1) {
            return new self($rawEntry, null, null, null);
        }

        $additionalData = json_decode($matches[2], true);
        if (!is_array($additionalData)) {
            return new self($rawEntry, null, null, null);
        }

        $node = array_key_exists('node', $additionalData) ? (string) $additionalData['node'] : null;
        $nodeUri = array_key_exists('nodeUri', $additionalData) ? (string) $additionalData['nodeUri'] : null;

        return new self($matches[1], $node, NodeIdentifierExtractor::fromText($node), $nodeUri);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'node' => $this->node,
            'nodeIdentifier' => $this->nodeIdentifier,
            'nodeUri' => $this->nodeUri,
        ];
    }
}
