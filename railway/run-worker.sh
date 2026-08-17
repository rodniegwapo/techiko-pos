#!/bin/bash
php artisan queue:work --max-attempts=3 --max-time=3600