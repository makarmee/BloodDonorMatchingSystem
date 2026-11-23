<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

app()->setLocale('fa');
session(['locale' => 'fa']);

$routesToTest = [
    ['name' => 'home', 'url' => '/', 'role' => null],
    ['name' => 'public.stock', 'url' => '/blood-stock', 'role' => null],
    ['name' => 'public.camps.index', 'url' => '/blood-camps', 'role' => null],
    ['name' => 'public.camps.show', 'url' => '/blood-camps/CMP-308', 'role' => null],
    ['name' => 'login', 'url' => '/login', 'role' => null],
    ['name' => 'register', 'url' => '/register', 'role' => null],
    ['name' => 'password.request', 'url' => '/forgot-password', 'role' => null],
    ['name' => 'dashboard', 'url' => '/dashboard', 'role' => 'admin'],
    ['name' => 'hospitals.index', 'url' => '/hospitals', 'role' => 'admin'],
    ['name' => 'inventory.index', 'url' => '/inventory', 'role' => 'admin'],
    ['name' => 'inventory.reconcile', 'url' => '/inventory/reconcile', 'role' => 'admin'],
    ['name' => 'donors.index', 'url' => '/donors', 'role' => 'admin'],
    ['name' => 'donors.create', 'url' => '/donors/create', 'role' => 'admin'],
    ['name' => 'donations.index', 'url' => '/donations', 'role' => 'admin'],
    ['name' => 'donations.create', 'url' => '/donations/create', 'role' => 'admin'],
    ['name' => 'screening.index', 'url' => '/screening', 'role' => 'admin'],
    ['name' => 'screening.create', 'url' => '/screening/create', 'role' => 'admin'],
    ['name' => 'requests.index', 'url' => '/requests', 'role' => 'admin'],
    ['name' => 'requests.create', 'url' => '/requests/create', 'role' => 'admin'],
    ['name' => 'camps.index', 'url' => '/camps', 'role' => 'admin'],
    ['name' => 'camps.create', 'url' => '/camps/create', 'role' => 'admin'],
    ['name' => 'donor-portal.dashboard', 'url' => '/donor-portal', 'role' => 'donor'],
    ['name' => 'donor-portal.appointments.index', 'url' => '/donor-portal/appointments', 'role' => 'donor'],
    ['name' => 'donor-portal.appointments.create', 'url' => '/donor-portal/appointments/create', 'role' => 'donor'],
    ['name' => 'donor-portal.eligibility', 'url' => '/donor-portal/eligibility', 'role' => 'donor'],
    ['name' => 'donor-portal.history', 'url' => '/donor-portal/history', 'role' => 'donor'],
];

$allowedEnglishWords = [
    'BDMS', 'ABO', 'Rh', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-',
    'HIV', 'HBV', 'HCV', 'VDRL', 'TPHA', 'COMPAT', 'DNA', 'RNA', 'PRC', 'FFP', 'PLT', 'CRYO',
    'ml', 'kg', 'lbs', 'min', 'mins', 'g', 'dL', 'mg', 'mm', 'Hg', 'UI', 'ID', 'ETA', 'FIFO',
    'HTML', 'CSS', 'JavaScript', 'Alpine', 'Vite', 'Chrome', 'Firefox', 'Safari',
    'Elena', 'Martinez', 'Marcus', 'Vance', 'Sarah', 'Chen', 'Devon', 'Miles',
];

$results = [];

foreach ($routesToTest as $r) {
    echo "Testing {$r['name']} ({$r['url']})...\n";

    $req = Request::create($r['url'], 'GET');
    $req->setLaravelSession(app('session.store'));
    $req->session()->put('locale', 'fa');

    if ($r['role']) {
        $user = User::query()->whereHas('roles', fn ($q) => $q->where('slug', $r['role']))->first();
        if (! $user) {
            $user = User::first();
        }
        if ($user) {
            $req->setUserResolver(fn () => $user);
            Auth::setUser($user);
        }
    } else {
        Auth::logout();
    }

    try {
        $response = $app->handle($req);
        $content = $response->getContent();

        // Remove <script>...</script>
        $content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content);
        // Remove <style>...</style>
        $content = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $content);
        // Remove <svg>...</svg>
        $content = preg_replace('#<svg(.*?)>(.*?)</svg>#is', '', $content);
        // Strip tags
        $text = strip_tags($content);
        // Decode html entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Find English text phrases (2 or more English words)
        preg_match_all('/\b[A-Za-z][A-Za-z0-9\'\-]{2,}(?:\s+[A-Za-z0-9\'\-]+){1,}\b/u', $text, $matches);

        $suspicious = [];
        foreach ($matches[0] as $phrase) {
            $phrase = trim($phrase);
            // Skip entity codes like REQ-2026-001, DNR-1041, DON-2026-001, etc.
            if (preg_match('/^(REQ|DNR|DON|CMP|UNIT|CTR)-/i', $phrase)) {
                continue;
            }
            // Skip phrases that are purely allowed words or numbers
            $words = preg_split('/\s+/', $phrase);
            $cleanWords = array_filter($words, fn ($w) => ! in_array($w, $allowedEnglishWords, true) && ! is_numeric($w));
            if (empty($cleanWords)) {
                continue;
            }
            $suspicious[] = $phrase;
        }

        $results[$r['name']] = array_unique($suspicious);
    } catch (Throwable $e) {
        $results[$r['name']] = ['ERROR: '.$e->getMessage()];
    }
}

file_put_contents(__DIR__.'/audit_results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "DONE. Written to scratch/audit_results.json\n";
