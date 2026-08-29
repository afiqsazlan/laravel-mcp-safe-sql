# Telescope

The `telescope` tool reads a whole request batch and returns a compact digest.

Paste the uuid from a Telescope URL (`…/telescope/requests/<uuid>`) and you get
the request line, status and duration, any exceptions with their top stack
frames, the SQL that ran, and log entries — for a few hundred tokens rather
than the tens of thousands a full response body costs.

## The loop this is built for

1. Digest the batch to see the shape of what happened.
2. Pull only what you still need with `include`: `payload`, `headers`,
   `response`, `trace`. These are large — ask for one at a time, and only when
   the digest has not already answered the question.
3. Verify what was actually written by querying the database directly.

Step 3 is the point. Telescope tells you what the request did; SQL tells you
what survived it. Neither answers the question alone.

## What you will not get

Credential-bearing headers — `Authorization`, `Cookie`, `X-API-Key` and
similar — are removed and cannot be retrieved through this tool. They show as
`[removed]`. There is no parameter that returns them; do not look for one, and
do not ask the user to paste them.
