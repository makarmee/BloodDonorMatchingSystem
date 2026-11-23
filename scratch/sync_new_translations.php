<?php

$faPath = __DIR__.'/../lang/fa.json';
$enPath = __DIR__.'/../lang/en.json';

$fa = json_decode(file_get_contents($faPath), true) ?: [];
$en = json_decode(file_get_contents($enPath), true) ?: [];

$newBatch = [
    // Donation Camps fixtures & names
    'Central Plaza Community Blood Drive' => 'کمپین اهدای خون مجتمع مرکزی',
    'Central Medical Plaza Pavilion' => 'پاویون مجتمع پزشکی مرکزی',
    'Metro Center' => 'مترو سنتر',
    'Elena Martinez' => 'النا مارتینز',
    'University Health Campus Autumn Drive' => 'کمپین پاییزی پردیس بهداشت دانشگاه',
    'Student Union Atrium' => 'آتریوم اتحادیه دانشجویی',
    'University District' => 'منطقه دانشگاه',
    'Tariq Nassar' => 'طارق نصار',
    'Westgate Tech Park Express Collection' => 'جمع‌آوری سریع پارک فناوری وست‌گیت',
    'Westgate Innovation Hall' => 'تالار نوآوری وست‌گیت',
    'Westgate' => 'وست‌گیت',
    'Samuel Brooks' => 'ساموئل بروکس',
    'Harborfront Full-Capacity Mobile Drive' => 'کمپین سیار تمام‌ظرفیت هاربرفرانت',
    'Harborfront Community Hall' => 'تالار اجتماعی هاربرفرانت',
    'Eastside' => 'ایست‌ساید',
    'Nurse Hannah Reed' => 'پرستار هانا رید',
    'Southgate Hospital Pre-Deadline Drive' => 'کمپین شفاخانه ساوت‌گیت (پیش از پایان مهلت)',
    'Southgate Outpatient Lobby' => 'لابی سرپایی ساوت‌گیت',
    'Southgate' => 'ساوت‌گیت',
    'Maya Lin' => 'مایا لین',
    'Winter Corporate Blood Drive Plan' => 'طرح زمستانی کمپین اهدای خون شرکت‌ها',
    'Midtown Civic Center' => 'مرکز مدنی مرکز شهر',
    'Midtown' => 'میدتاون',
    'Victor Hernandez' => 'ویکتور هرناندز',
    'Northside Autumn Community Drive' => 'کمپین پاییزی محله شمالی',
    'Northside Community Gymnasium' => 'سالن ورزشی محله شمالی',
    'North Hills' => 'نورث هیلز',
    'Nurse Clara Diaz' => 'پرستار کلارا دیاز',
    'Riverside Holiday Park Drive' => 'کمپین پارک تفریحی ریورساید',
    'Riverside Amphitheater Annex' => 'آمفی‌تئاتر ریورساید',
    'Downtown' => 'مرکز شهر',
    'Mateo Alvarez' => 'ماتئو آلوارز',

    // Dates in sample fixtures
    'Nov 18' => '۱۸ نوامبر',
    'Nov 11' => '۱۱ نوامبر',
    'Nov 21' => '۲۱ نوامبر',
    'Nov 12' => '۱۲ نوامبر',
    'Dec 05' => '۰۵ دسامبر',
    'Oct 18' => '۱۸ اکتبر',
    'Nov 25' => '۲۵ نوامبر',
    'Nov 18, 2026' => '۱۸ نوامبر ۲۰۲۶',
    'Nov 21, 2026' => '۲۱ نوامبر ۲۰۲۶',
    'Nov 12, 2026' => '۱۲ نوامبر ۲۰۲۶',
    'Dec 05, 2026' => '۰۵ دسامبر ۲۰۲۶',
    'Oct 18, 2026' => '۱۸ اکتبر ۲۰۲۶',
    'Nov 25, 2026' => '۲۵ نوامبر ۲۰۲۶',
];

foreach ($newBatch as $k => $v) {
    $fa[$k] = $v;
    if (! isset($en[$k])) {
        $en[$k] = $k;
    }
}

// Ensure key parity
foreach ($fa as $k => $v) {
    if (! isset($en[$k])) {
        $en[$k] = $k;
    }
}
foreach ($en as $k => $v) {
    if (! isset($fa[$k])) {
        $fa[$k] = $k;
    }
}

ksort($fa);
ksort($en);

file_put_contents($faPath, json_encode($fa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($enPath, json_encode($en, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

echo 'Synced '.count($fa)." keys with 100% parity.\n";
