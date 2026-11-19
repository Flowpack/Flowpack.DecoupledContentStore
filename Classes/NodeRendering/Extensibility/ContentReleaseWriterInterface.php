<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;

/**
 * Takes the fully rendered document and writes it to the content release.
 *
 * This is extensible to control the format of the content releases, and to add additional metadata to the final
 * content release.
 */
interface ContentReleaseWriterInterface
{
    /**
     * @param array<string, mixed> $options the writer's configured "options"
     */
    public function __construct(array $options = []);

    /**
     * take a rendered document and add it to the content release
     */
    public function processRenderedDocument(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        RenderedDocumentFromContentCache $renderedDocumentFromContentCache,
        ContentReleaseLogger $logger,
    ): void;
}
