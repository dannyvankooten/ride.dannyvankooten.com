<?php

define('STRAVA_REDIRECT_URI', (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/callback');

function varDir(): string {
    $id = $_SESSION['athlete_id'] ?? '_';
    return __DIR__ . '/../var/' . $id . '/';
}

function tokensFile(): string {
    return varDir() . 'tokens.json';
}

define('TARGET_RIDES', 4);
define('TARGET_MINUTES', 300);
define('LONG_RIDE_FACTOR', 1.5);
define('MIN_WEEKLY_MINUTES', 150);

// Set these to enable accurate hard-ride detection via streams.
// FTP is used when available; MAX_HEARTRATE is the fallback.
// A ride is hard if more than 75% of its seconds exceed 75% of FTP (or max HR).
define('FTP', 238);           // e.g. 250
define('MAX_HEARTRATE', 195); // e.g. 185
