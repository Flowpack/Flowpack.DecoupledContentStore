@fixtures
@flowEntities
@resetRedis
Feature: Errors while rendering

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
    And I have a site for Site Node "test" with site package key "Flowpack.DecoupledContentStore" with domain "test.de"
    And I flush the content cache depending on the modified nodes

  Scenario: Non-Existing Fusion Prototype for a content Node
    Given I have the following additional NodeTypes configuration:
    """yaml
    Flowpack.DecoupledContentStore.Test:Content.NotExistingInFusion:
      superTypes:
        'Neos.Neos:Content': true
      properties:
        text:
          type: string
    """
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId | nodeTypeName                                                    | initialPropertyValues                 | nodeName |
      | test-main-t1    | test-main             | Flowpack.DecoupledContentStore.Test:Content.NotExistingInFusion | {"text": "Hallo - this is rendered."} | t1       |

    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 1 error occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 4
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de/"
    And I expect the content release "5" to have the completion status failed
    And no next content release was triggered

  Scenario: Non-Existing Fusion Prototype for a document Node
    Given I have the following additional NodeTypes configuration:
    """yaml
    Flowpack.DecoupledContentStore.Test:Document.NotExistingInFusion:
      superTypes:
        'Neos.Neos:Document': true
    """
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId | nodeTypeName                                                     | initialPropertyValues                  | nodeName |
      | sub             | test                  | Flowpack.DecoupledContentStore.Test:Document.NotExistingInFusion | {"title":"foo","uriPathSegment":"sub"} | sub      |

    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 2 nodes
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 1 error occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 4
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de/sub"
    And I expect the content release "5" to have the completion status failed
    And no next content release was triggered

  Scenario: Exception during Fusion invocation
    Given I have the following additional NodeTypes configuration:
    """yaml
    Flowpack.DecoupledContentStore.Test:Content.FusionWithRenderingException:
      superTypes:
        'Neos.Neos:Content': true
    """
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId | nodeTypeName                                                             | initialPropertyValues | nodeName |
      | test-main-ex    | test-main             | Flowpack.DecoupledContentStore.Test:Content.FusionWithRenderingException | {}                    | ex       |

    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 1 error occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 4
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de/"
    And I expect the content release "5" to have the completion status failed
    And no next content release was triggered


  Scenario: Missing Fusion Implementation Class
    Given I have the following additional NodeTypes configuration:
    """yaml
    Flowpack.DecoupledContentStore.Test:Content.MissingImplementationClass:
      superTypes:
        'Neos.Neos:Content': true
    """
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId | nodeTypeName                                                           | initialPropertyValues | nodeName |
      | test-main-ex    | test-main             | Flowpack.DecoupledContentStore.Test:Content.MissingImplementationClass | {}                    | ex       |

    # build content release
    When I create a content release "5"
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 1 error occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 4
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de/"
    And I expect the content release "5" to have the completion status failed
    And no next content release was triggered
