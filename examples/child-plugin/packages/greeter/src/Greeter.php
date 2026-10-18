<?php

namespace Acme\Greeter;

/**
 * Says hello.
 *
 * @final
 */
final class Greeter
{
    public function __construct(private string $greeting = 'Hello')
    {
    }

    public function greet(string $name): string
    {
        return $this->greeting.', '.$name.'.';
    }
}
