# Decoupled Content Store based on Redis

This is the 2nd generation of a Two-Stack CMS package for Neos.

**This Package is used in production in multiple bigger instances.**

The Content Store package is one part of a [Two-Stack CMS](https://martinfowler.com/articles/two-stack-cms/)
solution with Neos. A Two-Stack architecture separates editing and publishing
from the delivery of content. This is also an architecture that's suitable to+
integrate Neos content in various other systems without adding overhead during
delivery.

The first iteration was not open source; developed jointly by [Networkteam](https://networkteam.com/) and [Sandstorm](https://sandstorm.de/)
and is in use for several large customers. The second iteration (this project) is developed from scratch, in an open-source
way, based on the learnings of the first iteration. Especially the robustness has been greatly increased.

## Versioning Scheme

| Package Version             | Neos / Flow Version | Released? | Supported              | Remarks                                                                                                                                               |
|-----------------------------|---------------------|-----------|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1.x                         | 8.x                 | ☑️        | ⛔️                     | out of support                                                                                                                                        |
| 2.x                         | 8.x                 | ☑️        | ⛔️️️                   | Breaking configuration changes, to support **different renderers** and more flexible rendering overall. Currently used in production in bigger sites. |
| 3.x (current `main` branch) | 8.x                 | ☑️        | current active release | Breaking configuration changes, to make configuration more readable. Currently used in production in bigger sites.                                    |
| 4.x                         | 9.x                 | ⛔️        | ⛔️                     | Not yet planned                                                                                                                                       |

## Upgrade guides

For upgrade guides have a look at [UpgradeGuides](Documentation/UpgradeGuides).

## What does it do?

The Content Store package publishes content from Neos to a Redis database as
immutable _content releases_. These releases can be atomically switched and
a _current release_ points to the active release.

The _delivery layer_ in the Two-Stack architecture uses the _current release_
and looks for matching URLs in the _content store_ and delivers the pre-rendered
content. A _delivery layer_ is decoupled from the actual Neos CMS and can be
implemented in any language or framework. It is also possible to integrate the
delivery layer part in another software (e.g. a shop system) as an extension.

## Features

- Publish a full, read-only snapshot of your live content to Redis in a so-called *Content Release*
- allows for *incremental publishing*; so if a change is made, only the needed pages are re-rendered. This is
  *integrated with the Neos Content Cache*; so cache flushings work correctly.
- Integration with Neos workspace publishing for automatic incremental
  publishing to the Content Store
- Configurable Content Store format, decoupled from the internal representation in Neos.
- Extensibility: Enrich content releases with your custom data.
- Allows parallel rendering
- Allows copying the content releases to different environments.
- Allows rsyncing persistent assets around (should you need it)
- Backend module with overview of _content releases_ (current release, switching
  releases, manual publish)
- Pausing the automatic releases, so nothing goes live while a change is being prepared
- *Quick content releases*: publish single documents into a copy of the release which is live, instead of
  re-rendering everything

This project is using the go-package [prunner](https://github.com/Flowpack/prunner) and [its Flow Package wrapper](https://github.com/Flowpack/Flowpack.Prunner)
as the basis for orchestrating and executing a content release.

## Requirements

- Redis — 6.2 or newer if you want to use [Quick Content Releases](#quick-content-releases), which copy a release
  with the server-side `COPY` command. Everything else works with older versions.
- Prunner

Start up prunner via the following command:

```bash
prunner/prunner --path Packages --data Data/Persistent/prunner
```

Copy the `pipelines_template.yml` file into your project and adjust it as needed (see below and the comments in the file for explanation).

## Approach to Rendering

The following flow chart shows the rendering pipeline for creating a content release.

```                                                                                                 
                   ┌─────────────────────┐                                                      
                   │   Node Rendering    │                                                      
 ┌───────────┐     │   ┌─────────────┐   │     ┌───────────┐     ┌───────────┐     ┌───────────┐
 │   Node    │     │   │Orchestrator │   │     │  Release  │     │Transfer to│     │  Atomic   │
 │Enumeration│────▶│   └─────────────┘   │────▶│Validation │────▶│  Target   │────▶│  Switch   │
 └───────────┘     │┌────────┐ ┌────────┐│     └───────────┘     └───────────┘     └───────────┘
                   ││Renderer│ │Renderer││                                                      
                   └┴────────┴─┴────────┴┘                                                      
```

- At the beginning of every render, all nodes are **enumerated**. The Node Enumeration contains all pages
  which need to be in the final content release.

- Then, the rendering takes place. In parallel, the **orchestrator** checks if pages are already fully rendered. If no,
  he creates rendering jobs. If yes, the rendered page is added to the in-progress content release.
  
  The **renderers** simply render the pages as instructed by the orchestrator.

  The **orchestrator** tries to render multiple times: It can happen that after a render, the rendering did not
  successfully work, because an editor has changed pages at the same time; leading to content cache flushes and
  "holes" in the output.

  From the second attempt on, the renderer **flushes the document's content cache entries** (by node tag) before
  re-rendering it. Without this, a retry can be answered completely from the content cache - then no document-level
  cache segment is processed, `CacheUrlMappingAspect` writes no `doc--...` mapping entry, and the orchestrator
  schedules the very same node again in the next iteration. Can be turned off via the setting
  `nodeRendering.flushDocumentCacheOnRetry`. If the identical set of nodes is scheduled three iterations in a row,
  the orchestrator gives up early and registers a rendering error per node instead of running into the
  10-attempt limit.

- During **validation**, checks can happen to see whether the content release is fully complete; to check whether
  it really can go online.

- During the **transfer** phase, the finished content release is copied to the production Redis instance if needed.
  This includes copying of assets if needed.

- In the **switch** phase, the content release goes live.

The above pipeline is implemented with [prunner](https://github.com/Flowpack/prunner) which is orchestrating
the different steps.

## Infrastructure

Here, we explain the different infrastructure and setup constraints for using the content store.

- The Neos Content Cache must use Redis. It can use the OptimizedRedisCacheBackend.
- The Content Store needs a separate Redis Database, but it can run on the same server.

**It is crucial that Redis is available via lowest latency for Neos AND the Delivery Layer.** See the different
setup scenarios below for how this can be done.

### Minimal Setup

The minimal setup looks as follows:

- Neos writes into the Content Store Redis Database, and the Delivery Layer reads from the Content Store Redis Database.
- Assets (persistent resources) are written directly to a publicly available Cloud Storage such as S3.

```
┌──────────────┐   ┌──────────────┐            
│ Neos Content │   │Content Store │            
│Cache Redis DB│   │   Redis DB   │◀───┐       
└──────────────┘   └──────────────┘    │       
        ▲                  ▲           │       
        └────────┬─────────┘           │       
                 │                     │       
             ╔══════╗          ╔══════════════╗
             ║ Neos ║          ║Delivery Layer║
             ╚══════╝          ╚══════════════╝
                 │                             
                 │                             
                 │       ┌──────────────┐      
                 │       │Asset Storage │      
                 └──────▶│   (S3 etc)   │      
                         └──────────────┘      
```

In this case, the *transfer* phase does not need to do anything, and you need to configure Neos to use the cloud
storage (e.g. via [Flownative.Google.CloudStorage](https://github.com/flownative/flow-google-cloudstorage) or
[Flownative.Aws.S3](https://github.com/flownative/flow-aws-s3/)) for resources.

**This is implemented in the default `pipelines_template.yml`.**

**This Setup should be used if:**
- the Delivery Layer and Neos are in the same data center (or host), so both can access Redis via lowest latencies
- you want the easiest possible setup.

If you use Cloud Asset Storage, ensure that you **never delete** assets from there. For `Flownative.Aws.S3`,
you can [follow the guide on "Preventing Unpublishing of Resources in the Target"](https://github.com/flownative/flow-aws-s3/#preventing-unpublishing-of-resources-in-the-target).

### Manually Sync Assets to the Delivery Layer via RSync

If you can not to use a Cloud Asset Storage, there's a built-in feature to manually sync assets to the delivery
layer(s) via RSync.

To enable this, you need to follow the following steps:

1. Configure in `Settings.yaml`:

    ```yaml
    Flowpack:
      DecoupledContentStore:
        resourceSync:
          targets:
            -
              host: localhost
              port: ''
              user: ''
              directory: '../nginx/frontend/resources/'
    ```

2. In `pipelines.yml`, underneath `4) TRANSFER`, comment-in the `transfer_resources` task.

### Copy Content Releases to a different Redis instance

**This Setup should be used if:**
- the Delivery Layer and Neos are in *different* data centers, so that there is a higher latency between one of the instances toward Redis
- Or you need multiple delivery layers with different content states, with e.g. a *staging* delivery layer and a *live* delivery layer.

```
┌──────────────┐   ┌──────────────┐                   ┌──────────────┐
│ Neos Content │   │Content Store │                   │Content Store │
│Cache Redis DB│   │   Redis DB   │  ┌ ─ ─ ─ ─ ─ ─ ─ ▶│   Redis DB   │
└──────────────┘   └──────────────┘    Higher         └──────────────┘
        ▲                  ▲         │ Latency                ▲       
        └────────┬─────────┘                                  │       
                 │                   │                        │       
             ╔══════╗                                 ╔══════════════╗
             ║ Neos ║─ ─ ─ ─ ─ ─ ─ ─ ┘                ║Delivery Layer║
             ╚══════╝                                 ╚══════════════╝
                 │                                                    
                 │                                                    
                 │       ┌──────────────┐                             
                 │       │Asset Storage │                             
                 └──────▶│   (S3 etc)   │                             
                         └──────────────┘                                                 
```

In this case, the content store Redis DB is **explicitly synced** by Neos to another Delivery layer.

To enable this feature, do the following:

1. Configure the additional Content Stores in `Settings.yaml` underneath `Flowpack.DecoupledContentStore.redisContentStores`.
   The key is the internal identifier of the content store:

    ```yaml
    Flowpack:
      DecoupledContentStore:
        redisContentStores:
          live:
            label: 'Live Site'
            hostname: my-redis-hostname
            port: 6379
            database: 11
          staging:
            label: 'Staging Site'
            hostname: my-staging-redis-hostname
            port: 6379
            database: 11
    ```

2. In `pipelines.yml`, underneath `4) TRANSFER`, comment-in and adjust the `transfer_content` task.

3. In `pipelines.yml`, underneath `5) TRANSFER`, comment-in the additional `contentReleaseSwitch:switchActiveContentRelease` commands.

> **Alternative: Redis Replication**
> 
> Instead of the explicit synchronization described here, you can also use [Redis Replication](https://redis.io/topics/replication)
> to synchronize the primary Redis to the other instances.
>
> Using Redis replication is transparent to Neos or the Delivery Layer.
> 
> To be able to use Redis replication, the Redis *secondary* (i.e. the delivery-layer's instance)
> needs to connect to the primary Redis instance.
> 
> For the explicit synchronization described here, the Redis instances do not need to communicate directly
> with each other; but Neos needs to be able to reach all instances.

## Incremental Rendering

As a big improvement for stability (compared to v1), the rendering pipeline does not make a difference whether
it is a full or an incremental render. To trigger a full render, the content cache is flushed before
the rendering is started.

### Options

By default, the Package is configured to automatically start an incremental release whenever any workspace is published.
This behavior can be disabled via Flow configuration in your Settings.yaml

```yaml
Flowpack:
  DecoupledContentStore:
    # if you want to disable auto-start of incremental releases, set this to false
    # (default value: true)
    startIncrementalReleaseOnWorkspacePublish: false
    # ...
```

After changing an Asset (e.g. in the Media Module) an incremental rendering is triggered.
You can opt out of this behavior by setting the following configuration:

````yaml
Flowpack:
  DecoupledContentStore:
    startIncrementalReleaseOnAssetChange: false
````

### What happens if edits happen during a rendering?

If a change by an editor happens during a rendering, the content cache is flushed (by tag) as a result of
this content modification. Now, there are two possible cases:

- the document (which was modified) has not been rendered yet inside the current rendering. In this case,
  the rendered document would contain the recent changes.
- the document was already rendered and added to the content release. **In this case, the rendered
  document would *not* contain the recent changes**.

The 2nd case is a bit dangerous, in the sense that we need a re-render to happen soon; otherwise we would
not converge to a consistent state.

For use cases like scheduling re-renders, `prunner` supports a *concurrency limit* (i.e. how many
jobs can run in parallel) - and if this limit is reached, it supports an additional *queue* which can
be also limited.

So the following lines from `pipelines.yml` are crucial:

```yaml
pipelines:
  do_content_release:
    concurrency: 1
    queue_limit: 1
    queue_strategy: replace
```

So, if a content release is currently running, and we try to start a new content release, then this task is
added to the queue (but not yet executed). In case there is already a rendering task queued, this gets replaced
by the newer rendering task.

**This ensures that we have at most one content release running at any given time; and at most one content-release
in the wait-list waiting to be rendered.** Additionally, we can be sure that scheduled content releases will be
eventually executed, because that's prunner's job.

## Quick Content Releases

Rendering dominates the runtime of a content release: on a big site, a release which changes a single page still
re-renders every other page to produce a release which is identical to the previous one everywhere else.

A *quick content release* is the shortcut for that case. It copies the content release which is currently live,
re-renders only the documents you name into that copy, and publishes the result. From the enumeration onwards it is
the ordinary pipeline, so validation, transfer and switching behave exactly as they always do — the release which
goes live is a complete, ordinary content release, not a patch.

It is deliberately manual and explicit. Nothing starts a quick release automatically, and the backend offers it only
while automatic releases are paused, because that is the situation it exists for: something has to go live now, and
waiting for a full release is not an option.

The reasoning behind the design — the measurement it is based on, why the copy is configured per key, and which
limitations follow from copying a release forward — is written up in
[Documentation/Concepts/QuickContentReleases.md](Documentation/Concepts/QuickContentReleases.md).

### Requirements

- **Redis 6.2 or newer** on the primary content store. The copy is the server-side `COPY` command, so nothing travels
  over the wire. The command checks the server version before it copies anything and aborts with a message naming the
  required version, rather than failing cryptically on an older server.
- **The `do_quick_content_release` pipeline** from `pipelines_template.yml` in your own `pipelines.yml`.
- **`copyOnQuickRelease: true` on every custom release key** you write (see below). This is the part which is easy to
  miss, and getting it wrong is not subtle: a key which is `isRequired` and is neither copied nor written by the
  quick pipeline makes the switch abort.

### Registering your own keys for the copy

Which keys a quick release carries over is configuration, not a hardcoded list. Extend the registration described
under [Writing Custom Data to the Content Release](#writing-custom-data-to-the-content-release):

```yaml
Flowpack:
  DecoupledContentStore:
    redisKeyPostfixesForEachRelease:
      foo:
        transfer: true
        # true for content which a quick release does not rebuild, and which therefore has to come along from the
        # release being copied. Defaults to false.
        copyOnQuickRelease: true
```

The default is `false`, which is the safe direction: a key which should have been copied shows up as missing content,
while a key which should not have been copied describes a *different* release and is much harder to notice. The
package's own content keys (`data`, `meta:urls`, `renderedDocuments`, `renderedMetadata`) are `true`; its enumeration,
job queue and statistics keys are `false`, because the quick pipeline writes those itself.

Set it to `true` for anything your pipeline exports in a task the quick pipeline does not run — brand data, redirect
exports, reusable snippets and the like. Those then go live exactly as they were in the copied release.

### Pausing the automatic releases

The Content Store module has a *Pause automatic releases* button. While the pause is on:

- every automatic trigger (workspace publish, asset change, re-render after a rendering error) is suppressed and
  counted, so the module can show how much is waiting;
- *Publish All* still works — it is an explicit request, and the pause exists to let you prepare a release by hand;
- editors see a warning in the Neos content module telling them their changes are not going live yet, because a pause
  stops everybody's publishes, not just yours.

Resuming only lifts the switch. It does not start a release, so the suppressed changes go live with the next release
that is triggered — start one yourself if you do not want to wait for the next editor publish.

Pause, resume and quick publish sit behind the `Flowpack.DecoupledContentStore:ReleaseControl` privilege target,
which the package grants to `Neos.Neos:Administrator`. It is separate from the module privilege, so an installation
which lets editors watch the module can still restrict who may stop everybody's publishes from going live. The
read-only status the content-module warning uses is outside the target, so editors can see the banner.

### Publishing single documents

With automatic releases paused, the module offers *Quick publish pages*. Paste the node identifiers of the documents
to publish, one per line. The confirmation page then shows one row per dimension variant with its title, path,
dimensions, node type and a link into the Neos backend, and flags every row which will **not** be published — a page
which is hidden, orphaned, of a node type outside `nodeRendering.nodeTypeWhitelist`, or an identifier which resolves
nowhere at all. Check those before you continue: a page you meant to fix would otherwise silently stay as it is.

The identifiers are checked against the identifier format before they are used anywhere. They end up inside a shell
command in the pipeline, so anything else is refused outright.

Two situations are refused with an explanation instead of a release:

- **No release is live.** There is nothing to copy, so run a full release instead.
- **Another quick release is still running or queued.** Its copy source is resolved when it is scheduled, so a second
  one queued behind the first would build on the release the first is about to replace — and drop that change without
  a word.

### What a quick release cannot do

These follow from copying the previous release forward. They are not gaps to be closed later.

- **Only the named pages change.** Anything on *other* pages which embeds them — navigation titles, teasers, sitemap
  entries, search indexes, and whatever your own export tasks produce — stays as it was until the next normal release.
- **Adding or removing pages is out of scope.** A deleted page still sits in the copied `meta:urls` and
  `renderedDocuments`. Quick publish is for fixing pages which already exist.
- **The release being copied has to still exist**, so it must not have been pruned by
  `contentReleaseRetentionCount`.
- **Pausing means editor changes stop going live.** The banner and the counter are the whole mitigation, which is why
  resuming is a deliberate manual step.
- **Concurrency.** The quick pipeline takes the same concurrent build lock as any other release, so it terminates an
  in-flight full release, and a full release started afterwards terminates it. Correct, but pressing *Publish All*
  during a quick release throws the quick release away.

### Scoping your own validators

Validation is what is left of the runtime once rendering is gone, and after a copy-forward almost all of it is wasted:
every document except the handful just re-rendered is byte-for-byte what the previous release was already validated
on.

`Flowpack\DecoupledContentStore\QuickPublish\ContentReleaseScope` is the hook for that. A validator which knows
nothing about quick releases keeps working unchanged; one which opts in asks for the scope and narrows its read:

```php
$changedUrls = $this->contentReleaseScope->getChangedUrls($contentReleaseIdentifier);
if ($changedUrls === null) {
    // an ordinary release: validate everything, as before
} else {
    // a quick release: only these URLs were rendered, everything else was validated in the release we copied
}
```

`NULL` means "this release was rendered as a whole" — a validator which reads it as "no URLs to check" would wave
everything through, so treat the two cases explicitly. The typical win is turning an `hGetAll` over the whole
document hash into an `hMGet` for the changed URLs.

The package's own `contentReleaseValidation:validate` had to be adapted as well: it aborts a release below 70% of the
size of the live one, while a quick release deliberately enumerates a handful of documents instead of all of them. As
the new release its enumeration would fail that check every single time; as the currently live one it would put the
threshold at a handful of URLs and wave the next full release through however much of the site that one lost.

It therefore counts the URLs a release *publishes* (`ContentReleaseScope::countPublishedUrls()`, the `meta:urls`
cardinality) on **both** sides, which after a copy-forward equals the release the quick one was built on. Do the same
in a size check of your own, and do not mix the two measures: the enumeration holds one entry per document **and
renderer**, so with a second document renderer configured it is a multiple of the URL count, and comparing one against
the other refuses every quick release while letting a full release which lost half the site pass.

### The commands

Both are pipeline steps and are not meant to be called by hand, but they are useful to know when reading a failed
job log:

```bash
# copy every key registered with copyOnQuickRelease from one release to another, within one content store
./flow contentReleaseQuickPublish:copyReleaseWithin primary <sourceReleaseId> <targetReleaseId>

# write the enumeration of a quick release: these documents, and nothing else
./flow contentReleaseQuickPublish:enumerateGivenNodes <contentReleaseId> --nodeIdentifiers <uuid,uuid>
```

The copy refuses a source release whose status is not `success` or which is missing a required key, because
switching a release live by hand is possible and "currently live" alone does not guarantee a clean release. The
enumeration skips an identifier which resolves nowhere — with a warning in the job log, like every other node it
cannot publish — and refuses to end up empty: a quick release which renders nothing would publish the release it
copied and look like a successful publish while the change is nowhere.

To start one from your own code:

```php
$this->contentReleaseManager->startQuickContentRelease(
    NodeIdentifiers::fromCommaSeparatedString('<uuid>,<uuid>')
);
```

## Extensibility

### Custom `pipelines.yml`

Crafting a custom `pipelines.yml` is the main extension point for doing additional work (f.e. additional enumeration
or rendering).

### Custom Rendering

(NEW with v2)

DecoupledContentStore v1 was specifically tied to Fusion as rendering engine and the Neos Content cache.
This has changed in V2, where **different renderings** of a given document can be instantiated.

(TODO EXPLAIN IN DETAIL)

### Custom Document Metadata, integrated with the Content Cache

Sometimes, you need to build additional data structures for every individual document. Ideally, you'll want this
structure to be integrated with the content cache; i.e. only refresh it if the page has changed.

Performance-wise, it is clever to do this at the same time as the rendering itself, as the content nodes
(which you'll usually need) are already loaded in memory. You can register a
`Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentMetadataGeneratorInterface` in `Settings.yaml`:

```yaml
Flowpack:
  DecoupledContentStore:
    extensions:
      documentMetadataGenerators:
        'yourMetadataGenerator':
          className: 'Your\Extra\MetadataGenerator'
```

When you implement this class, you can add additional Metadata which is serialized to the Neos content cache
for every rendered document.

Often, you'll also want to add another `contentReleaseWriter` which reads the newly added metadata and adds
it to the final content release. Read the next section how this works.

### Custom Content Release Writer

You can completely define how a content release is laid out in Redis for consumption by your delivery layer.

By implementing a custom `ContentReleaseWriter`, you can specify how the rendered content is stored in Redis.

Again, this is registered in `Settings.yaml`:

```yaml
Flowpack:
  DecoupledContentStore:
    extensions:
      contentReleaseWriters:
        'yourMetadataReleaseWriter':
          className: 'Your\Extra\MetadataWriter'
```

### Writing Custom Data to the Content Release

In case you write custom data to the content release (using `$redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'foo')`), you need to register
the custom key also in the settings:

```yaml
Flowpack:
  DecoupledContentStore:
    redisKeyPostfixesForEachRelease:
      foo:
        transfer: true
```

This is needed so that the system knows which keys should be synchronized between the different content stores,
and what data to delete if a release is removed.

If you use [Quick Content Releases](#quick-content-releases), decide here whether the key travels into one — see
[Registering your own keys for the copy](#registering-your-own-keys-for-the-copy).

### Rendering additional nodes with arguments (e.g. pagination or filters)

If you render a paginated list or have filters (with a predictable list of values) that can be
added to a document via arguments, you can implement a slot for the `nodeEnumerated` signal to enumerate additional
nodes with arguments.

> **Note:** Request arguments must be mapped to URIs via custom routes, since we do not support HTTP query parameters for rendered documents.

#### Example

Add a slot for the `nodeEnumerated` signal via `Package.php`:

```php
<?php
class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(NodeEnumerator::class, 'nodeEnumerated', MyNodeListsEnumerator::class, 'enumerateNodeLists');
    }
}
```

Implement the slot and enumerate additional nodes depending on the node type:

```php
<?php
class NodeListsEnumerator
{
    public function enumerateNodeLists(EnumeratedNode $enumeratedNode, ContentReleaseIdentifier $releaseIdentifier, ContentReleaseLogger $logger)
    {
        $nodeTypeName = $enumeratedNode->getNodeTypeName();
        $nodeType = $this->nodeTypeManager->getNodeType($nodeTypeName);
        if ($nodeType->isOfType('Vendor.Site:Document.Blog.Folder')) {
            // Get the node and count the number of pages to render
            // $pageCount = ...

            $pageCount = ceil($postCount / (float)$this->perPage);
            if ($pageCount <= 1) {
                return;
            }

            // Start after the first page, because the first page will be the document without arguments
            for ($page = 2; $page <= $pageCount; $page++) {
                $enumeratedNodes[] = EnumeratedNode::fromNode($documentNode, ['page' => $page]);
            }

            $this->redisEnumerationRepository->addDocumentNodesToEnumeration($releaseIdentifier, ...$enumeratedNodes);
        }
    }
}
```

The actual logic will depend on your use of the node. Having the actual filtering logic implemented in PHP is
beneficial, because it allows you to use it in the rendering process as well as in the additional enumeration.

A [quick content release](#quick-content-releases) emits the signal for the documents it re-renders, so the extra
nodes a slot adds are re-rendered along with them instead of staying at the rendering of the release which was
copied. They are not part of the release's [scope](#scoping-your-own-validators), though: a validator which
reads `getChangedUrls()` sees the documents that were named, not the variants a slot derived from them.

### Extending the backend module

- You need a Views.yaml in your package, looking like this:
```
-
  requestFilter: 'isPackage("Flowpack.DecoupledContentStore")'
  viewObjectName: 'Neos\Fusion\View\FusionView'
  options:
    fusionPathPatterns:
      - 'resource://Flowpack.DecoupledContentStore/Private/BackendFusion'
      - 'resource://Vendor.Site/Private/DecoupledContentStoreFusion'
```
- Ensure that your package depends on `flowpack/decoupledcontentstore` in composer.json (so that your Views.yaml "wins" because the DecoupledContentStore-Package comes with its own Views.yaml)
- Add a Root.fusion in `Vendor.Site/Resources/Private/DecoupledContentStoreFusion` which can contain your modifications
- We currently support the following adjustments:
  - Adding a button to the footer
    ```
    prototype(Flowpack.DecoupledContentStore:ListFooter) {
        test = '<span class="align-middle inline-block text-sm pr-4 pl-16">TEST</span>'
        test.@position = 'before reload'
    }
    ```
  - Adding a flash message
    ```
    // ActionController
    $this->addFlashMessage('sth important you have to say');
    ```
  
### Using different sets of config

In some cases it might be necessary to make fundamental adjustments to some configuration properties that would be
really hard to handle (safely, non-breaking) on the consuming site of the content store. Therefore we added the config
property `configEpoch` that can contain a current and previous config version. The `current` value (that should be used
on the consuming site) gets published to the content store.

We decided to save the configEpoch on content store level instead of content release level for simplicity reasons on the
consuming site. If you need to switch back to an older release that was rendered with the previous config epoch version
and would not match the currently published one, you may manually toggle between current and previous config epoch.
There is a button for this in the backend module for each target content store. Obviously this button should be used
with extra care as the config epoch needs to fit the current release at all times.

Example:

- We need to make a bigger change to the contentDimensions config, let's say we need to add uriPrefixes that weren't
  there before. We adjust the config accordingly and in the same deployment we configure the config epoch as follows:

    ```yml
    Flowpack:
      DecoupledContentStore:
        configEpoch:
          current: '2'
          previous: '1'
    ```

- Now on the consuming site we can take action to handle both the old and new config and decide based on the value in
  redis which case is executed.

    ```php
    $configEpoch = (int) $redisClient->get('contentStore:configEpoch');
    $contentStoreUrl = 'https://www.vendor.de/' . ($configEpoch > 1 ? 'de-de/' : '');
    ```

## Development

- You need [pnpm](https://github.com/pnpm/pnpm) as package panager installed: `curl -f https://get.pnpm.io/v6.js | node - add --global pnpm`
- Run `pnpm install` in this folder
- Then run `pnpm watch` for development and `pnpm build` for prod build.

We use esbuild combined with tailwind.css for building.

### Rendering Deep Dive

TODO write

CacheUrlMappingAspect - * NOTE: This aspect is NOT active during interactive page rendering; but only when a content release is built
* through Batch Rendering (so when {@see DocumentRenderer} has invoked the rendering. This is to keep complexity lower
* and code paths simpler: The system NEVER re-uses content cache entries created by editors while browsing the page; but
* ONLY re-uses content cache entries created by previous Batch Renderings.


### Debugging

If you need to debug single steps of the pipeline just run the corresponding commands from CLI, 
e.g. `./flow nodeEnumeration:enumerateAllNodes {{ .contentReleaseId }}`.

#### The orchestrator schedules the same nodes over and over again

Symptom: `Scheduling rendering for Node, as it was not found or its content is incomplete: No Redis Key "doc--..."
found.` for the same nodes in every iteration, and the release finally exits with code 3
(`FAILED to build a complete content release after 10 rendering attempts`).

`doc--<nodeId>-<dimensions>-<arguments>` is not content - it is the URL → root cache identifier mapping, written by
`CacheUrlMappingAspect` *after* the document's content cache entries were stored. So anything interrupting a
rendering between those two steps leaves content cache entries behind without a mapping entry, and a node in that
state used to be unrecoverable: the re-render was served from the content cache, so the mapping entry was never
written again.

That case is self-healing now (see *Approach to Rendering* above), and both the aspect (`No "doc--..." mapping entry
was written for this rendering`) and the orchestrator (rendering error per node, visible in the backend module) say
so out loud. If you hit it on an older version, flush the affected documents from the Neos content cache
(`./flow flow:cache:flushOne Neos_Fusion_Content` flushes all of it) and start a new content release.

The orchestrator's exit codes: `1` release already completed, `2` empty enumeration, `3` retry limit reached,
`4` rendering errors.

#### Finding out which document is slow

The rendering has a tracer slot at
`Flowpack.DecoupledContentStore.nodeRendering.performanceTracer`. There is no on/off flag: the setting either
names a factory or it is absent, and absent means nothing is recorded. Comment it in:

```yaml
Flowpack:
  DecoupledContentStore:
    nodeRendering:
      performanceTracer:
        factoryObjectName: Flowpack\DecoupledContentStore\NodeRendering\Tracing\PlumberTracerFactory
        options:
          # how long must a document take to be recorded? if 0, everything is recorded.
          minimumDocumentDurationMs: 0
```

**Set `minimumDocumentDurationMs` for a full release.** With `0` every render batch writes a profile; one
measured full release of ~36.000 document renders wrote 1817 of them totalling 17 GB - which `/plumber` cannot
list. Above `0` the threshold decides twice: a document faster than it is not recorded, and a batch of 20
documents in which *nothing* crossed it is not written at all. At 5000 ms that same release would have left
roughly 20 profiles behind, and those are the ones worth opening. A quick release is small enough for `0`.

The shipped implementation needs [sandstorm/plumber](https://github.com/sandstorm/Plumber)
(`composer require --dev sandstorm/plumber`) to be installed, but **not** to be switched on. Leave
`Sandstorm.Plumber.enabled` at `false`: the factory calls `Profiler::startIfNotRunning()`, so a profiling run
begins in the process which renders documents and nowhere else. That keeps the profile list free of the runs
every backend click would otherwise produce, which is what makes the list usable - each entry is one render
worker of one content release. `PLUMBER_ENABLED=0` still switches everything off, including this.

The factory throws if the package is missing - the setting is only ever reachable when somebody configured it on
purpose, so it fails loudly rather than silently doing nothing.

Two spans are recorded per document: `Content Release: Render Document`, same name for every document so a
profiler can sum it into one figure, and `Content Release Document: <contextPath>`, one distinct name per page.

What the resulting profiles look like:

* **One profile per render worker run, not per release, and not per document.** A worker restarts itself after 20
  documents (`RESTART_AFTER_RENDER_COUNT`), and every restart writes its own profile. A release rendered by four
  workers therefore leaves `ceil(documents / 20)` profiles behind - unless `minimumDocumentDurationMs` is set, in
  which case only the batches containing a document above the threshold are kept.
* All of them carry the tag `contentRelease:<releaseId>` and the run options `Content Release` and `Renderer`,
  which is how you collect the profiles belonging to one release.
* The profile starts with the first document, not with the process, because that is where the run is started.
  Bootstrap and command startup are therefore not in it. Use `PLUMBER_ENABLED=1` on a single
  `./flow nodeRendering:renderWorker` call if you need those too.

To write your own tracer - e.g. one that just appends `duration<TAB>url` lines and needs no Plumber at all -
implement `RenderTracerInterface` plus `RenderTracerFactoryInterface` and point `factoryObjectName` at it.

### Testing the Rendering

The behavioral tests need the `neos/behat` package (`composer require --dev neos/behat`), which brings Behat itself
along. Behat is used from the main composer installation:

```bash
cd Packages/Application/Flowpack.DecoupledContentStore/Tests/Behavior
../../../../../bin/behat -c behat.yml.dist
```

(five levels up is the installation root - adjust the path if the package sits somewhere else, for example as a symlink
into a `DistributionPackages` checkout)

The tests bootstrap the `Testing/Behat` context, so the database and the Redis instances they work on are the ones
configured in `Configuration/Testing/Behat/`.

**Every feature file is tagged `@resetRedis`, and that hook calls `FLUSHALL`** on the primary content store - not just
the configured database, but every database on that Redis server. Point the Behat context at a Redis instance whose
contents you are willing to lose; if it is the same server a development content store uses, running the tests wipes it,
including caches other applications keep there.

Behat also supports running single tests or single files - they need to be specified after the config file, e.g.

```bash

# run all scenarios in a given folder
../../../../../bin/behat -c behat.yml.dist Features/ContentStore/

# run all scenarios in the single feature file
../../../../../bin/behat -c behat.yml.dist Features/ContentStore/Basics.feature

# run the scenario starting at line 66
../../../../../bin/behat -c behat.yml.dist Features/ContentStore/Basics.feature:66
```

In case of exceptions, it might be helpful to run the tests with `--stop-on-failure`, which stops the test cases at the first
error. Then, you can inspect the testing database and manually reproduce the bug.

Additionally, `-vvv` is a helpful CLI flag (extra-verbose) - this displays the full exception stack trace in case of errors.

## License

GPL v3
