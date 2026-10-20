### Updating from 4.x to 5.x

5.x runs on Neos 9. Configuration, CLI commands and `pipelines.yml` stay the same; the changes are in the PHP extension
points, the publish hook and the data a release writes to Redis.

#### 1. Requirements

PHP `^8.4`, `neos/neos` `^9.0`, `flowpack/prunner` `^1.4.1`, and `"flowpack/decoupledcontentstore": "^5.0"`.

#### 2. PHP code (if you wrote extensions or call package classes)

| Area                                                 | 4.x                                        | 5.x                                                          |
|------------------------------------------------------|--------------------------------------------|--------------------------------------------------------------|
| Node type in all extension points                    | `NodeInterface`                            | `Neos\ContentRepository\Core\Projection\ContentGraph\Node`   |
| `DocumentMetadataGeneratorInterface::generateMetadata()` | `ControllerContext $controllerContext` | `ActionRequest $actionRequest`                               |
| `ContentReleaseWriterInterface`                      | no constructor                             | declares `__construct(array $options = [])`                  |
| `ContentReleaseManager::start*ContentRelease()`      | Neos 8 workspace entity                    | `Neos\ContentRepository\Core\SharedModel\Workspace\Workspace` |
| `DocumentNodeFilter::skipReason()`, `skipReasonForNamedNode()` | `($node, $siteNode)`             | `($node)`, no `orphaned` reason any more                     |
| `ContentReleaseMetadata`                             | getters (`getStatus()`, …)                 | public readonly properties (`status`, …)                     |
| `EnumeratedNode` context path                        | `/sites/foo@live;language=de`              | JSON of a `NodeAddress`, read it via `getNodeAddress()`      |
| Package classes                                      | extendable, `@Flow\Inject` properties      | `final` (mostly `readonly`), constructor injection           |
| Constants `ContentReleaseManager::REDIS_CURRENT_RELEASE_KEY`, `RedisPruneService::PRUNE_LUA_SCRIPT`, `NodeRenderer::SPAN_*` | public | `private`; use the value                |
| `NodeContextCombinator`                              | `nodeInContexts($id, $site)`, `nodeVariantsWithSiteNode($id)`, `recurseDocumentChildNodes($node)` | `nodeVariants($id)` yields the `Node` only; the others are gone |
| `NodeContextCombinator::siteNodeInContexts()`        | `($site, $workspaceName, ?bool $invisibleContentShown)` | `($site, $workspaceName)`, always follows `recurseHiddenContent` |
| `RenderingException`                                 | `__construct($message, $node, $nodeUri, …)`, `getNodeUri()` | `__construct($message, $nodeUri, …)`, public `nodeUri`  |
| `DocumentNodeCacheKey::fromNodeAndArguments()`       | `NodeInterface`                            | `Node`                                                       |
| `ContentReleaseDetails` (backend module Fusion)      | getters                                    | public properties, see below                                 |

##### Document metadata generators

`generateMetadata()` receives the `Node` and the `ActionRequest`; where you called `$controllerContext->getRequest()`,
use `$actionRequest` directly.

OLD:

```php
public function generateMetadata(
    NodeInterface $node,
    array $arguments,
    ControllerContext $controllerContext,
    DocumentNodeCacheValues $cacheValues,
): DocumentNodeCacheValues;
```

NEW:

```php
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Mvc\ActionRequest;

public function generateMetadata(
    Node $node,
    array $arguments,
    ActionRequest $actionRequest,
    DocumentNodeCacheValues $cacheValues,
): DocumentNodeCacheValues;
```

`DocumentRendererInterface::renderDocumentNodeVariant()` and `DocumentEnumeratorInterface::enumerateDocumentNode()`
take the `Node` as well.

##### Extensions: creation and dependency injection

Enumerators, renderers, writers and metadata generators are created by the Flow object manager instead of `new`:

* Configured `enumeratorOptions` or writer `options` arrive as the first constructor argument; without them nothing is
  passed and the default `[]` applies.
* Renderers and metadata generators can use constructor injection.
* Enumerators and writers can't: their interfaces declare `__construct(array $options = [])`, which allows no further
  required parameter. Use `#[Flow\Inject]` properties.
* `#[Flow\Scope]` on an extension now takes effect. Enumerators and writers must stay prototype, because Flow refuses
  constructor arguments for singletons.

**Every writer needs the constructor** - also one without options, and a metadata generator which implements
`ContentReleaseWriterInterface` too - otherwise PHP refuses to load the class.

OLD:

```php
class MyWriter implements ContentReleaseWriterInterface
{
    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    public function processRenderedDocument(/* ... */): void
    {
        // ...
    }
}
```

NEW:

```php
final class MyWriter implements ContentReleaseWriterInterface
{
    #[Flow\Inject]
    protected RedisClientManager $redisClientManager;

    public function __construct(array $options = []) {}

    public function processRenderedDocument(/* ... */): void
    {
        // ...
    }
}
```

##### `EnumeratedNode`

The stored context path is the JSON of a `NodeAddress` (content repository, workspace, dimension space point, node
aggregate id). Don't parse it yourself.

OLD:

```php
$enumeratedNode = EnumeratedNode::fromNode($nodeInterface);
$siteNodeName = $enumeratedNode->getSiteNodeNameFromContextPath();
$dimensions = $enumeratedNode->getDimensionsFromContextPath(); // ['language' => ['de', 'en']]
```

NEW:

```php
$enumeratedNode = EnumeratedNode::fromNode($node);
$nodeAddress = $enumeratedNode->getNodeAddress(); // the site comes from the node, not from a path
$dimensions = $enumeratedNode->getDimensionsFromContextPath(); // ['language' => 'de']
```

`getWorkspaceNameFromContextPath()` is unchanged.

##### Starting releases from your own code

`startIncrementalContentRelease()`, `startFullContentRelease()` and `startQuickContentRelease()` take an optional Neos 9
`Workspace`.

OLD:

```php
use Neos\ContentRepository\Domain\Model\Workspace;

$this->contentReleaseManager->startIncrementalContentRelease(null, $workspace);
```

NEW:

```php
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

$workspace = $contentRepository->findWorkspaceByName(WorkspaceName::forLive());
$this->contentReleaseManager->startIncrementalContentRelease(null, $workspace);
```

##### `DocumentNodeFilter`

Drop the site node argument. The methods no longer report `orphaned`, since the Neos 9 content graph cannot hold a
node without a parent.

OLD:

```php
$this->documentNodeFilter->skipReason($node, $siteNode);
$this->documentNodeFilter->skipReasonForNamedNode($node, $siteNode);
```

NEW:

```php
$this->documentNodeFilter->skipReason($node);
$this->documentNodeFilter->skipReasonForNamedNode($node);
```

##### `ContentReleaseMetadata`

The getters are gone; read the public properties `prunnerJobId`, `startTime`, `endTime`, `switchTime`, `status`,
`manualTransferJobIds` (always an array), `workspaceName` (always a string), `accountId` and `contentReleaseSize`.
Fusion such as `metadata.startTime` keeps working.

OLD:

```php
$metadata->getStatus()->isFailed();
$metadata->getPrunnerJobId()->getIdentifier();
```

NEW:

```php
$metadata->status->isFailed();
$metadata->prunnerJobId->getIdentifier();
```

##### `NodeContextCombinator`

`nodeVariants()` searches every dimension space point of the `default` content repository and yields the `Node` alone;
use `$node->contentRepositoryId` or a subgraph lookup instead of the site node `nodeVariantsWithSiteNode()` handed out.
Replace `recurseDocumentChildNodes()` with a subgraph query.

OLD:

```php
foreach ($this->nodeContextCombinator->nodeVariantsWithSiteNode($identifier) as [$siteNode, $node]) {
    // ...
}
foreach ($this->nodeContextCombinator->recurseDocumentChildNodes($siteNode) as $documentNode) {
    // ...
}
```

NEW:

```php
foreach ($this->nodeContextCombinator->nodeVariants($identifier) as $node) {
    // ...
}
$documentNodes = $this->contentRepositoryRegistry->subgraphForNode($siteNode)->findDescendantNodes(
    $siteNode->aggregateId,
    FindDescendantNodesFilter::create(nodeTypes: 'Neos.Neos:Document'),
);
```

##### `RenderingException`

The constructor no longer takes the node, and the URI is a public property.

OLD:

```php
throw new RenderingException($message, $node, $nodeUri, 1234567890, $previous);
$exception->getNodeUri();
```

NEW:

```php
throw new RenderingException($message, $nodeUri, 1234567890, $previous);
$exception->nodeUri;
```

##### Custom backend module Fusion

`ContentReleaseDetails` (`detailsData` in the details view) exposes public properties instead of getters. Eel reads
both, but three names changed:

| 4.x                               | 5.x                                                        |
|-----------------------------------|------------------------------------------------------------|
| `detailsData.active`              | `detailsData.isActive`                                     |
| `detailsData.removed`             | `detailsData.isRemoved`                                    |
| `detailsData.renderingErrorCount` | `Array.length(detailsData.renderingErrors)`                |

`detailsData.renderingErrors` holds the rendering error messages themselves.

##### Package classes

Package classes are `final`, so extending them (e.g. `DefaultEnumerator`, `GzipWriter`) is no longer possible - inject
them into your own class instead. They take dependencies and settings as constructor parameters, so code which creates
one with `new` passes them:

OLD:

```php
$contentReleaseManager = new ContentReleaseManager();
$this->inject($contentReleaseManager, 'redisClientManager', $redisClientManager);
// ...
```

NEW:

```php
$contentReleaseManager = new ContentReleaseManager(
    $prunnerApiService,
    $redisClientManager,
    $automaticReleaseSwitchService,
    $logger,
    $securityContext,
    $configEpochSettings,
);
```

#### 3. Configuration and Fusion

##### Non-default Content Repository preset

Publishing starts a release via a catch-up hook, which the package registers for the `default` preset only. Register
it for your preset too, **otherwise publishing never starts a release**:

```yaml
Neos:
  ContentRepositoryRegistry:
    presets:
      'yourPreset':
        contentGraphProjection:
          catchUpHooks:
            'Flowpack.DecoupledContentStore:IncrementalContentRelease':
              factoryObjectName: Flowpack\DecoupledContentStore\IncrementalContentReleaseCatchUpHookFactory
```

##### Asset cache tags (`Neos.Neos:ImageUri`, Kaleidoscope)

Neos 9 flushes the nodes referencing a changed asset, but not pages which render it from another node (e.g. a teaser
showing another page's image). The package still overrides `Neos.Neos:ImageUri` with
`Flowpack\DecoupledContentStore\Fusion\ImageUriImplementation`, which tags the cache with the asset; replacing the
asset flushes that tag. Image renderers which bypass `Neos.Neos:ImageUri` (e.g. `Sitegeist.Kaleidoscope`) have to add
the tag themselves, e.g. in an `AssetImageSourceImplementation` subclass:

```php
$this->runtime->addCacheTag(AssetChangeContentReleaseHandler::cacheTagForAsset(
    (string) $this->persistenceManager->getIdentifierByObject($asset),
));
```

Use the original asset's identifier for an `AssetVariantInterface`.

##### Shortcuts

Neos 9 routing always builds the target's URI for a `Neos.Neos:Shortcut`, so a rendered shortcut would overwrite its
target in the release. The default `nodeRendering.nodeTypeWhitelist` now excludes `Neos.Neos:Shortcut`; if you
override the list, add `'!Neos.Neos:Shortcut'`.

##### Exception handlers in `Root.fusion`

`Neos.Neos:Breadcrumb` no longer exists; the package now sets its publishing aware handler on `Neos.Neos:BreadcrumbMenu`
and `Neos.Neos:DimensionsMenu`. Move your own overrides of `Neos.Neos:Breadcrumb` the same way.

##### `Objects.yaml`

A replaced dependency of a package class moves from `properties:` to `arguments:`, addressed by the 1-based
constructor position (`readonly` properties can't be set after construction). Re-check the position on every package
update.

OLD:

```yaml
Flowpack\DecoupledContentStore\ContentReleaseManager:
  properties:
    redisClientManager:
      object: My\Package\CustomRedisClientManager
```

NEW:

```yaml
Flowpack\DecoupledContentStore\ContentReleaseManager:
  arguments:
    2:
      object: My\Package\CustomRedisClientManager
```

#### 4. Deployment

The enumeration format and the `doc--…` URL mapping keys changed. Don't let a release run across the deployment, then
start a **full** release (`./flow contentStorePublish:all`); it flushes the content cache itself. The keys the
consuming side reads (`data`, `renderedDocuments`, `renderedMetadata`, `meta:urls`) are unchanged, so 4.x releases
remain valid rollback targets.

#### 5. Behaviour changes (no code changes needed)

* Only a publication **into `live`** starts an incremental release (4.x: into any workspace). Replaying the projection
  doesn't start one. Publishing from the CLI still doesn't, as in 4.x.
* A page is hidden when it carries the `disabled` subtree tag. The 4.x properties `hiddenBeforeDateTime` and
  `hiddenAfterDateTime` are `enableAfterDateTime` and `disableAfterDateTime` in Neos 9, and only take effect when
  `./flow timeableNodeVisibility:execute` (package `neos/timeable-node-visibility`) runs, e.g. from a cron job. That
  command enables and disables nodes directly in `live` and **starts an incremental release** when it changed anything,
  if `startIncrementalReleaseOnWorkspacePublish` is set.
* A changed asset starts an incremental release also when only Fusion renders it (see "Asset cache tags").
* Quick publish no longer reports `orphaned` nodes, and resolves pasted node identifiers in the `default` content
  repository only.
