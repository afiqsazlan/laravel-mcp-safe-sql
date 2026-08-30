# Which database this is

This server reads **{{ source }}**. Every result repeats it in a `source` field.

## If more than one of these servers is connected

You may have one per environment. They expose identical tool names, so the
only thing telling them apart is which server a tool belongs to — and choosing
wrong is not a small mistake:

- A question about **real usage** answered from a **non-production** database
  returns a plausible number that is simply false. Nothing in the result looks
  wrong.
- Reaching for **production** to investigate a bug throws away the isolation
  that made a separate environment worth having.

When a question could be about either, ask which before querying. When it is
unambiguous, name the database you used in your answer. If a result surprises
you, check `source` first — the wrong server is a likelier explanation than a
genuinely surprising number.

Never combine rows from two servers into one figure, and never carry a
pseudonym between them: unrelated databases, unrelated salts.
