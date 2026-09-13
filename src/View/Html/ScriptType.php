<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The ScriptType enum. What kind of script a `<script>` carries.
 *
 * One case, and it is load-bearing in a way that is easy to miss: without `type="module"` the
 * browser treats `main.js` as a classic script, where `import` is a syntax error and the whole
 * front end fails to parse. It also stops being deferred, so it would run against a document that
 * is not there yet.
 *
 * So the absence of this value is not a missing attribute, it is a broken page — which is exactly
 * the kind of fact this codebase makes a type rather than a string. Same single-case shape as
 * {@link LinkTarget} and {@link \Phpanta\Http\Security\ContentTypeOptions}.
 */
enum ScriptType: string
{
    /** An ES module: `import` resolves, and the script defers on its own. */
    case Module = 'module';
}
