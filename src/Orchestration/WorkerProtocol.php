<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Orchestration;

use Docuccino\Core\Inference\ActionRef;

/**
 * The line-delimited JSON (NDJSON) wire format spoken between the parent
 * orchestrator and its workers over stdin/stdout (design §3). One JSON object per
 * line; every message carries a discriminator `t`:
 *
 *   parent → worker   `{"t":"a","id":..,"file":..,"class":..|null,"method":..,"line":..}`
 *   worker → parent   `{"t":"ready","engine":"phpstan"|"null"}`   (startup handshake)
 *                     `{"t":"r","id":..,"analysis":{…canonical ActionAnalysis…}}`
 *                     `{"t":"bye","reason":"recycle"|"rss"}`       (clean self-exit)
 *
 * The `id` is the {@see ActionRef::symbol()} so the parent can match a result to
 * its request regardless of which worker (or attempt) produced it.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class WorkerProtocol
{
    public const string ACTION = 'a';

    public const string RESULT = 'r';

    public const string READY = 'ready';

    public const string BYE = 'bye';

    public const string ENGINE_PHPSTAN = 'phpstan';

    public const string ENGINE_NULL = 'null';

    public static function encodeLine(mixed $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, mixed>|null null when the line is blank or not a JSON object
     */
    public static function decodeLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            return null;
        }

        $message = [];
        foreach ($decoded as $key => $value) {
            $message[(string) $key] = $value;
        }

        return $message;
    }

    /**
     * @return array{t: string, id: string, file: string, class: string|null, method: string, line: int}
     */
    public static function action(ActionRef $ref): array
    {
        return [
            't' => self::ACTION,
            'id' => $ref->symbol(),
            'file' => $ref->file,
            'class' => $ref->class,
            'method' => $ref->method,
            'line' => $ref->line,
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public static function actionRefFrom(array $message): ?ActionRef
    {
        $file = $message['file'] ?? null;
        $method = $message['method'] ?? null;
        if (! is_string($file) || ! is_string($method)) {
            return null;
        }

        $class = $message['class'] ?? null;
        $line = $message['line'] ?? 0;

        return new ActionRef(
            $file,
            is_string($class) ? $class : null,
            $method,
            is_int($line) ? $line : 0,
        );
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{t: string, id: string, analysis: array<string, mixed>}
     */
    public static function result(string $id, array $analysis): array
    {
        return ['t' => self::RESULT, 'id' => $id, 'analysis' => $analysis];
    }

    /**
     * @return array{t: string, engine: string}
     */
    public static function ready(string $engine): array
    {
        return ['t' => self::READY, 'engine' => $engine];
    }

    /**
     * @return array{t: string, reason: string}
     */
    public static function bye(string $reason): array
    {
        return ['t' => self::BYE, 'reason' => $reason];
    }
}
