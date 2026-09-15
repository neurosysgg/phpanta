import { HtmlAttribute } from '../model/HtmlAttribute.js';
import { HtmlTag } from '../model/HtmlTag.js';
import { MachineAttribute } from '../model/MachineAttribute.js';
import { MachineCounter } from '../model/MachineCounter.js';
import { MachineReading } from '../model/MachineReading.js';
import { MachineTag } from '../model/MachineTag.js';
import { MediaType } from '../model/MediaType.js';
import { RequestHeader } from '../model/RequestHeader.js';
import { ResultKey } from '../model/ResultKey.js';

/** The machine's counters at one moment, under MachineCounter's keys. */
type Counters = Readonly<Record<MachineCounter, number>>;

/** What one reading shows: how full its meter is, and what its value says. */
type Shown = readonly [number, string];

/**
 * <machine-stats data-source="/admin/machine/v1/system"> <table>…</table> </machine-stats>
 *
 * The admin's machine readings, kept live. The server writes the whole table — a meter and a value
 * for each reading, as one moment says them — and this asks `data-source` for the same answer as
 * data every few seconds, writing each reading again from what it said.
 *
 * **Two moments make a rate.** A processor's busyness and the network's speed are changes between
 * two sets of counters, which only the page holds, so the first answer shows totals and every one
 * after it shows rates; the server never sleeps to sample. What a reading is called and which meter
 * shows it are the server's: this only finds them by `data-reading`.
 *
 * **It asks only while it is seen**, and stops for good at the first answer it cannot read — a
 * session that ran out answers the admin's entrance, not data, and asking again would only ask for
 * the entrance again. Without this module the table is the moment the page was made.
 */
export class MachineStats extends HTMLElement {
  /** How often the readings are asked for again, in milliseconds. */
  private static readonly EVERY = 2000;

  /** The binary units after a byte, each 1024 of the one before — the server's Measure's. */
  private static readonly UNITS = 'KMGTPE';

  private timer: ReturnType<typeof setInterval> | undefined = undefined;

  /** The counters the last answer carried, which the next one's rates are worked out against. */
  private last: Counters | null = null;

  /** Held as one reference, so the listener that is added is the one that is taken away. */
  private readonly visibility = (): void => { this.schedule(); };

  connectedCallback(): void {
    document.addEventListener('visibilitychange', this.visibility);
    this.schedule();
  }

  disconnectedCallback(): void {
    document.removeEventListener('visibilitychange', this.visibility);
    this.stop();
  }

  /** Asks every few seconds while the page is shown, and not while it is hidden. */
  private schedule(): void {
    this.stop();

    if (document.visibilityState !== 'hidden') {
      this.timer = setInterval(() => { void this.refresh(); }, MachineStats.EVERY);
    }
  }

  private stop(): void {
    clearInterval(this.timer);
    this.timer = undefined;
  }

  /** One answer: every reading written again — or, where the answer is not one, no more asking. */
  private async refresh(): Promise<void> {
    const counters = await MachineStats.read(this.getAttribute(MachineAttribute.Source) ?? '');

    if (counters === null) {
      this.stop();

      return;
    }

    this.show(counters);
    this.last = counters;
  }

  /** The counters `source` answers with as data, or null where it answers something else. */
  private static async read(source: string): Promise<Counters | null> {
    try {
      const response = await fetch(source, {
        headers: { [RequestHeader.Accept]: MediaType.Json },
        credentials: 'same-origin',
      });

      return MachineStats.counters(await response.json());
    } catch {
      return null;
    }
  }

  /** The counters an admin answer carries in one of its sections, or null where none does. */
  private static counters(data: unknown): Counters | null {
    const sections: unknown = data instanceof Object ? (data as Record<string, unknown>)[ResultKey.Sections] : null;
    const found: unknown = Array.isArray(sections)
      ? sections.find((section: unknown) => section instanceof Object && ResultKey.Counters in section)
      : undefined;

    return found === undefined ? null : (found as Record<string, Counters>)[ResultKey.Counters] as Counters;
  }

  /** Writes every reading `now` gives, against the answer before it. */
  private show(now: Counters): void {
    const before = this.last;
    const shown  = new Map<MachineReading, Shown>([
      [MachineReading.Cpu, before === null ? [0, ''] : MachineStats.busy(now, before)],
      [MachineReading.Memory, MachineStats.share(now[MachineCounter.MemoryUsed], now[MachineCounter.MemoryTotal])],
      [
        MachineReading.Swap,
        now[MachineCounter.SwapTotal] > 0
          ? MachineStats.share(now[MachineCounter.SwapUsed], now[MachineCounter.SwapTotal])
          : [0, ''],
      ],
      [MachineReading.Received, [0, MachineStats.flow(now, before, MachineCounter.Received)]],
      [MachineReading.Sent, [0, MachineStats.flow(now, before, MachineCounter.Sent)]],
      [MachineReading.Load, [0, (now[MachineCounter.Load] / 100).toFixed(2)]],
    ]);

    shown.forEach(([percent, text], reading) => {
      this.querySelectorAll(`[${MachineAttribute.Reading}="${reading}"]`).forEach((element) => {
        if (element.localName === HtmlTag.Meter) {
          element.setAttribute(HtmlAttribute.Value, String(percent));
        } else {
          element.textContent = text;
        }
      });
    });
  }

  /** How busy the processors were between two answers. */
  private static busy(now: Counters, before: Counters): Shown {
    const percent = MachineStats.percent(
      now[MachineCounter.CpuBusy] - before[MachineCounter.CpuBusy],
      now[MachineCounter.CpuTotal] - before[MachineCounter.CpuTotal],
    );

    return [percent, `${percent}%`];
  }

  /** `part` of `whole` bytes, as the server writes one: `512 B of 1.0 KiB (50%)`. */
  private static share(part: number, whole: number): Shown {
    const percent = MachineStats.percent(part, whole);

    return [percent, `${MachineStats.bytes(part)} of ${MachineStats.bytes(whole)} (${percent}%)`];
  }

  /** What `counter` has moved: its total on a first answer, its rate on every one after. */
  private static flow(now: Counters, before: Counters | null, counter: MachineCounter): string {
    if (before === null) return MachineStats.bytes(now[counter]);

    const seconds = Math.max(1, now[MachineCounter.Time] - before[MachineCounter.Time]) / 1000;

    return `${MachineStats.bytes(Math.max(0, Math.round((now[counter] - before[counter]) / seconds)))}/s`;
  }

  private static percent(part: number, whole: number): number {
    return whole > 0 ? Math.round(part * 100 / whole) : 0;
  }

  /** `count` bytes in the largest binary unit that keeps it at one or more. */
  private static bytes(count: number): string {
    if (count < 1024) return `${count} B`;

    let value = count;
    let unit  = -1;

    while (value >= 1024 && unit < MachineStats.UNITS.length - 1) {
      value /= 1024;
      unit++;
    }

    return `${value.toFixed(1)} ${MachineStats.UNITS.charAt(unit)}iB`;
  }
}

customElements.define(MachineTag.Stats, MachineStats);
