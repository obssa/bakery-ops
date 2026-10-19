<?php

use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Shared\SharedServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
];
