---
title: Errors
order: 50
---

# Errors

Three classes of the engine; what reaches the user is a Composer command's failure or a listing's line.

| Class | Parent | Meaning |
|---|---|---|
| `Omnibridge\Composer\Patch\InvalidDeclaration` | `\InvalidArgumentException` | a declaration in `extra.omnibridge` the engine cannot read |
| `Omnibridge\Composer\Patch\TargetNotFound` | `\RuntimeException` | a patch did not find what it changes, or found it already changed |
| `\RuntimeException` (Composer's command fails) | | `Plugin::onPostInstall()` after a run with failed patches, with `Report::failureMessage()` |

None of the first two escapes: the reconciler turns each into a `FAILED` outcome (or a skip for an
`optional` patch), and the install or update fails at the end with the list.

## Invalid declarations

The patch is `FAILED` with `invalid declaration: <message>`:

| Message | Cause |
|---|---|
| `a patch is an object` | an entry of `patches` that is not an object |
| `"<key>" must be a non-empty string` | `id`, `package`, `file`, `why` missing or empty; `class`, `versions`, `php`, `upstream` given empty |
| `"id" is letters, digits, dots, dashes and underscores, not "..."` | |
| `"file" is relative to the target package, inside it, not "..."` | an absolute path or `..` |
| `a patch says either "do" (operations) or "class" (a PatchInterface), one of them` | both or neither |
| `"do" is an operation or a non-empty list of them` / `an operation is an object, such as {...}` | |
| `an operation says exactly one of replace, prepend, append, erase, unfinal, visibility, default, returns; got {...}` | no verb or two |
| `"<key>" is missing in {...}` | an operation's required key (`with` for `replace` says `"replace" needs "with" (a string, possibly empty)`; `before` / `after` for `prepend` / `append`) |
| `"count" must be a positive integer` | |
| `"visibility" is public, protected or private, not "..."` | |
| `"of" names ...` | an `of` that is not `Class::$property`, `Class::method()`, `Class::CONSTANT`, `Class::*` (`visibility`), `Class::method($parameter)` or `function($parameter)` (`default`), `Class::method()` or `function()` (`returns`) |
| `"<versions or php>" is not a version constraint: ...` | Composer's version parser refused it |
| `declared twice` | one package declares the same id twice |

## Targets not found

The patch is `FAILED` with the message (or skipped when `optional`):

- `<package> has no file <file>`;
- `<text> not found in <file>`, `<text> found N times in <file>, the patch expects M` (`replace`,
  `prepend`, `append`, `erase` and their `count`);
- `class X not found in <file>`, `class X is neither final nor @final in <file>` (`unfinal`);
- `<member> is already <visibility> in <file>`, no such member (`visibility`);
- `<function> not found`, `$p of <function> has no default value`, `... already defaults to ...`,
  `... has no parameter $p` (`default`);
- `<function> declares no return type`, `... already returns ...` (`returns`);
- `<file> does not parse as PHP: ...` (every AST operation);
- `class X not found in <declaring package>`, `X does not implement ...PatchInterface`,
  `X: <message of its exception>`, `X changed nothing in <file>` (a `class` patch).

## Stub problems

Reported as warnings by the hooks, as errors by `omnibridge:stubs` (exit code 1): `<package>: class "X"
not found`, `<package>: X does not implement ...StubInterface`, `X: "<name>" is not a directory
name`, `X: another provider already writes the stubs "<name>"`, `X: "<path>" is not a path inside its
directory`; and with `--check`: `<path> is missing`, `<path> is not what its provider generates`,
`<path> is no provider's any more`.

## Record problems

With `--check` (and listed as problems by the other runs): `<key> differs from the record (<hash>
expected, <hash> found)`, `<key>: the published copy the record keeps is missing or altered; the file is
taken as it is`, `<key> is in the record but no longer installed`, `<key> is not in the declared state
(...)`, `<key> is not in the record (patches never applied: run ...)`, `<key>: the record says ..., the
declarations give ...`, `<key> is in the record but no patch applies to it any more`.
