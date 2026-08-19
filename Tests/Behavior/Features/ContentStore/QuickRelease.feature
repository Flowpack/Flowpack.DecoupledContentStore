@fixtures
@flowEntities
@resetRedis
Feature: Quick Release

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
      properties:
        text:
          type: string

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

    # the release a quick release is built on
    When I create a content release "5"
    And I enumerate all nodes for content release "5"
    Then the enumeration for content release "5" contains 3 nodes
    When I run the render-orchestrator control loop once for content release "5"
    And I run the renderer for content release "5" until the queue is empty
    Then during rendering of content release "5", no errors occured
    When I continue running the render-orchestrator control loop
    Then I expect the render-orchestrator control loop to exit with status code 0
    And I expect the content release "5" to have the completion status success

  Scenario: A finished release is copied forward instead of being rendered again
    # nothing is rendered for the new release, and it holds the content of its predecessor anyway
    When I create a content release "6"
    And I copy the content release "5" to the content release "6"
    Then I expect the content release "6" to contain the following content for URI "http://test.de/de/nested" at CSS selector "body .neos-contentcollection":
    """
    BEFOREUnterseiteAFTER
    """
    # the enumeration says what a release renders, so a quick release brings its own instead of inheriting one
    And the enumeration for content release "6" contains 0 nodes

    # and that enumeration holds nothing but the nodes the quick release was asked to publish
    When I enumerate the node at path "/sites/test/sub" for content release "6"
    Then the enumeration for content release "6" contains 1 node

  Scenario: A quick release is not rejected for enumerating only what it changed
    # the URL count check compares the new release against the live one, and the enumeration of a quick release is
    # smaller than that by design - it has to be measured by the URLs it publishes instead
    Given the currently live content release is "5"
    When I create a content release "6"
    And I copy the content release "5" to the content release "6"
    And I enumerate the node at path "/sites/test/sub" for content release "6"
    Then validating content release "6" succeeds

  Scenario: A node which cannot be found is not published
    # a quick release which renders nothing would publish the release it was copied from, and look successful
    When I create a content release "6"
    Then enumerating the node "3239baee-3e7f-785c-0853-f4302ef32570" for content release "6" is refused

  Scenario: A release which has not finished is not copied forward
    # everything the copy does not overwrite is published as if it had been rendered, so an unfinished release
    # must not be built upon
    When I create a content release "7"
    And I create a content release "8"
    Then copying the content release "7" to the content release "8" is refused
