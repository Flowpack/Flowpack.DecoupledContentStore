@fixtures
@flowEntities
@resetRedis
Feature: Quick Release

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
      | nodeAggregateId                      | parentNodeAggregateId                | nodeTypeName                                           | initialPropertyValues                                | nodeName |
      | test                                 | sites                                | Flowpack.DecoupledContentStore.Test:Document.StartPage | {"title":"Startseite","uriPathSegment":"startseite"} | test     |
      | test-main                            | test                                 | Neos.Neos:ContentCollection                            | {}                                                   | main     |
      | test-main-t1                         | test-main                            | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Hallo - this is rendered."}                | t1       |
      | 5b0d6c1e-8a3f-4e27-9c14-7f2a6e9d3b81 | test                                 | Flowpack.DecoupledContentStore.Test:Document.Page      | {"title":"Subpage","uriPathSegment":"nested"}        | sub      |
      | sub-main                             | 5b0d6c1e-8a3f-4e27-9c14-7f2a6e9d3b81 | Neos.Neos:ContentCollection                            | {}                                                   | main     |
      | sub-main-t1                          | sub-main                             | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Unterseite"}                               | t1       |
      | sub2                                 | test                                 | Flowpack.DecoupledContentStore.Test:Document.Page      | {"title":"Subpage2","uriPathSegment":"nested2"}      | sub2     |
      | sub2-main                            | sub2                                 | Neos.Neos:ContentCollection                            | {}                                                   | main     |
      | sub2-main-t1                         | sub2-main                            | Flowpack.DecoupledContentStore.Test:Content.Text       | {"text": "Unterseite2"}                              | t1       |
    And I have a site for Site Node "test" with site package key "Flowpack.DecoupledContentStore" with domain "test.de"
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
    When I enumerate the node "5b0d6c1e-8a3f-4e27-9c14-7f2a6e9d3b81" for content release "6"
    Then the enumeration for content release "6" contains 1 node

  Scenario: A quick release is not rejected for enumerating only what it changed
    # the URL count check compares the new release against the live one, and the enumeration of a quick release is
    # smaller than that by design - both sides are therefore measured by the URLs they publish
    Given the currently live content release is "5"
    When I create a content release "6"
    And I copy the content release "5" to the content release "6"
    And I enumerate the node "5b0d6c1e-8a3f-4e27-9c14-7f2a6e9d3b81" for content release "6"
    Then validating content release "6" succeeds

  Scenario: A quick release is not rejected for the size of a release rendered by two renderers
    # the enumeration counts documents per renderer, the released URLs count documents - so the two measures must not
    # be compared against each other. A live release enumerated by two renderers is twice its own URL count, which
    # puts the threshold above what any quick release publishes, and it refuses one which holds the whole site.
    Given the currently live content release is "5"
    And the enumeration of content release "5" is duplicated for a second document renderer
    When I create a content release "6"
    And I copy the content release "5" to the content release "6"
    And I enumerate the node "5b0d6c1e-8a3f-4e27-9c14-7f2a6e9d3b81" for content release "6"
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
