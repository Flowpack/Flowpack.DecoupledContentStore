<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Metrics;

use Neos\Flow\Annotations as Flow;

/**
 * Pulls the node identifier out of the free-form node references which travel through the rendering error paths
 * (EnumeratedNode::debugString(), the "node" entries of a worker log, ...).
 *
 * A node identifier is a UUID, and it is the only UUID in any of those strings.
 */
#[Flow\Proxy(false)]
final class NodeIdentifierExtractor
{
    private const NODE_IDENTIFIER_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    public static function fromText(?string $text): ?string
    {
        if ($text === null || preg_match(self::NODE_IDENTIFIER_PATTERN, $text, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }
}
