@fixtures
@resetRedis
Feature: Quick Release

  Background:
    Given I have the following NodeTypes configuration:
    """
    Flowpack.DecoupledContentStore.Test:Document.StartPage:
      superTypes:
        'Neos.Neos:Document': true

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
      | Path                | Node Type                                              | Properties                                           | HiddenInIndex | Language |
      | /sites              | unstructured                                           | []                                                   | false         | de       |
      | /sites/test         | Flowpack.DecoupledContentStore.Test:Document.StartPage | {"title":"Startseite","uriPathSegment":"startseite"} | false         | de       |
      | /sites/test/main    | Neos.Neos:ContentCollection                            | {}                                                   | false         | de       |
      | /sites/test/main/t1 | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Hallo - this is rendered."}                | false         | de       |
    And I flush the content cache depending on the modified nodes

  Scenario: A finished release is copied forward instead of being rendered again
    When I create a content release "5"
    And I enumerate all nodes for content release "5"
    And I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 0
    And I expect the content release "5" to have the completion status success

    # nothing is rendered for the new release, and it holds the content of its predecessor anyway
    When I create a content release "6"
    And I copy the content release "5" to the content release "6"
    Then I expect the content release "6" to contain the following content for URI "http://test.de/de" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered.AFTER
    """
    # the enumeration says what a release renders, so a quick release brings its own instead of inheriting one
    And the enumeration for content release "6" contains 0 nodes

  Scenario: A release which has not finished is not copied forward
    # everything the copy does not overwrite is published as if it had been rendered, so an unfinished release
    # must not be built upon
    When I create a content release "5"
    And I enumerate all nodes for content release "5"
    When I create a content release "6"
    Then copying the content release "5" to the content release "6" is refused
