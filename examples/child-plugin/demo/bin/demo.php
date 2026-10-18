<?php

// What the program does with acme/greeter as installed.
require __DIR__.'/../vendor/autoload.php';

use Acme\Greeter\Greeter;

echo '  (new Greeter())->greet("world"): ', (new Greeter())->greet('world'), "\n";

if ((new ReflectionClass(Greeter::class))->isFinal()) {
    echo "  Greeter is final: App\\LoudGreeter cannot extend it\n";
} else {
    echo '  (new App\LoudGreeter())->greet("world"): ', (new App\LoudGreeter())->greet('world'), "\n";
}
