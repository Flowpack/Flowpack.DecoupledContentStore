<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
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
     * @Flow\InjectConfiguration("extensions.documentRenderers")
     * @var array
     */
    protected $configuredDocumentRenderers;

    /**
     * cache of the instantiated objects which are configured at {@see configuredDocumentRenderers}.enumeratorClassName
     * @var DocumentEnumeratorInterface[]
     */
    protected $documentEnumerators;

    /**
     * cache of the instantiated objects which are configured at {@see configuredDocumentRenderers}.rendererClassName
     * @var DocumentRendererInterface[]
     */
    protected $documentRenderers;

    /**
     * cache of the instantiated objects which are configured at {@see configuredDocumentRenderers}.contentReleaseWriters
     * @var ContentReleaseWriterInterface[][]
     */
    protected $contentReleaseWriters;

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
     * @return iterable<EnumeratedNode>
     */
    public function enumerateDocumentNode(NodeInterface $documentNode): iterable
    {
        if (!isset($this->documentEnumerators)) {
            $this->documentEnumerators = self::instantiateExtensions($this->configuredDocumentRenderers, DocumentEnumeratorInterface::class, classNameKey: 'enumeratorClassName', optionsKey: 'enumeratorOptions', preserveKey: true);
        }
        foreach ($this->documentEnumerators as $rendererId => $documentEnumerator) {
            foreach ($documentEnumerator->enumerateDocumentNode($documentNode) as $enumeratedNode) {
                assert($enumeratedNode instanceof EnumeratedNode);
                // we enforce the renderer ID here for the returned enumerated node
                yield $enumeratedNode->withRendererId($rendererId);
            }
        }
    }

    public function tryToExtractRenderingForEnumeratedNodeFromContentCache(EnumeratedNode $enumeratedNode): RenderedDocumentFromContentCache
    {
        return $this->rendererFor($enumeratedNode)
            ->tryToExtractRenderingForEnumeratedNodeFromContentCache($enumeratedNode);
    }

    public function renderDocumentNodeVariant(NodeInterface $node, EnumeratedNode $enumeratedNode, ContentReleaseLogger $contentReleaseLogger)
    {
        return $this->rendererFor($enumeratedNode)
            ->renderDocumentNodeVariant($node, $enumeratedNode, $contentReleaseLogger);
    }

    protected function rendererFor(EnumeratedNode $enumeratedNode): DocumentRendererInterface
    {
        if (!isset($this->documentEnumerators)) {
            $this->documentRenderers = self::instantiateExtensions($this->configuredDocumentRenderers, DocumentRendererInterface::class, classNameKey: 'rendererClassName', preserveKey: true);
        }
        if (!array_key_exists($enumeratedNode->rendererId, $this->documentRenderers)) {
            throw new \RuntimeException('No renderer found for renderer ID ' . $enumeratedNode->rendererId . ' - should never happen!');
        }
        return $this->documentRenderers[$enumeratedNode->rendererId];
    }

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
    public function addRenderedDocumentToContentRelease(ContentReleaseIdentifier $contentReleaseIdentifier, EnumeratedNode $enumeratedNode, RenderedDocumentFromContentCache $renderedDocumentFromContentCache, ContentReleaseLogger $logger): void
    {
        if (!isset($this->contentReleaseWriters[$enumeratedNode->rendererId])) {
            $this->contentReleaseWriters[$enumeratedNode->rendererId] = self::instantiateExtensions($this->configuredDocumentRenderers[$enumeratedNode->rendererId]['contentReleaseWriters'], ContentReleaseWriterInterface::class);
        }
        foreach ($this->contentReleaseWriters[$enumeratedNode->rendererId] as $contentReleaseWriter) {
            assert($contentReleaseWriter instanceof ContentReleaseWriterInterface);
            $contentReleaseWriter->processRenderedDocument($contentReleaseIdentifier, $renderedDocumentFromContentCache, $logger);
        }
    }

    private static function instantiateExtensions(array $configuration, string $extensionInterfaceName, string $classNameKey = 'className', string|null $optionsKey = null, bool $preserveKey = false): array
    {
        $instantiatedExtensions = [];
        foreach ($configuration as $k => $extensionConfig) {
            if (!is_array($extensionConfig)) {
                continue;
            }
            $className = $extensionConfig[$classNameKey];
            if ($optionsKey !== null) {
                $instance = new $className($extensionConfig[$optionsKey] ?? []);
            } else {
                $instance = new $className();
            }
            if (!($instance instanceof $extensionInterfaceName)) {
                throw new \RuntimeException('Extension ' . get_class($instance) . ' does not implement ' . $extensionInterfaceName);
            }

            if ($preserveKey) {
                $instantiatedExtensions[$k] = $instance;
            } else {
                $instantiatedExtensions[] = $instance;
            }
        }
        return $instantiatedExtensions;
    }
}
