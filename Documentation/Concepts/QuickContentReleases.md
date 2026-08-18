# Quick Content Releases

Getting a single fixed page live in minutes instead of waiting for a full publish: pause the automatic release, copy
the last release forward, re-render only the pages you name.

This document is about *why* the feature looks the way it does — the measurement it is based on, the design decisions
and the constraints they follow from. For how to use and configure it, see the
[Quick Content Releases](../../README.md#quick-content-releases) chapter of the README.

## 1. The problem: rendering is the release

A release is nothing but a set of Redis keys named `contentStore:<releaseId>:<postfix>`: hashes keyed by URL for
`renderedDocuments` and `renderedMetadata`, a sorted set `meta:urls`, plus whatever an installation registers under
`Flowpack.DecoupledContentStore.redisKeyPostfixesForEachRelease`. Everything the delivery layer reads is in there.

`NodeRenderOrchestrator` already implements a "check whether it is already rendered" loop — but it checks the *Neos
content cache*, per node, for the whole enumeration. On a large site that per-node work over every page in every
dimension is most of the release, and a release which changes a single page still produces a set of keys that is
identical to the previous release everywhere else.

### Measured: rendering is 92% of a full release

A full release of an installation with 18 015 documents and two renderers per document (36 030 render jobs, ~320 MB of
release data), triggered as *Publish All without validation* so the content cache was flushed first — the worst case,
and exactly the situation a bugfix release runs into. Full breakdown in section 9.

| Phase | Duration | Share |
|---|---:|---:|
| prepare | 2 s | 0.1 % |
| enumerate | 47 s | 2.0 % |
| **render** | **2 183 s** | **91.6 %** |
| validate | 145 s | 6.1 % |
| transfer (two additional content stores) | 3 s | 0.1 % |
| switch | 1 s | 0.0 % |
| **total** | **2 384 s ≈ 40 min** | |

Two conclusions come out of this table, and both shaped the design:

* **The copy-forward is aimed at the right thing.** An earlier draft also planned a "transfer only the changed
  documents" optimisation. Transfer is three seconds; that work was dropped.
* **Removing rendering moves the goalposts once.** With rendering gone, the validators become the entire remaining
  cost, which is why validation is scoped to the changed URLs as well (section 6).

## 2. The approach: copy the last release forward, re-render the exceptions

The obvious formulation — "iterate every node; if it was named, re-render it, otherwise take the page from the old
release" — is right, and there is a cheaper way to express it. Copy the entire previous release to the new release ID
at the Redis level first, then enumerate *only* the named nodes. `NodeRenderOrchestrator` then does its normal job on
a two-item enumeration and overwrites those hash fields.

Everything downstream stays as it is. The new release is a complete, valid release; it simply differs from the
previous one in a handful of fields.

| Pipeline stage | Full release | Quick release |
|---|---|---|
| prepare | create release, terminate others | unchanged |
| copy | — | server-side copy of the registered release keys from the live release |
| enumerate | all documents × all dimensions × all renderers | only the named node identifiers |
| render | all workers plus any project-specific render tasks | orchestrator + a few workers, nothing else |
| validate | full consistency validators | scoped to the changed URLs, see section 6 |
| transfer | full copy to every configured content store | unchanged (3 s measured) |
| switch | unchanged | unchanged |

### Measured: a quick release

The same installation, one document changed, published as a quick release:

| | full release | quick release |
|---|---:|---:|
| `enumeration:documentNodes` | 36 030 entries | **56** (28 dimension variants × 2 renderers) |
| copy of 13 registered keys | — | **0.28 s** |
| rendering | 2 183 s | **10 s** |
| schema validation of the documents | 145 s | **2 s** |
| total | ~40 min | **~107 s**, 80 s of it in project-specific validators which still walk the whole release |

Comparing the two releases key by key afterwards: every content key is identical to the byte except inside the
re-rendered URLs, and the cardinality of every hash matches. Two details are worth knowing because they look like
bugs and are not:

* A quick release reports a **smaller release size**. `RedisContentReleaseSizeService` sums `MEMORY USAGE` over *all*
  keys of a release, including the bookkeeping — and the two keys with one entry per enumerated node
  (`renderAttempts`, `enumeration:documentNodes`) are 21 MB for 36 030 documents against 20 KB for 56. The content is
  identical; the number is not comparable between the two pipelines.
* Copied keys can occupy slightly *less* memory than their source. `COPY` rebuilds the value, so it lands in a
  freshly sized encoding without the rehash slack the original accumulated while it was being filled.

## 3. Which keys are copied is configuration

`RedisReleaseCopyService` copies every release key registered with `copyOnQuickRelease: true`. This flag is the main
reason the copy belongs in this package rather than in a site package: only the installation knows which of its keys
carry content the delivery layer reads and which describe the build of one particular release.

The default is `false`, and an absent flag means `false`. That is the safe direction: a key which should have been
copied shows up as missing content, while a key which should not have been copied describes a *different* release and
is much harder to notice. The package's own content keys (`data`, `meta:urls`, `renderedDocuments`,
`renderedMetadata`) are `true`; its enumeration, job queue and statistics keys are `false`, because the quick
pipeline writes those itself.

The copy is the native `COPY` command (Redis 6.2+) — server-side, nothing over the wire, no Lua. Since the package
cannot assume 6.2, the service reads `INFO server` and aborts with a message naming the required version rather than
failing cryptically on an older server.

### The source release is the live one, and it is verified

The copy source is read from `contentStore:current`: by definition a release which passed validation and was switched
live. That alone is not enough, because switching to an arbitrary release by hand is possible, so before copying
anything the service checks that the source's `meta:info` status is `success` and that the required keys it is about
to inherit exist. If either check fails, it aborts and tells the operator to run a normal release — it never falls
back to "the newest release we can find".

That second check is narrower than it first looks, and deliberately so: it covers the keys marked **both**
`isRequired` and `copyOnQuickRelease`. Keys the quick release builds itself do not have to exist in the source, and
more importantly, `isRequired` means "must exist if it is transferred" everywhere else in this package —
`RedisReleaseSwitchService` and `ContentReleaseSynchronizer` both test it only for keys they actually transfer. An
installation which retires a key by setting `transfer: false` therefore leaves it registered as required, and a copy
which demanded every required key would refuse to build on a perfectly good release.

## 4. A separate pipeline

`do_quick_content_release` in `pipelines_template.yml` is a pipeline of its own rather than a flag on
`do_content_release`, because that one is configured with `queue_strategy: replace` — a queued automatic release
would silently replace a quick release.

For the same reason the quick pipeline does **not** use `queue_strategy: replace` either: a quick release publishes
exactly the documents somebody named, so a queued one must not be thrown away by the next one. Instead
`ContentReleaseManager::startQuickContentRelease()` refuses to schedule a second one while the first has not gone
live, because the copy source is resolved when the release is scheduled: a queued release would build on the release
the first one is about to replace and drop that change without a word. `Jobs::waiting()` alone is not that check —
it means "never started", which a job cancelled while it was still queued never was either, and such a job stays in
prunner's list until it falls out of `retention_count`. It is therefore combined with `isCompleted()`, or one
cancelled job would block every quick release for as long as it is kept.

Details of the pipeline which are not obvious from reading it:

* The copy task is called **`prepare_copy_previous_release`**, not `copy_previous_release`. The backend module's
  details view groups tasks by name prefix (`prepare_`, `enumerate_`, `render_`, `validate_`, `transfer_`,
  `switch_`), so a task outside those prefixes is not rendered anywhere. Every step the UI shows is prefix-driven,
  which is why adding a phase needed no UI change at all.
* **Fewer render workers** than a full release. A quick release enumerates a handful of documents; further workers
  only add Flow bootstraps.
* **No `flushContentCacheIfRequired`.** It flushes the whole content cache, while the quick enumerator flushes
  exactly the nodes it re-renders.
* **Validation runs unconditionally**, not behind a `validate` variable. Scoped validation of a quick release costs
  nothing worth skipping.
* Every task which is identical to the one in `do_content_release` should be a **YAML alias** of it, so the two
  pipelines cannot drift apart. `depends_on` names a task and resolves per pipeline, which is what makes aliasing
  whole tasks across pipelines safe.

### Scheduling guards

`ContentReleaseManager::startQuickContentRelease()` refuses in two cases, both with a message written to be read by
the person who pressed the button:

* **Nothing is live to copy.** The release would hold the named documents and nothing else.
* **Another quick release is running or queued.** This is the subtle one: the copy source is resolved when the
  release is *scheduled*, so a second quick release queued behind the first would copy the release the first is
  about to replace, and silently undo it.

Like *Publish All*, a quick release is an explicit request and is deliberately **not** blocked by the pause switch —
a paused automatic release is the situation it exists for. `cancelAllRunningContentReleases()` and
`cancelRunningContentRelease()` cover both pipelines, since a running quick release ends up switched live just like a
full one.

## 5. Enumeration of named nodes

`QuickPublishNodeEnumerator` flushes the content cache for each target node first, then writes the enumeration for
those nodes only, through `NodeRenderingExtensionManager::enumerateDocumentNode()` so that every configured renderer
is covered.

The deprecated `nodeEnumerated` signal is emitted for that enumeration too, through
`NodeEnumerator::emitNodesEnumerated()` — a signal is identified by the class which declares it, so emitting it from
the quick enumerator would reach nobody. A slot on it derives further variants of a document (pagination, filter
arguments) and writes them into the enumeration itself; without the signal those variants would keep the rendering of
the release which was copied while the document itself is re-rendered, so a page and its own paginated variants would
go live disagreeing with each other. They are not written into `quickPublish:changedUrls`, though, which only knows
the documents that were named — a scoped validator does not see them.

The hidden / orphaned / node-type guards are shared with the full enumeration through
`NodeEnumeration/Domain/Service/DocumentNodeFilter`, which expresses `nodeTypeWhitelist` twice: as the FlowQuery
filter string the full enumeration passes to `find()`, and as a check for a single node, which is what an enumerator
starting from identifiers needs. The node-type check is deliberately **not** part of the shared `skipReason()`:
`NodeEnumerator` adds the site node to its result without passing it through the FlowQuery filter, so folding the
node type into the shared guard would silently drop site nodes whose type is excluded.

One failure aborts the task rather than shrinking the release quietly: an enumeration which ends up empty. A quick
release which renders nothing would publish the release it copied and look like a successful publish while the change
is nowhere. An identifier which resolves in no site and dimension is a skip like any other, because a document can be
deleted between the confirmation page and the confirmation itself. Everything skipped is logged as a warning rather
than at debug level, because somebody asked for those documents by hand.

The identifiers are a value object, `QuickPublish/Dto/NodeIdentifiers`, which rejects anything that is not a UUID.
This is not cosmetic: the list travels through a pipeline variable into a shell command, so unvalidated input is a
command-injection hole. The check lives at the point where the list is read, not only in the backend form.

Variants of a node are resolved through `NodeContextCombinator::nodeVariantsWithSiteNode()` — via `sites()` and
`siteNodeInContexts()` rather than `nodeInContexts()`, because the orphan check needs the site node.
`getNodeByIdentifier()` searches the whole content repository rather than one site, so it answers for every site
alike: each candidate is matched against the site node by its `findNodePath()` before it is handed out, or a
multi-site installation would pair every node with the first site and report it as orphaned. Once a site has claimed
the node the remaining ones are skipped, since a node belongs to exactly one site.

That lookup shows invisible content regardless of `nodeRendering.recurseHiddenContent`, which defaults to `false`.
The setting is about recursing into hidden content while walking the tree; applied to a lookup by identifier it makes
a hidden page indistinguishable from one which does not exist, and the first hidden page tried reported "not found in
any site and dimension". `siteNodeInContexts()` therefore takes an `$invisibleContentShown` override, `NULL` keeping
the configured behaviour for the full enumeration. Hidden pages are still not published — the skip reason says
"hidden", on the confirmation page and in the pipeline log alike.

Because of that override, `skipReasonForNamedNode()` also has to walk the pages **above** the node, which
`skipReason()` does not: the full enumeration descends from the site node in a context which hides them, so a
document below a hidden page is not in the content store at all. Naming it in a quick release would resolve, render
and publish it — a page live until the next full release quietly removes it again. The skip reason for that is
"below a hidden page". It is the node's own `hidden` flag which is checked, so a page hidden by
`hiddenBeforeDateTime` / `hiddenAfterDateTime` is not covered on either level.

## 6. Validation scoped to the changed URLs

With rendering gone, validation is the whole cost of a quick release. It is also almost entirely wasted work: after a
copy-forward, every document except the handful just re-rendered is byte-for-byte what the previous release was
validated on.

* The enumerator writes the URLs it produced into the release key `quickPublish:changedUrls`, registered with
  `transfer: false` and `isRequired: false`. It is written with `sAdd`, and an empty set is never written, so "the key
  exists" and "this is a quick release" are the same statement.
* `QuickPublish/ContentReleaseScope` is the one accessor the whole pipeline shares. `getChangedUrls()` returns `NULL`
  for an ordinary release, meaning "validate everything", and the URL list for a quick release. Validators which know
  nothing about quick releases keep working unchanged; validators which opt in narrow their read from `hGetAll` to
  `hMGet`. `countPublishedUrls()` — the `meta:urls` cardinality — is there for the threshold check, which needs it
  for the live release as well as the new one.
* The URLs are built with `NodeRenderingUriService::buildNodeUri()`, the same call `DocumentRenderer` makes before
  rendering, so the strings match the keys the release is written under, per renderer and per dimension. They are
  built in a **second pass** after every identifier has been resolved, not while enumerating: `buildNodeUri()` marks
  the security context as initialized as a side effect, which would change what the node lookups of the remaining
  identifiers are allowed to see. A URL which cannot be built aborts the task rather than being left out, because a
  missing entry would silently exclude a changed document from validation.

`NULL` versus an empty list is the trap in this API. A validator which reads "no scope" as "no URLs to check" waves
every ordinary release through, so the two cases have to be handled explicitly.

### `contentReleaseValidation:validate` had to be adapted, not just scoped

This is a trap rather than an optimisation. The validator compares the enumeration count of the new release against
the live one and aborts below 70%. A quick release deliberately enumerates a handful of documents instead of all of
them, so it is counted by its number of *published* URLs instead, which after a copy-forward equals the release it
was built on. Both sides of the comparison are measured that way, each release on its own terms: as the new release a
quick one would fail the check every single time, and as the currently live release it would put the threshold at a
handful of URLs and let the next full release pass no matter how much of the site that one lost.

Any project validator which reasons about the size of the enumeration has the same problem, and the failure mode is
the good one — the release is refused rather than published wrongly — but it needs the same treatment.

## 7. The pause switch

A quick release only makes sense while ordinary releases are held back, so the pause is part of the same design.

The state is a Redis hash on the primary instance, `contentStore:automaticReleasesPaused`, outside the per-release
key space so pruning never touches it, holding `pausedAt`, `accountId` and `suppressedReleaseCount`. A hash rather
than a JSON string so the counter can be raised with `HINCRBY`, without a read-modify-write race against a
concurrent publish. `HINCRBY` creates the hash it counts in, though, so `countSuppressedRelease()` runs it from a Lua
script guarded by `HEXISTS … pausedAt`: an increment racing a resume would otherwise leave a key behind holding
nothing but a counter, and that key is outside the space pruning cleans. `isPaused()` tests the `pausedAt` field
rather than the key for the same reason — it is that field which records the pause.

`ContentReleaseManager::startIncrementalContentRelease()` is the single entry point for **all** automatic releases —
workspace publish, asset change, re-render after a rendering error — so one gate there covers every trigger.
`startFullContentRelease()` is deliberately not gated, so *Publish All* keeps working.

Pause, resume and quick publish sit behind `Flowpack.DecoupledContentStore:ReleaseControl`, separate from the module
privilege so that an installation which lets editors watch the module can still restrict who may stop everybody's
publishes. The buttons are hidden with `Security.hasAccess()` rather than only protected, so nobody is offered a
control which then throws.

### Why the content-module warning is a data source

A pause stops *everybody's* publishes, so the warning has to reach editors inside the Neos content module, not only
administrators inside the Content Store module. The state is published as a Neos **data source**, not as an action on
the backend module: `ModulePrivilege` expands internally into a method privilege over every action of the module
controller, so a status action there would be readable only by the people who already have module access — precisely
not the editors the warning is for. Data sources are granted to `Neos.Neos:AbstractEditor` by Neos itself, need no
route of their own, and the script reaches them through `_NEOS_UI_routes.core.service.dataSource`, so no URI is
hardcoded. The data source returns nothing but the flag, the timestamp, the account and the counter, so widening its
access costs nothing. It must not be `final`: Neos instantiates data sources with `new`, and an unproxied class gets
no property injection.

Two constraints on the script which registers under `Neos.Neos.Ui.resources.javascript`, both learned the hard way:
it must run **before** `Neos.Neos.UI:Host` and **without `defer`**. The UI host reads the inlined `_NEOS_UI_*`
globals through `getInlinedData()`, which does `delete window[...]` immediately after reading, so a deferred script
finds `_NEOS_UI_routes` already gone and cannot build the endpoint URI. The script therefore captures the route table
at evaluation time and waits for `DOMContentLoaded` before touching the DOM.

The warning is translated **server-side in the data source**: Neos exposes no translation API to plain JavaScript,
and the service controllers already set the current locale from the backend user's interface language, so the message
arrives in the language the reader chose and the script only prints it. Timestamps go through
`BackendUi/BackendDateFormatter` on both paths, so the same moment does not read differently depending on which
screen shows it.

## 8. The backend UI

Three actions — the form, the confirmation page, the release — rendered through the package's existing `FusionView`
setup.

The confirmation page deliberately does **not** show the live URL of a document.
`NodeRenderingUriService::buildNodeUri()` is the only thing which can produce the real URL — routing turns dimension
values into path prefixes, so a URL assembled from `uriPathSegment` properties would be wrong on any multi-language
site — and it marks the security context as initialized and swaps in a fake `ActionRequest` as a side effect. That is
fine in a CLI render and not fine in a backend request, where the same singleton is still serving the page. The row
shows title, node path, dimensions, node type, identifier and a link into the Neos backend instead, which answers
"are these the documents I mean?" without touching the request's security context.

The reason a row gives for not being published comes from `DocumentNodeFilter::skipReasonForNamedNode()`, the same
method the enumerator calls, so what the confirmation page says will be skipped is exactly what the pipeline then
skips rather than a second implementation of the same rules. An identifier which resolves nowhere becomes a row
rather than an error: somebody who pasted five identifiers needs to see which one is wrong.

The enumeration skips such an identifier as well, instead of failing the task. Otherwise the page's promise would
not hold in the one case it cannot rule out: a document deleted between the preview and the confirmation would take
the whole release down with it — with the release already marked `running` and its enumeration cleared — rather than
publishing the other four documents the editor asked for. `DocumentNodeFilter::NOT_FOUND_SKIP_REASON` is the wording
both sides use. What still fails the task is a list in which *nothing* can be published, because the release would
then be a copy of the live one under a new identifier.

One trap for anyone adding forms to this module: Neos dispatches a backend module as a sub-request and reads its
arguments from the `moduleArguments` namespace alone, so a field posted at the top level never reaches the action and
fails with "required argument is missing". Both forms name their fields `moduleArguments[…]`; `__csrfToken` belongs
to the outer request and stays where it is. The module's other buttons never hit this, because they carry their
parameters in a `formaction` URI, which the UriBuilder namespaces already.

## 9. Appendix: the measured full release

18 015 documents, two renderers per document, ~320 MB, triggered as *Publish All without validation*
(`flushContentCache: true`, `validate: false`). Task start offsets and durations, project-specific tasks folded into
one line:

```
task                                    start+s     dur s
prepare_finished                              0       2.0
enumerate_nodes                               2      47.2
project-specific enumerate/render tasks       2     ~219 (longest)
enumerate_finished                           49       0.0
render_orchestrator                          49    2181.2
render_1 … render_20                         49    ~2182
render_finished                            2232       0.0
validate_content                           2232       0.0     (skipped, validate=false)
validate_documents_against_schema          2232     144.3
validate_finished                          2377       0.9
transfer_content (two content stores)      2378       2.8
transfer_finished                          2380       0.6
switch_*                                   2381       1.1
```

Notes that matter for the design:

* The orchestrator needed **two iterations**: 2 142 s for the first, 34 s for the second. The second iteration is the
  copy-into-release pass over everything the first one rendered — which is exactly the work a copy-forward replaces.
* All project-specific render tasks finish inside the document-rendering window, so they never sit on the critical
  path and cost nothing extra in a full release. In a quick release they are not run at all, which is why the keys
  they write need `copyOnQuickRelease: true`.
* Transfer moved the whole release to both additional content stores in 2.8 s each. That was a local Docker network,
  so a production network is slower — but with rendering at 2 183 s, transfer would have to get two orders of
  magnitude worse before it mattered.
* Schema validation at 144 s is untouched by the rendering work and becomes the dominant cost of a quick release,
  hence section 6.
