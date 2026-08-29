# Pseudonymized values

Values that may identify a person are replaced before results reach you. You
are reading pseudonyms, not data. Reasoning about them as if they were real
values produces confident, wrong answers.

## What a token looks like

    [email:51e84a2b]   [name:15cd0e0f]   [phone:bd3d279f]   [value:221a3546]

The prefix is the kind of value detected. The suffix is a keyed hash of it.

## What you can rely on

- **Equal tokens mean equal underlying values.** Within one session,
  `[email:51e84a2b]` is the same address every time it appears, including
  across separate queries and across different column labels. You can join,
  group and deduplicate on tokens.
- **Different tokens mean different values.**
- **Aggregates are exact.** `COUNT`, `SUM`, `AVG`, `COUNT(DISTINCT email)` are
  computed by the database before pseudonymization, so the numbers are real
  even when the column they summarise comes back tokenized. When you need a
  count of distinct people, ask SQL for it rather than counting tokens in a
  truncated result.

## What you cannot do

- **You cannot reverse a token.** Nothing you can query will turn
  `[email:51e84a2b]` back into an address. Do not guess, and do not present a
  token as if it were a real value to the person you are helping.
- **You cannot filter by one.** `WHERE email = '[email:51e84a2b]'` matches
  nothing — the database stores the real value, not the token. Filter on
  non-identifying columns, or on the real value if the user supplied it.
- **You cannot compare across sessions.** Tokens are session-scoped by
  default. A token from an earlier conversation is meaningless now, so never
  carry one across and never treat two tokens from different sessions as the
  same person.

## Reading `[value:…]` correctly

Pseudonymization is fail-closed: anything not positively established as safe
is tokenized. `[value:…]` therefore usually means **"this column has not been
classified yet"**, not "this is sensitive". A status or category column that
nobody has added to the safe list appears this way.

This matters for how you report. Say the column is not available in readable
form; do not tell the user their data is sensitive when the honest answer is
that the tool could not tell. If a genuinely harmless column is being
tokenized and it is getting in the way, the fix is a one-line config change on
the application's side — say so rather than working around it.
