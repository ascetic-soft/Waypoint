<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Fixture;

/**
 * Fixture class that uses PHP attributes but NOT #[Route].
 * Used to test that AttributeRouteLoader skips such classes.
 */
#[\AllowDynamicProperties]
final class NonRouteAttributeClass
{
    public function doSomething(): void
    {
    }
}
