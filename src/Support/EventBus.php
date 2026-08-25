<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Support;

use OpenYam\HyperfExcel\Concerns\WithEvents;
use Psr\EventDispatcher\EventDispatcherInterface;

final class EventBus
{
    public function __construct(private readonly EventDispatcherInterface $dispatcher) {}

    public function dispatch(object $owner, object $event): void
    {
        if ($owner instanceof WithEvents) {
            $listener = $owner->registerEvents()[$event::class] ?? null;
            if (is_callable($listener)) {
                $listener($event);
            }
        }
        $this->dispatcher->dispatch($event);
    }
}
