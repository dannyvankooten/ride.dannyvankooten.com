<?php

define('STRAVA_REDIRECT_URI', (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/callback');

function varDir(): string {
    $id = $_SESSION['athlete_id'] ?? '_';
    return __DIR__ . '/../var/' . $id . '/';
}

function tokensFile(): string {
    return varDir() . 'tokens.json';
}

const SETTINGS_DEFAULTS = [
    'target_minutes'   => 150,
    'long_ride_factor' => 1.5,
    'ftp'              => null,
    'max_heartrate'    => null,
    'available_days'   => [0, 1, 2, 3, 4], // Mon–Fri
];

function settingsFile(): string { return varDir() . 'settings.json'; }

function loadSettings(): array {
    $file  = settingsFile();
    $saved = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    return array_merge(SETTINGS_DEFAULTS, is_array($saved) ? $saved : []);
}

function saveSettings(array $s): void {
    $file = settingsFile();
    $dir  = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents($file, json_encode($s));
}
