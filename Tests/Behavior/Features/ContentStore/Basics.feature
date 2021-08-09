@fixtures
@resetRedis
Feature: Basic Rendering

  Scenario: Initial Test
    Given I have the following NodeTypes configuration:
    """
    unstructured:
      abstract: true

    Neos.Neos:FallbackNode:
      abstract: true

    Neos.Neos:Document:
      abstract: true

    Neos.Neos:Content:
      abstract: true

    Neos.Neos:ContentCollection:
      abstract: true

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

