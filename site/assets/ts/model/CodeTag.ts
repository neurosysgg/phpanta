/**
 * Mirrors PhpantaSite\CodeTag — the code samples' elements. ContractTest compares the two, so the
 * server cannot write a tag this side never registers.
 */
export enum CodeTag {
  Block    = 'code-block',
  Line     = 'code-line',
  Keyword  = 'code-keyword',
  Type     = 'code-type',
  Call     = 'code-call',
  Variable = 'code-variable',
  String   = 'code-string',
  Comment  = 'code-comment',
  Command  = 'code-command',
  Flag     = 'code-flag',
  Branch   = 'code-branch',
}
