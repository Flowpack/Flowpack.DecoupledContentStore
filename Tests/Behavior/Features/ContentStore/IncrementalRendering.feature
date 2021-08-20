@fixtures
@resetRedis
Feature: Incremental Rendering

  Background:
    Given I have the following NodeTypes configuration:
    """
    Flowpack.DecoupledContentStore.Test:Document.StartPage:
      superTypes:
        'Neos.Neos:Document': true

    Flowpack.DecoupledContentStore.Test:Document.Page:
      superTypes:
        'Neos.Neos:Document': true


    Flowpack.DecoupledContentStore.Test:Content.Text:
      superTypes:
        'Neos.Neos:Content': true

    """
    Given I am authenticated with role "Neos.Neos:Editor"
    Given I have a site for Site Node "test" with site package key "Flowpack.DecoupledContentStore" with domain "test.de"
    And I have the following nodes:
      | Path                     | Node Type                                              | Properties                                           | HiddenInIndex | Language |
      | /sites                   | unstructured                                           | []                                                   | false         | de       |
      | /sites/test              | Flowpack.DecoupledContentStore.Test:Document.StartPage | {"title":"Startseite","uriPathSegment":"startseite"} | false         | de       |
      | /sites/test/main         | Neos.Neos:ContentCollection                            | {}                                                   | false         | de       |
      | /sites/test/main/t1      | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Hallo - this is rendered."}                | false         | de       |
      | /sites/test/sub          | Flowpack.DecoupledContentStore.Test:Document.Page      | {"title":"Subpage","uriPathSegment":"nested"}        | false         | de       |
      | /sites/test/sub/main     | Neos.Neos:ContentCollection                            | {}                                                   | false         | de       |
      | /sites/test/sub/main/t1  | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Unterseite"}                               | false         | de       |
      | /sites/test/sub2         | Flowpack.DecoupledContentStore.Test:Document.Page      | {"title":"Subpage2","uriPathSegment":"nested2"}      | false         | de       |
      | /sites/test/sub2/main    | Neos.Neos:ContentCollection                            | {}                                                   | false         | de       |
      | /sites/test/sub2/main/t1 | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Unterseite2"}                              | false         | de       |

    And I flush the content cache depending on the modified nodes

    # build content release
    When I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 3 node
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
    When I get a node by path "/sites/test/sub/main/t1" with the following context:
      | Workspace | Language |
      | live      | de       |
    And I set the node property "text" to "New Text"
    And I flush the content cache depending on the modified nodes

    When I enumerate all nodes for content release "6"
    When I run the render-orchestrator control loop once for content release "6"
    # no rerendering of the sub2 branch needed
    Then I expect the content release "6" to contain the following content for URI "http://test.de/de/nested2" at CSS selector "body .neos-contentcollection":
    """
    BEFOREUnterseite2AFTER
    """
    Then I expect the content release "6" to not contain anything for URI "http://test.de/de/nested"
    # TODO: right now, a rerendering of the homepage is still needed - would be nice to get rid of this sometime in the future.
    Then I expect the content release "6" to not contain anything for URI "http://test.de/de"
    # /sites/test/sub
    # /sites/test right now (TODO debatable whether this makes sense)
    And the rendering queue for content release "6" contains 2 documents

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

    Then I expect the content release "6" to contain the following content for URI "http://test.de/de" at CSS selector "body .neos-contentcollection":
    """
    BEFOREHallo - this is rendered.AFTER
    """
