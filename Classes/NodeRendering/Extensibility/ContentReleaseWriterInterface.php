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
     * take a rendered document and add it to the content release
     *
     * @param ContentReleaseIdentifier $contentReleaseIdentifier
     * @param RenderedDocumentFromContentCache $renderedDocumentFromContentCache
     * @param ContentReleaseLogger $logger
     */
    public function processRenderedDocument(ContentReleaseIdentifier $contentReleaseIdentifier, RenderedDocumentFromContentCache $renderedDocumentFromContentCache, ContentReleaseLogger $logger): void;
}