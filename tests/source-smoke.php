<?php declare(strict_types=1);

$root = dirname(__DIR__);
$module = file_get_contents($root . '/PlausibleAnalytics.module.php');
$changelog = file_get_contents($root . '/CHANGELOG.md');
$readme = file_get_contents($root . '/README.md');
$checks = 0;

$assert = static function(bool $condition, string $message) use (&$checks): void {
    $checks++;
    if(!$condition) throw new RuntimeException($message);
};

$assert(str_contains($module, "'version'    => '1.3.1'"), 'module version is not 1.3.1');
$assert(str_contains($module, 'public function ___request('), 'hookable HTTP transport is missing');
$assert(substr_count($module, '$this->request(') === 2, 'not every Plausible API workflow uses the HTTP seam');
$assert(str_contains($module, 'CURLOPT_SSL_VERIFYPEER => true') && !str_contains($module, 'CURLOPT_SSL_VERIFYPEER => false'), 'TLS peer verification is not enforced');
$assert(str_contains($module, 'CURLOPT_SSL_VERIFYHOST => 2'), 'TLS hostname verification is not enforced');
$assert(substr_count($module, "if (!\$this->debug_mode) {\n                \$this->wire('cache')->save") === 2, 'debug mode does not bypass every API cache write');
$assert(str_contains($changelog, '## [1.3.1] - 2026-09-30'), '1.3.1 changelog entry is missing');
$assert(str_contains($readme, 'hookable `PlausibleAnalytics::request()`'), 'HTTP seam documentation is missing');

fwrite(STDOUT, "PlausibleAnalytics source smoke: {$checks} checks passed\n");
