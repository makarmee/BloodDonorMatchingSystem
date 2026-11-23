<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\User::where('name', 'اهداکننده فارسی')->first();
if ($u) {
    $u->donor?->eligibilityRecords()->delete();
    $u->donor?->delete();
    $u->roles()->detach();
    $u->delete();
    echo "Deleted.\n";
}
