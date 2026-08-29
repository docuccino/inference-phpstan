# docuccino/inference-phpstan

[![Latest version](https://img.shields.io/packagist/v/docuccino/inference-phpstan?label=packagist)](https://packagist.org/packages/docuccino/inference-phpstan)
[![Downloads](https://img.shields.io/packagist/dt/docuccino/inference-phpstan)](https://packagist.org/packages/docuccino/inference-phpstan)
[![PHP version](https://img.shields.io/packagist/dependency-v/docuccino/inference-phpstan/php)](https://packagist.org/packages/docuccino/inference-phpstan)
[![CI](https://github.com/docuccino/docuccino/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/docuccino/docuccino/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/docuccino/inference-phpstan)](LICENSE)

**PHPStan and Larastan type inference for [Docuccino](https://docuccino.app)** — it reads the real
types in your code to infer what your endpoints return and which exceptions they throw.

This is what makes documentation reflect your application rather than your annotations. It analyzes
controllers, actions and the types they hand back, narrows thrown types so `instanceof` branches
resolve, and reports response and exception shapes behind core's `TypeEngine` contract. There is no
Docuccino-specific type system to learn: your own PHPStan extensions improve your documentation for
free.

You do not normally use this package directly —
**[`docuccino/laravel`](https://packagist.org/packages/docuccino/laravel)** wires it in — but any
adapter built on `docuccino/core` can consume it.

## Install

Analysis is a build-time job, so install it as a **dev** dependency:

```bash
composer require --dev docuccino/inference-phpstan
```

It brings PHPStan and Larastan with it. `docuccino/laravel` finds it at runtime and falls back to
docblock- and attribute-only documentation — with a warning on every export — when it is absent, so
a production install that ships neither package still boots.

## Usage

The engine is constructed behind the core `TypeEngine` interface and queried by the pipeline:

```php
$analysis = $typeEngine->analyzeAction($actionRef); // ReturnSite[], ThrownException[], …
```

See [`docuccino/laravel`](https://packagist.org/packages/docuccino/laravel) for the wired-up
integration.

## Part of Docuccino

| Package | Role |
| --- | --- |
| [`docuccino/laravel`](https://packagist.org/packages/docuccino/laravel) | The Laravel adapter: provider, config, commands, viewer, integrations. **Start here.** |
| [`docuccino/core`](https://packagist.org/packages/docuccino/core) | Framework-agnostic document model, canonicalizer, identities, emitters, diff. |
| **`docuccino/inference-phpstan`** ← you are here | PHPStan + Larastan type inference. Install as a **dev** dependency. |
| [`docuccino/attributes`](https://packagist.org/packages/docuccino/attributes) | Dependency-free PHP attribute classes. |

## Documentation

Full documentation is at **[docs.docuccino.app](https://docs.docuccino.app)**; see
[getting started](https://docs.docuccino.app/laravel/getting-started/) for how the engine is wired in
and how it degrades when it cannot boot.

## Issues and contributing

**This repository is a read-only subtree split** of
[docuccino/docuccino](https://github.com/docuccino/docuccino). Open issues and pull requests on the
monorepo — commits pushed here are overwritten. See
[CONTRIBUTING.md](https://github.com/docuccino/docuccino/blob/main/CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
