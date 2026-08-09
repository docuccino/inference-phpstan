# docuccino/inference-phpstan

> **This repository is a read-only subtree split** of [docuccino/docuccino](https://github.com/docuccino/docuccino).
> Open issues and pull requests on the monorepo — commits pushed here are overwritten.

The PHPStan / Larastan type-inference engine for
[Docuccino](https://docuccino.app). It implements the core `TypeEngine`
contract, analysing your controllers and DTOs to infer response and exception
shapes from the real types in your code. You normally do not use this package
directly — `docuccino/laravel` wires it in behind the `TypeEngine` boundary —
but it can be consumed standalone by any adapter that depends on
`docuccino/core`.

## Install

Analysis is a build-time job, so install it as a **dev** dependency:

```bash
composer require --dev docuccino/inference-phpstan
```

It brings PHPStan and Larastan with it. `docuccino/laravel` finds it at runtime and falls
back to docblock- and attribute-only documentation (with a warning) when it is absent.

## Usage

The engine is constructed behind the core `TypeEngine` interface and queried by
the pipeline:

```php
$analysis = $typeEngine->analyzeAction($actionRef); // ReturnSite[], ThrownException[], …
```

See `docuccino/laravel` for the wired-up integration.

## Documentation

Full documentation is at <https://docs.docuccino.app>; see
[getting started](https://docs.docuccino.app/laravel/getting-started/) for how the engine is wired in and
degrades to a `NullTypeEngine` on boot failure.

## License

MIT. See [LICENSE](LICENSE).
