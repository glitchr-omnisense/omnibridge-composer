<?php

namespace Acme\Greeter;

/** A word to greet with. */
class Salutation
{
    public function __construct(public readonly string $word = 'Hello')
    {
    }
}
