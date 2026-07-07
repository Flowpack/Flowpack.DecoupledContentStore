### Updating from 2.x to 3.x

You need to adjust the following things when updating from DecoupledContentStore 2.x to 3.x:

CHANGES:

* change how `nodeRendering.nodeTypeWhitelist` is configured, previously it was a string, which could lead to unreadable
  configuration, now its a list

#### UPDATING:

**Settings.yaml** - `nodeRendering.nodeTypeWhitelist` must be configured differently.

Split the comma-separated string into a YAML list, one entry per node type. Entries prefixed
with `!` (exclusions) keep working the same way.

OLD:

```yaml
Flowpack:
    DecoupledContentStore:
        nodeRendering:
            nodeTypeWhitelist: 'Neos.Neos:Document,!My.Package:Bar,!My.Package:Baz'
```

NEW:

```yaml
Flowpack:
    DecoupledContentStore:
        nodeRendering:
            nodeTypeWhitelist:
                - 'Neos.Neos:Document'
                - '!My.Package:Bar'
                - '!My.Package:Baz'
```
