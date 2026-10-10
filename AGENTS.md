# productimport

PrestaShop 9.x module at repo root. Scrapers live elsewhere; JSON/XML feeds are the interface.

## Principles

- Match verification to risk. Parsing and mapping logic: unit tests. Anything that changes how products reach the store: store integration.
- Never point tests at a production store. Never commit vendor/, secrets, real store exports or local-dev/.env.
- Production runs PHP 8.3; PHPUnit 13 needs 8.4. Use the isolated Docker setup for PHP-version-sensitive work. Don't install PHP, Composer or database software globally.
- Follow PrestaShop escaping and database conventions. Validate input. Preserve cron token protection.
- If you didn't run something, say so. Passing unit tests is not store verification.

## Challenge requests

- Treat the user's requests as ideas to examine, not specs to execute. Before building, check them against PrestaShop conventions, industry practice and how feed-import tools usually work.
- If a request conflicts with common practice, adds risk, or has a simpler or more standard alternative, say so and ask before implementing. Give the trade-off and a recommendation.
- Ask when a request is ambiguous. Don't silently choose an interpretation or quietly reduce scope.

## Live testing

Store integration, the Docker setup, example sources, and verification checks are in docs/store-testing.md. Read it when a change needs a live store. Don't read it for changes that don't.

## Hard limits

- No `docker compose down -v` without explicit approval; it erases the local test store.
- Ask before changing public behavior or database schema.

## Reporting

End with: what changed; what you ran and the result; whether store integration ran (and why not, if not); what's untested.

## Delegation

Make code changes through a worker subagent, even for small edits. The orchestrator plans, dispatches, reviews, and reports; it does not edit product files itself.