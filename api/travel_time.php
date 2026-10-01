<?php
/**
 * Travel-time endpoint for the event planner.
 *
 * GET api/travel_time.php?venue_id=123
 *   - Geocodes the signed-in user's saved address (Nominatim / OpenStreetMap)
 *   - Routes from the user's point to the venue coordinates (OSRM public demo)
 *   - Returns driving minutes + distance so the planner can pre-fill the
 *     "Travel" field of the preparation reminder.
 *
 * Everything is cached in the session per address+venue pair so repeated
 * planner opens do not hammer the free public services.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_api_login();
$u = current_user();

header('Content-Type: application/json; charset=utf-8');

$venueId = (int)($_GET['venue_id'] ?? 0);
$address = trim((string)($u['address'] ?? ''));
if ($venueId <= 0) { echo json_encode(['ok' => false, 'error' => 'Choose a venue first.']); exit; }
if ($address === '') {
    echo json_encode(['ok' => false, 'error' => 'Add your home address on My Profile to get a personal travel estimate.']); exit;
}
if (!function_exists('curl_init')) { echo json_encode(['ok' => false, 'error' => 'The server cannot reach the routing service.']); exit; }

/** Small GET helper returning [status, body]. */
$gets = function (string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, (string)$body];
};

// 1) Venue coordinates (optionally fall back to the chosen planner venue row).
$q = $pdo->prepare('SELECT name,address,latitude,longitude FROM venues WHERE id=?');
$q->execute([$venueId]);
$venue = $q->fetch(PDO::FETCH_ASSOC);
if (!$venue || !is_numeric((string)$venue['latitude']) || !is_numeric((string)$venue['longitude'])) {
    echo json_encode(['ok' => false, 'error' => 'This venue has no map coordinates yet.']); exit;
}
$vLat = (float)$venue['latitude']; $vLng = (float)$venue['longitude'];

// 2) Session cache: one geocode per address + per venue route.
$addrKey = 'geo_' . md5(mb_strtolower($address));
$uLat = $_SESSION[$addrKey]['lat'] ?? null;
$uLng = $_SESSION[$addrKey]['lng'] ?? null;
$label = $_SESSION[$addrKey]['label'] ?? null;

// 3) Geocode once per address. Restrict to South Cotabato / Philippines to
//    avoid stray matches, mirroring how venues are stored.
if (!$uLat || !$uLng) {
    // Try the full address first, then progressively simpler variants so
    // "Purok X, Brgy. Y, Tupi" still resolves even if OSM lacks the purok.
    $variants = [$address];
    if (!str_contains($address, 'Tupi')) $variants[] = $address . ', Tupi, South Cotabato';
    $variants[] = preg_replace('/^Purok[^,]+,\s*/i', '', $address);
    $variants[] = 'Brgy. Poblacion, Tupi, South Cotabato';
    $variants[] = 'Tupi, South Cotabato';
    $geo = null;
    $lastStatus = 0;
    foreach ($variants as $attempt => $q) {
        $q = trim($q);
        if ($q === '') continue;
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $q,
            'format' => 'jsonv2',
            'limit' => 1,
            'countrycodes' => 'ph',
            'viewbox' => '124.60,5.60,125.30,6.80',
            'bounded' => 1,
        ]);
        [$status, $body] = $gets($url, ['User-Agent: SportSync/1.0 (sportsync local deployment)']);
        $lastStatus = $status;
        $data = json_decode($body, true);
        if ($status < 400 && is_array($data) && $data) { $geo = $data; break; }
        usleep(300000); // Nominatim usage policy: no rapid-fire requests
    }
    if (!$geo) {
        echo json_encode(['ok' => false, 'error' => 'We could not find your address on the map yet. You can still type Travel minutes manually. (' . ($lastStatus >= 400 ? 'map service busy' : 'address not found — add your barangay or municipality on My Profile') . ')']); exit;
    }
    $uLat = (float)$geo[0]['lat'];
    $uLng = (float)$geo[0]['lon'];
    $label = trim((string)($geo[0]['display_name'] ?? ''));
    if (mb_strlen($label) > 90) $label = mb_substr($label, 0, 87) . '…';
    $_SESSION[$addrKey] = ['lat' => $uLat, 'lng' => $uLng, 'label' => $label];
}

// 4) Route via OSRM (public demo server), cached per address+venue pair.
$routeKey = 'route_' . md5(mb_strtolower($address) . '|' . $vLat . ',' . $vLng);
$minutes = $_SESSION[$routeKey]['minutes'] ?? null;
$distanceKm = $_SESSION[$routeKey]['km'] ?? null;

if ($minutes === null) {
    $osrm = 'https://router.project-osrm.org/route/v1/driving/' .
        number_format($uLng, 6, '.', '') . ',' . number_format($uLat, 6, '.', '') . ';' .
        number_format($vLng, 6, '.', '') . ',' . number_format($vLat, 6, '.', '') .
        '?overview=false&alternatives=false&steps=false';
    [$status, $body] = $gets($osrm);
    $route = json_decode($body, true);
    if ($status >= 400 || !is_array($route) || strtoupper((string)($route['code'] ?? '')) !== 'OK' || empty($route['routes'][0]['duration'])) {
        // Fall back to straight-line distance at a conservative rural average.
        $dLat = deg2rad($vLat - $uLat);
        $dLng = deg2rad($vLng - $uLng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($uLat)) * cos(deg2rad($vLat)) * sin($dLng / 2) ** 2;
        $km = 6371 * 2 * asin(min(1, sqrt($a)));
        $distanceKm = round($km, 1);
        $minutes = (int)max(5, round($km / 30 * 60)); // ~30 km/h municipal average
    } else {
        $minutes = (int)max(1, round(((float)$route['routes'][0]['duration']) / 60));
        $distanceKm = round(((float)$route['routes'][0]['distance']) / 1000, 1);
    }
    $_SESSION[$routeKey] = ['minutes' => $minutes, 'km' => $distanceKm];
}

echo json_encode([
    'ok' => true,
    'minutes' => $minutes,
    'km' => $distanceKm,
    'origin' => $label,
    'venue' => $venue['name'],
    'approx' => $distanceKm !== null && $minutes !== null ? null : null,
]);
