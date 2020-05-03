#!/bin/zsh

export PATH=vendor/bin:$PATH

parallel-lint --exclude vendor .
phpcbf src config
/usr/local/bin/phpcs --standard=PSR12 --ignore=vendor src config
phpstan analyse
phpmd src ansi cleancode,codesize,controversial,design,unusedcode,naming
