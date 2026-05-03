<?php

class Controller
{
    private const CACHE_TTL = 900; // 15 minutes

    private const BADGE_COLORS = [
        'hard' => '#c0392b',
        'long' => '#2471a3',
        'easy' => '#1e8449',
    ];

    public function dispatch(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        try {
            match ($path) {
                '/callback' => $this->callback(),
                '/logout'   => $this->logout(),
                '/refresh'  => $this->refresh(),
                '/settings' => $this->settings(),
                default     => $this->index(),
            };
        } catch (\Exception $e) {
            $this->renderError($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    private function index(): void
    {
        $tokens = getValidTokens();
        if ($tokens === null) {
            $authUrl = 'https://www.strava.com/oauth/authorize?' . http_build_query([
                'client_id'     => STRAVA_CLIENT_ID,
                'redirect_uri'  => STRAVA_REDIRECT_URI,
                'response_type' => 'code',
                'scope'         => 'activity:read_all',
            ]);
            $this->render('connect', ['authUrl' => $authUrl]);
            return;
        }
        $this->renderDashboard($tokens['access_token']);
    }

    private function refresh(): void
    {
        $tokens = getValidTokens();
        if ($tokens === null) { header('Location: /'); exit; }
        $this->renderDashboard($tokens['access_token'], forceRefresh: true);
    }

    private function logout(): void
    {
        session_destroy();
        header('Location: /');
        exit;
    }

    private function callback(): void
    {
        $tokens = exchangeCode($_GET['code'] ?? '');
        $_SESSION['athlete_id'] = (string) $tokens['athlete']['id'];
        saveTokens($tokens);
        header('Location: /');
        exit;
    }

    // -------------------------------------------------------------------------
    // Renderers
    // -------------------------------------------------------------------------

    private function renderDashboard(string $accessToken, bool $forceRefresh = false): void
    {
        $settings = loadSettings();
        [$allRides, $cacheAge] = $this->loadRides($accessToken, $forceRefresh, $settings);

        $cutoff    = date('Y-m-d', strtotime('-7 days'));
        $weekRides = array_values(array_filter($allRides, fn($r) => $r['date'] >= $cutoff));

        $result      = suggest($weekRides, $settings);
        $complete    = !empty($result['_complete']);
        $warning     = $result['_warning'] ?? null;
        $suggestions = $result['suggestions'];

        $ridesDone   = count($weekRides);
        $minutesDone = (int) round(array_sum(array_column($weekRides, 'moving_time')) / 60);
        $hasHard     = detectHardRide($weekRides);
        $hasLong     = detectLongRide($weekRides);

        $this->classifyRides($allRides);

        $this->render('dashboard', [
            'allRides'    => $allRides,
            'cacheAge'    => $cacheAge,
            'weekRides'   => $weekRides,
            'complete'    => $complete,
            'warning'     => $warning,
            'suggestions' => $suggestions,
            'ridesDone'   => $ridesDone,
            'minutesDone' => $minutesDone,
            'hasHard'     => $hasHard,
            'hasLong'     => $hasLong,
            'colors'      => self::BADGE_COLORS,
            'settings'    => $settings,
        ]);
    }

    private function settings(): void
    {
        $tokens = getValidTokens();
        if ($tokens === null) { header('Location: /'); exit; }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            saveSettings([
                'target_rides'       => max(1,   (int)   ($_POST['target_rides']       ?? 4)),
                'target_minutes'     => max(1,   (int)   ($_POST['target_minutes']     ?? 300)),
                'long_ride_factor'   => max(1.0, (float) ($_POST['long_ride_factor']   ?? 1.5)),
                'min_weekly_minutes' => max(0,   (int)   ($_POST['min_weekly_minutes'] ?? 150)),
                'ftp'           => ($_POST['ftp']           ?? '') !== '' ? max(1, (int) $_POST['ftp'])           : null,
                'max_heartrate' => ($_POST['max_heartrate'] ?? '') !== '' ? max(1, (int) $_POST['max_heartrate']) : null,
            ]);
            if (file_exists($this->cacheFile())) unlink($this->cacheFile());
            header('Location: /settings');
            exit;
        }

        $this->render('settings', ['settings' => loadSettings()]);
    }

    private function renderError(string $message): void
    {
        http_response_code(502);
        $this->render('error', ['message' => $message]);
        exit;
    }

    private function render(string $view, array $vars = []): void
    {
        extract($vars, EXTR_SKIP);
        $title     = 'Strava Workout Planner';
        $pageStyle = '';
        ob_start();
        include __DIR__ . '/../views/' . $view . '.php';
        $content = ob_get_clean();
        include __DIR__ . '/../views/layout.php';
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    private function cacheFile(): string
    {
        return varDir() . 'rides_cache.json';
    }

    /** Returns [rides[], cacheAgeSeconds] */
    private function loadRides(string $accessToken, bool $forceRefresh, array $settings): array
    {
        $cached = null;

        if (!$forceRefresh && file_exists($this->cacheFile())) {
            $cached = json_decode(file_get_contents($this->cacheFile()), true);
            if (!is_array($cached) || (time() - ($cached['ts'] ?? 0)) >= self::CACHE_TTL) {
                $cached = null;
            }
        }

        if ($cached !== null) {
            return [$cached['rides'], time() - $cached['ts']];
        }

        $rides = fetchRides($accessToken, 21, $settings);
        file_put_contents($this->cacheFile(), json_encode(['ts' => time(), 'rides' => $rides]));
        return [$rides, 0];
    }

    /** Adds _types key to each ride in place. */
    private function classifyRides(array &$rides): void
    {
        foreach ($rides as &$r) {
            $types = [];
            if (detectLongRide([$r])) $types[] = 'long';
            if (detectHardRide([$r])) $types[] = 'hard';
            if (empty($types))        $types[] = 'easy';
            $r['_types'] = $types;
        }
        unset($r);
    }
}
