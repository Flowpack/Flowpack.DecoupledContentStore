@fixtures
@flowEntities
@resetRedis
Feature: Incremental Rendering

  Background:
    Given using the following content dimensions:
      | Identifier | Values | Generalizations |
      | language   | de     | de              |
    And I have the following NodeTypes configuration:
    """yaml
    Flowpack.DecoupledContentStore.Test:Document.StartPage:
      superTypes:
        'Neos.Neos:Site': true

    Flowpack.DecoupledContentStore.Test:Document.Page:
      superTypes:
        'Neos.Neos:Document': true

    Flowpack.DecoupledContentStore.Test:Content.Text:
      superTypes:
        'Neos.Neos:Content': true
      properties:
        text:
          type: string
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |
    And I am in workspace "live" and dimension space point {"language":"de"}
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "sites"           |
      | nodeTypeName    | "Neos.Neos:Sites" |
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId | nodeTypeName                                           | initialPropertyValues                                | nodeName |
      | test            | sites                 | Flowpack.DecoupledContentStore.Test:Document.StartPage | {"title":"Startseite","uriPathSegment":"startseite"} | test     |
      | test-main       | test                  | Neos.Neos:ContentCollection                            | {}                                                   | main     |
      | test-main-t1    | test-main             | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Hallo - this is rendered."}                | t1       |
      | sub             | test                  | Flowpack.DecoupledContentStore.Test:Document.Page      | {"title":"Subpage","uriPathSegment":"nested"}        | sub      |
      | sub-main        | sub                   | Neos.Neos:ContentCollection                            | {}                                                   | main     |
      | sub-main-t1     | sub-main              | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Unterseite"}                               | t1       |
      | sub2            | test                  | Flowpack.DecoupledContentStore.Test:Document.Page      | {"title":"Subpage2","uriPathSegment":"nested2"}      | sub2     |
      | sub2-main       | sub2                  | Neos.Neos:ContentCollection                            | {}                                                   | main     |
      | sub2-main-t1    | sub2-main             | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Unterseite2"}                              | t1       |
    And I have a site for Site Node "test" with site package key "Flowpack.DecoupledContentStore" with domain "test.de"
    And I flush the content cache depending on the modified nodes

    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 3 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured
    # for filling the rendered content release
    When I run the render-orchestrator control loop once for content release "5"
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de/" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered.AFTER
    """
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de/nested" at CSS selector "body .neos-contentcollection":
    """
    BEFOREUnterseiteAFTER
    """
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de/nested2" at CSS selector "body .neos-contentcollection":
    """
    BEFOREUnterseite2AFTER
    """


  Scenario: Incremental rendering only renders the needed changes
    # Now, the Editor does a modification, IN THE LIVE. This should trigger a re-rendering
    When I am in workspace "live" and dimension space point {"language":"de"}
    And the command SetNodeProperties is executed with payload:
      | Key             | Value                |
      | nodeAggregateId | "sub-main-t1"        |
      | propertyValues  | {"text": "New Text"} |
    And I flush the content cache depending on the modified nodes

    When I create a content release "6"
    When I enumerate all nodes for content release "6"
    When I run the render-orchestrator control loop once for content release "6"
    # no rerendering of the sub2 branch needed
    Then I expect the content release "6" to contain the following content for URI "http://test.de/de/nested2" at CSS selector "body .neos-contentcollection":
    """
    BEFOREUnterseite2AFTER
    """
    Then I expect the content release "6" to not contain anything for URI "http://test.de/de/nested"
    # The homepage keeps its rendering: the cache entry of a content collection is tagged with the collection node
    # (Neos.Neos:ContentCollection sets @context.node to the nearest content collection), so a change inside a
    # document further down the tree flushes neither the homepage nor its collection.
    Then I expect the content release "6" to contain the following content for URI "http://test.de/de/" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered.AFTER
    """
    # only the "sub" document
    And the rendering queue for content release "6" contains 1 document

    # however, when we re-run the rendering (in the next iteration), the rendering should converge and work out.
    And I run the renderer for content release "6" until the queue is empty
    Then during rendering of content release "6", no errors occured
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 0
    And I expect the content release "6" to have the completion status success

    Then I expect the content release "6" to contain the following content for URI "http://test.de/de/nested" at CSS selector "body .neos-contentcollection":
    """
    BEFORENew TextAFTER
    """

    Then I expect the content release "6" to contain the following content for URI "http://test.de/de/" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered.AFTER
    """
    And no next content release was triggered
