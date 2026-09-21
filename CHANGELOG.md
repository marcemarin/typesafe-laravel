# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Fluent, immutable request builder: `TypeSafe::state(...)->ask(...)->get()`.
- `Choice`, `Score` and `Noul` questions with client-side validation and backed-enum support
  (`Choice::fromEnum()`, `DescribedOption`).
- Readonly answer value objects: `ChoiceAnswer` (`as()`, `is()`, `probability()`), `ScoreAnswer`
  (`normalized()`, `level()`, `label()`) and `NoulAnswer` (`isTrue()`, `isFalse()`, `isUncertain()`), plus
  `Usage::costUsd()`.
- Typed exceptions: `AuthenticationException`, `ValidationException`, `RateLimitException`,
  `OverloadedException`, `ConnectionException`, `InvalidQuestionException`, `UnexpectedResponseException`, all
  extending `TypeSafeException`.
- Retries on HTTP 429 and 529 only, with exponential backoff and jitter (configurable).
- `TypeSafe::fake()` with `assertAsked()`, `assertAskedCount()` and `assertNothingAsked()`.
- `NoulRule` Laravel validation rule with `min` / `max` bounds, translatable messages and fail-open / fail-closed
  behaviour.
- Optional `->cacheFor($ttl)` using the Laravel cache.
- Auto-discovered service provider, `TypeSafe` facade alias and publishable `config/typesafe.php`.
