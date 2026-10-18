<?php

namespace App;

use Acme\Greeter\Greeter;

/** Loaded only once Greeter is no longer final (bin/demo.php checks). */
class LoudGreeter extends Greeter
{
    public function greet(string $name): string
    {
        return strtoupper(parent::greet($name));
    }
}
