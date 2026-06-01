<?php

$configPath = __DIR__ . '/config.php';

if (!file_exists($configPath)) {
    error_log('Config file not found: config.php');
    exit;
}

$config = require $configPath;

$TELEGRAM_TOKEN = $config['telegram_token'] ?? '';
$STORMGLASS_KEY = $config['stormglass_key'] ?? '';
$CACHE_FILE = $config['cache_file'] ?? (__DIR__ . '/tides_cache.json');
$CACHE_TTL = $config['cache_ttl'] ?? 3600;

// Версия кэша.
// Если меняется логика получения данных, увеличиваем число,
// чтобы старые данные в tides_cache.json не мешали.
$CACHE_VERSION = 2;

if ($TELEGRAM_TOKEN === '' || $STORMGLASS_KEY === '') {
    error_log('Telegram token or Stormglass API key is missing.');
    exit;
}

// --- ВХОДЯЩИЕ ДАННЫЕ ОТ TELEGRAM ---
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update || !isset($update['message'])) {
    exit;
}

$message = $update['message'];
$chatId = $message['chat']['id'];
$text = trim($message['text'] ?? '');
$location = $message['location'] ?? null;

// --- МАРШРУТИЗАЦИЯ ---
if ($text === '/start') {
    sendMessage($chatId, 'Привет! Отправь мне название города, острова или геолокацию 📍');
} elseif ($location) {
    $lat = $location['latitude'];
    $lon = $location['longitude'];

    getTides($chatId, $lat, $lon, 'GPS Location');
} elseif ($text && !str_starts_with($text, '/')) {

    // 1. Если пользователь отправил координаты текстом:
    // 9.557602, 100.052064
    // 9.557602 100.052064
    // используем их напрямую, без Nominatim.
    $coordinates = parseCoordinatesFromText($text);

    if ($coordinates) {
        getTides(
            $chatId,
            $coordinates['lat'],
            $coordinates['lon'],
            'Координаты: ' . $coordinates['lat'] . ', ' . $coordinates['lon']
        );
        exit;
    }

    // 2. Готовим поисковый запрос.
    // Для Samui/Koh Samui/Самуи уточняем запрос, тк путает с локацией в Европе
    
    $searchSettings = resolveSearchSettings($text);

    $nominatimParams = [
        'q' => $searchSettings['query'],
        'format' => 'json',
        'limit' => 1,
        'accept-language' => 'ru,en',
    ];

    if (!empty($searchSettings['countrycodes'])) {
        $nominatimParams['countrycodes'] = $searchSettings['countrycodes'];
    }

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($nominatimParams);

    $response = curlRequest($url, ['User-Agent: TideBot/1.0']);
    $data = json_decode($response, true);

    if (empty($data)) {
        sendMessage($chatId, 'Локация не найдена. Попробуй написать название иначе.');
    } else {
        $lat = $data[0]['lat'];
        $lon = $data[0]['lon'];

        if (!empty($searchSettings['display_name'])) {
            $cleanName = $searchSettings['display_name'];
        } else {
            $displayNameParts = explode(',', $data[0]['display_name']);
            $cleanName = trim($displayNameParts[0]);

            if (isset($displayNameParts[1])) {
                $cleanName .= ', ' . trim($displayNameParts[1]);
            }
        }

        getTides($chatId, $lat, $lon, $cleanName);
    }
}

// --- ОБРАБОТКА КООРДИНАТ ИЗ ТЕКСТА ---
function parseCoordinatesFromText($text) {
    $text = trim($text);

    // Формат: 9.557602, 100.052064
    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $text, $matches)) {
        $lat = (float)$matches[1];
        $lon = (float)$matches[2];

        if (isValidCoordinates($lat, $lon)) {
            return [
                'lat' => $lat,
                'lon' => $lon,
            ];
        }
    }

    // Формат: 9.557602 100.052064
    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*$/', $text, $matches)) {
        $lat = (float)$matches[1];
        $lon = (float)$matches[2];

        if (isValidCoordinates($lat, $lon)) {
            return [
                'lat' => $lat,
                'lon' => $lon,
            ];
        }
    }

    return null;
}

function isValidCoordinates($lat, $lon) {
    return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
}

// --- ПОДГОТОВКА ПОИСКОВОГО ЗАПРОСА ДЛЯ NOMINATIM ---
function resolveSearchSettings($query) {
    $normalized = mb_strtolower(trim($query), 'UTF-8');

    // Убираем лишние символы, чтобы варианты вроде "koh-samui" тоже распознавались.
    $normalized = str_replace(['-', '_', '.', ',', '  '], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    $samuiAliases = [
        'samui',
        'koh samui',
        'ko samui',
        'косамуи',
        'ко самуи',
        'самуи',
        'koh samui thailand',
        'ko samui thailand',
        'samui thailand',
        'самуи таиланд',
    ];

    if (in_array($normalized, $samuiAliases, true)) {
        return [
            'query' => 'Koh Samui, Surat Thani, Thailand',
            'countrycodes' => 'th',
            'display_name' => 'Koh Samui, Thailand',
        ];
    }

    return [
        'query' => $query,
        'countrycodes' => null,
        'display_name' => null,
    ];
}

// --- ФУНКЦИЯ ПРИЛИВОВ ---
function getTides($chatId, $lat, $lon, $locationName) {
    global $STORMGLASS_KEY, $CACHE_FILE, $CACHE_TTL, $CACHE_VERSION;

    $lat = round((float)$lat, 6);
    $lon = round((float)$lon, 6);

    $cacheKey = "{$lat},{$lon}";
    $tidesData = null;
    $nowUnix = time();
    $timezone = 'UTC';

    // --- ПРОВЕРКА КЭША ---
    if (file_exists($CACHE_FILE)) {
        $cache = json_decode(file_get_contents($CACHE_FILE), true);

        if (
            is_array($cache) &&
            isset($cache[$cacheKey]) &&
            isset($cache[$cacheKey]['version']) &&
            $cache[$cacheKey]['version'] === $CACHE_VERSION &&
            isset($cache[$cacheKey]['timestamp']) &&
            $nowUnix < $cache[$cacheKey]['timestamp'] + $CACHE_TTL
        ) {
            $cachedData = $cache[$cacheKey]['data'] ?? [];
            $cachedTimezone = $cache[$cacheKey]['timezone'] ?? 'UTC';

            // Проверяем, хватает ли в кэше минимум 4 будущих события.
            // Если нет запрашиваем свежие данные.
            $futureCount = 0;

            foreach ($cachedData as $item) {
                if (isset($item['time']) && strtotime($item['time']) > $nowUnix) {
                    $futureCount++;
                }
            }

            if ($futureCount >= 4) {
                $tidesData = $cachedData;
                $timezone = $cachedTimezone;
            }
        }
    }

    // --- ЕСЛИ КЭША НЕТ ИЛИ ОН НЕ ПОДХОДИТ ---
    if (!$tidesData) {
        sendMessage($chatId, '⏳ Считаю волны и определяю время...');

        // 1. Получаем часовой пояс по координатам.
        $tzUrl = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&timezone=auto";
        $tzResponse = curlRequest($tzUrl);
        $tzData = json_decode($tzResponse, true);

        if (isset($tzData['timezone'])) {
            $timezone = $tzData['timezone'];
        }

        // 2. Получаем данные о приливах.
        // start - 12 часов назад, чтобы рассчитать текущую фазу.
        // end - 48 часов вперёд, чтобы всегда было достаточно событий.
        $url = 'https://api.stormglass.io/v2/tide/extremes/point?' . http_build_query([
            'lat' => $lat,
            'lng' => $lon,
            'start' => $nowUnix - 43200,
            'end' => $nowUnix + 172800,
        ]);

        $response = curlRequest($url, ["Authorization: {$STORMGLASS_KEY}"]);
        $apiData = json_decode($response, true);

        if (isset($apiData['data']) && is_array($apiData['data']) && count($apiData['data']) > 0) {
            $tidesData = $apiData['data'];

            $cache = file_exists($CACHE_FILE) ? json_decode(file_get_contents($CACHE_FILE), true) : [];

            if (!is_array($cache)) {
                $cache = [];
            }

            $cache[$cacheKey] = [
                'version' => $CACHE_VERSION,
                'timestamp' => $nowUnix,
                'data' => $tidesData,
                'timezone' => $timezone,
            ];

            file_put_contents($CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE));
        } else {
            sendMessage(
                $chatId,
                "Не удалось получить данные о приливах для этой точки.\n\n" .
                "Попробуй отправить другую ближайшую прибрежную локацию или геолокацию через Telegram 📍"
            );
            return;
        }
    }

    // --- СОРТИРОВКА СОБЫТИЙ ПО ВРЕМЕНИ ---
    usort($tidesData, function($a, $b) {
        return strtotime($a['time']) - strtotime($b['time']);
    });

    $lastEvent = null;
    $nextEvent = null;

    foreach ($tidesData as $item) {
        if (!isset($item['time'])) {
            continue;
        }

        if (strtotime($item['time']) <= $nowUnix) {
            $lastEvent = $item;
        } else {
            $nextEvent = $item;
            break;
        }
    }

    $statusText = 'неизвестно';
    $comment = '';

    if ($lastEvent && $nextEvent) {
        $total = strtotime($nextEvent['time']) - strtotime($lastEvent['time']);
        $passed = $nowUnix - strtotime($lastEvent['time']);

        if ($total > 0) {
            $percent = round(($passed / $total) * 100);
            $percent = max(0, min(100, $percent));

            $isHighTide = ($lastEvent['type'] === 'low');
            $statusText = $isHighTide ? "прилив ({$percent}%)" : "отлив ({$percent}%)";
            $comment = getComment($isHighTide, $percent);
        }
    }

    // --- ЧАСОВОЙ ПОЯС ---
    try {
        $tzObj = new DateTimeZone($timezone);
    } catch (Exception $e) {
        $timezone = 'UTC';
        $tzObj = new DateTimeZone($timezone);
    }

    $nowLocal = new DateTime('now', $tzObj);
    $timeNow = $nowLocal->format('H:i');

    // Экранируем данные для HTML-режима Telegram.
    $safeLocationName = htmlspecialchars($locationName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTimezone = htmlspecialchars($timezone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeStatusText = htmlspecialchars($statusText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeComment = htmlspecialchars($comment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // --- ФОРМИРОВАНИЕ СООБЩЕНИЯ ---
    $messageText = "🌎 <b>Ваша локация:</b> {$safeLocationName}\n";
    $messageText .= "🕘 <b>Текущее время:</b> {$timeNow} (Часовой пояс: {$safeTimezone})\n\n";
    $messageText .= "🌊 <b>В данный момент:</b> {$safeStatusText}\n";

    if ($safeComment !== '') {
        $messageText .= "<i>{$safeComment}</i>\n\n";
    } else {
        $messageText .= "\n";
    }

    $messageText .= "<b>Прогноз приливов и отливов:</b>\n";
    $messageText .= "<i>Местное время локации</i>\n";

    $eventsAdded = 0;

    foreach ($tidesData as $item) {
        if (!isset($item['time'], $item['type'], $item['height'])) {
            continue;
        }

        if (strtotime($item['time']) > $nowUnix && $eventsAdded < 4) {
            $eventTime = new DateTime($item['time']);
            $eventTime->setTimezone($tzObj);

            $dateTimeText = formatRussianDateTime($eventTime);
            $height = number_format((float)$item['height'], 2, '.', '');

            $type = ($item['type'] === 'high') ? '🔼 ПРИЛИВ' : '🔽 ОТЛИВ';

            $messageText .= "\n{$type} — {$dateTimeText}\n";
            $messageText .= "Высота: {$height} м\n";

            $eventsAdded++;
        }
    }

    if ($eventsAdded === 0) {
        $messageText .= "\nНет ближайших данных по приливам и отливам для этой локации.\n";
    }

    sendMessage($chatId, $messageText, 'HTML');
}

// --- ФОРМАТИРОВАНИЕ ДАТЫ НА РУССКОМ ---
function formatRussianDateTime(DateTime $dateTime) {
    $months = [
        1 => 'января',
        2 => 'февраля',
        3 => 'марта',
        4 => 'апреля',
        5 => 'мая',
        6 => 'июня',
        7 => 'июля',
        8 => 'августа',
        9 => 'сентября',
        10 => 'октября',
        11 => 'ноября',
        12 => 'декабря',
    ];

    $day = (int)$dateTime->format('j');
    $month = $months[(int)$dateTime->format('n')];
    $time = $dateTime->format('H:i');

    return "{$day} {$month}, {$time}";
}

// --- ФУНКЦИЯ ВЫБОРА КОММЕНТАРИЯ ---
function getComment($isHighTide, $percent) {
    if ($isHighTide) {
        if ($percent < 10) return 'Прилив только-только начался - вода далеко от берега!';
        if ($percent < 30) return 'Начался прилив. Вода еще далеко, но скоро начнет прибывать!';
        if ($percent < 60) return 'Прилив в самом разгаре, вода прибывает!';
        if ($percent < 90) return 'Вода поднялась. Лучшее время для купания! 🏊‍♂️';

        return 'Максимальный прилив! Не пропусти большую воду! 🌊';
    }

    if ($percent < 10) return 'Отлив только начался! Вода высоко, еще можно успеть искупаться!';
    if ($percent < 30) return 'Начался отлив, скоро вода начнет уходить.';
    if ($percent < 60) return 'В данный момент идет отлив. Будьте внимательнее на мелководье!';
    if ($percent < 90) return 'Отлив в самом разгаре! Вода уходит очень быстро.';

    return 'Максимальный отлив! Осторожно, на берегу мелко и торчат кораллы! 🪸';
}

// --- ОТПРАВКА СООБЩЕНИЯ В TELEGRAM ---
function sendMessage($chatId, $text, $parseMode = null) {
    global $TELEGRAM_TOKEN;

    $url = "https://api.telegram.org/bot{$TELEGRAM_TOKEN}/sendMessage";

    $postFields = [
        'chat_id' => $chatId,
        'text' => $text,
    ];

    if ($parseMode) {
        $postFields['parse_mode'] = $parseMode;
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    curl_exec($ch);
    curl_close($ch);
}

// --- ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ ДЛЯ HTTP-ЗАПРОСОВ ---
function curlRequest($url, $headers = []) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);

    curl_close($ch);

    return $response;
}

?>