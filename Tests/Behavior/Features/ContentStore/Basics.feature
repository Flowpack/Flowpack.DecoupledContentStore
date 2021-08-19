@fixtures
@resetRedis
Feature: Basic Rendering

  Background:
    Given I have the following NodeTypes configuration:
    """
    Flowpack.DecoupledContentStore.Test:Document.StartPage:
      superTypes:
        'Neos.Neos:Document': true

    Flowpack.DecoupledContentStore.Test:Content.Text:
      superTypes:
        'Neos.Neos:Content': true

    """
    Given I am authenticated with role "Neos.Neos:Editor"
    Given I have a site for Site Node "test" with site package key "Flowpack.DecoupledContentStore" with domain "test.de"
    And I have the following nodes:
      | Path                | Node Type                                              | Properties                                           | HiddenInIndex | Language |
      | /sites              | unstructured                                           | []                                                   | false         | de       |
      | /sites/test         | Flowpack.DecoupledContentStore.Test:Document.StartPage | {"title":"Startseite","uriPathSegment":"startseite"} | false         | de       |
      | /sites/test/main    | Neos.Neos:ContentCollection                            | {}                                                   | false         | de       |
      | /sites/test/main/t1 | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Hallo - this is rendered."}                | false         | de       |
    And I flush the content cache depending on the modified nodes

  Scenario: Basic successful render
    # build content release
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured
    # for filling the rendered content release
    When I run the render-orchestrator control loop once for content release "5"
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered.AFTER
    """


  Scenario: No Re-Render because Editor did a modification in his user workspace
    # build content release
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured

    # Now, the Editor does a modification, BUT IN THE USER WORKSPACE. This should not trigger a re-rendering
    When I get a node by path "/sites/test/main/t1" with the following context:
      | Workspace  | Language |
      | user-admin | de       |
    And I set the node property "text" to "New Text"
    And I flush the content cache depending on the modified nodes

    # for filling the rendered content release
    When I run the render-orchestrator control loop once for content release "5"
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered.AFTER
    """

  Scenario: Re-Render because Editor did a modification in the live workspace
    # build content release
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 1 node
    # for filling the render queue:
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured

    # Now, the Editor does a modification, BUT IN THE LIVE. This should trigger a re-rendering
    When I get a node by path "/sites/test/main/t1" with the following context:
      | Workspace | Language |
      | live      | de       |
    And I set the node property "text" to "New Text"
    And I flush the content cache depending on the modified nodes

    # because a modification has happened, the rendered node cannot be copied over to the finished content release;
    # thus the content release cannot contain anything at this position yet.
    When I run the render-orchestrator control loop once for content release "5"
    Then I expect the content release "5" to not contain anything for URI "http://test.de/de"

    # however, when we re-run the rendering (in the next iteration), the rendering should converge and work out.
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured
    When I run the render-orchestrator control loop once for content release "5"
    Then I expect the content release "5" to contain the following content for URI "http://test.de/de" at CSS selector "body .neos-contentcollection":
    """
    BEFORENew TextAFTER
    """
