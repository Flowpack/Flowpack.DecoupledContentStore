<?php
namespace Flowpack\DecoupledContentStore\Controller;

use Flowpack\DecoupledContentStore\BackendUi\BackendUiDataService;
use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\Core\RedisPruneService;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure\RedisReleaseSwitchService;
use Flowpack\DecoupledContentStore\Transfer\ContentReleaseCleaner;
use Flowpack\Prunner\PrunnerApiService;
use Flowpack\Prunner\ValueObject\PipelineName;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\View\FusionView;
use Symfony\Component\Console\Output\BufferedOutput;

class BackendController extends \Neos\Flow\Mvc\Controller\ActionController
{

    /**
     * @Flow\Inject
     * @var PrunnerApiService
     */
    protected $prunnerApiService;

    /**
     * @Flow\Inject
     * @var ContentReleaseManager
     */
    protected $contentReleaseManager;

    /**
     * @Flow\Inject
     * @var BackendUiDataService
     */
    protected $backendUiDataService;

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    /**
     * @Flow\Inject
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;

    /**
     * @Flow\Inject
     * @var RedisReleaseSwitchService
     */
    protected $redisReleaseSwitchService;

    /**
     * @Flow\Inject
     * @var RedisKeyService
     */
    protected $redisKeyService;

    /**
     * @Flow\Inject
     * @var ContentReleaseCleaner
     */
    protected $contentReleaseCleaner;

    /**
     * @Flow\Inject
     * @var RedisPruneService
     */
    protected $redisPruneService;

    /**
     * @Flow\InjectConfiguration("redisContentStores")
     * @var array
     */
    protected $redisContentStores;

    protected $defaultViewObjectName = FusionView::class;

    public function indexAction(?string $contentStore = null)
    {
        $contentStore = $contentStore ? RedisInstanceIdentifier::fromString($contentStore) : RedisInstanceIdentifier::primary();
        $storeSize = $this->redisClientManager->getRedis($contentStore)->info('memory')['used_memory_human'];

        $this->view->assign('contentStore', $contentStore->getIdentifier());
        $this->view->assign('overviewData', $this->backendUiDataService->loadBackendOverviewData($contentStore));
        $this->view->assign('redisContentStores', array_keys($this->redisContentStores));
        $this->view->assign('storeSize', $storeSize);
    }

    public function detailsAction(string $contentReleaseIdentifier, ?string $contentStore = null, ?string $detailTaskName = '', ?string $prunnerJobId = '')
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $contentStore = $contentStore ? RedisInstanceIdentifier::fromString($contentStore) : RedisInstanceIdentifier::primary();

        $this->view->assign('contentStore', $contentStore->getIdentifier());

        $detailsData = $this->backendUiDataService->loadDetailsData($contentReleaseIdentifier, $contentStore);
        $this->view->assign('detailsData', $detailsData);
        $this->view->assign('redisContentStores', array_keys($this->redisContentStores));
        $this->view->assign('isPrimary', $contentStore->isPrimary());

        if ($detailTaskName !== '') {
            $this->view->assign('detailTaskName', $detailTaskName);
            $this->view->assign('jobLogs', $this->prunnerApiService->loadJobLogs($prunnerJobId ? PrunnerJobId::fromString($prunnerJobId)->toJobId() : $detailsData->getJob()->getId(), $detailTaskName));
        }
    }


    public function publishAllAction()
    {
        $this->contentReleaseManager->cancelAllRunningContentReleases();
        $this->contentReleaseManager->startFullContentRelease();
        $this->redirect('index');
    }

    public function removeAction(string $contentReleaseIdentifier, string $redisInstanceIdentifier)
    {
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->response->setStatusCode(405);
            return 'Method not allowed';
        }

        $contentReleaseIdentifierToRemove = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisInstanceIdentifier = $redisInstanceIdentifier ? RedisInstanceIdentifier::fromString($redisInstanceIdentifier) : RedisInstanceIdentifier::primary();

        $bufferedOutput = new BufferedOutput();
        $logger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifierToRemove);

        $this->contentReleaseCleaner->removeRelease($contentReleaseIdentifierToRemove, $redisInstanceIdentifier, $logger);

        $this->redirect('index', null, null, ['contentStore' => $redisInstanceIdentifier->getIdentifier()]);
    }

    public function switchAction(string $contentReleaseIdentifier, string $redisInstanceIdentifier)
    {
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->response->setStatusCode(405);
            return 'Method not allowed';
        }

        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisInstanceIdentifier = $redisInstanceIdentifier ? RedisInstanceIdentifier::fromString($redisInstanceIdentifier) : RedisInstanceIdentifier::primary();

        $bufferedOutput = new BufferedOutput();
        $logger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifier);

        $this->redisReleaseSwitchService->switchContentRelease($redisInstanceIdentifier, $contentReleaseIdentifier, $logger);

        $this->redirect('index', null, null, ['contentStore' => $redisInstanceIdentifier->getIdentifier()]);
    }

    public function switchContentReleaseOnOtherInstanceAction(string $targetRedisInstanceIdentifier, string $contentReleaseIdentifier)
    {
        $redis = $this->redisClientManager->getPrimaryRedis();
        $currentContentReleaseId = $redis->get('contentStore:current');

        $this->prunnerApiService->schedulePipeline(PipelineName::create('manually_transfer_content_release'),
            ['contentReleaseId' => $contentReleaseIdentifier, 'currentContentReleaseId' => $currentContentReleaseId ?: ContentReleaseManager::NO_PREVIOUS_RELEASE, 'redisInstanceId' => $targetRedisInstanceIdentifier]);

        $this->redirect('index', null, null, ['contentStore' => $targetRedisInstanceIdentifier]);
    }

    public function pruneContentStoreAction(string $redisInstanceIdentifier)
    {
        $redisInstanceIdentifier = RedisInstanceIdentifier::fromString($redisInstanceIdentifier);
        $this->redisPruneService->pruneRedisInstance($redisInstanceIdentifier);

        $this->redirect('index', null, null, ['contentStore' => $redisInstanceIdentifier->getIdentifier()]);
    }

    public function cancelRunningReleaseAction(string $redisInstanceIdentifier)
    {
        $redisInstanceIdentifier = RedisInstanceIdentifier::fromString($redisInstanceIdentifier);
        $this->contentReleaseManager->cancelAllRunningContentReleases();

        $this->redirect('index', null, null, ['contentStore' => $redisInstanceIdentifier->getIdentifier()]);
    }
}
