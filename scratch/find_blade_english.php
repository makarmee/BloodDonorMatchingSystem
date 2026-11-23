<?php

$dir = new RecursiveDirectoryIterator(__DIR__.'/../resources/views');
$iterator = new RecursiveIteratorIterator($dir);

$results = [];

foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $relative = str_replace(realpath(__DIR__.'/..').DIRECTORY_SEPARATOR, '', $path);
    $content = file_get_contents($path);

    // Remove comments {{-- ... --}} and <!-- ... -->
    $content = preg_replace('/\{\{--.*?--\}\}/s', '', $content);
    $content = preg_replace('/<!--.*?-->/s', '', $content);

    // Remove PHP blocks <?php ... ? >, @php ... @endphp
    $content = preg_replace('/<\?php.*?\?>/s', '', $content);
    $content = preg_replace('/@php.*?@endphp/s', '', $content);

    // Remove Blade directives that don't output text: @if, @foreach, @slot, etc.
    // But keep what's inside text.
    // Remove script and style tags
    $content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content);
    $content = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $content);

    // Remove {{ __('...') }} or {{ __("...") }}
    $content = preg_replace('/\{\{\s*__\((?:(?:\'[^\']*\')|(?:\"[^\"]*\"))\s*(?:,\s*\[.*?\])?\s*\)\s*\}\}/s', '', $content);

    // Remove {!! __('...') !!} or {!! __("...") !!}
    $content = preg_replace('/\{!!\s*__\((?:(?:\'[^\']*\')|(?:\"[^\"]*\"))\s*(?:,\s*\[.*?\])?\s*\)\s*!!\}/s', '', $content);

    // Remove HTML tags, keeping their inner content
    // But wait, attributes like placeholder="...", title="..." might contain hardcoded English!
    // Let's check attributes first:
    preg_match_all('/(placeholder|title|aria-label|label)=["\']([^"\']*[A-Za-z]{3,}[^"\']*)["\']/i', $content, $attrMatches, PREG_SET_ORDER);
    foreach ($attrMatches as $m) {
        $attrVal = $m[2];
        if (str_contains($attrVal, '{{') || str_contains($attrVal, ':')) {
            continue; // handled dynamically
        }
        $results[$relative][] = "Attribute {$m[1]}: \"{$attrVal}\"";
    }

    // Now strip HTML tags
    $text = strip_tags($content);

    // Remove {{ $var }} echos for now to see what literal text remains
    $textWithoutEcho = preg_replace('/\{\{.*?\}\}/s', '', $text);
    $textWithoutEcho = preg_replace('/\{!!.*?!!\}/s', '', $textWithoutEcho);
    $textWithoutEcho = preg_replace('/@[a-zA-Z_]+(?:\(.*?\))?/', '', $textWithoutEcho);

    // Find sequences of 2 or more English words
    preg_match_all('/\b[A-Za-z]{2,}(?:\s+[A-Za-z0-9\-]+){1,}\b/', $textWithoutEcho, $textMatches);
    foreach ($textMatches[0] as $phrase) {
        $trimmed = trim($phrase);
        if (preg_match('/^(true|false|null|route|request|auth|array|count|match|default)$/i', $trimmed)) {
            continue;
        }
        if (preg_match('/^(A\+|A\-|B\+|B\-|AB\+|AB\-|O\+|O\-)$/', $trimmed)) {
            continue;
        }
        $results[$relative][] = "Literal text: \"{$trimmed}\"";
    }
}

file_put_contents(__DIR__.'/blade_audit.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'Blade audit complete. Found issues in '.count($results)." files.\n";
