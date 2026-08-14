<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Cache;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Support\GeneratedDirectory;

/**
 * A filesystem-backed {@see EngineResultCache}, laid out as `<baseDir>/{actions,classes}/<primary>.json`
 * where `<primary>` = sha256(fingerprint prefix ‖ entry identity ‖ source-file hash). Each entry also
 * embeds a `dependencyFiles` manifest (path → hash) and a `contentKey` folding those hashes in, which is
 * what makes invalidation sound: a lookup re-hashes every recorded dependency, and a missing file, a
 * changed hash or a `contentKey` that no longer recomputes is a miss — so an edit three calls deep is
 * caught. The stored payload is the value's canonical `toArray()`, so a hit round-trips to byte-identical
 * output.
 *
 * Writes go to a unique temp file and are `rename()`d into place, atomic on one filesystem; reads tolerate
 * absent, partial and corrupt files. Paths are never identity — only their content hashes are.
 *
 * @internal
 */
final readonly class FilesystemEngineResultCache implements EngineResultCache
{
    public function __construct(private string $baseDir) {}

    public function getAction(ActionRef $action, VersionFingerprint $fingerprint): ?ActionAnalysis
    {
        $entry = $this->load('actions', $this->actionKey($action, $fingerprint));
        if ($entry === null) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = $entry['payload'];

        return ActionAnalysis::fromArray($payload);
    }

    public function putAction(ActionRef $action, ActionAnalysis $analysis, VersionFingerprint $fingerprint): void
    {
        $payload = $analysis->toArray();
        /** @var list<string> $deps */
        $deps = $payload['dependencyFiles'];

        $this->store('actions', $this->actionKey($action, $fingerprint), $payload, $deps);
    }

    public function getClass(ClassRef $class, VersionFingerprint $fingerprint): ?ClassMetadata
    {
        $entry = $this->load('classes', $this->classKey($class, $fingerprint));
        if ($entry === null) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = $entry['payload'];

        return ClassMetadata::fromArray($payload);
    }

    public function putClass(ClassRef $class, ClassMetadata $metadata, VersionFingerprint $fingerprint): void
    {
        // A class's only dependency is its own source file; the fingerprint covers everything else.
        $deps = $class->file !== null ? [$class->file] : [];

        $this->store('classes', $this->classKey($class, $fingerprint), $metadata->toArray(), $deps);
    }

    private function actionKey(ActionRef $action, VersionFingerprint $fingerprint): string
    {
        return hash('sha256', implode("\0", [
            $fingerprint->prefix(),
            'action',
            $action->class ?? '',
            $action->method,
            $action->file,
            self::fileHash($action->file),
        ]));
    }

    private function classKey(ClassRef $class, VersionFingerprint $fingerprint): string
    {
        return hash('sha256', implode("\0", [
            $fingerprint->prefix(),
            'class',
            $class->fqcn,
            $class->file ?? '',
            $class->file !== null ? self::fileHash($class->file) : '',
        ]));
    }

    /**
     * @return array{contentKey: string, dependencyFiles: array<string, string>, payload: array<string, mixed>}|null
     */
    private function load(string $bucket, string $key): ?array
    {
        $path = $this->pathFor($bucket, $key);
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)
            || ! isset($decoded['contentKey'], $decoded['dependencyFiles'], $decoded['payload'])
            || ! is_string($decoded['contentKey'])
            || ! is_array($decoded['dependencyFiles'])
            || ! is_array($decoded['payload'])
        ) {
            return null;
        }

        /** @var array<string, string> $manifest */
        $manifest = $decoded['dependencyFiles'];
        if (! $this->manifestStillValid($manifest)) {
            return null;
        }

        if (self::contentKey($key, $manifest) !== $decoded['contentKey']) {
            return null;
        }

        /** @var array{contentKey: string, dependencyFiles: array<string, string>, payload: array<string, mixed>} $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $dependencyFiles
     */
    private function store(string $bucket, string $key, array $payload, array $dependencyFiles): void
    {
        $manifest = [];
        foreach ($dependencyFiles as $file) {
            $manifest[$file] = self::fileHash($file);
        }
        ksort($manifest);

        $entry = [
            'contentKey' => self::contentKey($key, $manifest),
            'dependencyFiles' => $manifest,
            'payload' => $payload,
        ];

        $this->atomicWrite($this->pathFor($bucket, $key), json_encode($entry, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, string>  $manifest
     */
    private function manifestStillValid(array $manifest): bool
    {
        foreach ($manifest as $file => $hash) {
            if (self::fileHash($file) !== $hash) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $manifest
     */
    private static function contentKey(string $primaryKey, array $manifest): string
    {
        ksort($manifest);
        $parts = [$primaryKey];
        foreach ($manifest as $file => $hash) {
            $parts[] = $hash;
        }

        return hash('sha256', implode("\0", $parts));
    }

    private static function fileHash(string $file): string
    {
        return is_file($file) ? (string) hash_file('sha256', $file) : 'missing';
    }

    private function pathFor(string $bucket, string $key): string
    {
        return $this->baseDir.'/'.$bucket.'/'.$key.'.json';
    }

    private function atomicWrite(string $path, string $contents): void
    {
        GeneratedDirectory::ensure(dirname($path));

        // random_int over bin2hex(random_bytes(…)): its int return type is unambiguous in every supported
        // analyser version, and 63 bits of entropy beats the 48 it replaces.
        $tmp = $path.'.'.getmypid().'.'.dechex(random_int(0, PHP_INT_MAX)).'.tmp';
        if (@file_put_contents($tmp, $contents) === false) {
            return;
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
        }
    }
}
