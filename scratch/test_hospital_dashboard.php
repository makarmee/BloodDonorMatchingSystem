<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$email = 'hosp_coord_' . time() . '@test.com';
$request = Illuminate\Http\Request::create('/register', 'POST', [
    'role' => 'hospital',
    'name' => 'Dr. Hospital Test',
    'email' => $email,
    'organization_name' => 'Test Hospital',
    'phone' => '+1 555 1234',
    'city' => 'Metropolis',
    'address' => '123 Main St',
    'password' => 'password123',
    'password_confirmation' => 'password123',
]);

$session = app('session')->driver();
$session->setId('test-session-hosp-' . time());
$session->start();
$request->setLaravelSession($session);
$app->instance('request', $request);

$controller = app(App\Http\Controllers\Auth\RegisteredUserController::class);
$response = $controller->store($request);
echo "Hospital Register returned: " . get_class($response) . "\n";
echo "Target URL: " . $response->getTargetUrl() . "\n";

$user = App\Models\User::where('email', $email)->first();
echo "Created hospital user: " . ($user ? $user->name . ' (ID: ' . $user->id . ')' : 'NULL') . "\n";

$target = $response->getTargetUrl();
$path = parse_url($target, PHP_URL_PATH);
echo "Visiting redirect path: " . $path . "\n";

$getReq = Illuminate\Http\Request::create($path, 'GET');
$getReq->setLaravelSession($session);
$getReq->setUserResolver(fn() => $user);
$app->instance('request', $getReq);

$dashController = app(App\Http\Controllers\DashboardController::class);
try {
    $view = $dashController($getReq);
    echo "DashboardController returned view: " . $view->name() . "\n";
} catch (\Throwable $e) {
    echo "CAUGHT ON DASHBOARD: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Clean up
if ($user) {
    $user->hospitalUser?->hospital?->delete();
    $user->hospitalUser?->delete();
    $user->roles()->detach();
    $user->delete();
}

