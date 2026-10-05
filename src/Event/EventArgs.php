<?php

declare(strict_types=1);

namespace Tether\Event;

/**
 * What the browser says about an event: the last parameter of an event handler, when it is
 * typed EventArgs or a subclass of it.
 *
 * `EventArgs` itself gives the raw payload in $data (the events without a class of their own:
 * visibilitychange, popstate, custom events with a `detail`). A subclass declares the payload
 * it knows as promoted constructor parameters, with defaults for what may be missing; from()
 * fills them from the payload, an int being accepted where a float is declared, and refuses
 * any other mismatch. $data always holds the whole payload, `tether-event` additions included.
 *
 * The payload comes from the browser: nothing in it is to be trusted.
 */
class EventArgs
{
    /** @var array<class-string, list<string>> constructor parameter names, per class */
    private static array $parameters = [];

    /** The event's type: "click", "keydown". */
    public readonly string $type;

    /** @var array<string, mixed> the whole payload */
    public readonly array $data;

    /**
     * @param array<string, mixed> $payload the browser's payload, with its `type`
     *
     * @throws \InvalidArgumentException the payload does not fit the class
     */
    final public static function from(array $payload): static
    {
        $names = self::$parameters[static::class] ??= self::names();
        $args  = [];
        foreach ($names as $name) {
            if (\array_key_exists($name, $payload)) {
                $args[$name] = $payload[$name];
            }
        }
        try {
            $event = new static(...$args);
        } catch (\TypeError|\ArgumentCountError $e) {
            throw new \InvalidArgumentException(static::class . ': the event\'s data does not fit', 0, $e);
        }
        $type        = $payload['type'] ?? '';
        $event->type = \is_string($type) ? $type : '';
        $event->data = $payload;

        return $event;
    }

    /** @return list<string> the names of the constructor's parameters, up the class chain */
    private static function names(): array
    {
        $names = [];
        for ($class = new \ReflectionClass(static::class); $class; $class = $class->getParentClass()) {
            foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
                if (!$parameter->isVariadic()) {
                    $names[$parameter->getName()] = true;
                }
            }
        }

        return \array_keys($names);
    }
}
