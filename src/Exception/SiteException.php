<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use Throwable;

/**
 * The SiteException interface. What every exception this site raises carries, and nothing else does.
 *
 * The eleven classes in this namespace had no type in common, which meant the one question worth
 * asking at a boundary — *did this come from us, or from something underneath us?* — had no way of
 * being asked. This is that type. It is deliberately the only thing in here that is not a class:
 * PHP has one inheritance chain to spend and these have already spent it saying whether they are a
 * `LogicException`, a `RuntimeException` or a `TypeError`.
 *
 * **`catch (Exception)` would not have worked, and that is worth stating rather than assuming.**
 * {@link CollectionException} extends `TypeError`, which extends `Error` — a sibling of `Exception`,
 * not a subclass of it. So the widest catch anybody would reach for by habit misses one of the
 * eleven, and misses it silently, in the class most likely to be thrown by a mistake somebody made
 * five minutes ago. Only `Throwable` catches all eleven, and `Throwable` also catches everything
 * PHP itself raises. This interface is the difference.
 *
 * It extends `Throwable` so that a `catch (SiteException $fault)` can ask for a message without a
 * second `instanceof` — PHP allows an *interface* to extend it, and requires that whatever
 * implements the result already be an `Exception` or an `Error`, which all eleven are. Eight of them
 * declare it; the three under {@link MarkupException} inherit it, which is the whole reason that
 * base is worth having.
 *
 * One caller today: the handler at the door in `public/index.php`, which logs a fault from this
 * repository differently from one raised underneath it. That is the whole reason it exists — see
 * {@link \NeuroSYS\Test\Unit\GuidelineTest::testEveryExceptionThrownUnderSrcIsOneOfOurs()} for the
 * rule that keeps the set closed.
 */
interface SiteException extends Throwable
{
}
