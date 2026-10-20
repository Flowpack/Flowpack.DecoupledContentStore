<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheValues;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use RuntimeException;

#[Flow\Scope('singleton')]
final class NodeRenderingExtensionManager
{
    /**
     * cache of the instantiated objects which are configured at {@see configuredDocumentRenderers}.enumeratorClassName
     * @var DocumentEnumeratorInterface[]|null
     */
    private ?array $documentEnumerators = null;

    /**
     * cache of the instantiated objects which are configured at {@see configuredDocumentRenderers}.rendererClassName
     * @var DocumentRendererInterface[]|null
     */
    private ?array $documentRenderers = null;

    /**
     * cache of the instantiated objects which are configured at {@see configuredDocumentRenderers}.contentReleaseWriters
     * @var ContentReleaseWriterInterface[][]
     */
    private array $contentReleaseWriters = [];

    /**
     * cache of the instanciated objects which are configured at {@see $configuredDocumentMetadataGenerators}
     * @var DocumentMetadataGeneratorInterface[]|null
     */
    private ?array $documentMetadataGenerators = null;

    /**
     * @param array<string, mixed> $configuredDocumentRenderers
     * @param array<string, mixed> $configuredDocumentMetadataGenerators
     */
    public function __construct(
        #[Flow\InjectConfiguration('extensions.documentRenderers')]
        protected readonly array $configuredDocumentRenderers,
        #[Flow\InjectConfiguration('extensions.documentMetadataGenerators')]
        protected readonly array $configuredDocumentMetadataGenerators,
    ) {}

    /**
     * @return iterable<EnumeratedNode>
     */
    public function enumerateDocumentNode(Node $documentNode): iterable
    {
        $this->documentEnumerators ??= self::instantiateExtensions(
            $this->configuredDocumentRenderers,
            DocumentEnumeratorInterface::class,
            'enumeratorClassName',
            'enumeratorOptions',
            true,
        );
        foreach ($this->documentEnumerators as $rendererId => $documentEnumerator) {
            foreach ($documentEnumerator->enumerateDocumentNode($documentNode) as $enumeratedNode) {
                assert($enumeratedNode instanceof EnumeratedNode);
                // we enforce the renderer ID here for the returned enumerated node
                yield $enumeratedNode->withRendererId($rendererId);
            }
        }
    }

    public function tryToExtractRenderingForEnumeratedNodeFromContentCache(
        EnumeratedNode $enumeratedNode,
    ): RenderedDocumentFromContentCache {
        return $this->rendererFor($enumeratedNode)->tryToExtractRenderingForEnumeratedNodeFromContentCache(
            $enumeratedNode,
        );
    }

    public function renderDocumentNodeVariant(
        Node $node,
        EnumeratedNode $enumeratedNode,
        ContentReleaseLogger $contentReleaseLogger,
    ): void {
        $this->rendererFor($enumeratedNode)->renderDocumentNodeVariant($node, $enumeratedNode, $contentReleaseLogger);
    }

    private function rendererFor(EnumeratedNode $enumeratedNode): DocumentRendererInterface
    {
        $this->documentRenderers ??= self::instantiateExtensions(
            $this->configuredDocumentRenderers,
            DocumentRendererInterface::class,
            'rendererClassName',
            preserveKey: true,
        );
        if (!array_key_exists($enumeratedNode->rendererId, $this->documentRenderers)) {
            throw new RuntimeException(
                'No renderer found for renderer ID ' . $enumeratedNode->rendererId . ' - should never happen!',
            );
        }
        return $this->documentRenderers[$enumeratedNode->rendererId];
    }

    /**
     * Execute Document Metadata Generators to modify $cacheValues
     *
     * @param Node $node
     * @param array $arguments
     * @param ActionRequest $actionRequest
     * @param DocumentNodeCacheValues $cacheValues
     * @return DocumentNodeCacheValues
     */
    public function runDocumentMetadataGenerators(
        Node $node,
        array $arguments,
        ActionRequest $actionRequest,
        DocumentNodeCacheValues $cacheValues,
    ): DocumentNodeCacheValues {
        $this->documentMetadataGenerators ??= self::instantiateExtensions(
            $this->configuredDocumentMetadataGenerators,
            DocumentMetadataGeneratorInterface::class,
        );
        foreach ($this->documentMetadataGenerators as $documentMetadataGenerator) {
            assert($documentMetadataGenerator instanceof DocumentMetadataGeneratorInterface);
            $cacheValues = $documentMetadataGenerator->generateMetadata(
                $node,
                $arguments,
                $actionRequest,
                $cacheValues,
            );
        }
        return $cacheValues;
    }

    /**
     * add the fully rendered documents to the content store
     *
     * @param ContentReleaseIdentifier $contentReleaseIdentifier
     * @param RenderedDocumentFromContentCache $renderedDocumentFromContentCache
     */
    public function addRenderedDocumentToContentRelease(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        EnumeratedNode $enumeratedNode,
        RenderedDocumentFromContentCache $renderedDocumentFromContentCache,
        ContentReleaseLogger $logger,
    ): void {
        $this->contentReleaseWriters[$enumeratedNode->rendererId] ??= self::instantiateExtensions(
            $this->configuredDocumentRenderers[$enumeratedNode->rendererId]['contentReleaseWriters'],
            ContentReleaseWriterInterface::class,
            optionsKey: 'options',
        );
        foreach ($this->contentReleaseWriters[$enumeratedNode->rendererId] as $contentReleaseWriter) {
            assert($contentReleaseWriter instanceof ContentReleaseWriterInterface);
            $contentReleaseWriter->processRenderedDocument(
                $contentReleaseIdentifier,
                $renderedDocumentFromContentCache,
                $logger,
            );
        }
    }

    private static function instantiateExtensions(
        array $configuration,
        string $extensionInterfaceName,
        string $classNameKey = 'className',
        ?string $optionsKey = null,
        bool $preserveKey = false,
    ): array {
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
            if (!$instance instanceof $extensionInterfaceName) {
                throw new RuntimeException(
                    'Extension ' . get_class($instance) . ' does not implement ' . $extensionInterfaceName,
                );
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
