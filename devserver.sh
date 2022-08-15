#!/bin/bash

export APP_ENV="dev"
echo $APP_ENV
cd public
php -S localhost:8000