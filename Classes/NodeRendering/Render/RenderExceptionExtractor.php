<?php
namespace Flowpack\DecoupledContentStore\NodeRendering\Render;

class RenderExceptionExtractor
{

    const HTML_MESSAGE_HANDLER_PATTERN = '#
        <div \s* class="neos-message-wrapper">
            \s*
            <p \s* class="neos-message-content">
                (?P<message> [^<]*)
            </p>
            \s*
            (
                <p \s* class="neos-message-stacktrace">
                    \s*
                    <code>
                        (?P<stackTrace> [^<]*)
                    </code>
                    \s*
                </p>
                \s*
            )?
            (
                <p \s* class="neos-reference-code">
                    [^<]*<code>[^<]*?(?P<referenceCode> [0-9a-z]*).txt</code>
                </p>
            )?
        </div>
    #xi';

    const XML_COMMENT_HANDLER_PATTERN = '#
        <!--
            \s*
            Exception\ while\ rendering\ (?P<stackTrace> [^\s]*?):\ (?P<message> [^>]*?)\ \( (?P<referenceCode> [^)]*) \)
            \s*
        -->
    #xi';

    /**
     * @param string $content
     * @return ExtractedExceptionDto
     */
    public static function extractRenderingException($content)
    {
        if (
            preg_match(self::HTML_MESSAGE_HANDLER_PATTERN, $content, $matches) ||
            preg_match(self::XML_COMMENT_HANDLER_PATTERN, $content, $matches)
        ) {
            return new ExtractedExceptionDto($matches['message'], $matches['stackTrace'], $matches['referenceCode']);
        }
        return null;
    }

}
