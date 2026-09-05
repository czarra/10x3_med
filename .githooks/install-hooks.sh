#!/bin/sh
#
# Install the versioned git hooks from .githooks/ into .git/hooks/.
# Run automatically by `composer install` / `composer update`
# (composer.json: post-install-cmd / post-update-cmd). Safe to run by hand:
#   sh .githooks/install-hooks.sh
#
set -eu

[ -d .git/hooks ] || exit 0

cp .githooks/pre-commit .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit

echo "git hooks installed: .git/hooks/pre-commit"
