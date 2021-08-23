<?php

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeEnumeration\NodeEnumerator;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingQueue;
use Flowpack\DecoupledContentStore\NodeRendering\InterruptibleProcessRuntime;
use Flowpack\DecoupledContentStore\NodeRendering\InterruptibleProcessRuntimeEventInterface;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderer;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderOrchestrator;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\ExitEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\QueueEmptyEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\RenderingQueueFilledEvent;
use Flowpack\DecoupledContentStore\NodeRendering\Render\CustomFusionView;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Neos\Behat\Tests\Behat\FlowContextTrait;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Tests\Behavior\Features\Bootstrap\NodeOperationsTrait;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Tests\Behavior\Features\Bootstrap\SecurityOperationsTrait;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Neos\Utility\Arrays;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Assert;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

require_once(__DIR__ . '/../../../../../../Packages/Application/Neos.Behat/Tests/Behat/FlowContextTrait.php');
require_once(__DIR__ . '/../../../../../../Packages/Application/Neos.ContentRepository/Tests/Behavior/Features/Bootstrap/NodeOperationsTrait.php');
require_once(__DIR__ . '/../../../../../../Packages/Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/SecurityOperationsTrait.php');

/**
 * Features context
 */
class FeatureContext implements Context
{
    use FlowContextTrait;
    use SecurityOperationsTrait;
    use NodeOperationsTrait;

    protected $isolated = false;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    private ?InterruptibleProcessRuntime $renderOrchestratorProcess;
    private ?InterruptibleProcessRuntimeEventInterface $renderOrchestratorProcessLastEvent;

    public function __construct()
    {
        if (self::$bootstrap === null) {
            self::$bootstrap = $this->initializeFlow();
        }
        $this->objectManager = self::$bootstrap->getObjectManager();
        $this->setupSecurity();

        // for testing, we use Private/EndToEndTestFusion as fusion folder to load.
        CustomFusionView::$useCustomSiteRootFusionPatternEntryPointForBehavioralTests = true;
    }

    /**
     * @AfterScenario @fixtures
     */
    public function resetNodeTypeManagerFully()
    {
        $nodeTypeManager = $this->getObjectManager()->get(NodeTypeManager::class);
        // This is a WORKAROUND, and should be done in NodeTypeManager::overrideNodeTypes().
        ObjectAccess::setProperty($nodeTypeManager, 'cachedSubNodeTypes', [], true);
    }

    /**
     * @return ObjectManagerInterface
     */
    public function getObjectManager(): ObjectManagerInterface
    {
        return $this->objectManager;
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

        $this->persistAll();
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
     * @Then the enumeration for content release :contentReleaseIdentifier contains :expectedCount node
     * @Then the enumeration for content release :contentReleaseIdentifier contains :expectedCount nodes
     */
    public function theEnumerationContainsNode($contentReleaseIdentifier, $expectedCount)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisEnumerationRepository = $this->getObjectManager()->get(RedisEnumerationRepository::class);
        $iterable = $redisEnumerationRepository->findAll($contentReleaseIdentifier);
        $enumerationAsArray = iterator_to_array((function() use ($iterable) {yield from $iterable;})());

        Assert::assertCount($expectedCount, $enumerationAsArray);
    }


    /**
     * @When I run the render-orchestrator control loop once for content release :contentReleaseIdentifier
     */
    public function iRunTheRenderOrchestratorControlLoopOnceForContentRelease($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $nodeRenderOrchestrator = $this->getObjectManager()->get(NodeRenderOrchestrator::class);

        $bufferedOutput = new BufferedOutput();
        $contentReleaseLogger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifier);
        $this->renderOrchestratorProcess = InterruptibleProcessRuntime::createForTesting($nodeRenderOrchestrator->renderContentRelease($contentReleaseIdentifier, $contentReleaseLogger));
        $this->renderOrchestratorProcessLastEvent = $this->renderOrchestratorProcess->runUntilEventEncountered(RenderingQueueFilledEvent::class);

        echo $bufferedOutput->fetch();
    }

    /**
     * @When I continue running the render-orchestrator control loop
     */
    public function iContinueRunningTheRenderOrchestratorControlLoop()
    {
        $this->renderOrchestratorProcessLastEvent = $this->renderOrchestratorProcess->runUntilEventEncountered(RenderingQueueFilledEvent::class);
    }

    /**
     * @Then I expect the render-orchestrator control loop to exit with status code :expectedStatusCode
     */
    public function iExpectTheRenderOrchestratorControlLoopToExitWithStatusCode($expectedStatusCode)
    {
        Assert::assertNotNull($this->renderOrchestratorProcessLastEvent, 'renderOrchestratorProcessLastEvent cannot be null');
        Assert::assertInstanceOf(ExitEvent::class, $this->renderOrchestratorProcessLastEvent, 'renderOrchestratorProcessLastEvent needs to be an ExitEvent');
        assert($this->renderOrchestratorProcessLastEvent instanceof ExitEvent);
        Assert::assertEquals($expectedStatusCode, $this->renderOrchestratorProcessLastEvent->getStatusCode(), 'Status Code Mismatch');
    }

    /**
     * @Then I expect the content release :contentReleaseIdentifier to have the completion status failed
     */
    public function iExpectTheContentReleaseToHaveTheCompletionStatusFailed($contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisContentReleaseService = $this->objectManager->get(RedisContentReleaseService::class);
        assert($redisContentReleaseService instanceof RedisContentReleaseService);
        $renderStatus = $redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier)->getStatus();
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
        $renderStatus = $redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier)->getStatus();
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

        $renderProcess = InterruptibleProcessRuntime::createForTesting($nodeRenderer->render($contentReleaseIdentifier, $contentReleaseLogger, RendererIdentifier::fromString('rdr')));
        $renderProcess->runUntilEventEncountered(QueueEmptyEvent::class);

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
        Assert::assertCount($expectedNumberOfErrors, $renderingErrors);
    }


    private const DEFAULT_NODETYPES_CONFIG = <<<EOF
unstructured:
  abstract: true

Neos.Neos:FallbackNode:
  abstract: true

Neos.Neos:Document:
  abstract: true

Neos.Neos:Content:
  abstract: true

Neos.Neos:ContentCollection:
  abstract: true


EOF;


    /**
     * @Given /^I have the following (additional |)NodeTypes configuration:$/
     */
    public function iHaveTheFollowingNodetypesConfiguration($additional, $nodeTypesConfiguration)
    {
        if (strlen($additional) > 0) {
            $configuration = Arrays::arrayMergeRecursiveOverrule($this->nodeTypesConfiguration, Yaml::parse($nodeTypesConfiguration->getRaw()));
        } else {
            $combined = self::DEFAULT_NODETYPES_CONFIG . $nodeTypesConfiguration->getRaw();
            $this->nodeTypesConfiguration = Yaml::parse(self::DEFAULT_NODETYPES_CONFIG . $nodeTypesConfiguration->getRaw());
            $configuration = $this->nodeTypesConfiguration;
        }
        $this->getObjectManager()->get(NodeTypeManager::class)->overrideNodeTypes($configuration);
    }


    /**
     * @Then I expect the content release :contentReleaseIdentifier to contain the following content for URI :uri at CSS selector :cssSelector:
     */
    public function iExpectTheContentReleaseToContainTheFollowingContentForUriAtCssSelector($contentReleaseIdentifier, $uri, $cssSelector, PyStringNode $expected)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisClient = $this->getObjectManager()->get(RedisClientManager::class);
        $actualContent = $redisClient->getPrimaryRedis()->hGet($contentReleaseIdentifier->redisKey('data'), $uri);
        Assert::assertIsString($actualContent, "Did not find rendered document");
        $actualContentDecompressed = gzdecode($actualContent);

        $domCrawler = new Symfony\Component\DomCrawler\Crawler($actualContentDecompressed);
        $actual = $domCrawler->filter($cssSelector)->text();
        Assert::assertSame($expected->getRaw(), $actual, 'Full Output was: ' . $actualContentDecompressed);
    }

    /**
     * @Then I expect the content release :contentReleaseIdentifier to not contain anything for URI :uri
     */
    public function iExpectTheContentReleaseToNotContainAnythingForUri($contentReleaseIdentifier, $uri)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisClient = $this->getObjectManager()->get(RedisClientManager::class);
        $actualContent = $redisClient->getPrimaryRedis()->hGet($contentReleaseIdentifier->redisKey('data'), $uri);
        Assert::assertFalse($actualContent);
    }

    /**
     * @Given /^I flush the content cache depending on the modified nodes$/
     */
    public function iFlushTheContentCacheDependingOnTheModifiedNodes()
    {
        $contentCacheFlusher = $this->getObjectManager()->get(ContentCacheFlusher::class);

        $testLogger = new TestLogger();
        ObjectAccess::setProperty($contentCacheFlusher, 'systemLogger', $testLogger, true);
        $contentCacheFlusher->shutdownObject();
        ObjectAccess::setProperty($contentCacheFlusher, 'tagsToFlush', [], true);
        foreach ($testLogger->records as $record) {
            echo $record['message'] . "\n";
        }
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
}
