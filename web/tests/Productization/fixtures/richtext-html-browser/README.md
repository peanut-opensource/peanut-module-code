# Native Chromium RichText HTML-import security gate

The original happy-dom Vitest file `web/src/modules/peanut-rich-text/tests/html-import.spec.ts`
was moved here as a real-browser gate, **not skipped or mocked**. The 22 source
allow/deny inputs now live in `cases.ts`, with precise document projection
assertions in `App.vue`. The fixture imports the current production
`RichTextEditor.vue` and `html-import.ts` unchanged.

Run from the Module workspace root:

```sh
python3 scripts/project-env --tools node run -- node --test web/tests/Productization/richtext-html-chromium.test.mjs
```

The test builds this page with the native installed Vue/Vite packages, uses a
fresh ephemeral Chrome profile, verifies all 22 results and that a real editable
ProseMirror component mounted, then removes the temporary output. It does not
start app services, request credentials, create databases or modify product
routes. A browser binary is required; set `CHROME_BIN` on non-macOS hosts.

Reason: DOMPurify WHOLE_DOCUMENT+RETURN_DOM interacts incorrectly with
happy-dom 20.10.6 HTMLDocument.insertBefore; real Chromium 158 passes 22/22.
Do not weaken sanitizer behavior or silently classify these security tests as
passed on an incompatible simulated DOM.
