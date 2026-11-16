<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Inference\PhpStan\Support\ContentTypeHeader;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;

/**
 * Peels the fluent tail off a response expression — `->setStatusCode(202)`, `->header('X-Total', $n)`,
 * `->setData($rows)` — so {@see ResponseShapeRefiner} can refine the response where it was BUILT and then
 * apply what the chain restated. Without this a status the application states plainly is lost, or worse
 * inherited from the receiver: `response()->json($body)` types as `JsonResponse<…, 200>` and
 * `setStatusCode()` returns `static`, so the 200 survives a `->setStatusCode(201)` unchallenged.
 *
 * A call whose RECEIVER is a response the refiner reads where it is built — a framework one, a file, stream or
 * redirect among them, or an application's subclass of one ({@see ResponseShapeRefiner::responseRoot()}) — and
 * that the INSTALLED framework declares as handing the same object back is a link; any other call is the
 * PRODUCER the chain was built on, and the refiner takes it from there. Each link is read for the three facts
 * the document carries — the status, the media type and the body — and a link reports only the facts it can
 * touch ({@see LINKS}): a `->cookie()` or `->setMaxAge(60)` touches none, a `->setData($rows)` the body alone, and one this cannot
 * read is UNKNOWN in each fact it could have set, never refused. Refusing would leave the receiver's own
 * shape standing where the code plainly replaced it — the `JsonResponse<…, 200>` of `response()->json()`
 * published for a status the endpoint may never send. A link not in {@see LINKS}, a call this cannot name,
 * and one an application's subclass overrides may have touched any of the three, so all three are unknown and
 * the response keeps only its class.
 *
 * The status is read as the refiner reads every other one, so `$ok ? 200 : 503` is both codes and
 * `Reason::Expired->status()` the one its case maps to; a body is read as the response's constructor reads
 * the same argument.
 *
 * @phpstan-import-type ChainLink from LinkFacts
 *
 * @phpstan-type PeeledChain array{receiver: Node\Expr, status: LiteralT|UnionT|null, statusUnknown: bool, contentType: string|null, contentTypeUnknown: bool, body: DType|null, bodyUnknown: bool}
 *
 * @internal
 */
final class FluentResponseChain
{
    /**
     * Every link read by name, and what it touches. `withStatus()` is absent: it is PSR-7's setter, which no
     * response class read here declares. So are `prepare()`, which may label, strip or re-status the response
     * for the request it is handed, and the `send…()` methods, which write it out.
     */
    private const LINKS = [
        'setStatusCode' => self::STATUS,
        'header' => self::HEADER,
        'withHeaders' => self::HEADERS,
        'withoutHeader' => self::WITHOUT_HEADER,
        'setContent' => self::CONTENT,
        'setData' => self::DATA,
        'setJson' => self::JSON,
        'setEncodingOptions' => self::ENCODING,
        'setCallback' => self::CALLBACK,
        'withCallback' => self::CALLBACK,
        'setNotModified' => self::NOT_MODIFIED,
        'cookie' => self::UNDOCUMENTED,
        'withCookie' => self::UNDOCUMENTED,
        'withoutCookie' => self::UNDOCUMENTED,
        'withException' => self::UNDOCUMENTED,
        'setProtocolVersion' => self::UNDOCUMENTED,
        // The charset is a parameter appended to a text media type, never the media type itself.
        'setCharset' => self::UNDOCUMENTED,
        'setCache' => self::UNDOCUMENTED,
        'setPublic' => self::UNDOCUMENTED,
        'setPrivate' => self::UNDOCUMENTED,
        'setImmutable' => self::UNDOCUMENTED,
        'setMaxAge' => self::UNDOCUMENTED,
        'setSharedMaxAge' => self::UNDOCUMENTED,
        'setTtl' => self::UNDOCUMENTED,
        'setClientTtl' => self::UNDOCUMENTED,
        'setStaleIfError' => self::UNDOCUMENTED,
        'setStaleWhileRevalidate' => self::UNDOCUMENTED,
        'setEtag' => self::UNDOCUMENTED,
        'setLastModified' => self::UNDOCUMENTED,
        'setExpires' => self::UNDOCUMENTED,
        'setDate' => self::UNDOCUMENTED,
        'setVary' => self::UNDOCUMENTED,
        'expire' => self::UNDOCUMENTED,
        // A download's own: whether the file outlives the send, and headers no fact reads.
        'deleteFileAfterSend' => self::UNDOCUMENTED,
        'setAutoEtag' => self::UNDOCUMENTED,
        'setAutoLastModified' => self::UNDOCUMENTED,
        // Content-Disposition names how the file is saved, never its media type.
        'setContentDisposition' => self::UNDOCUMENTED,
        // A redirect's own: what it flashes to the session, its cookies, and its target's fragment.
        'with' => self::UNDOCUMENTED,
        'withErrors' => self::UNDOCUMENTED,
        'withInput' => self::UNDOCUMENTED,
        'withCookies' => self::UNDOCUMENTED,
        'withFragment' => self::UNDOCUMENTED,
        'withoutFragment' => self::UNDOCUMENTED,
    ];

    /** Sets the status. */
    private const STATUS = 'status';

    /** Sets one header by name and value: the media type, or nothing documented. */
    private const HEADER = 'header';

    /** Sets a map of headers at once. */
    private const HEADERS = 'headers';

    /** Removes headers by name: the media type, or nothing documented. */
    private const WITHOUT_HEADER = 'without-header';

    /** Replaces the body with content, as the constructor's first argument would. */
    private const CONTENT = 'content';

    /** Replaces a JSON body with data, as the constructor's first argument would. */
    private const DATA = 'data';

    /** Replaces a JSON body with JSON text nobody here decodes. */
    private const JSON = 'json';

    /** Re-encodes a JSON body, which only some flags change the shape of. */
    private const ENCODING = 'encoding';

    /** Wraps a JSON body in a JavaScript call, labelled as JavaScript, unless the callback is null. */
    private const CALLBACK = 'callback';

    /** Makes the response a 304 with no body and no media type. */
    private const NOT_MODIFIED = 'not-modified';

    /** Sets state no fact the document carries reads: a cookie, a cache header, the protocol, the charset. */
    private const UNDOCUMENTED = 'undocumented';

    /**
     * @param  Closure(Node\Expr, Scope): (LiteralT|UnionT|null)  $statusIn  the refiner's status reader
     * @param  Closure(string): ?string  $rootOf  the framework response a class is or derives from, where the refiner reads it ({@see ResponseShapeRefiner::responseRoot()})
     * @param  Closure(Type): ?DType  $payloadIn  the refiner's reader of a JSON body
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly TypeTranslator $translator,
        private readonly Closure $statusIn,
        private readonly Closure $rootOf,
        private readonly Closure $payloadIn,
    ) {}

    /**
     * The receiver the chain was built on plus what its links stated, or null when there is no chain here
     * — either nothing was peeled, or a call on the response hands back something other than it.
     *
     * Merging is outermost-first because the outermost call is the one that ran LAST: `->setStatusCode(201)
     * ->setStatusCode(202)` sends a 202, and a replacing `Content-Type` header works the same way.
     *
     * @return PeeledChain|null
     */
    public function peel(Node\Expr $expr, Scope $scope): ?array
    {
        $status = null;
        $statusUnknown = false;
        $contentType = null;
        $unknown = false;
        $body = null;
        $bodyUnknown = false;
        $current = $expr;
        $peeled = false;

        while ($current instanceof Node\Expr\MethodCall) {
            $link = $this->readLink($current, $scope);
            if ($link === null) {
                break; // a producer, not a mutator — the shape is this expression's
            }
            if ($link === false) {
                return null; // a call on a response that hands back something else: no chain to read
            }

            // Whichever fact a link settles, it settles for the chain: an inner link cannot undo an outer
            // one, and neither can it undo an outer one this could not read.
            if ($status === null && ! $statusUnknown) {
                $status = $link['status'];
                $statusUnknown = $link['statusUnknown'];
            }
            if ($contentType === null && ! $unknown) {
                $contentType = $link['contentType'];
                $unknown = $link['contentTypeUnknown'];
            }
            if ($body === null && ! $bodyUnknown) {
                $body = $link['body'];
                $bodyUnknown = $link['bodyUnknown'];
            }
            $current = $current->var;
            $peeled = true;
        }

        return $peeled
            ? ['receiver' => $current, 'status' => $status, 'statusUnknown' => $statusUnknown, 'contentType' => $contentType, 'contentTypeUnknown' => $unknown, 'body' => $body, 'bodyUnknown' => $bodyUnknown]
            : null;
    }

    /**
     * One link of a chain, in three answers: `null` = the call is not a mutation of a response at all (its
     * receiver is something else), so it is the base; `false` = it does not hand the response back, so the
     * chain is off; an array = what this link stated, each fact it cannot touch left as it was.
     *
     * @return ChainLink|false|null
     */
    private function readLink(Node\Expr\MethodCall $call, Scope $scope): array|false|null
    {
        $receiver = $this->translator->translate($scope->getType($call->var));
        $root = $receiver instanceof ClassT ? ($this->rootOf)($receiver->fqcn) : null;
        if (! $receiver instanceof ClassT || $root === null) {
            return null;
        }

        if (! $call->name instanceof Node\Identifier) {
            return LinkFacts::unread(); // `$response->{$method}()` — a mutation this cannot even name
        }

        $name = $call->name->toString();
        $declared = $this->declared($receiver->fqcn, $name);
        if ($declared === null || $call->isFirstClassCallable()) {
            return false;
        }

        // An application's own override may do anything, whatever the framework's method of the name does.
        $kind = $declared['own'] ? null : (self::LINKS[$name] ?? null);
        $args = ArgumentSlots::of($call->getArgs(), $declared['parameters']);

        $first = $args->at(0) === null ? null : $scope->getType($args->at(0));

        return match ($kind) {
            self::STATUS => $this->readStatus($args, $scope),
            self::HEADER => $this->readHeader($args, $scope),
            self::HEADERS => $this->readHeaders($args, $scope),
            self::WITHOUT_HEADER => LinkFacts::withoutHeader($first),
            self::CONTENT => LinkFacts::content($first, $root, $this->payloadIn),
            self::DATA => LinkFacts::data($first, $this->payloadIn),
            self::JSON => ['bodyUnknown' => true] + LinkFacts::untouched(),
            self::ENCODING => LinkFacts::encoding($first),
            self::CALLBACK => LinkFacts::callback($first, $args->knows(0)),
            self::NOT_MODIFIED => LinkFacts::notModified(),
            self::UNDOCUMENTED => LinkFacts::untouched(),
            default => LinkFacts::unread(),
        };
    }

    /**
     * A method as the INSTALLED framework declares it — its parameter names, and whether the application
     * wrote it — or null when what it declares is not a link at all: no such method, or one that does not
     * hand back the object it was called on (`static`, or a `@return $this` — which is how the framework's
     * own trait spells the header setters). A vendor package's fluent grammar is a function of the version
     * the app resolved, so this reads that version's own reflection rather than the one it was written
     * against; a release that moved either fact degrades here instead of publishing this major's dialect.
     *
     * @return array{parameters: list<string>, own: bool}|null
     */
    private function declared(string $fqcn, string $method): ?array
    {
        if (! $this->reflectionProvider->hasClass($fqcn)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($fqcn);
        if (! $class->hasNativeMethod($method)) {
            return null;
        }

        $reflection = $class->getNativeMethod($method);
        $variant = $reflection->getVariants()[0];
        if (! $variant->getReturnType() instanceof StaticType) {
            return null;
        }

        $names = [];
        foreach ($variant->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }
        $declaring = $reflection->getDeclaringClass()->getName();
        $root = ($this->rootOf)($declaring);

        return ['parameters' => $names, 'own' => $root !== null && $root !== $declaring];
    }

    /**
     * `setStatusCode(202)`, `setStatusCode($ok ? 200 : 503)` as both codes, or a read on an enum case as the
     * code its case maps to. A status this build cannot fold is still a status the code states, so it is
     * reported unknown: passing the receiver's through would publish one the endpoint may never send.
     *
     * @return ChainLink
     */
    private function readStatus(ArgumentSlots $args, Scope $scope): array
    {
        $code = $args->at(0);
        $status = $code === null ? null : ($this->statusIn)($code, $scope);

        return ['statusUnknown' => $status === null, 'status' => $status] + LinkFacts::untouched();
    }

    /**
     * `header('Content-Type', 'application/problem+json')` states the media type; `header('X-Total', $n)`
     * states nothing this documents. Anything in between — a name that will not fold, a value that will
     * not, a `$replace` that is not provably true (appending a second `Content-Type` is not the same claim
     * as setting one) — leaves the media type unknown, which is the whole of what a header can affect.
     *
     * @return ChainLink
     */
    private function readHeader(ArgumentSlots $args, Scope $scope): array
    {
        $name = $args->at(0);
        $folded = $name === null ? null : ScalarFold::of($scope->getType($name));
        if ($folded === null || ! is_string($folded[0])) {
            return LinkFacts::unknownMediaType();
        }
        if (! ContentTypeHeader::names($folded[0])) {
            return LinkFacts::untouched();
        }

        $replace = $args->at(2);
        $value = $args->at(1);
        if (($replace !== null && ! $scope->getType($replace)->isTrue()->yes()) || $value === null) {
            return LinkFacts::unknownMediaType();
        }

        $media = ScalarFold::of($scope->getType($value));

        return $media !== null && is_string($media[0])
            ? ['contentType' => $media[0]] + LinkFacts::untouched()
            : LinkFacts::unknownMediaType();
    }

    /**
     * `withHeaders(['Content-Type' => '…'])`. Only a written array can be read: a map assembled elsewhere
     * may carry the media type without saying so here, and a link that might have set one is not a link
     * that provably set none.
     *
     * @return ChainLink
     */
    private function readHeaders(ArgumentSlots $args, Scope $scope): array
    {
        $headers = $args->at(0);
        if (! $headers instanceof Node\Expr\Array_) {
            return LinkFacts::unknownMediaType();
        }

        return ['contentType' => ContentTypeHeader::inArray($headers, $scope)] + LinkFacts::untouched();
    }
}
