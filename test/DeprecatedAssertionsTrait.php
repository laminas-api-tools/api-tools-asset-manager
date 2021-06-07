<?php

namespace LaminasTest\ApiTools\AssetManager;

use PHPUnit\Framework\Assert;
use ReflectionProperty;

trait DeprecatedAssertionsTrait
{
    public function assertAttributeCount(int $count, string $attributeName, object $object, string $message = ''): void
    {
        $r = new ReflectionProperty($object, $attributeName);
        $r->setAccessible(true);
        $value = $r->getValue($object);

        Assert::assertCount($count, $value, $message);
    }
}
