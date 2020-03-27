#!/bin/zsh

export PATH=vendor/bin:$PATH

parallel-lint --exclude vendor .
phpcbf src config
phpcs src config
phpstan analyse