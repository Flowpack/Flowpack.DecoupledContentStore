<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheValues;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Mvc\Controller\ControllerContext;

/**
 * @Flow\Scope("singleton")
 */
class NodeRenderingExtensionManager
{

    /**
     * @Flow\InjectConfiguration("extensions.documentMetadataGenerators")
     * @var array
     */
    protected $configuredDocumentMetadataGenerators;

    /**
     * cache of the instanciated objects which are configured at {@see $configuredDocumentMetadataGenerators}
     * @var DocumentMetadataGeneratorInterface[]
     */
    protected $documentMetadataGenerators;

    /**
     * @Flow\InjectConfiguration("extensions.contentReleaseWriters")
     * @var array
     */
    protected $configuredContentReleaseWriters;

    /**
     * cache of the instanciated objects which are configured at {@see $configuredContentReleaseWriters}
     * @var ContentReleaseWriterInterface[]
     */
    protected $contentReleaseWriters;

    /**
     * Execute Document Metadata Generators to modify $cacheValues
     *
     * @param NodeInterface $node
     * @param array $arguments
     * @param ControllerContext $controllerContext
     * @param DocumentNodeCacheValues $cacheValues
     * @return DocumentNodeCacheValues
     */
    public function runDocumentMetadataGenerators(NodeInterface $node, array $arguments, ControllerContext $controllerContext, DocumentNodeCacheValues $cacheValues): DocumentNodeCacheValues
    {
        if (!isset($this->documentMetadataGenerators)) {
            $this->documentMetadataGenerators = self::instantiateExtensions($this->configuredDocumentMetadataGenerators, DocumentMetadataGeneratorInterface::class);
        }
        foreach ($this->documentMetadataGenerators as $documentMetadataGenerator) {
            assert($documentMetadataGenerator instanceof DocumentMetadataGeneratorInterface);
            $cacheValues = $documentMetadataGenerator->generateMetadata($node, $arguments, $controllerContext, $cacheValues);
        }
        return $cacheValues;
    }

    /**
     * add the fully rendered documents to the content store
     *
     * @param ContentReleaseIdentifier $contentReleaseIdentifier
     * @param RenderedDocumentFromContentCache $renderedDocumentFromContentCache
     */
    public function addRenderedDocumentToContentRelease(ContentReleaseIdentifier $contentReleaseIdentifier, RenderedDocumentFromContentCache $renderedDocumentFromContentCache, ContentReleaseLogger $logger): void
    {
        if (!isset($this->contentReleaseWriters)) {
            $this->contentReleaseWriters = self::instantiateExtensions($this->configuredContentReleaseWriters, ContentReleaseWriterInterface::class);
        }
        foreach ($this->contentReleaseWriters as $contentReleaseWriter) {
            assert($contentReleaseWriter instanceof ContentReleaseWriterInterface);
            $contentReleaseWriter->processRenderedDocument($contentReleaseIdentifier, $renderedDocumentFromContentCache, $logger);
        }
    }

    private static function instantiateExtensions(array $configuration, string $extensionInterfaceName): array
    {
        $instantiatedExtensions = [];
        foreach ($configuration as $extensionConfig) {
            if (!is_array($extensionConfig)) {
                continue;
            }
            $className = $extensionConfig['className'];
            $instance = new $className();
            if (!($instance instanceof $extensionInterfaceName)) {
                throw new \RuntimeException('Extension ' . get_class($instance) . ' does not implement ' . $extensionInterfaceName);
            }
            $instantiatedExtensions[] = $instance;

        }
        return $instantiatedExtensions;
    }
}
