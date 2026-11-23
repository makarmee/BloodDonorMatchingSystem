<?php

$cookieFile = __DIR__ . '/cookies_hosp.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

$ch = curl_init('http://127.0.0.1:8000/register');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$response = curl_exec($ch);
curl_close($ch);

preg_match('/name="_token" value="([^"]+)"/', $response, $matches);
$csrfToken = $matches[1] ?? '';

$postData = [
    '_token' => $csrfToken,
    'role' => 'hospital',
    'name' => 'Dr. Hospital Real',
    'email' => 'real_hospital_' . time() . '@example.com',
    'organization_name' => 'Real Hospital Org',
    'phone' => '+1 (555) 019-9922',
    'city' => 'New York',
    'address' => '77 Flatbush Avenue',
    'password' => 'hospital-pass-123',
    'password_confirmation' => 'hospital-pass-123',
];

$ch = curl_init('http://127.0.0.1:8000/register');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
$postResponse = curl_exec($ch);
$postCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);

echo "Hospital POST /register HTTP Code: {$postCode}\n";
echo "Redirect URL: {$redirectUrl}\n";

if ($redirectUrl) {
    $ch = curl_init($redirectUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $redirectResponse = curl_exec($ch);
    $redirectCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "Hospital Redirect Target HTTP Code: {$redirectCode}\n";
    if ($redirectCode === 404) {
        echo "404 BODY PREVIEW:\n" . substr($redirectResponse, 0, 500) . "\n";
    }
}
