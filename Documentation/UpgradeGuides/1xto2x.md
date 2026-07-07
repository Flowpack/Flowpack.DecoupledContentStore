### Updating from 1.x to 2.x

You need to adjust the following things when updating from DecoupledContentStore 1.x to 2.x:

NEW FEATURES / IMPROVEMENTS:

* support for different output formats, with differing documentRenderers and custom enumerators
    * this is especially handy for full or additional headless rendering
* define which workspaces to release

#### UPDATING:

**Settings.yaml - contentReleaseWriters must be configured differently.

OLD:

```yaml

Flowpack:
    DecoupledContentStore:
        extensions:
            contentReleaseWriters:
                gzipCompressed:
                    className: Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriters\GzipWriter
                legacy:
                    className: Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriters\LegacyWriter

```

NEW:

```yaml
Flowpack:
    DecoupledContentStore:
        extensions:
            # Decide how node rendering should happen. 
            documentRenderers:
                htmlViaFusion:
                    # ... the other config matches the old behavior ...

                    # Register additional content release writers, being called for every finished node which should be added
                    # to the content release.
                    #  (must implement ContentReleaseWriterInterface)
                    contentReleaseWriters:
                        gzipCompressed:
                            className: Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriters\GzipWriter
                        legacy:
                            className: Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriters\LegacyWriter


```
