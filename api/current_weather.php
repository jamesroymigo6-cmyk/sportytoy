<?php
require '../config/db.php';
require '../config/auth.php';
require_api_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

$lat = 6.3348;
$lon = 124.9526;
$url = 'https://api.open-meteo.com/v1/forecast?latitude='.$lat.'&longitude='.$lon.'&current=temperature_2m,relative_humidity_2m,precipitation,weather_code,wind_speed_10m&hourly=precipitation_probability&timezone=Asia%2FManila&forecast_days=1';

function fetch_weather_json(string $url): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>7,CURLOPT_USERAGENT=>'SportSync/1.0']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? $body : false;
    }
    $ctx = stream_context_create(['http'=>['timeout'=>7,'header'=>"User-Agent: SportSync/1.0\r\n"]]);
    return @file_get_contents($url,false,$ctx);
}
function weather_label(int $code): string {
    if ($code === 0) return 'Clear sky';
    if (in_array($code,[1,2],true)) return 'Partly cloudy';
    if ($code === 3) return 'Cloudy';
    if (in_array($code,[45,48],true)) return 'Foggy';
    if (in_array($code,[51,53,55,56,57],true)) return 'Drizzle';
    if (in_array($code,[61,63,65,66,67],true)) return 'Rain';
    if (in_array($code,[80,81,82],true)) return 'Rain showers';
    if (in_array($code,[95,96,99],true)) return 'Thunderstorms';
    return 'Current conditions';
}

try {
    $raw = fetch_weather_json($url);
    if ($raw === false) throw new RuntimeException('Weather provider unavailable.');
    $data = json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $current = $data['current'] ?? [];
    $hourly = $data['hourly'] ?? [];
    $rain = 0;
    if (!empty($hourly['time']) && !empty($hourly['precipitation_probability'])) {
        $now = time(); $best = PHP_INT_MAX; $idx = 0;
        foreach ($hourly['time'] as $i=>$t) { $diff=abs(strtotime($t)-$now); if($diff<$best){$best=$diff;$idx=$i;} }
        $rain = (int)($hourly['precipitation_probability'][$idx] ?? 0);
    }
    $code=(int)($current['weather_code'] ?? -1);
    echo json_encode([
        'ok'=>true,
        'location'=>'Tupi, South Cotabato',
        'temperature'=>(float)($current['temperature_2m'] ?? 0),
        'humidity'=>(int)($current['relative_humidity_2m'] ?? 0),
        'precipitation'=>(float)($current['precipitation'] ?? 0),
        'rain_probability'=>$rain,
        'wind_speed'=>(float)($current['wind_speed_10m'] ?? 0),
        'weather_code'=>$code,
        'condition'=>weather_label($code),
        'updated_at'=>date(DATE_ATOM),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Live weather is temporarily unavailable.']);
}
