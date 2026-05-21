<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Console\TypeDiscovery;

use Devletes\NotificationsMax\Services\NotificationDispatcher;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * AST visitor used by {@see CallSiteScanner}. Records every
 * NotificationDispatcher::send() / ::schedule() call discovered while
 * traversing one file.
 *
 * Receiver shapes recognised:
 *   - `app(NotificationDispatcher::class)`
 *   - `resolve(NotificationDispatcher::class)`
 *   - `App::make(NotificationDispatcher::class)`
 *   - `$this-><prop>` where `<prop>` is declared on the current class
 *     with the dispatcher's FQCN as its type (regular or
 *     constructor-promoted)
 *   - `$variable` where `$variable` is in the current function/method
 *     scope as a known dispatcher — either a typed parameter
 *     (`function foo(NotificationDispatcher $d)`) or the result of a
 *     `$d = app(NotificationDispatcher::class)` style assignment
 *
 * Arguments are matched by name OR position. Calls like
 * `$d->send(typeKey: 'foo', context: [...])` resolve via the named-arg
 * lookup; bare-positional calls fall back to the historical indexing.
 *
 * Class properties are tracked on a stack so anonymous classes declared
 * inside a method don't lose the outer class's tracking when they exit.
 * Function-scope variables are tracked on a separate stack — pushed on
 * entering a method/function/closure/arrow-fn, popped on leaving — so
 * `$d` in one method can't be mistaken for `$d` in another.
 */
final class CallSiteVisitor extends NodeVisitorAbstract
{
    /** @var array<int, DiscoveredCallSite> */
    public array $found = [];

    /**
     * Stack of dispatcher-property name lists, one entry per nested class
     * scope.
     *
     * @var array<int, array<int, string>>
     */
    protected array $propertyStack = [];

    /**
     * Stack of dispatcher-variable name lists, one entry per nested
     * function-like (method / function / closure / arrow-fn) scope.
     *
     * @var array<int, array<int, string>>
     */
    protected array $functionScopeStack = [];

    public function __construct(protected string $filePath) {}

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->propertyStack[] = $this->collectDispatcherProperties($node);

            return null;
        }

        if ($this->isFunctionLike($node)) {
            $this->functionScopeStack[] = $this->scopeForFunctionLike($node);

            return null;
        }

        if ($node instanceof Node\Expr\Assign) {
            $this->maybeTrackAssignment($node);
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $this->maybeRecord($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->propertyStack);
        }

        if ($this->isFunctionLike($node)) {
            array_pop($this->functionScopeStack);
        }

        return null;
    }

    protected function isFunctionLike(Node $node): bool
    {
        return $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction;
    }

    /**
     * Build the initial set of in-scope dispatcher variables for a
     * function-like node. Parameters typed as the dispatcher count.
     * Closures additionally forward `use ($var)` captures from the outer
     * scope. Arrow fns auto-capture the entire enclosing scope by value.
     *
     * @return array<int, string>
     */
    protected function scopeForFunctionLike(Node $node): array
    {
        $scope = $this->collectDispatcherParameters($node);

        if ($node instanceof Node\Expr\Closure) {
            $outer = $this->currentFunctionScope();

            foreach ($node->uses as $use) {
                if ($use->var instanceof Node\Expr\Variable
                    && is_string($use->var->name)
                    && in_array($use->var->name, $outer, true)
                ) {
                    $scope[] = $use->var->name;
                }
            }
        }

        if ($node instanceof Node\Expr\ArrowFunction) {
            // Arrow fns inherit the enclosing scope's variables verbatim
            // — they can use any outer var without an explicit capture
            // clause. Merge first so explicit params still win.
            $scope = array_values(array_unique(array_merge($this->currentFunctionScope(), $scope)));
        }

        return $scope;
    }

    /**
     * @return array<int, string>
     */
    protected function currentClassProperties(): array
    {
        return end($this->propertyStack) ?: [];
    }

    /**
     * @return array<int, string>
     */
    protected function currentFunctionScope(): array
    {
        return end($this->functionScopeStack) ?: [];
    }

    /**
     * @return array<int, string>
     */
    protected function collectDispatcherProperties(Node\Stmt\ClassLike $class): array
    {
        $names = [];

        foreach ($class->getMethods() as $method) {
            if ($method->name->toLowerString() !== '__construct') {
                continue;
            }

            // Constructor-promoted properties: a Param with a visibility
            // flag (public/protected/private) declares a property of the
            // same name.
            foreach ($method->params as $param) {
                if ($param->flags === 0) {
                    continue;
                }

                if (! $this->typeIsDispatcher($param->type)) {
                    continue;
                }

                if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $names[] = $param->var->name;
                }
            }
        }

        foreach ($class->getProperties() as $property) {
            if (! $this->typeIsDispatcher($property->type)) {
                continue;
            }

            foreach ($property->props as $prop) {
                $names[] = $prop->name->toString();
            }
        }

        return $names;
    }

    /**
     * Pull dispatcher-typed parameters off a function-like node.
     *
     * @return array<int, string>
     */
    protected function collectDispatcherParameters(Node $node): array
    {
        if (! property_exists($node, 'params')) {
            return [];
        }

        $names = [];

        foreach ($node->params as $param) {
            if (! $this->typeIsDispatcher($param->type)) {
                continue;
            }

            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }

    protected function typeIsDispatcher(?Node $type): bool
    {
        if ($type instanceof Node\NullableType) {
            return $this->typeIsDispatcher($type->type);
        }

        if ($type instanceof Node\Name) {
            $resolved = $type->getAttribute('resolvedName');

            if ($resolved instanceof Node\Name) {
                return $resolved->toString() === NotificationDispatcher::class;
            }

            return $type->toString() === NotificationDispatcher::class;
        }

        // Union / intersection types: match if any constituent is the
        // dispatcher.
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $inner) {
                if ($this->typeIsDispatcher($inner)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Catch assignments whose RHS resolves to a dispatcher
     * (`$d = app(NotificationDispatcher::class)`, etc.) and add the LHS
     * variable to the current function scope so subsequent `$d->send(...)`
     * calls are recognised.
     *
     * No-ops outside a function-like scope (top-level scripts, etc.)
     * because the scanner targets app-layer code where every dispatch
     * lives inside a method.
     */
    protected function maybeTrackAssignment(Node\Expr\Assign $assign): void
    {
        if ($this->functionScopeStack === []) {
            return;
        }

        if (! $assign->var instanceof Node\Expr\Variable || ! is_string($assign->var->name)) {
            return;
        }

        if (! $this->expressionResolvesToDispatcher($assign->expr)) {
            return;
        }

        $top = array_pop($this->functionScopeStack);

        if (! in_array($assign->var->name, $top, true)) {
            $top[] = $assign->var->name;
        }

        $this->functionScopeStack[] = $top;
    }

    /**
     * Returns true when the expression statically resolves to a
     * NotificationDispatcher instance — `app(NotificationDispatcher::class)`,
     * `resolve(...)`, or `App::make(...)` with the dispatcher class.
     */
    protected function expressionResolvesToDispatcher(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            $func = $expr->name->toLowerString();

            if (in_array($func, ['app', 'resolve'], true)) {
                return $this->firstArgIsDispatcherClassConst($expr->getArgs());
            }
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier) {
            if ($expr->name->toLowerString() === 'make' && $expr->class instanceof Node\Name) {
                $resolvedClass = $expr->class->getAttribute('resolvedName');
                $className = $resolvedClass instanceof Node\Name ? $resolvedClass->toString() : $expr->class->toString();

                if ($className === 'Illuminate\\Support\\Facades\\App' || $className === 'App') {
                    return $this->firstArgIsDispatcherClassConst($expr->getArgs());
                }
            }
        }

        return false;
    }

    protected function maybeRecord(Node\Expr\MethodCall $call): void
    {
        if (! $call->name instanceof Node\Identifier) {
            return;
        }

        $methodName = $call->name->toLowerString();

        if ($methodName !== 'send' && $methodName !== 'schedule') {
            return;
        }

        if (! $this->receiverIsDispatcher($call->var)) {
            return;
        }

        // `send(string $typeKey, array $context, …)`
        // `schedule(DateTimeInterface $delayUntil, string $typeKey, array $context, …)`
        $typeKeyPos = $methodName === 'send' ? 0 : 1;
        $contextPos = $methodName === 'send' ? 1 : 2;

        $args = $call->getArgs();
        $typeKeyArg = $this->findArg($args, $typeKeyPos, 'typeKey');
        $contextArg = $this->findArg($args, $contextPos, 'context');

        $typeKey = $this->stringValue($typeKeyArg?->value);
        $contextKeys = $this->arrayKeyNames($contextArg?->value);

        $this->found[] = new DiscoveredCallSite(
            typeKey: $typeKey,
            contextKeys: $contextKeys,
            sourceFile: $this->filePath,
            sourceLine: $call->getStartLine(),
        );
    }

    /**
     * Find an argument by name (`->send(typeKey: 'x')`) OR by positional
     * index, with named-arg lookup taking precedence. Positional indexing
     * counts only un-named args so a mixed call like
     * `->send('foo', context: [...])` still resolves correctly.
     *
     * @param  array<int, Node\Arg>  $args
     */
    protected function findArg(array $args, int $position, string $name): ?Node\Arg
    {
        foreach ($args as $arg) {
            if ($arg->name instanceof Node\Identifier && $arg->name->toString() === $name) {
                return $arg;
            }
        }

        $i = 0;

        foreach ($args as $arg) {
            if ($arg->name !== null) {
                continue;
            }

            if ($i === $position) {
                return $arg;
            }

            $i++;
        }

        return null;
    }

    protected function receiverIsDispatcher(Node\Expr $receiver): bool
    {
        // `$variable->send(...)` where `$variable` is in scope as a
        // dispatcher (either a typed parameter or the result of a tracked
        // assignment). `$this` is deliberately excluded — it's handled by
        // the PropertyFetch case below.
        if ($receiver instanceof Node\Expr\Variable && is_string($receiver->name)) {
            if ($receiver->name === 'this') {
                return false;
            }

            return in_array($receiver->name, $this->currentFunctionScope(), true);
        }

        if ($receiver instanceof Node\Expr\FuncCall && $receiver->name instanceof Node\Name) {
            $func = $receiver->name->toLowerString();

            if (in_array($func, ['app', 'resolve'], true)) {
                return $this->firstArgIsDispatcherClassConst($receiver->getArgs());
            }
        }

        if ($receiver instanceof Node\Expr\StaticCall && $receiver->name instanceof Node\Identifier) {
            if ($receiver->name->toLowerString() === 'make' && $receiver->class instanceof Node\Name) {
                $resolvedClass = $receiver->class->getAttribute('resolvedName');
                $className = $resolvedClass instanceof Node\Name ? $resolvedClass->toString() : $receiver->class->toString();

                if ($className === 'Illuminate\\Support\\Facades\\App' || $className === 'App') {
                    return $this->firstArgIsDispatcherClassConst($receiver->getArgs());
                }
            }
        }

        if ($receiver instanceof Node\Expr\PropertyFetch
            && $receiver->var instanceof Node\Expr\Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Node\Identifier
        ) {
            return in_array($receiver->name->toString(), $this->currentClassProperties(), true);
        }

        return false;
    }

    /**
     * @param  array<int, Node\Arg>  $args
     */
    protected function firstArgIsDispatcherClassConst(array $args): bool
    {
        if (! isset($args[0])) {
            return false;
        }

        $value = $args[0]->value;

        if ($value instanceof Node\Expr\ClassConstFetch
            && $value->class instanceof Node\Name
            && $value->name instanceof Node\Identifier
            && $value->name->toLowerString() === 'class'
        ) {
            $resolved = $value->class->getAttribute('resolvedName');
            $name = $resolved instanceof Node\Name ? $resolved->toString() : $value->class->toString();

            return $name === NotificationDispatcher::class;
        }

        if ($value instanceof Node\Scalar\String_) {
            return ltrim($value->value, '\\') === NotificationDispatcher::class;
        }

        return false;
    }

    protected function stringValue(?Node $node): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function arrayKeyNames(?Node $node): array
    {
        if (! $node instanceof Node\Expr\Array_) {
            return [];
        }

        $keys = [];

        foreach ($node->items as $item) {
            if (! $item instanceof Node\ArrayItem) {
                continue;
            }

            if ($item->key instanceof Node\Scalar\String_) {
                $keys[] = $item->key->value;
            }
        }

        return $keys;
    }
}
