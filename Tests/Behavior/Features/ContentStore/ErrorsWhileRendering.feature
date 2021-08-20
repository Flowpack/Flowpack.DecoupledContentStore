@fixtures
@resetRedis
Feature: Errors while rendering

  Background:
    Given I have the following NodeTypes configuration:
    """
    Flowpack.DecoupledContentStore.Test:Document.StartPage:
      superTypes:
        'Neos.Neos:Document': true

    Flowpack.DecoupledContentStore.Test:Document.Page:
      superTypes:
        'Neos.Neos:Document': true
    """
    Given I am authenticated with role "Neos.Neos:Editor"
    Given I have a site for Site Node "test" with site package key "Flowpack.DecoupledContentStore" with domain "test.de"
    And I have the following nodes:
      | Path             | Node Type                                              | Properties                                           | HiddenInIndex | Language |
      | /sites           | unstructured                                           | []                                                   | false         | de       |
      | /sites/test      | Flowpack.DecoupledContentStore.Test:Document.StartPage | {"title":"Startseite","uriPathSegment":"startseite"} | false         | de       |
      | /sites/test/main | Neos.Neos:ContentCollection                            | {}                                                   | false         | de       |
    And I flush the content cache depending on the modified nodes

  Scenario: Non-Existing Fusion Prototype for a content Node
    Given I have the following additional NodeTypes configuration:
    """
    Flowpack.DecoupledContentStore.Test:Content.NotExistingInFusion:
      superTypes:
        'Neos.Neos:Content': true
    """
    Given I create the following nodes:
      | Path                | Node Type                                                       | Properties                            | HiddenInIndex | Language |
      | /sites/test/main/t1 | Flowpack.DecoupledContentStore.Test:Content.NotExistingInFusion | {"text": "Hallo - this is rendered."} | false         | de       |

    # build content release
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 1 error occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 4
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de"
    And I expect the content release "5" to have the completion status failed

  Scenario: Non-Existing Fusion Prototype for a document Node
    Given I have the following additional NodeTypes configuration:
    """
    Flowpack.DecoupledContentStore.Test:Document.NotExistingInFusion:
      superTypes:
        'Neos.Neos:Document': true
    """
    Given I create the following nodes:
      | Path            | Node Type                                                        | Properties                             | HiddenInIndex | Language |
      | /sites/test/sub | Flowpack.DecoupledContentStore.Test:Document.NotExistingInFusion | {"title":"foo","uriPathSegment":"sub"} | false         | de       |

    # build content release
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

  Scenario: Exception during Fusion invocation
    Given I have the following additional NodeTypes configuration:
    """
    Flowpack.DecoupledContentStore.Test:Content.FusionWithRenderingException:
      superTypes:
        'Neos.Neos:Content': true
    """
    Given I create the following nodes:
      | Path                | Node Type                                                                | Properties | HiddenInIndex | Language |
      | /sites/test/main/ex | Flowpack.DecoupledContentStore.Test:Content.FusionWithRenderingException | {}         | false         | de       |

    # build content release
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 1 error occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 4
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de"
    And I expect the content release "5" to have the completion status failed


  Scenario: Missing Fusion Implementation Class
    Given I have the following additional NodeTypes configuration:
    """
    Flowpack.DecoupledContentStore.Test:Content.MissingImplementationClass:
      superTypes:
        'Neos.Neos:Content': true
    """
    Given I create the following nodes:
      | Path                | Node Type                                                              | Properties | HiddenInIndex | Language |
      | /sites/test/main/ex | Flowpack.DecoupledContentStore.Test:Content.MissingImplementationClass | {}         | false         | de       |

    # build content release
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", 1 error occured
    # for filling the rendered content release
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 4
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de"
    And I expect the content release "5" to have the completion status failed
