<?php

declare(strict_types=1);

namespace Phpanta\Controller;

use Phpanta\Http\Request;
use Phpanta\Http\Response;

/**
 * The Layer interface. Something that stands around a controller: before it, after it, or instead
 * of it.
 *
 * A layer is handed the request and whatever comes next — the next layer in, or the controller at
 * the middle — as one more {@link Controller}. What it does with them is the whole contract:
 *
 * - **before**: look at the request and return `$next->handle($request)`;
 * - **instead**: return a response of its own and never ask `$next` — a gate's 401, a maintenance
 *   503;
 * - **after**: take what `$next` answered and return something wrapped around it —
 *   {@link \Phpanta\Http\WithHeaders} is the usual shape.
 *
 * **Listed, never discovered.** An app lists the layers around every request in
 * {@link \Phpanta\App::layers()}, and a route lists its own with
 * {@link \Phpanta\Support\Route::through()}; nothing registers itself, so what stands around a
 * controller is written where the controller is named. The first listed is the outermost. See
 * {@link Layered}.
 */
interface Layer
{
    /**
     * Answers $request — by asking $next, or instead of it.
     *
     * @param Request    $request
     * @param Controller $next    The rest of the way in: the next layer, or the controller itself.
     * @return Response
     */
    public function handle(Request $request, Controller $next): Response;
}
