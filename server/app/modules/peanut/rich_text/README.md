# RichText plain text projection

`RichTextProjection::fromStructured()` retains the existing validation of the editor tree and resource references. Its public `plainText(): string` method reads that already validated projection without rebuilding Yjs state or creating annotations.

The stable profile is `RichTextProjection::TEXT_PROFILE = 'peanut.richtext.plain-text/1'`.

| Node | Text rule |
| --- | --- |
| text | Exact text, preserving Unicode and whitespace |
| hardBreak | LF |
| paragraph, heading, codeBlock | Concatenate child text |
| tableRow | Join cell text once with TAB |
| doc, blockquote, lists, list items, table, table cells/headers | Join child blocks once with LF |
| image, video, audio, file, media, horizontalRule | One U+FFFC object replacement character |

There is no automatic trailing LF, trimming, normalization, link expansion, or media title/alt substitution. Empty blocks remain empty and therefore retain their separators. A cell with two paragraphs produces `A\nB`; its adjacent cell produces `A\nB\tC`. Nested block containers apply their join rule once at each structural level.

The profile defines text, not positions in a live ProseMirror/Yjs document. Revision-bound consumers must bind the immutable source version and text digest themselves. Consumers choosing Unicode code point offsets must count astral emoji as one and combining marks separately; the public Document comment contract does so.

Any future text profile must preserve the meaning of retained bindings to this profile. Adding `plainText()` changes neither the structured JSON nor its digest. Nonempty annotations, unsupported nodes, invalid references and existing limits are still rejected by the original validator.

The dedicated `RichTextPlainTextProjectionTest.php` covers empty/blank blocks, nested lists/blocks, multi-paragraph table cells, hard breaks, object replacements, Chinese/astral/combining text, preserved code whitespace, and the existing annotation rejection.
