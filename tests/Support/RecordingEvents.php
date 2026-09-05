<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

final class RecordingEvents implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
