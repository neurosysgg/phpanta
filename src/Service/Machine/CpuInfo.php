<?php

declare(strict_types=1);

namespace Phpanta\Service\Machine;

/**
 * The CpuInfo class. What `/proc/cpuinfo` says about a machine's processors: their name, how many
 * cores and threads there are, and the fastest clock one reports.
 *
 * The file is a block of `key : value` lines per thread; a core is a `physical id` and a `core id`
 * together, so two threads of one core count once — and a machine that reports neither, as some ARM
 * boards do, has as many cores as threads.
 */
final readonly class CpuInfo
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $model   The first processor's name, or `''`.
     * @param int    $threads How many threads the kernel runs.
     * @param int    $cores   How many cores those are on.
     * @param float  $mhz     The fastest clock any thread reported.
     */
    private function __construct(public string $model, public int $threads, public int $cores, public float $mhz) {}

    /**
     * What $text, `/proc/cpuinfo`'s contents, says.
     *
     * @param string $text
     * @return self
     */
    public static function read(string $text): self
    {
        $model   = '';
        $threads = 0;
        $cores   = [];
        $mhz     = 0.0;
        $package = '';

        foreach (explode("\n", $text) as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value         = trim($value);

            match (trim($key)) {
                'model name'  => $model = $model === '' ? $value : $model,
                'processor'   => $threads++,
                'physical id' => $package = $value,
                'core id'     => $cores[$package . ':' . $value] = true,
                'cpu MHz'     => $mhz = max($mhz, (float) $value),
                default       => null,
            };
        }

        return new self($model, $threads, $cores === [] ? $threads : count($cores), $mhz);
    }
}
