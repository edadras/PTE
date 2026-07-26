<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Three tiers, three different ways of resolving the tenant:
|
|   /api/platform/v1  super admin only, no tenant
|   /api/v1           academy integrations, tenant from the API key
|   /api/student/v1   bot mini-app, web and mobile clients, tenant from the host
|
| @see docs/08-api-and-integrations.md
*/

require __DIR__.'/api/platform.php';
require __DIR__.'/api/academy.php';
require __DIR__.'/api/student.php';
