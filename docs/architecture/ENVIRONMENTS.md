# Environments

FOODEX separates local, CI/test, staging/demo and production configuration.

- Commit only `.env.example`.
- Never commit secrets.
- Production uses PostgreSQL and Redis.
- CI may use SQLite for deterministic migration/smoke validation while PostgreSQL compatibility remains a release gate.
- Environment-specific URLs, mail, payment keys, object storage and mobile store URLs are external configuration.
