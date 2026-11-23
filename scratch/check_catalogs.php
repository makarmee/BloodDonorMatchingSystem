<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "BloodComponent count: " . App\Models\BloodComponent::count() . "\n";
echo "ScreeningTestType count: " . App\Models\ScreeningTestType::count() . "\n";
echo "ComponentScreeningRequirement count: " . App\Models\ComponentScreeningRequirement::count() . "\n";
echo "BloodCompatibilityRule count: " . App\Models\BloodCompatibilityRule::count() . "\n";
echo "Role count: " . App\Models\Role::count() . "\n";
echo "Permission count: " . App\Models\Permission::count() . "\n";

