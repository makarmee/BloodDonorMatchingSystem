<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (App\Models\User::with(['roles', 'donor', 'hospitalUser.hospital'])->get() as $u) {
    echo "ID: {$u->id}, Name: {$u->name}, Email: {$u->email}, Roles: " . implode(',', $u->roles->pluck('slug')->all()) . "\n";
    if ($u->donor) {
        echo "  Donor: {$u->donor->donor_code}, Status: {$u->donor->account_status}\n";
    }
    if ($u->hospitalUser) {
        echo "  Hospital: {$u->hospitalUser->hospital->name}, Status: {$u->hospitalUser->hospital->verification_status}\n";
    }
}
