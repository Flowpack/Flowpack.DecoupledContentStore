<?php

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeEnumeration\NodeEnumerator;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingQueue;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderer;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderOrchestrator;
use Flowpack\DecoupledContentStore\NodeRendering\Render\CustomFusionView;
use Neos\Behat\Tests\Behat\FlowContextTrait;
use Neos\ContentRepository\Tests\Behavior\Features\Bootstrap\NodeOperationsTrait;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Tests\Behavior\Features\Bootstrap\SecurityOperationsTrait;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\FusionService;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Output\BufferedOutput;
use function PHPUnit\Framework\assertEquals;

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
     * @return ObjectManagerInterface
     */
    public function getObjectManager(): ObjectManagerInterface
    {
        return $this->objectManager;
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
        $redisRenderingQueue = $this->getObjectManager()->get(RedisRenderingQueue::class);
        $redisEnumerationRepository = $this->getObjectManager()->get(RedisEnumerationRepository::class);
        $redisRenderingErrorManager = $this->getObjectManager()->get(RedisRenderingErrorManager::class);

        $bufferedOutput = new BufferedOutput();
        $contentReleaseLogger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifier);

        $reflMethod = new \ReflectionMethod(NodeRenderOrchestrator::class, 'goTroughEnumeratedNodesFillContentReleaseAndCheckWhatStillNeedsToBeDone');
        $reflMethod->setAccessible(true);

        $redisRenderingErrorManager->flush($contentReleaseIdentifier);
        $redisRenderingQueue->flush($contentReleaseIdentifier);
        $currentEnumeration = $redisEnumerationRepository->findAll($contentReleaseIdentifier);

        $reflMethod->invoke($nodeRenderOrchestrator, $currentEnumeration, $contentReleaseIdentifier, $contentReleaseLogger);

        echo $bufferedOutput->fetch();
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

        $reflMethod = new \ReflectionMethod(NodeRenderer::class, 'singleRenderIteration');
        $reflMethod->setAccessible(true);

        do {
            $foundRenderingJob = $reflMethod->invoke($nodeRenderer, $contentReleaseIdentifier, $contentReleaseLogger, RendererIdentifier::fromString('foo'));
        } while ($foundRenderingJob === true);

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
     * @Then I expect the content release :contentReleaseIdentifier to contain the following content for URI :uri at CSS selector :cssSelector:
     */
    public function iExpectTheContentReleaseToContainTheFollowingContentForUriAtCssSelector($contentReleaseIdentifier, $uri, $cssSelector, PyStringNode $expected)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisClient = $this->getObjectManager()->get(\Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager::class);
        $actualContent = $redisClient->getPrimaryRedis()->hGet($contentReleaseIdentifier->redisKey('result:renderedDocuments'), $uri);

        Assert::assertSame($expected->getRaw(), $actualContent);
    }
}
