<?php

declare(strict_types=1);

namespace Phpanta\Controller;

use NoDiscard;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Support\Collection;

/**
 * The Layered class. One {@link Layer} around a controller, itself a controller — so layers nest.
 */
final readonly class Layered implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Layer      $layer
     * @param Controller $next  What the layer stands around.
     */
    public function __construct(
        private Layer      $layer,
        private Controller $next,
    ) {}

    /**
     * $core with $layers around it, the first of them outermost: the first listed is the first to
     * see the request and the last to see the response.
     *
     * No layers is $core itself, untouched.
     *
     * @param Collection<Layer> $layers
     * @param Controller        $core
     * @return Controller
     */
    #[NoDiscard('around() builds the controller and runs nothing; a call whose result goes nowhere wrapped nothing')]
    public static function around(Collection $layers, Controller $core): Controller
    {
        $controller = $core;

        foreach (array_reverse($layers->toValues()) as $layer) {
            $controller = new self($layer, $controller);
        }

        return $controller;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return $this->layer->handle($request, $this->next);
    }
}
