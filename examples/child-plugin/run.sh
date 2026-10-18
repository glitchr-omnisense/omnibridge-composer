#!/bin/sh
# The example from start to end, in the container (docker compose run --rm example):
# a project installs acme/greeter, requires the child that patches it, shows the
# patch, its diff, the stub and the program with the patch, checks it, installs
# again (nothing changes), then removes the child (the patch is taken back).
set -eu

ENGINE=/engine
WORK=${WORK:-/tmp/child-plugin-demo}

title() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
fingerprint() { find vendor/acme vendor/composer/omnibridge -type f 2>/dev/null | sort | xargs sha256sum; }

rm -rf "$WORK"
cp -R "$ENGINE/examples/child-plugin/demo" "$WORK"
cd "$WORK"

title "1. composer install: acme/greeter 1.2.0 alone (Greeter final, 'Hello')"
composer install --no-progress
php bin/demo.php

title "2. composer require acme/child-plugin (requires omnibridge/composer; declares a patch and a stub provider)"
composer require acme/child-plugin:1.0.0 --no-progress

title "composer omnibridge:patches"
composer omnibridge:patches

title "composer omnibridge:patches --diff"
composer omnibridge:patches --diff

title "the stubs (vendor/composer/omnibridge/stubs, never autoloaded)"
find vendor/composer/omnibridge/stubs -type f | sort
cat vendor/composer/omnibridge/stubs/Greeter/Greeter.php

title "the program, with the patch"
php bin/demo.php

title "composer omnibridge:patches --check, composer omnibridge:stubs --check"
composer omnibridge:patches --check
composer omnibridge:stubs --check

title "3. composer install again: nothing changes"
before=$(fingerprint)
composer install --no-progress
after=$(fingerprint)
if [ "$before" = "$after" ]; then echo "  vendor/acme and the record: identical, byte for byte"; else echo "  CHANGED"; exit 1; fi

title "4. composer remove acme/child-plugin: the patch is taken back"
composer remove acme/child-plugin --no-progress
php bin/demo.php
diff -r "$ENGINE/examples/child-plugin/packages/greeter" vendor/acme/greeter
echo "  vendor/acme/greeter: as published"
test ! -e vendor/composer/omnibridge
echo "  vendor/composer/omnibridge: gone"

title "done"
