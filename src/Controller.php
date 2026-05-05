<?php

class Controller
{
    private const CACHE_TTL = 60*60; // 1 hour

    private const BADGE_COLORS = [
        'hard' => '#c0392b',
        'long' => '#2471a3',
        'easy' => '#1e8449',
        'rest' => '#7f8c8d',
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
        if (getValidTokens() === null) { header('Location: /'); exit; }
        if (file_exists($this->cacheFile())) unlink($this->cacheFile());
        header('Location: /');
        exit;
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

    private function renderDashboard(string $accessToken): void
    {
        $settings = loadSettings();
        [$allRides, $cacheAge] = $this->loadRides($accessToken, $settings);

        // Current calendar week (Mon–Sun) — matches the planner's accounting
        $weekStart   = startOfCalendarWeek(date('Y-m-d'));
        $weekRides   = array_values(array_filter($allRides, fn($r) => $r['date'] >= $weekStart));
        $minutesDone = (int) round(array_sum(array_column($weekRides, 'moving_time')) / 60);

        $weekSchedule    = schedule($allRides, $settings);
        $pastWeeks       = $this->buildPastWeeks($allRides, $weekStart, 3);
        $weeklyTarget    = effectiveTarget($allRides, $settings);

        $this->render('dashboard', [
            'cacheAge'     => $cacheAge,
            'weekSchedule' => $weekSchedule,
            'pastWeeks'    => $pastWeeks,
            'minutesDone'  => $minutesDone,
            'weeklyTarget' => $weeklyTarget,
            'colors'       => self::BADGE_COLORS,
            'settings'     => $settings,
        ]);
    }

    /** Build $count past calendar weeks (most recent first). */
    private function buildPastWeeks(array $allRides, string $thisWeekStart, int $count): array
    {
        $rideByDate = [];
        foreach ($allRides as $r) $rideByDate[$r['date']] = $r;

        $weeks = [];
        for ($i = 1; $i <= $count; $i++) {
            $weekStart = date('Y-m-d', strtotime("$thisWeekStart -" . (7 * $i) . " days"));
            $days      = [];
            $total     = 0.0;
            for ($j = 0; $j < 7; $j++) {
                $date = date('Y-m-d', strtotime("$weekStart +$j days"));
                $ride = $rideByDate[$date] ?? null;
                if ($ride) $total += $ride['moving_time'] / 60;
                $days[] = ['date' => $date, 'dow' => $j, 'ride' => $ride];
            }
            $weeks[] = [
                'start' => $weekStart,
                'end'   => date('Y-m-d', strtotime("$weekStart +6 days")),
                'days'  => $days,
                'total' => (int) round($total),
            ];
        }
        return $weeks;
    }

    private function settings(): void
    {
        $tokens = getValidTokens();
        if ($tokens === null) { header('Location: /'); exit; }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $rawDays       = array_map('intval', (array) ($_POST['available_days'] ?? []));
            $availableDays = array_values(array_unique(array_filter($rawDays, fn($d) => $d >= 0 && $d <= 6)));
            sort($availableDays);

            saveSettings([
                'target_minutes'   => min(1800, max(1,   (int)   ($_POST['target_minutes']   ?? 150))),
                'long_ride_factor' => min(2.5,  max(1.0, (float) ($_POST['long_ride_factor'] ?? 1.5))),
                'ftp'           => ($_POST['ftp']           ?? '') !== '' ? min(1000, max(1, (int) $_POST['ftp']))           : null,
                'max_heartrate' => ($_POST['max_heartrate'] ?? '') !== '' ? min(300,  max(1, (int) $_POST['max_heartrate'])) : null,
                'available_days'   => $availableDays,
            ]);
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
        $title     = 'WattWeek - your personalised riding plan';
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
    private function loadRides(string $accessToken, array $settings): array
    {
        if (file_exists($this->cacheFile())) {
            $cached = json_decode(file_get_contents($this->cacheFile()), true);
            if (is_array($cached) && (time() - ($cached['ts'] ?? 0)) < self::CACHE_TTL) {
                return [$cached['rides'], time() - $cached['ts']];
            }
        }

        $rides = fetchRides($accessToken, 21, $settings);
        file_put_contents($this->cacheFile(), json_encode(['ts' => time(), 'rides' => $rides]));
        return [$rides, 0];
    }
}
