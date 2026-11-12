<?php

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Flowpack\DecoupledContentStore\Command\ContentReleaseValidationCommandController;
use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\Exception as DecoupledContentStoreException;
use Flowpack\DecoupledContentStore\IncrementalContentReleaseHandler;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeEnumeration\NodeEnumerator;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingQueue;
use Flowpack\DecoupledContentStore\NodeRendering\InterruptibleProcessRuntime;
use Flowpack\DecoupledContentStore\NodeRendering\InterruptibleProcessRuntimeEventInterface;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderer;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderOrchestrator;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\DocumentRenderedEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\ExitEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\QueueEmptyEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\RenderingQueueFilledEvent;
use Flowpack\DecoupledContentStore\NodeRendering\Render\CustomFusionView;
use Flowpack\DecoupledContentStore\NodeRendering\Render\DocumentRenderer;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\NodeIdentifiers;
use Flowpack\DecoupledContentStore\QuickPublish\Infrastructure\RedisReleaseCopyService;
use Flowpack\DecoupledContentStore\QuickPublish\QuickPublishNodeEnumerator;
use Flowpack\DecoupledContentStore\Tests\Behavior\Fixtures\StubPrunnerApiService;
use Neos\Behat\FlowBootstrapTrait;
use Neos\Behat\FlowEntitiesTrait;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\TestSuite\Behavior\Features\Bootstrap\CRBehavioralTestsSubjectProvider;
use Neos\ContentRepository\TestSuite\Behavior\Features\Bootstrap\CRTestSuiteTrait;
use Neos\ContentRepository\TestSuite\Fakes\FakeContentDimensionSourceFactory;
use Neos\ContentRepository\TestSuite\Fakes\FakeNodeTypeManagerFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Neos\Utility\Arrays;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * Features context
 */
class FeatureContext implements Context
{
    use FlowBootstrapTrait;
    use FlowEntitiesTrait;
    use CRTestSuiteTrait;
    use CRBehavioralTestsSubjectProvider;

    /**
     * Node types every scenario needs, so that the features only declare their own.
     */
    private const BASE_NODE_TYPES = <<<'YAML'
                      'Neos.ContentRepository:Root': {}
                      'Neos.Neos:Sites':
                        superTypes:
                          'Neos.ContentRepository:Root': true
                      'Neos.Neos:Document':
                        properties:
                          title:
                            type: string
                          uriPathSegment:
                            type: string
                      'Neos.Neos:Site':
                        superTypes:
                          'Neos.Neos:Document': true
                      'Neos.Neos:Content': {}
                      'Neos.Neos:ContentCollection': {}
                      YAML;

    protected ObjectManagerInterface $objectManager;

    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    private ?InterruptibleProcessRuntime $renderOrchestratorProcess;
    private ?InterruptibleProcessRuntimeEventInterface $renderOrchestratorProcessLastEvent;
    private StubPrunnerApiService $stubPrunnerApiService;
    private BufferedOutput $renderOrchestratorProcessBufferedOutput;

    /**
     * @var array<string,mixed>
     */
    private array $nodeTypesConfiguration = [];

    public function __construct()
    {
        $this->objectManager = self::bootstrapFlow()->getObjectManager();
        $this->contentRepositoryRegistry = $this->getObject(ContentRepositoryRegistry::class);

        // for testing, we use Private/EndToEndTestFusion as fusion folder to load.
        CustomFusionView::$useCustomSiteRootFusionPatternEntryPointForBehavioralTests = true;

        $contentReleaseManager = $this->objectManager->get(ContentReleaseManager::class);
        assert($contentReleaseManager instanceof ContentReleaseManager);
        $this->stubPrunnerApiService = new StubPrunnerApiService();
        ObjectAccess::setProperty($contentReleaseManager, 'prunnerApiService', $this->stubPrunnerApiService, true);
    }

    /**
     * Every scenario builds its site from scratch, under the same node identifiers - so nothing a previous scenario
     * left behind in these singletons may survive into the next one.
     *
     * @BeforeScenario
     */
    public function resetRuntimeState(): void
    {
        FakeContentDimensionSourceFactory::reset();
        FakeNodeTypeManagerFactory::reset();
        $this->nodeTypesConfiguration = [];

        $documentRenderer = $this->objectManager->get(DocumentRenderer::class);
        $customFusionView = ObjectAccess::getProperty($documentRenderer, 'fusionView', true);
        ObjectAccess::setProperty($customFusionView, 'fusionConfigurationPerSiteNode', [], true);

        $this->stubPrunnerApiService->calls = [];
        $incrementalContentReleaseHandler = $this->objectManager->get(IncrementalContentReleaseHandler::class);
        ObjectAccess::setProperty($incrementalContentReleaseHandler, 'nodePublishedInThisRequest', false, true);

        $this->objectManager->get(PersistenceManagerInterface::class)->clearState();
    }

    public function getObjectManager(): ObjectManagerInterface
    {
        return $this->objectManager;
    }

    protected function getContentRepositoryService(
        ContentRepositoryServiceFactoryInterface $factory,
    ): ContentRepositoryServiceInterface {
        return $this->contentRepositoryRegistry->buildService($this->currentContentRepository->id, $factory);
    }

    protected function createContentRepository(ContentRepositoryId $contentRepositoryId): ContentRepository
    {
        $this->contentRepositoryRegistry->resetFactoryInstance($contentRepositoryId);
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        FakeContentDimensionSourceFactory::reset();
        FakeNodeTypeManagerFactory::reset();

        return $contentRepository;
    }

    /**
     * @BeforeScenario @resetRedis
     */
    public function resetRedis($event): void
    {
        /** @var RedisClientManager $redisClientManager */
        $redisClientManager = $this->objectManager->get(RedisClientManager::class);
        $redisClientManager->getPrimaryRedis()->flushAll();
    }

    /**
     * @Given I have a site for Site Node :siteNodeName with site package key :sitePackageKey with domain :domainName
     */
    public function iHaveASite($siteNodeName, $sitePackageKey, $domainName)
    {
        $site = new Site($siteNodeName);
        $site->setState(Site::STATE_ONLINE);
        $site->setSiteResourcesPackageKey($sitePackageKey);

        /** @var SiteRepository $siteRepository */
        $siteRepository = $this->objectManager->get(SiteRepository::class);
        $siteRepository->add($site);

        $this->objectManager->get(PersistenceManagerInterface::class)->persistAll();

        $domain = new Domain();
        $domain->setSite($site);
        $domain->setHostname($domainName);
        $domain->setScheme('http');

        $domainRepository = $this->objectManager->get(DomainRepository::class);
        $domainRepository->add($domain);

        $persistenceManager = $this->objectManager->get(PersistenceManagerInterface::class);
        $persistenceManager->persistAll();
        $persistenceManager->clearState();
    }

    /**
     * @When I create a content release :contentReleaseIdentifier
     */
    public function iCreateAContentRelease($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisContentReleaseService = $this->getObjectManager()->get(RedisContentReleaseService::class);
        $bufferedOutput = new BufferedOutput();
        $prunnerJobId = PrunnerJobId::fromString('...');
        $logger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifier);
        $redisContentReleaseService->createContentRelease($contentReleaseIdentifier, $prunnerJobId, $logger);
        $concurrentBuildLockService = $this->getObjectManager()->get(ConcurrentBuildLockService::class);
        $concurrentBuildLockService->ensureAllOtherInProgressContentReleasesWillBeTerminated($contentReleaseIdentifier);
        echo $bufferedOutput->fetch();
    }

    /**
     * @When I enumerate all nodes for content release :contentReleaseIdentifier
     */
    public function iEnumerateAllNodesForContentRelease($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $nodeEnumerator = $this->getObjectManager()->get(NodeEnumerator::class);
        $bufferedOutput = new BufferedOutput();
        $contentReleaseLogger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifier);
        $nodeEnumerator->enumerateAndStoreInRedis(null, $contentReleaseLogger, $contentReleaseIdentifier);
        echo $bufferedOutput->fetch();
    }

    /**
     * Sets what the pipeline reads as the currently live release, without going through a switch - which validates
     * a lot more than this is about.
     *
     * @Given the currently live content release is :contentReleaseIdentifier
     */
    public function theCurrentlyLiveContentReleaseIs($contentReleaseIdentifier)
    {
        $redisClientManager = $this->getObjectManager()->get(RedisClientManager::class);
        $redisClientManager->getPrimaryRedis()->set('contentStore:current', $contentReleaseIdentifier);
    }

    /**
     * @Then validating content release :contentReleaseIdentifier succeeds
     */
    public function validatingContentReleaseSucceeds($contentReleaseIdentifier)
    {
        // the command ends the process when it considers the release invalid, so getting past this line is half of
        // the assertion - the other half is that it did not mark the release as broken on the way
        $validationCommandController = $this->getObjectManager()->get(ContentReleaseValidationCommandController::class);
        $validationCommandController->validateCommand($contentReleaseIdentifier);

        $redisRenderingErrorManager = $this->getObjectManager()->get(RedisRenderingErrorManager::class);
        Assert::assertCount(
            0,
            $redisRenderingErrorManager->getRenderingErrors(ContentReleaseIdentifier::fromString(
                $contentReleaseIdentifier,
            )),
        );
    }

    /**
     * @When I copy the content release :sourceContentReleaseIdentifier to the content release :targetContentReleaseIdentifier
     */
    public function iCopyTheContentRelease($sourceContentReleaseIdentifier, $targetContentReleaseIdentifier)
    {
        $this->copyContentRelease($sourceContentReleaseIdentifier, $targetContentReleaseIdentifier);
    }

    /**
     * @Then copying the content release :sourceContentReleaseIdentifier to the content release :targetContentReleaseIdentifier is refused
     */
    public function copyingTheContentReleaseIsRefused($sourceContentReleaseIdentifier, $targetContentReleaseIdentifier)
    {
        try {
            $this->copyContentRelease($sourceContentReleaseIdentifier, $targetContentReleaseIdentifier);
        } catch (DecoupledContentStoreException $exception) {
            return;
        }

        Assert::fail('Copying content release ' . $sourceContentReleaseIdentifier . ' should have been refused.');
    }

    private function copyContentRelease($sourceContentReleaseIdentifier, $targetContentReleaseIdentifier): void
    {
        $sourceContentReleaseIdentifier = ContentReleaseIdentifier::fromString($sourceContentReleaseIdentifier);
        $targetContentReleaseIdentifier = ContentReleaseIdentifier::fromString($targetContentReleaseIdentifier);
        $redisReleaseCopyService = $this->getObjectManager()->get(RedisReleaseCopyService::class);
        $bufferedOutput = new BufferedOutput();
        $contentReleaseLogger = ContentReleaseLogger::fromSymfonyOutput(
            $bufferedOutput,
            $targetContentReleaseIdentifier,
        );

        try {
            $redisReleaseCopyService->copyReleaseWithin(
                RedisInstanceIdentifier::primary(),
                $sourceContentReleaseIdentifier,
                $targetContentReleaseIdentifier,
                $contentReleaseLogger,
            );
        } finally {
            echo $bufferedOutput->fetch();
        }
    }

    /**
     * @When I enumerate the node :nodeIdentifiers for content release :contentReleaseIdentifier
     */
    public function iEnumerateTheNodeForContentRelease($nodeIdentifiers, $contentReleaseIdentifier)
    {
        $this->enumerateGivenNodes($nodeIdentifiers, $contentReleaseIdentifier);
    }

    /**
     * @Then enumerating the node :nodeIdentifiers for content release :contentReleaseIdentifier is refused
     */
    public function enumeratingTheNodeIsRefused($nodeIdentifiers, $contentReleaseIdentifier)
    {
        try {
            $this->enumerateGivenNodes($nodeIdentifiers, $contentReleaseIdentifier);
        } catch (DecoupledContentStoreException $exception) {
            return;
        }

        Assert::fail('Enumerating ' . $nodeIdentifiers . ' should have been refused.');
    }

    private function enumerateGivenNodes($nodeIdentifiers, $contentReleaseIdentifier): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $quickPublishNodeEnumerator = $this->getObjectManager()->get(QuickPublishNodeEnumerator::class);
        $bufferedOutput = new BufferedOutput();
        $contentReleaseLogger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifier);

        try {
            $quickPublishNodeEnumerator->enumerateGivenNodesAndStoreInRedis(
                NodeIdentifiers::fromCommaSeparatedString($nodeIdentifiers),
                $contentReleaseLogger,
                $contentReleaseIdentifier,
            );
        } finally {
            echo $bufferedOutput->fetch();
        }
    }

    /**
     * @Then the enumeration for content release :contentReleaseIdentifier contains :expectedCount node
     * @Then the enumeration for content release :contentReleaseIdentifier contains :expectedCount nodes
     */
    public function theEnumerationContainsNode($contentReleaseIdentifier, $expectedCount)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisEnumerationRepository = $this->getObjectManager()->get(RedisEnumerationRepository::class);
        $iterable = $redisEnumerationRepository->findAll($contentReleaseIdentifier);
        $enumerationAsArray = iterator_to_array(
            (function () use ($iterable) {
                yield from $iterable;
            })(),
        );

        Assert::assertCount((int) $expectedCount, $enumerationAsArray);
    }

    /**
     * The enumeration is written per document *and renderer*, so a second document renderer doubles it while the
     * release still publishes one URL per document. That is the one setup in which the length of the enumeration and
     * the number of published URLs differ for an ordinary release, and rendering a page twice - as HTML and as JSON,
     * say - is a common enough reason to configure one.
     *
     * @Given the enumeration of content release :contentReleaseIdentifier is duplicated for a second document renderer
     */
    public function theEnumerationIsDuplicatedForASecondDocumentRenderer($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisEnumerationRepository = $this->getObjectManager()->get(RedisEnumerationRepository::class);

        $enumerationOfSecondRenderer = [];
        foreach ($redisEnumerationRepository->findAll($contentReleaseIdentifier) as $enumeratedNode) {
            $enumerationOfSecondRenderer[] = EnumeratedNode::fromJsonString((string) json_encode(array_merge(
                $enumeratedNode->jsonSerialize(),
                ['rendererId' => 'secondRenderer'],
            )));
        }

        $redisEnumerationRepository->addDocumentNodesToEnumeration(
            $contentReleaseIdentifier,
            ...$enumerationOfSecondRenderer,
        );
    }

    /**
     * @When I run the render-orchestrator control loop once for content release :contentReleaseIdentifier
     */
    public function iRunTheRenderOrchestratorControlLoopOnceForContentRelease($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $nodeRenderOrchestrator = $this->getObjectManager()->get(NodeRenderOrchestrator::class);

        $this->renderOrchestratorProcessBufferedOutput = new BufferedOutput();
        $contentReleaseLogger = ContentReleaseLogger::fromSymfonyOutput(
            $this->renderOrchestratorProcessBufferedOutput,
            $contentReleaseIdentifier,
        );
        $this->renderOrchestratorProcess = InterruptibleProcessRuntime::createForTesting($nodeRenderOrchestrator->renderContentRelease(
            $contentReleaseIdentifier,
            $contentReleaseLogger,
        ));
        $this->renderOrchestratorProcessLastEvent = $this->renderOrchestratorProcess->runUntilEventEncountered(RenderingQueueFilledEvent::class);

        echo $this->renderOrchestratorProcessBufferedOutput->fetch();
    }

    /**
     * @When I continue running the render-orchestrator control loop
     */
    public function iContinueRunningTheRenderOrchestratorControlLoop()
    {
        $this->renderOrchestratorProcessLastEvent = $this->renderOrchestratorProcess->runUntilEventEncountered(RenderingQueueFilledEvent::class);
        echo $this->renderOrchestratorProcessBufferedOutput->fetch();
    }

    /**
     * @Then I expect the render-orchestrator control loop to exit with status code :expectedStatusCode
     */
    public function iExpectTheRenderOrchestratorControlLoopToExitWithStatusCode($expectedStatusCode)
    {
        Assert::assertNotNull(
            $this->renderOrchestratorProcessLastEvent,
            'renderOrchestratorProcessLastEvent cannot be null',
        );
        Assert::assertInstanceOf(
            ExitEvent::class,
            $this->renderOrchestratorProcessLastEvent,
            'renderOrchestratorProcessLastEvent needs to be an ExitEvent',
        );
        assert($this->renderOrchestratorProcessLastEvent instanceof ExitEvent);
        Assert::assertEquals(
            $expectedStatusCode,
            $this->renderOrchestratorProcessLastEvent->getStatusCode(),
            'Status Code Mismatch',
        );
    }

    /**
     * @Then I expect the content release :contentReleaseIdentifier to have the completion status failed
     */
    public function iExpectTheContentReleaseToHaveTheCompletionStatusFailed($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisContentReleaseService = $this->objectManager->get(RedisContentReleaseService::class);
        assert($redisContentReleaseService instanceof RedisContentReleaseService);
        $renderStatus = $redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier)
            ->getStatus();
        Assert::isTrue($renderStatus->isFailed(), 'Completion Status should be failed');
        Assert::isFalse($renderStatus->isSuccessful(), 'Completion Status should not be successful');
    }

    /**
     * @Then I expect the content release :contentReleaseIdentifier to have the completion status success
     */
    public function iExpectTheContentReleaseToHaveTheCompletionStatusSuccess($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisContentReleaseService = $this->objectManager->get(RedisContentReleaseService::class);
        assert($redisContentReleaseService instanceof RedisContentReleaseService);
        $renderStatus = $redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier)
            ->getStatus();
        Assert::isTrue($renderStatus->isSuccessful(), 'Completion Status should be success');
        Assert::isFalse($renderStatus->isFailed(), 'Completion Status should not be failed');
    }

    /**
     * @When I run the renderer for content release :contentReleaseIdentifier until the queue is empty
     */
    public function iRunTheRendererForContentReleaseUntilTheQueueIsEmpty($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $nodeRenderer = $this->getObjectManager()->get(NodeRenderer::class);

        $bufferedOutput = new BufferedOutput();
        $contentReleaseLogger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifier);

        $renderProcess = InterruptibleProcessRuntime::createForTesting($nodeRenderer->render(
            $contentReleaseIdentifier,
            $contentReleaseLogger,
            RendererIdentifier::fromString('rdr'),
        ));
        $renderProcess->runUntilEventEncountered(QueueEmptyEvent::class);

        echo $bufferedOutput->fetch();
    }

    /**
     * @When I run the renderer for content release :contentReleaseIdentifier for :expectedRenderCount renders
     */
    public function iRunTheRendererForContentReleaseForRenders($contentReleaseIdentifier, $expectedRenderCount)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $nodeRenderer = $this->getObjectManager()->get(NodeRenderer::class);

        $bufferedOutput = new BufferedOutput();
        $contentReleaseLogger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifier);

        $renderProcess = InterruptibleProcessRuntime::createForTesting($nodeRenderer->render(
            $contentReleaseIdentifier,
            $contentReleaseLogger,
            RendererIdentifier::fromString('rdr'),
        ));

        for ($i = 0; $i < $expectedRenderCount; $i++) {
            $renderProcess->runUntilEventEncountered(DocumentRenderedEvent::class);
        }

        echo $bufferedOutput->fetch();
    }

    /**
     * @Then during rendering of content release :contentReleaseIdentifier, no errors occured
     */
    public function duringRenderingOfContentReleaseNoErrorsOccured($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisRenderingErrorManager = $this->getObjectManager()->get(RedisRenderingErrorManager::class);
        $renderingErrors = $redisRenderingErrorManager->getRenderingErrors($contentReleaseIdentifier);
        if (count($renderingErrors) > 0) {
            Assert::fail(implode("\n", $renderingErrors));
        }
    }

    /**
     * @Then /^during rendering of content release "([^"].*)", ([0-9]+) errors? occured$/
     */
    public function duringRenderingOfContentReleaseSomeErrorsOccured($contentReleaseIdentifier, $expectedNumberOfErrors)
    {
        if ($expectedNumberOfErrors === 'no') {
            $this->duringRenderingOfContentReleaseNoErrorsOccured($contentReleaseIdentifier);
            return;
        }

        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisRenderingErrorManager = $this->getObjectManager()->get(RedisRenderingErrorManager::class);
        $renderingErrors = $redisRenderingErrorManager->getRenderingErrors($contentReleaseIdentifier);
        Assert::assertCount((int) $expectedNumberOfErrors, $renderingErrors);
    }

    /**
     * The first variant goes before "I define a content repository", the additional one rebuilds the content
     * repository with the extended node types.
     *
     * @Given /^I have the following (additional |)NodeTypes configuration:$/
     */
    public function iHaveTheFollowingNodetypesConfiguration($additional, PyStringNode $nodeTypesConfiguration)
    {
        $givenConfiguration = Yaml::parse($nodeTypesConfiguration->getRaw()) ?? [];
        if ($additional === '') {
            $this->nodeTypesConfiguration = Arrays::arrayMergeRecursiveOverrule(
                Yaml::parse(self::BASE_NODE_TYPES),
                $givenConfiguration,
            );
            FakeNodeTypeManagerFactory::setConfiguration($this->nodeTypesConfiguration);
            return;
        }

        $this->nodeTypesConfiguration = Arrays::arrayMergeRecursiveOverrule(
            $this->nodeTypesConfiguration,
            $givenConfiguration,
        );
        $this->iChangeTheNodeTypesInContentRepositoryTo(
            $this->currentContentRepository->id->value,
            new PyStringNode([Yaml::dump($this->nodeTypesConfiguration, 10)], 0),
        );
    }

    /**
     * @Then I expect the content release :contentReleaseIdentifier to contain the following content for URI :uri at CSS selector :cssSelector:
     */
    public function iExpectTheContentReleaseToContainTheFollowingContentForUriAtCssSelector(
        $contentReleaseIdentifier,
        $uri,
        $cssSelector,
        PyStringNode $expected,
    ) {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisClient = $this->getObjectManager()->get(RedisClientManager::class);
        $redisKeyService = $this->getObjectManager()->get(RedisKeyService::class);

        $actualContent = $redisClient->getPrimaryRedis()->hGet(
            $redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderedDocuments'),
            $uri,
        );
        Assert::assertIsString($actualContent, 'Did not find rendered document');
        $actualContentDecompressed = gzdecode($actualContent);

        $domCrawler = new Symfony\Component\DomCrawler\Crawler($actualContentDecompressed);
        $actual = $domCrawler->filter($cssSelector)->text();
        Assert::assertSame($expected->getRaw(), $actual, 'Full Output was: ' . $actualContentDecompressed);
    }

    /**
     * @Then I expect the content release :contentReleaseIdentifier to contain the following HTML content for URI :uri at CSS selector :cssSelector:
     */
    public function iExpectTheContentReleaseToContainTheFollowingHtmlContentForUriAtCssSelector(
        $contentReleaseIdentifier,
        $uri,
        $cssSelector,
        PyStringNode $expected,
    ) {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisClient = $this->getObjectManager()->get(RedisClientManager::class);
        $redisKeyService = $this->getObjectManager()->get(RedisKeyService::class);

        $actualContent = $redisClient->getPrimaryRedis()->hGet(
            $redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderedDocuments'),
            $uri,
        );
        Assert::assertIsString($actualContent, 'Did not find rendered document');
        $actualContentDecompressed = gzdecode($actualContent);

        $domCrawler = new Symfony\Component\DomCrawler\Crawler($actualContentDecompressed);
        $actual = $domCrawler->filter($cssSelector)->html();
        Assert::assertSame($expected->getRaw(), $actual, 'Full Output was: ' . $actualContentDecompressed);
    }

    /**
     * @Then I expect the content release :contentReleaseIdentifier to not contain anything for URI :uri
     */
    public function iExpectTheContentReleaseToNotContainAnythingForUri($contentReleaseIdentifier, $uri)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisClient = $this->getObjectManager()->get(RedisClientManager::class);
        $redisKeyService = $this->getObjectManager()->get(RedisKeyService::class);
        $actualContent = $redisClient->getPrimaryRedis()->hGet(
            $redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderedDocuments'),
            $uri,
        );
        Assert::assertFalse($actualContent);
    }

    /**
     * Flushes what the content cache flusher collected to flush at the end of a request - the tags of the changed
     * nodes are flushed during the catch-up already.
     *
     * @Given /^I flush the content cache depending on the modified nodes$/
     */
    public function iFlushTheContentCacheDependingOnTheModifiedNodes()
    {
        $this->getObjectManager()->get(ContentCacheFlusher::class)->flushCollectedTags();
    }

    /**
     * @Then the rendering queue for content release :contentReleaseIdentifier contains :expectedCount document
     * @Then the rendering queue for content release :contentReleaseIdentifier contains :expectedCount documents
     */
    public function theRenderingQueueContainsDocument($contentReleaseIdentifier, $expectedCount)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisRenderingQueue = $this->getObjectManager()->get(RedisRenderingQueue::class);
        $actual = $redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier);

        Assert::assertEquals($expectedCount, $actual, 'Count does not match');
    }

    /**
     * @Then a next content release was triggered
     */
    public function aNextContentReleaseWasTriggered()
    {
        $incrementalContentReleaseHandler = $this->objectManager->get(IncrementalContentReleaseHandler::class);
        $incrementalContentReleaseHandler->startContentReleaseIfNodesWerePublishedBefore();

        Assert::assertTrue(count($this->stubPrunnerApiService->calls) > 0);

        // reset all invocations
        $this->stubPrunnerApiService->calls = [];
        ObjectAccess::setProperty($incrementalContentReleaseHandler, 'nodePublishedInThisRequest', false, true);
    }

    /**
     * @Then no next content release was triggered
     */
    public function noNextContentReleaseWasTriggered()
    {
        $incrementalContentReleaseHandler = $this->objectManager->get(IncrementalContentReleaseHandler::class);
        $incrementalContentReleaseHandler->startContentReleaseIfNodesWerePublishedBefore();

        Assert::assertCount(0, $this->stubPrunnerApiService->calls);

        // reset all invocations
        $this->stubPrunnerApiService->calls = [];
        ObjectAccess::setProperty($incrementalContentReleaseHandler, 'nodePublishedInThisRequest', false, true);
    }
}
