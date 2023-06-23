#!/bin/bash

export APP_ENV="dev"
echo $APP_ENV
php -S localhost:8000 -t public