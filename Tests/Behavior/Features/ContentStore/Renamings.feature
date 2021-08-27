@fixtures
@resetRedis
Feature: Renamings before rendering, and during a rendering.

  We want to take care of the following cases:


  Deletion of a page BEFORE it is rendered (but after enumeration)
  - Renderer Job will fail with an error
  - -> we need to start a new enumeration (to converge to a working content release)

  Hiding of a page BEFORE it is rendered (but after enumeration)
  - Renderer Job will fail with an error
  - -> we need to start a new enumeration (to converge to a working content release)

  Changing the node name BEFORE it is rendered should have NO INFLUENCE on the rendering.
  - should succeed successfully.
  - -> CANNOT TEST BECAUSE OF CORE BUG (see explanation in testcase below)

  Changing the URL segment of a document without subpages BEFORE it is rendered (but after enumeration)
  - should succeed.
  - the content release should contain ONLY the new URL; not the old one. (because the URL under which the element is stored in the content release is determined DURING rendering)

  EXTENDED: Changing the URL segment of a document without subpages BEFORE it is rendered (but after enumeration)
  - on the parent page, a link to the subpage exists.
  - we first render the parent page, then we change the URL segment, then we render the child page.
  - -> Behavior should be as described in **1** below.

  (TODO - not yet tested) Changing the URL segment of a document WITH subpages
  - ASSUME the following Fusion is REMOVED:
  - root.@cache.entryTags.2 >
  - documentRendering.@cache.entryTags.2 >
  - -> a change of the parent document node should flush the caches of all childen. -> they should all be re-rendered.
  (TODO - not yet tested) if CONTENT of the parent page is changed this should NOT lead to re-rendering the subpages.


  BACKGROUND EXPLANATION - General Behavior of Node Caches:

  ConvertUrisImplementation calls FusionRuntime::addCacheTag()
  -> NodeDynamicTag_UUID entries (uuid is the target Node's UUID)
  -> AssetDynamicTag_UUID entries (uuid is the target Asset's UUID)

  in ContentCacheFlusher in CORE: there is a bug, because AssetDynamicTag is built like:
  'AssetDynamicTag_' . $workspaceHash . '_' . $assetIdentifier
  - -> these tags NEVER exist (because workspaceHash is NOT included when generating the cache tag)
  FixedAssetHandlingInContentCacheFlusherAspect fixes the CORE bug for Assets

  BUT: NodeDynamicTag_UUID is NEVER flushed.
  -> this hints to a core bug: Broken links with plain Neos. This is fixed with FixedNodeLinkHandlingInContentCacheFlusherAspect.
  **1** -> With the fix of FixedNodeLinkHandlingInContentCacheFlusherAspect, we expect the following:
  - Example: PageA contains link to PageB
  - PageA is already rendered in the cache, PageB is not in the cache because it was modified.
  - Enumeration contains both PageA and B
  - Render Orchestrator would add PageA to Content Release, and schedule PageB for rendering
  - !! NOW, the URI path segment changes for PageB.
  - Cache is flushed for PageB
  - BUG in Core (hotfix with FixedNodeLinkHandlingInContentCacheFlusherAspect:) Cache is flushed for PageA
  - -> This schedules a new incremental render (on waitlist in prunner)
  - PageB is rendered with the new URL.
  - in the next render iteration, the Orchesrator adds PageB to the content release
  - -> THERE IS A BROKEN LINK IN THE CONTENT RELEASE. (on PageA)
  - -> THIS BROKEN LINK GOES LIVE FOR A WHILE.
  - Now, the new (incremental) content release starts.
  - PageA is rerendered with the correct link.

  WITHOUT fixing this issue: the broken link stays until either a full re-render or another way PageA's cache is flushed.


  Background:
    Given I have the following NodeTypes configuration:
    """
    Flowpack.DecoupledContentStore.Test:Document.StartPage:
      superTypes:
        'Neos.Neos:Document': true
      properties:
        uriPathSegment:
          type: string

    Flowpack.DecoupledContentStore.Test:Document.Page:
      superTypes:
        'Neos.Neos:Document': true
      properties:
        uriPathSegment:
          type: string

    Flowpack.DecoupledContentStore.Test:Content.Text:
      superTypes:
        'Neos.Neos:Content': true
      properties:
        text:
          type: string

    """
    Given I am authenticated with role "Neos.Neos:Editor"
    Given I have a site for Site Node "test" with site package key "Flowpack.DecoupledContentStore" with domain "test.de"
    And I have the following nodes:
      | Identifier                           | Path                     | Node Type                                              | Properties                                                                                                         | HiddenInIndex | Language |
      | f8e3c037-3e64-4ffd-a059-505dcdcd5bf7 | /sites                   | unstructured                                           | []                                                                                                                 | false         | de       |
      | fd3fe65c-703f-4c6f-8ab1-cbfc34213117 | /sites/test              | Flowpack.DecoupledContentStore.Test:Document.StartPage | {"title":"Startseite","uriPathSegment":"startseite"}                                                               | false         | de       |
      | 3acb96ea-f9a4-4b7c-b7fd-b0f069cf4b33 | /sites/test/main         | Neos.Neos:ContentCollection                            | {}                                                                                                                 | false         | de       |
      | 40e19944-2b5e-43c8-a3eb-c89c6a6ada1a | /sites/test/main/t1      | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Hallo - this is rendered. <a href=\"node://d8d2b4d7-305c-4c25-9823-f34c81df2ec2\">Link to /nested2</a>"} | false         | de       |
      | d75e5424-8256-42cc-81a4-09e5a00550ed | /sites/test/sub          | Flowpack.DecoupledContentStore.Test:Document.Page      | {"title":"Subpage","uriPathSegment":"nested"}                                                                      | false         | de       |
      | 222664a1-aeba-45ab-a323-32e7a0b651b0 | /sites/test/sub/main     | Neos.Neos:ContentCollection                            | {}                                                                                                                 | false         | de       |
      | 89ab6cca-bab2-4578-bce6-c1dcbf6d4d6b | /sites/test/sub/main/t1  | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Unterseite <a href=\"node://d8d2b4d7-305c-4c25-9823-f34c81df2ec2\">Link to /nested2</a>"}                | false         | de       |
      | d8d2b4d7-305c-4c25-9823-f34c81df2ec2 | /sites/test/sub2         | Flowpack.DecoupledContentStore.Test:Document.Page      | {"title":"Subpage2","uriPathSegment":"nested2"}                                                                    | false         | de       |
      | 875c4cd3-7d95-496f-954a-6f8951566936 | /sites/test/sub2/main    | Neos.Neos:ContentCollection                            | {}                                                                                                                 | false         | de       |
      | 5a0cbcb2-df21-4c33-b2ae-817e81109951 | /sites/test/sub2/main/t1 | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Unterseite2"}                                                                                            | false         | de       |
    And I flush the content cache depending on the modified nodes

  Scenario: Deletion of a page BEFORE it is rendered (but after enumeration)
    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 3 nodes

    # Preparing: delete "sub2"
    When I get a node by path "/sites/test/sub2" with the following context:
      | Workspace | Language |
      | live      | de       |
    And I remove the node

    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 1 error occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 4
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de/nested2"
    And I expect the content release "5" to have the completion status failed
    And a next content release was triggered


  Scenario: Hiding of a page BEFORE it is rendered (but after enumeration)
    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 3 nodes

    # Preparing: hide "sub2"
    When I get a node by path "/sites/test/sub2" with the following context:
      | Workspace | Language |
      | live      | de       |
    And I hide the node

    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 1 error occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 4
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de/nested2"
    And I expect the content release "5" to have the completion status failed
    And a next content release was triggered


#  Scenario: Changing the node name BEFORE it is rendered should have NO INFLUENCE on the rendering.
#    # build content release
#    When I create a content release "5"
#    When I enumerate all nodes for content release "5"
#    Then the enumeration for content release "5" contains 3 nodes
#
#    # Preparing: rename node
#    When I get a node by path "/sites/test/sub2" with the following context:
#      | Workspace  | Language |
#      | user-admin | de       |
#    And I rename the node to "sub2a"
#    And I publish unpublished nodes of workspace "user-admin"
#    ############################################################################################
#    # BUG: at this point, it seems that the node on live is still called /sites/test/sub2, DESPITE the sub nodes
#    #      being adjusted to be called /sites/test/sub2a/.... I'd say this is a core bug:
#    #      Workspace::publishNode -> calls Workspace::replaceNodeData -> there, we check for the PARENT NODE (which is the same)
#    #      but NOT for updated node names.
#    #
#    # WHAT TO DO ABOUT IT: I guess at this point nothing. It is not possible to rename nodes through the UI; so I am unsure
#    #                      this case can ever happen.
#    ############################################################################################
#
#    # for filling the render queue:
#    When I run the render-orchestrator control loop once for content release "5"
#    And I run the renderer for content release "5" until the queue is empty
#    Then during rendering of content release "5", 0 errors occured
#    # for filling the rendered content release
#    When I continue running the render-orchestrator control loop
#    Then I expect the render-orchestrator control loop to exit with status code 0
#    # we only renamed the node name, not the node's uriPathSegment
#    Then I expect the content release "5" to contain the following content for URI "http://test.de/de/nested2" at CSS selector "body .neos-contentcollection":
#    """
#    BEFOREUnterseite2AFTER
#    """
#    And I expect the content release "5" to have the completion status success
#    # technically we would not need this, but there is no way to prevent it.
#    And a next content release was triggered




  Scenario: Changing the node name BEFORE it is rendered should have NO INFLUENCE on the rendering.
    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 3 nodes

    # Preparing: rename URI path segment
    When I get a node by path "/sites/test/sub2" with the following context:
      | Workspace  | Language |
      | user-admin | de       |
    And I set the node property "uriPathSegment" to "foo"
    And I publish unpublished nodes of workspace "user-admin"

    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 0 errors occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 0
    # we only renamed the node name, not the node's uriPathSegment
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de/nested2"
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de/foo" at CSS selector "body .neos-contentcollection":
    """
    BEFOREUnterseite2AFTER
    """
    And I expect the content release "5" to have the completion status success
    # technically we would not need this, but there is no way to prevent it.
    And a next content release was triggered


  Scenario: EXTENDED: Changing the URL segment of a document without subpages BEFORE it is rendered (but after enumeration)
    # - Example: /sites/test contains link to /sites/test/sub

    # Preparation: build full release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 3 nodes
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 0

    Then I expect the content release "5" to not contain anything for URI "http://test.de/de/foo"
    Then I expect the content release "5" to contain the following HTML content for URI "http://test.de/de" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered. <a href="/de/nested2">Link to /nested2</a>AFTER
    """
    Then I expect the content release "5" to contain the following HTML content for URI "http://test.de/de/nested" at CSS selector "body .neos-contentcollection":
    """
    BEFOREUnterseite <a href="/de/nested2">Link to /nested2</a>AFTER
    """
    And I expect the content release "5" to have the completion status success

    # Preparation: modify Page B's content to ensure it is not in the cache.
    When I get a node by path "/sites/test/sub2/main/t1" with the following context:
      | Workspace  | Language |
      | user-admin | de       |
    And I set the node property "text" to "nested modified"
    And I publish unpublished nodes of workspace "user-admin"
    And I flush the content cache depending on the modified nodes
    And a next content release was triggered

    # - here, /sites/test is not in the cache (DescendantOf_ modification), /sites/test/sub2 is not in the cache (DescendantOf_ modification)
    # and /sites/test/sub is IN THE CACHE.

    # NOW, start a new enumeration and a new content release.
    When I create a content release "6"
    When I enumerate all nodes for content release "6"
    Then the enumeration for content release "6" contains 3 nodes
    # - Render Orchestrator would add /sites/test/sub to Content Release, and schedule /sites/test and /sites/test/sub2 for rendering
    When I run the render-orchestrator control loop once for content release "6"

    # - Rename Uri Path Semgment to "foo" for the sub2 page
    When I get a node by path "/sites/test/sub2" with the following context:
      | Workspace  | Language |
      | user-admin | de       |
    And I set the node property "uriPathSegment" to "foo"
    And I publish unpublished nodes of workspace "user-admin"
    And I flush the content cache depending on the modified nodes

    # - Cache is flushed for sub2
    # - Cache should also be flushed for /sites/site and /sites/site/sub because of FixedNodeLinkHandlingInContentCacheFlusherAspect
    # - however, /sites/site/sub has already a been added to the content release with the old URI -> BROKEN LINK which goes live.
    And I run the renderer for content release "6" until the queue is empty
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 0

    Then during rendering of content release "6", 0 errors occured
    Then I expect the content release "6" to not contain anything for URI "http://test.de/de/nested2"
    # no broken link :-)
    Then I expect the content release "6" to contain the following HTML content for URI "http://test.de/de" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered. <a href="/de/foo">Link to /nested2</a>AFTER
    """
    # THIS IS THE BROKEN LINK
    Then I expect the content release "6" to contain the following HTML content for URI "http://test.de/de/nested" at CSS selector "body .neos-contentcollection":
    """
    BEFOREUnterseite <a href="/de/nested2">Link to /nested2</a>AFTER
    """
    Then I expect the content release "6" to contain the following content for URI "http://test.de/de/foo" at CSS selector "body .neos-contentcollection":
    """
    BEFOREnested modifiedAFTER
    """
    And I expect the content release "6" to have the completion status success
    And a next content release was triggered

    # the next content release will contain the fixed link in
    When I create a content release "7"
    When I enumerate all nodes for content release "7"
    Then the enumeration for content release "7" contains 3 nodes
    When I run the render-orchestrator control loop once for content release "7"
    And I run the renderer for content release "7" until the queue is empty
    When I continue running the render-orchestrator control loop

    Then I expect the render-orchestrator control loop to exit with status code 0
    Then during rendering of content release "7", 0 errors occured
    Then I expect the content release "6" to not contain anything for URI "http://test.de/de/nested2"
    # no broken link :-)
    Then I expect the content release "7" to contain the following HTML content for URI "http://test.de/de" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered. <a href="/de/foo">Link to /nested2</a>AFTER
    """
    # BROKEN LINK -> fixed
    Then I expect the content release "7" to contain the following HTML content for URI "http://test.de/de/nested" at CSS selector "body .neos-contentcollection":
    """
    BEFOREUnterseite <a href="/de/foo">Link to /nested2</a>AFTER
    """
    Then I expect the content release "7" to contain the following content for URI "http://test.de/de/foo" at CSS selector "body .neos-contentcollection":
    """
    BEFOREnested modifiedAFTER
    """

    And I expect the content release "7" to have the completion status success

