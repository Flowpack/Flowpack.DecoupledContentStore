@fixtures
@flowEntities
@resetRedis
Feature: Basic Rendering

  Background:
    Given using the following content dimensions:
      | Identifier | Values | Generalizations |
      | language   | de     | de              |
    And I have the following NodeTypes configuration:
    """yaml
    Flowpack.DecoupledContentStore.Test:Document.StartPage:
      superTypes:
        'Neos.Neos:Site': true

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
    And the command CreateWorkspace is executed with payload:
      | Key                | Value                |
      | workspaceName      | "user-admin"         |
      | baseWorkspaceName  | "live"               |
      | newContentStreamId | "user-cs-identifier" |
    And I have a site for Site Node "test" with site package key "Flowpack.DecoupledContentStore" with domain "test.de"
    And I flush the content cache depending on the modified nodes

  Scenario: Basic successful render
    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 0
    And I expect the content release "5" to have the completion status success
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de/" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered.AFTER
    """
    And no next content release was triggered


  Scenario: No Re-Render because Editor did a modification in his user workspace
    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured

    # Now, the Editor does a modification, BUT IN THE USER WORKSPACE. This should not trigger a re-rendering
    When I am in workspace "user-admin" and dimension space point {"language":"de"}
    And the command SetNodeProperties is executed with payload:
      | Key             | Value                |
      | nodeAggregateId | "test-main-t1"       |
      | propertyValues  | {"text": "New Text"} |
    And I flush the content cache depending on the modified nodes

    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 0
    And I expect the content release "5" to have the completion status success
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de/" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered.AFTER
    """
    And no next content release was triggered

  Scenario: Re-Render because Editor did a modification in the live workspace
    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # rendering
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured

    # Now, the Editor does a modification, BUT IN WORKSPACE AND PUBLISHES. This should trigger a re-rendering
    When I am in workspace "user-admin" and dimension space point {"language":"de"}
    And the command SetNodeProperties is executed with payload:
      | Key             | Value                |
      | nodeAggregateId | "test-main-t1"       |
      | propertyValues  | {"text": "New Text"} |
    And the command PublishWorkspace is executed with payload:
      | Key           | Value        |
      | workspaceName | "user-admin" |
    And I flush the content cache depending on the modified nodes
    Then a next content release was triggered

    # because a modification has happened, the rendered node cannot be copied over to the finished content release;
    # thus the content release cannot contain anything at this position yet.
    When I continue running the render-orchestrator control loop
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de/"

    # however, when we re-run the rendering (in the next iteration), the rendering should converge and work out.
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 0
    And I expect the content release "5" to have the completion status success
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de/" at CSS selector "body .neos-contentcollection":
    """
    BEFORENew TextAFTER
    """
    And no next content release was triggered
