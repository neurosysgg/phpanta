/** Mirrors MachineCounter — the raw numbers machine v1 system answers as data. */
export enum MachineCounter {
  CpuBusy     = 'cpu-busy',
  CpuTotal    = 'cpu-total',
  MemoryUsed  = 'memory-used',
  MemoryTotal = 'memory-total',
  SwapUsed    = 'swap-used',
  SwapTotal   = 'swap-total',
  Received    = 'received',
  Sent        = 'sent',
  Load        = 'load',
  Time        = 'time',
}
