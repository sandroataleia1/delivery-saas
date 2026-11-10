<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\DisposableDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DisposableDatabase::guardApplication($app);

echo "Banco descartável validado.\n";
