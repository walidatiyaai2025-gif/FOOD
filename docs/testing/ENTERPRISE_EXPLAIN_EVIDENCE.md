# EXPLAIN Evidence Tool

Issue: #1508  
Parent: #1488

`Tests\\Performance\\Support\\ExplainEvidenceTool` captures repeatable read-only query-plan
evidence for changed critical queries.

Rules:

- only `SELECT` / `WITH` statements are accepted;
- bindings may be supplied to the database for planning but are never written to evidence;
- SQL is normalized with the shared query fingerprinting primitive;
- MySQL/MariaDB `EXPLAIN` and SQLite `EXPLAIN QUERY PLAN` are supported;
- obvious full scans are flagged only when the table is explicitly declared a growing table;
- the tool reports evidence; it does not create speculative indexes.

A critical query that reaches the >=250ms investigation threshold should attach this evidence when the
query or its access path changed.
