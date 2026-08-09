<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Types;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\ParserFactory;
use Throwable;

/**
 * A file's `use`-imports + declaring namespace, so an unqualified class name written in a
 * `#[Response(type: 'MfaChallengeData|…')]` attribute resolves the way PHP itself would: an alias /
 * imported short name wins, otherwise it is qualified against the file's namespace. Deliberately
 * simple (imports + same-namespace, no group-import edge cases beyond the common form) — a name it
 * cannot resolve is left unqualified for the downstream mapper to handle.
 */
final class ImportContext
{
    /**
     * @param  array<string, string>  $uses  lower-cased alias/short name → FQCN (no leading slash)
     */
    private function __construct(
        private readonly array $uses,
        private readonly ?string $namespace,
    ) {}

    public static function none(): self
    {
        return new self([], null);
    }

    public static function forFile(?string $file): self
    {
        if ($file === null || ! is_file($file)) {
            return self::none();
        }

        $code = @file_get_contents($file);
        if ($code === false) {
            return self::none();
        }

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        } catch (Throwable) {
            return self::none();
        }

        return $ast === null ? self::none() : self::fromStatements($ast);
    }

    /** Resolve an unqualified name the way PHP would in this file: alias/import, else same-namespace. */
    public function resolve(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return $name;
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $head = $segments[0];
        $key = strtolower($head);

        if (isset($this->uses[$key])) {
            return ltrim($this->uses[$key].substr($name, strlen($head)), '\\');
        }

        // A single unqualified segment (`MfaChallengeData`) whose head is not imported falls back to
        // the file's namespace — how PHP resolves an unqualified class reference. A name that already
        // carries its own path (`Workbench\App\Data\WidgetData`, an FQCN or a `::class` result) is left
        // as-is: the #[Response(type: '…')] convention writes either an imported short name or a full
        // FQCN, never a namespace-relative qualified name, so it must never be re-qualified.
        if (count($segments) === 1 && $this->namespace !== null && $this->namespace !== '') {
            return $this->namespace.'\\'.$name;
        }

        return $name;
    }

    /**
     * @param  array<Stmt>  $statements
     */
    private static function fromStatements(array $statements): self
    {
        foreach ($statements as $statement) {
            // A namespaced file: the namespace's own body carries the use statements.
            if ($statement instanceof Namespace_) {
                return new self(self::collectUses($statement->stmts), $statement->name?->toString());
            }
        }

        // No namespace declaration — file-level use statements only.
        return new self(self::collectUses($statements), null);
    }

    /**
     * @param  array<Stmt>  $statements
     * @return array<string, string>
     */
    private static function collectUses(array $statements): array
    {
        $uses = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Use_ && $statement->type === Use_::TYPE_NORMAL) {
                foreach ($statement->uses as $use) {
                    $uses[strtolower($use->getAlias()->toString())] = $use->name->toString();
                }

                continue;
            }

            if ($statement instanceof GroupUse) {
                $prefix = $statement->prefix->toString();
                foreach ($statement->uses as $use) {
                    if ($use->type === Use_::TYPE_UNKNOWN || $use->type === Use_::TYPE_NORMAL) {
                        $uses[strtolower($use->getAlias()->toString())] = $prefix.'\\'.$use->name->toString();
                    }
                }
            }
        }

        return $uses;
    }
}
