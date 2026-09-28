<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Inference\PhpStan\Extensions\VendorConcern;
use ReflectionClass;

/**
 * Who wrote the `toResponse()` a Responsable is sent through. The router renders ANY returned Responsable
 * through that method, and `JsonResource::response()` is the framework's own call of it on the current
 * request, so the file it is declared in decides the whole response: the framework's resource rendering
 * sends exactly what returning the resource bare sends, while an application's sends whatever it builds.
 *
 * Every path is read off the reflection handed in, never PHP's own: the analyser spells a file as the
 * autoloader recorded it, and `$isProject` is the predicate that normalises that spelling.
 *
 * @internal
 */
final class ResponsableRendering
{
    /** The router calls `toResponse()` on any returned object implementing this. */
    private const RESPONSABLE = 'Illuminate\\Contracts\\Support\\Responsable';

    /** The one Responsable family whose framework-written rendering the adapter describes from the class. */
    private const JSON_RESOURCE = 'Illuminate\\Http\\Resources\\Json\\JsonResource';

    public const TO_RESPONSE = 'toResponse';

    /** `JsonResource::response($request = null)`: the framework's `toResponse()` on the current request. */
    public const RESPONSE = 'response';

    /** The framework renders the object: its class describes the body, and its status is its own. */
    public const FRAMEWORK = 'framework';

    /** The application wrote `toResponse()`: that method's body is the response. */
    public const APPLICATION = 'application';

    /**
     * Who answers `$method` called on an object of `$class`, or null when that is not a Responsable
     * rendering itself — another method, a class that is not Responsable, a `response()` the application
     * wrote (a project helper like any other), or a vendor `toResponse()` outside the resource family, which
     * nothing here can describe.
     *
     * @param  ReflectionClass<object>  $class
     * @param  callable(string): bool  $isProject
     * @return self::FRAMEWORK|self::APPLICATION|null
     */
    public static function of(ReflectionClass $class, string $method, callable $isProject): ?string
    {
        if (($method !== self::TO_RESPONSE && $method !== self::RESPONSE) || ! $class->implementsInterface(self::RESPONSABLE)) {
            return null;
        }

        $resource = $class->getName() === self::JSON_RESOURCE || $class->isSubclassOf(self::JSON_RESOURCE);
        if ($method === self::RESPONSE && (! $resource || self::declaredInProject($class, self::RESPONSE, $isProject))) {
            return null;
        }

        if (self::declaredInProject($class, self::TO_RESPONSE, $isProject)) {
            return self::APPLICATION;
        }

        return $resource ? self::FRAMEWORK : null;
    }

    /**
     * The project files of the class's hierarchy ({@see DeclarationFiles}) — where a closer override could
     * be ADDED, so every answer about who renders the object depends on each of them.
     *
     * @param  ReflectionClass<object>  $class
     * @param  callable(string): bool  $isProject
     * @return list<string>
     */
    public static function projectFiles(ReflectionClass $class, callable $isProject): array
    {
        return array_values(array_filter(DeclarationFiles::forClass($class), $isProject(...)));
    }

    /**
     * Whether the body `$method` runs is one the application wrote — asked of the METHOD's file, which for
     * a trait-supplied body is the trait's, while the declaring class names whoever `use`d it
     * ({@see VendorConcern}).
     *
     * @param  ReflectionClass<object>  $class
     * @param  callable(string): bool  $isProject
     */
    private static function declaredInProject(ReflectionClass $class, string $method, callable $isProject): bool
    {
        if (! $class->hasMethod($method)) {
            return false;
        }

        $file = $class->getMethod($method)->getFileName();

        return $file !== false && $isProject($file);
    }
}
