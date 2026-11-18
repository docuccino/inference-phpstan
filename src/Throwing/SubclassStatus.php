<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Inference\PhpStan\Analysis\ParamAccessor;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The status a subclass pins on itself, where its ROOT takes the status as a constructor argument: an
 * `HttpException` ({@see ofHttpExceptions()}), or one of the framework's response classes
 * ({@see ofResponses()}), where `class GoneResponse extends Response` handing `parent::__construct('', 410)`
 * is how an application says "this response IS a 410". Everything below is written about exceptions and holds
 * for both; a response may also set its status in its own constructor ({@see setterCall()}).
 *
 * `HttpException` takes its status as a constructor argument, so a subclass that fixes one — which is how
 * an application says "this exception IS a 409" — states it in its own `parent::__construct()` call,
 * somewhere no name-keyed table can see. Reading it is what keeps a domain exception off the 500 a lookup
 * miss publishes, and 500 is the one answer that is never merely vague: it names a failure the server does
 * not have, on an endpoint whose only rejection is a 409.
 *
 * Two shapes pin a status, and the same applications carry both: a constant reaching the parent call
 * (`parent::__construct(422, …)`), and a constructor parameter with a constant default forwarded to it
 * (`__construct(array $errors, int $statusCode = 422)`, the static-factory idiom). The second is pinned
 * only where the class controls every construction — a PRIVATE constructor, no trait that could construct
 * out of sight, and no in-class `new self(...)` writing that slot — because a caller free to pass another
 * value makes the default a guess rather than a fact. Where the class pins nothing but forwards a
 * parameter, {@see statusParameter()} names the slot so a construction the build can see — a
 * `throw new X(423, …)`, or the `new self(…)` inside the factory a `throw` names ({@see FactoryStatus}) —
 * can still be folded at its own site.
 *
 * A slot may also be read THROUGH the value it holds: `parent::__construct($reason->status(), …)` forwards
 * the `$reason` slot, and the status is what the enum case a construction passes there maps to
 * ({@see statusRead()}, folded by {@see CaseStatus}). A reason enum is how an application writes a refusal
 * and its status in one place, and every construction reads exactly like one passing the number.
 *
 * A class only FORWARDS a parameter it hands over untouched. A constructor that writes the variable
 * anywhere in its body (`if ($errors === []) { $statusCode = 400; }`) gives the parent a value neither the
 * caller nor the default names, so it names no slot and pins nothing: what a construction filling that slot
 * would say is not what the response is.
 *
 * Anything else answers null, which means "this class knows a status this build does not" — a different
 * claim from the 500 that means "no HTTP status at all", and the reason the two are not one return value.
 *
 * A class inherits its parent's answer along with its constructor, so the walk is up the hierarchy: a
 * literal the parent pins is the subclass's status too, and the slot the parent forwards is the slot the
 * subclass's own `parent::__construct()` writes into. `HttpException` itself is the base case — argument 0
 * of its constructor IS the status.
 *
 * Only a class the APPLICATION declares has its constructor read, and that means every source root the
 * adapter primes — `app/` and a modular `Modules\…` root alike — never the narrower set interprocedural
 * descent is bounded by. Not as a policy but as a measurement: PHPStan hands back an UNPRIMED file with its
 * bodies stripped, so a vendor subclass's `parent::__construct(409, …)` arrives as an empty statement list
 * and the read declines anyway — while asking for it primes that file, growing the analysed set and
 * discarding every recorded walk the replay layer holds. The gate turns a cost with no answer behind it
 * into neither, and it is the same gate {@see FactoryStatus} applies one hop on.
 *
 * That argument is about PRIMING, so it reaches vendor and stops there. A primed root is already in the
 * analysed set: its bodies are intact, and reading one grows nothing. A read scoped to the descend paths
 * instead would decline for a class the build had already loaded and could have folded — publishing a
 * placeholder 500 for an exception whose 409 is written two lines into a file this build holds open, and
 * nothing recorded for a notice to be actionable about.
 *
 * Priming is also why the reads below cost the replay layer nothing, which is worth recording so it is not
 * re-litigated: the adapter primes the application's whole PSR-4 tree at boot, so every one of its
 * exception classes is already in the analysed set and reading one cannot grow it. Measured over one build
 * of the fixture app's throw corpus when it held 58 (it holds 172 throw actions now, and every one added
 * since depends only on files those already did, but for the nine helper rows, the seventeen reason-enum
 * rows, the twenty-five assertion rows and the four reading a framework parent, a transaction closure or
 * a private guard, whose enums, exception classes and services sit in the same primed tree, not
 * re-measured), the
 * analysed-file count is the same whether the reads are scoped to the application or to the descend
 * paths — so no recording is ever discarded, and the modular root is no different because being primed
 * is the whole of what makes that true. What the wider scope costs is one more live file walk, for an
 * exception class nothing else opened.
 *
 * @phpstan-type StatusPin array{status: int|null, parameter: int|null, read: ParamAccessor|null, files: list<string>}
 * @phpstan-type AgreedRead array{status: int|null, files: list<string>}
 * @phpstan-type ConstructorSlot array{names: list<string>, default: int|null}
 * @phpstan-type RootSlot array{slot: int, default: int|null}
 *
 * @internal
 */
final class SubclassStatus
{
    /** Argument 0 of `HttpException::__construct` is the status, and it has no default. */
    private const HTTP_EXCEPTION_SLOT = 0;

    /** The packages whose own `HttpException` children pin a status an application subclass inherits. */
    private const FRAMEWORK_NAMESPACES = ['Symfony\\Component\\HttpKernel\\', 'Illuminate\\'];

    /**
     * {@see frameworkPin()} by FQCN, the declines included.
     *
     * @var array<string, int|null>
     */
    private array $frameworkPins = [];

    /** The framework's own responses, each taking its status at the parameter its constructor names this. */
    private const RESPONSE_STATUS = 'status';

    /** What a response's own constructor may set its status with after the parent's has run. */
    private const RESPONSE_SETTER = 'setStatusCode';

    /**
     * Resolutions by FQCN. Seeded before the work so a hierarchy that cannot terminate answers rather than
     * recurses, and kept for the whole build so one exception class is read once however many routes throw
     * it.
     *
     * @var array<string, StatusPin>
     */
    private array $cache = [];

    /**
     * {@see agreed()} by FQCN, kept for the same reason and holding its declines, so a class that agrees
     * on nothing is memoised rather than re-read per route.
     *
     * @var array<string, AgreedRead>
     */
    private array $agreed = [];

    /**
     * @param  array<string, RootSlot>  $roots  each root class, the slot its constructor takes the status in,
     *                                          and the status a call leaving that slot empty passes
     * @param  string|null  $setter  the method a subclass's constructor may restate the status with
     */
    private function __construct(
        private readonly ClassBodies $bodies,
        private readonly ProjectFilter $appFilter,
        private readonly array $roots,
        private readonly ?string $setter = null,
    ) {}

    public static function ofHttpExceptions(ClassBodies $bodies, ProjectFilter $appFilter): self
    {
        return new self($bodies, $appFilter, [KnownThrowers::HTTP_EXCEPTION => ['slot' => self::HTTP_EXCEPTION_SLOT, 'default' => null]]);
    }

    /**
     * The response classes `$classes` names, each at the slot its INSTALLED constructor names `status`, with
     * that parameter's default. Reflection evaluates the default, which is safe here and nowhere else: these
     * are the framework's own constructors, whose status defaults are integer literals.
     *
     * @param  list<string>  $classes
     */
    public static function ofResponses(ClassBodies $bodies, ProjectFilter $appFilter, array $classes): self
    {
        $roots = [];
        foreach ($classes as $class) {
            $constructor = class_exists($class) ? (new ReflectionClass($class))->getConstructor() : null;
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === self::RESPONSE_STATUS) {
                    $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
                    $roots[$class] = ['slot' => $parameter->getPosition(), 'default' => is_int($default) ? $default : null];
                }
            }
        }

        return new self($bodies, $appFilter, $roots, self::RESPONSE_SETTER);
    }

    /** Whether the class is a root or descends from one, so that this read is the one answering for it. */
    public function governs(string $fqcn): bool
    {
        return $this->root($fqcn) !== null;
    }

    /** The status a root sends when its constructor is handed none. */
    public function rootDefault(string $root): ?int
    {
        return $this->roots[$root]['default'] ?? null;
    }

    /** The nearest root the class is, or descends from; null for a class this read does not answer for. */
    public function root(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        for ($class = new ReflectionClass($fqcn); $class !== false; $class = $class->getParentClass()) {
            if (isset($this->roots[$class->getName()])) {
                return $class->getName();
            }
        }

        return null;
    }

    /** The status the class states for every one of its instances, or null when none folded. */
    public function pinned(string $fqcn): ?int
    {
        return $this->resolve($fqcn)['status'];
    }

    /**
     * The constructor slot whose argument becomes the status, for a class that forwards one rather than
     * pinning it — argument 0 where no class below `HttpException` adds a constructor and its own is what
     * runs. Null when nothing forwards one.
     */
    public function statusParameter(string $fqcn): ?int
    {
        return $this->resolve($fqcn)['parameter'];
    }

    /**
     * How the argument in {@see statusParameter()} becomes the status: null where it IS the status, and
     * otherwise the read the constructor applies to it — `$reason->status()`, `$status->value`.
     */
    public function statusRead(string $fqcn): ?ParamAccessor
    {
        return $this->resolve($fqcn)['read'];
    }

    /**
     * The one status every construction the class makes of ITSELF agrees on — a weaker claim than
     * {@see pinned()}, and the only one left where a throw point carries no construction to read: a throw
     * inside a closure the callee runs, one written in a trait and surfacing as a `@throws`, a rethrow.
     * A class with a single factory says its status exactly once, in that factory, and nothing at the
     * throw repeats it.
     *
     * Weaker because a constructor the class does not keep to itself can be called from anywhere, so the
     * constructions here are a subset of the application's rather than all of them — which is why
     * {@see ThrowAnalyzer} asks this only where the throw site itself said nothing, never over a
     * construction it could read. Everything that would make the answer a guess rather than the class's
     * own statement still declines: a constructor that writes the status it forwards, which names no slot
     * to fold into; a class or ancestor this read cannot see all of ({@see constructionsOfSelf()}); and
     * constructions that disagree, where the class genuinely has several.
     *
     * The files are the declarations the read went beyond the hierarchy for — a class constant a
     * construction names ({@see ConstantSource}). The hierarchy's own files come back from
     * {@see filesFor()}, which every caller of this already asks.
     *
     * @return AgreedRead
     */
    public function agreed(string $fqcn): array
    {
        if (isset($this->agreed[$fqcn])) {
            return $this->agreed[$fqcn];
        }

        $this->agreed[$fqcn] = ['status' => null, 'files' => []];

        // A slot is only ever answered for a class; the `class_exists()` is spelled out beside it for the
        // same reason {@see resolve()} spells one out — it is what makes the name one reflection may take.
        $slot = $this->statusParameter($fqcn);
        if ($slot === null || ! class_exists($fqcn)) {
            return $this->agreed[$fqcn];
        }

        $sites = $this->constructionsOfSelf(new ReflectionClass($fqcn));
        if ($sites === null) {
            return $this->agreed[$fqcn];
        }

        $files = [];
        $status = $this->agreedOver($sites, $fqcn, $slot, $this->statusRead($fqcn), $files);

        return $this->agreed[$fqcn] = ['status' => $status, 'files' => array_values(array_unique($files))];
    }

    /**
     * The two facts a reader of ONE construction needs about the constructor a `new $fqcn(...)` binds to:
     * its parameter names, so an argument written by name lands in the position it fills, and the constant
     * default of `$slot`, which is the value a construction that leaves that slot empty passes. The default
     * is read here rather than restated elsewhere, so one rule says what counts as one.
     *
     * @return ConstructorSlot
     */
    public function constructorSlot(string $fqcn, int $slot): array
    {
        $constructor = class_exists($fqcn) ? (new ReflectionClass($fqcn))->getConstructor() : null;

        return ['names' => self::parameterNames($constructor), 'default' => $this->constantDefault($constructor, $slot)];
    }

    /**
     * Files whose contents were read to answer, for the analysis's dependency set: an exception class that
     * changes the status it sets must rebuild every route that throws it, and a warm build that missed it
     * would publish a status a cold one does not. The whole hierarchy is recorded, not just the class that
     * happens to declare the constructor today — adding one lower down changes the answer.
     *
     * @return list<string>
     */
    public function filesFor(string $fqcn): array
    {
        return $this->resolve($fqcn)['files'];
    }

    /**
     * @return StatusPin
     */
    private function resolve(string $fqcn): array
    {
        if (isset($this->cache[$fqcn])) {
            return $this->cache[$fqcn];
        }

        $this->cache[$fqcn] = ['status' => null, 'parameter' => null, 'read' => null, 'files' => []];

        // The `class_exists()` is spelled out beside `governs()`, which checks it too: it is what makes the
        // name a class the reflection below may be handed.
        if (! class_exists($fqcn) || ! $this->governs($fqcn)) {
            return $this->cache[$fqcn];
        }

        $read = $this->forClass(new ReflectionClass($fqcn));
        $read['files'] = array_values(array_unique($read['files']));

        return $this->cache[$fqcn] = $read;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return StatusPin
     */
    private function forClass(ReflectionClass $class): array
    {
        $file = $class->getFileName();
        $files = $file === false ? [] : [$file];

        $root = $this->roots[$class->getName()] ?? null;
        if ($root !== null) {
            return ['status' => null, 'parameter' => $root['slot'], 'read' => null, 'files' => $files];
        }

        $parent = $class->getParentClass();
        if ($parent === false) {
            return self::nothing($files);
        }

        $inherited = $this->resolve($parent->getName());

        // An application's subclass of one of the framework's fixed-status exceptions inherits that status:
        // no `parent::__construct()` it writes reaches the number, which the framework's own constructor
        // passes on as a literal. Only where the APPLICATION declares the subclass — the vendor class asked
        // for itself is still unread, so a package's `@throws` of one stays the plumbing it was.
        if ($inherited['status'] === null && $file !== false && $this->appFilter->isProjectFile($file)) {
            $pin = $this->frameworkPin($parent);
            if ($pin !== null) {
                $inherited = ['status' => $pin, 'parameter' => null, 'read' => null, 'files' => $inherited['files']];
            }
        }

        $files = [...$files, ...$inherited['files']];

        $constructor = $class->getConstructor();

        // No constructor of its own, so the parent's is what runs — and its answer is this class's.
        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $class->getName()) {
            return [...$inherited, 'files' => $files];
        }

        return $this->readConstructor($class, $constructor, $parent, $inherited, $files);
    }

    /**
     * What one class's own constructor does with the status the parent's takes.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $parent
     * @param  StatusPin  $inherited
     * @param  list<string>  $files
     * @return StatusPin
     */
    private function readConstructor(
        ReflectionClass $class,
        ReflectionMethod $constructor,
        ReflectionClass $parent,
        array $inherited,
        array $files,
    ): array {
        $file = $class->getFileName();
        if ($file === false || ! $this->appFilter->isProjectFile($file)) {
            return self::nothing($files);
        }

        $body = $this->bodies->methods($file, $class->getName())['__construct'] ?? null;
        if ($body === null) {
            return self::nothing($files);
        }

        $call = StatusForwarding::parentCall($body);
        if ($call === null) {
            return self::nothing($files);
        }

        // A status the constructor sets once its parent's has run replaces whatever the parent was handed.
        $setter = $this->setterCall($class, $body, $call);
        if ($setter === false) {
            return self::nothing($files);
        }

        if ($setter !== null) {
            [$argument, $at, $read] = [...$setter, null];
        } else {
            // A literal the parent pins is one no subclass can move: it reaches the parent's own
            // `parent::__construct()` call, and this constructor only chooses the message beside it.
            if ($inherited['status'] !== null) {
                return ['status' => $inherited['status'], 'parameter' => null, 'read' => null, 'files' => $files];
            }

            $slot = $inherited['parameter'];
            if ($slot === null) {
                return self::nothing($files);
            }

            $slots = ArgumentSlots::of($call->getArgs(), self::parameterNames($parent->getConstructor()));
            $argument = $slots->at($slot);
            [$at, $read] = [$call, $inherited['read']];

            // A slot left empty passes the parent's default — but only a slot that IS the status; one read
            // through an enum (`$reason->status()`) has no integer default to pass.
            if ($argument === null) {
                $default = $slots->knows($slot) && $read === null ? $this->slotDefault($parent, $slot) : null;

                return $default === null ? self::nothing($files) : ['status' => $default, 'parameter' => null, 'read' => null, 'files' => $files];
            }
        }

        // The declaration a `parent::__construct(HttpStatus::CONFLICT, …)` reads decides this class's
        // status, so it joins the set whether or not the fold went on to succeed — and so does the enum a
        // `parent::__construct(Reason::Gone->status(), …)` reads its status off.
        $fold = $this->bodies->foldStatus($file, $argument, $at, $read);
        $files = [...$files, ...ConstantSource::files($argument, $class->getName()), ...$fold['files']];

        $folded = HttpStatusCode::folded($fold['status']);
        if ($folded !== null) {
            return ['status' => $folded, 'parameter' => null, 'read' => null, 'files' => $files];
        }

        $forwarded = StatusForwarding::forwarded($argument, $read);
        if ($forwarded === null) {
            return self::nothing($files);
        }

        [$variable, $read] = $forwarded;

        // A body that WRITES the variable it forwards hands the parent a value neither the caller nor the
        // default names — `if ($errors === []) { $statusCode = 400; }` really builds a 400 — so this class
        // forwards no slot at all, and a `throw new X(409, …)` cannot be read off one either.
        if (StatusForwarding::reassigns($body, $variable)) {
            return self::nothing($files);
        }

        $index = array_search($variable, self::parameterNames($constructor), true);
        if (! is_int($index)) {
            return self::nothing($files);
        }

        // …and the declaration behind the DEFAULT of that slot, which is the value a construction leaving
        // it empty passes. Named through reflection rather than evaluated: `getDefaultValueConstantName()`
        // reads the initialiser's name off the declaration, where `getDefaultValue()` would run it.
        $default = ConstantSource::fileForName(self::defaultConstantName($constructor, $index) ?? '', $class->getName());
        if ($default !== null) {
            $files[] = $default;
        }

        $status = $this->forwardedDefault($class, $constructor, $index, $read, $file, $files);

        return ['status' => $status, 'parameter' => $index, 'read' => $read, 'files' => $files];
    }

    /**
     * The literal status one of the framework's own `HttpException` children pins, read off its installed
     * source: the class extends `HttpException` directly and its constructor hands `parent::__construct()`
     * an integer as the status. Read with php-parser and not through the analyser, so asking primes nothing
     * and discards no recorded walk ({@see self} for why the analyser is not asked); and read off what the
     * application RESOLVED, so the number is that version's. Anything else — a package outside the
     * framework, a grandchild, a status that is not a literal — is null.
     *
     * @param  ReflectionClass<object>  $class
     */
    private function frameworkPin(ReflectionClass $class): ?int
    {
        $name = $class->getName();
        if (array_key_exists($name, $this->frameworkPins)) {
            return $this->frameworkPins[$name];
        }

        $this->frameworkPins[$name] = null;

        $root = $class->getParentClass();
        $file = $class->getFileName();
        $framework = array_filter(self::FRAMEWORK_NAMESPACES, static fn (string $prefix): bool => str_starts_with($name, $prefix)) !== [];
        if (! $framework || $root === false || $root->getName() !== KnownThrowers::HTTP_EXCEPTION || ! isset($this->roots[$root->getName()]) || $file === false) {
            return null;
        }

        $code = @file_get_contents($file);
        $statements = $code === false ? null : (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        $finder = new NodeFinder;
        $constructor = $finder->findFirst($statements ?? [], static fn (Node $node): bool => $node instanceof ClassMethod && $node->name->toLowerString() === '__construct');
        $call = $constructor === null ? null : $finder->findFirst(
            $constructor,
            static fn (Node $node): bool => $node instanceof StaticCall && $node->class instanceof Name && $node->class->toLowerString() === 'parent'
                && $node->name instanceof Node\Identifier && $node->name->toLowerString() === '__construct',
        );

        $first = $call instanceof StaticCall && ! $call->isFirstClassCallable() ? ($call->getArgs()[0] ?? null) : null;

        return $this->frameworkPins[$name] = $first !== null && $first->name === null && $first->value instanceof Int_
            ? HttpStatusCode::folded($first->value->value)
            : null;
    }

    /**
     * A class that states nothing, answering with the files the read had already gone through to find
     * that out. Spelled as a function of the live set rather than captured once at the top of a body: a
     * declaration a fold READ decided this class's status whether or not the fold went on to succeed, and
     * a decline handing back a stale copy of the set leaves that file off the fragment's dependency list.
     *
     * @param  list<string>  $files
     * @return StatusPin
     */
    private static function nothing(array $files): array
    {
        return ['status' => null, 'parameter' => null, 'read' => null, 'files' => $files];
    }

    /**
     * The status argument of the `$this->setStatusCode(…)` a constructor makes once its parent's has run, and
     * the call it is written in; null where it makes none. `false` where it makes one this cannot place —
     * several, one inside a branch, or one before the parent's — since the status sent is then not one
     * expression's.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<array-key, Node\Stmt>  $body
     * @return array{Node\Expr, Node\Expr\MethodCall}|false|null
     */
    private function setterCall(ReflectionClass $class, array $body, StaticCall $parentCall): array|false|null
    {
        if ($this->setter === null || ! $class->hasMethod($this->setter)) {
            return null;
        }

        $calls = StatusForwarding::selfCalls($body, $this->setter);
        if ($calls === []) {
            return null;
        }

        $statements = array_values($body);
        $parentAt = StatusForwarding::statementOf($statements, $parentCall);
        $setterAt = count($calls) === 1 ? StatusForwarding::statementOf($statements, $calls[0]) : null;
        if ($parentAt === null || $setterAt === null || $setterAt < $parentAt) {
            return false;
        }

        $names = self::parameterNames($class->getMethod($this->setter));
        $argument = ArgumentSlots::of($calls[0]->getArgs(), $names)->at(0);

        return $argument === null ? false : [$argument, $calls[0]];
    }

    /**
     * The status a call leaving `$slot` of `$parent`'s constructor empty passes: a root's own default, or the
     * constant default of the application constructor that declares it.
     *
     * @param  ReflectionClass<object>  $parent
     */
    private function slotDefault(ReflectionClass $parent, int $slot): ?int
    {
        $constructor = $parent->getConstructor();
        $root = $this->roots[$constructor?->getDeclaringClass()->getName() ?? $parent->getName()] ?? null;

        return $root !== null
            ? ($root['slot'] === $slot ? HttpStatusCode::folded($root['default']) : null)
            : $this->constantDefault($constructor, $slot);
    }

    /**
     * The status a class with a PRIVATE constructor states for every one of its instances: every
     * construction is written in this class, so the one they agree on is the one the class has. A slot they
     * all leave empty takes the constructor's default, one they write takes its own folded literal, and
     * either way it is the same value every time or the class states none.
     *
     * That the constructor forwards the parameter untouched is already settled by the caller, which
     * declines outright for a body that writes it. A class that uses a trait declines at one remove: a
     * trait's methods are written in another file, so a `new self(...)` there is one this read never sees.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<string>  $files
     */
    private function forwardedDefault(
        ReflectionClass $class,
        ReflectionMethod $constructor,
        int $index,
        ?ParamAccessor $read,
        string $file,
        array &$files,
    ): ?int {
        if (! $constructor->isPrivate() || $class->getTraitNames() !== []) {
            return null;
        }

        // The class's own declared code is the whole set here, and no ancestor is walked: a private
        // constructor is out of scope from a base, so a `new static(…)` in one would not even compile.
        return $this->agreedOver(
            $this->constructionsIn($class->getName(), $class->getName(), $file),
            $class->getName(),
            $index,
            $read,
            $files,
        );
    }

    /**
     * The one status a set of the class's own constructions folds to, each at the site it is written.
     * `$read` is how the slot's argument becomes the status ({@see statusRead()}), and `$files` collects
     * the declarations the folds read, for the dependency set.
     *
     * @param  list<ConstructionSite>  $sites
     * @param  list<string>  $files
     */
    private function agreedOver(array $sites, string $class, int $slot, ?ParamAccessor $read, array &$files): ?int
    {
        return ConstructionStatus::agreedIn(
            $sites,
            $slot,
            $this->constructorSlot($class, $slot),
            function (Node\Expr $argument, ConstructionSite $site) use ($read, &$files): ?int {
                $fold = $this->bodies->foldStatus($site->file, $argument, $site->construction, $read);
                $files = [...$files, ...ConstantSource::files($argument, $site->declaringClass), ...$fold['files']];

                return $fold['status'];
            },
        );
    }

    /**
     * Every construction of `$class` this build can see, each with the file it is written in — or null
     * where the set could not be completed, which is a different answer from an empty one.
     *
     * A construction the class makes of ITSELF is one written in its own declared code OR in a class it
     * inherits from: `new static(…)` in a base builds the subclass as surely as `new self(…)` in the
     * subclass does, and reading only the subclass's own answers a status for a class that has two. So
     * the walk is the hierarchy, up to the root, whose own constructor takes the status and builds
     * no subclass. Read them all or read none: an ancestor written in a file this build cannot open — one a
     * package declares, whose bodies PHPStan strips — or one using a TRAIT, whose methods live in a file
     * this read never opens, leaves a construction unseen, and a partial set is a status the class may not
     * have.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<ConstructionSite>|null
     */
    private function constructionsOfSelf(ReflectionClass $class): ?array
    {
        $target = $class->getName();
        $constructions = [];

        for (
            $declaring = $class;
            $declaring !== false && ! isset($this->roots[$declaring->getName()]);
            $declaring = $declaring->getParentClass()
        ) {
            $file = $declaring->getFileName();
            if ($file === false
                || $declaring->getTraitNames() !== []
                || ! $this->appFilter->isProjectFile($file)
            ) {
                return null;
            }

            foreach ($this->constructionsIn($target, $declaring->getName(), $file) as $construction) {
                $constructions[] = $construction;
            }
        }

        return $constructions;
    }

    /**
     * Every `new` that builds `$target` in the methods `$declaring` writes in `$file` — its constructor
     * included, which a private constructor is reachable from and from nowhere else. Each one carries
     * where it is written ({@see ConstructionSite}).
     *
     * @return list<ConstructionSite>
     */
    private function constructionsIn(string $target, string $declaring, string $file): array
    {
        $sites = [];
        foreach ($this->bodies->methods($file, $declaring) as $statements) {
            foreach (StatusForwarding::constructionsOf($statements, $target, $declaring) as $construction) {
                $sites[] = new ConstructionSite($construction, $file, $declaring);
            }
        }

        return $sites;
    }

    /**
     * The constant integer default of one parameter, which is what a call leaving that slot empty passes.
     * Asked of the declaration through {@see ClassBodies::intDefault()} rather than of reflection, which
     * would EXECUTE the initialiser — PHP has allowed `new` in one since 8.1.
     *
     * The application's own files only, for the reason the class docblock gives: reading a package's
     * declaration costs the file's analysis, and `HttpException` and every subclass of it Symfony ships take
     * their status with no default at all, so there is nothing behind the cost.
     */
    private function constantDefault(?ReflectionMethod $method, int $index): ?int
    {
        $file = $method?->getFileName();
        if ($method === null || $file === false || $file === null || ! $this->appFilter->isProjectFile($file)) {
            return null;
        }

        return HttpStatusCode::folded(
            $this->bodies->intDefault($file, $method->getDeclaringClass()->getName(), $method->getName(), $index),
        );
    }

    /** The constant a parameter's default NAMES, where it names one — never the value it would evaluate to. */
    private static function defaultConstantName(ReflectionMethod $method, int $index): ?string
    {
        $parameter = $method->getParameters()[$index] ?? null;

        return $parameter?->isDefaultValueAvailable() === true && $parameter->isDefaultValueConstant()
            ? $parameter->getDefaultValueConstantName()
            : null;
    }

    /**
     * @return list<string>
     */
    private static function parameterNames(?ReflectionMethod $method): array
    {
        return $method === null ? [] : array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        );
    }
}
