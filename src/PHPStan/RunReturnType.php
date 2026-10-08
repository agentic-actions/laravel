<?php

namespace AgenticActions\PHPStan;

use AgenticActions\Action;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Tells PHPStan that `MyAction::run()` returns what `MyAction::handle()` declares, since run() hands that value back
 * unchanged; a void handle() makes it null. A class without handle(), such as Action itself, keeps run()'s mixed.
 * Only PHPStan loads this class, through the package's extension.neon.
 */
final class RunReturnType implements DynamicStaticMethodReturnTypeExtension
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getClass(): string
    {
        return Action::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'run';
    }

    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, Scope $scope): ?Type
    {
        $classes = $methodCall->class instanceof Name
            ? [$scope->resolveName($methodCall->class)]
            : $scope->getType($methodCall->class)->getObjectTypeOrClassStringObjectType()->getObjectClassNames();

        $types = [];

        foreach ($classes as $class) {
            $action = $this->reflectionProvider->hasClass($class) ? $this->reflectionProvider->getClass($class) : null;

            if ($action === null || ! $action->hasMethod('handle')) {
                return null;
            }

            foreach ($action->getMethod('handle', $scope)->getVariants() as $variant) {
                $type = $variant->getReturnType();
                $types[] = $type->isVoid()->yes() ? new NullType : $type;
            }
        }

        return $types === [] ? null : TypeCombinator::union(...$types);
    }
}
