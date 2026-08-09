# docuccino/inference-phpstan

The PHPStan / Larastan type-inference engine for
[Docuccino](https://docuccino.app). It implements the core `TypeEngine`
contract, analysing your controllers and DTOs to infer response and exception
shapes from the real types in your code. You normally do not use this package
directly — `docuccino/laravel` wires it in behind the `TypeEngine` boundary —
but it can be consumed standalone by any adapter that depends on
`docuccino/core`.

## Install

```bash
composer require docuccino/inference-phpstan
```

## Usage

The engine is constructed behind the core `TypeEngine` interface and queried by
the pipeline:

```php
$analysis = $typeEngine->analyzeAction($actionRef); // ReturnSite[], ThrownException[], …
```

See `docuccino/laravel` for the wired-up integration.

## Documentation

Full documentation is at <https://docs.docuccino.app>; see
[getting started](https://docs.docuccino.app/getting-started/) for how the engine is wired in and
degrades to a `NullTypeEngine` on boot failure.

## License

MIT. See [LICENSE](LICENSE).
