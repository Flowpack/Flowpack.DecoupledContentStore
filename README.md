# Decoupled Content Store based on Redis

This is the 2nd generation of a Two-Stack CMS package for Neos.

**This Package is used in production in a bigger instance.**

The Content Store package is one part of a [Two-Stack CMS](https://martinfowler.com/articles/two-stack-cms/)
solution with Neos. A Two-Stack architecture separates editing and publishing
from the delivery of content. This is also an architecture that's suitable to+
integrate Neos content in various other systems without adding overhead during
delivery.

The first iteration was not open source; developed jointly by [Networkteam](https://networkteam.com/) and [Sandstorm](https://sandstorm.de/)
and is in use for several large customers. The second iteration (this project) is developed from scratch, in an open-source
way, based on the learnings of the first iteration. Especially the robustness has been greatly increased.

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
-Integration with Neos workspace publishing for automatic incremental
  publishing to the Content Store
- Configurable Content Store format, decoupled from the internal representation in Neos.
- Extensibility: Enrich content releases with your custom data.
- Allows parallel rendering
- Allows copying the content releases to different environments.
- Allows rsyncing persistent assets around (should you need it)
- Backend module with overview of _content releases_ (current release, switching
  releases, manual publish)

This project is using the go-package [prunner](https://github.com/Flowpack/prunner) and [its Flow Package wrapper](https://github.com/Flowpack/Flowpack.Prunner)
as the basis for orchestrating and executing a content release.

## Requirements

- Redis
- Sandstorm.OptimizedCacheBackend recommended
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

## Extensibility

### Custom `pipelines.yml`

Crafting a custom `pipelines.yml` is the main extension point for doing additional work (f.e. additional enumeration
or rendering).

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

### Testing the Rendering

For executing behavioral tests, install the `neos/behat` package and run `./flow behat:setup`. Then:

```bash
cd Packages/Application/Flowpack.DecoupledContentStore/Tests/Behavior
../../../../bin/behat -c behat.yml.dist
```

Behat also supports running single tests or single files - they need to be specified after the config file, e.g.

```bash

# run all scenarios in a given folder
../../../../bin/behat -c behat.yml.dist Features/ContentStore/

# run all scenarios in the single feature file
../../../../bin/behat -c behat.yml.dist Features/ContentStore/Basics.feature

# run the scenario starting at line 66
../../../../bin/behat -c behat.yml.dist Features/ContentStore/Basics.feature:66
```

In case of exceptions, it might be helpful to run the tests with `--stop-on-failure`, which stops the test cases at the first
error. Then, you can inspect the testing database and manually reproduce the bug.

Additionally, `-vvv` is a helpful CLI flag (extra-verbose) - this displays the full exception stack trace in case of errors.

## TODO

- clean up of old content releases
  - in Content Store / Redis
- generate the old content format
- (SK) error handling tests
- force-switch possibility
- (AM) UI
- check for TODOs :)

## Missing Features from old

data-url-next-page (or so) not supported

## License

GPL v3
