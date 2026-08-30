# Database access

You are connected to a live application database over a read-only interface.

## What you can run

- `SELECT`, `WITH` (CTEs), and plain `EXPLAIN`.
- Everything else is refused before it reaches the database: writes, schema
  changes, `SET`, stored procedures, filesystem access, stacked statements.
- `EXPLAIN ANALYZE` and `EXPLAIN FOR CONNECTION` are refused too, because they
  execute the statement. Plain `EXPLAIN` does not, so it is free — use it when
  you are unsure whether a filter is indexed.

A refused query returns an error explaining why — a guardrail doing its job,
not a transient fault. Rewrite the query rather than retrying it.

## Working within the limits

- Queries are subject to a server-side timeout. Filter before you aggregate.
- A `LIMIT` is applied automatically when you do not supply one, so a result
  reaching the cap may be incomplete — check `truncated` in the response before
  concluding anything about totals.
- The response caps rows independently of the query's own `LIMIT`. `rowCount`
  is the true count; `showing` is how many you received.
- Long cell values are truncated.

## Finding your way around

- Read the schema resource first. It describes the main tables with their
  columns, types and foreign keys — usually enough to write a correct join
  without further calls.
- Use `describe-table` for anything it lists by name only.
- Prefer one focused query per question over one that answers everything.
