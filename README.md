# Tide Forecast Telegram Bot
Telegram-бот на PHP показывает прогноз приливов и отливов для выбранной локации. 
Пользователь отправляет название места или координаты, а бот возвращает текущую фазу воды, местное время и расписание ближайших приливов и отливов.

## Live Demo
Бот доступен в Telegram @TideForecastBot

## Источник данных
Данные о приливах берутся из Stormglass API через endpoint:
https://api.stormglass.io/v2/tide/extremes/point

Бот отправляет в Stormglass координаты локации (`lat`, `lng`) и временной диапазон (`start`, `end`). В ответ API возвращает события:
type - high или low
time - время события
height - высота воды
где `high` - прилив, а `low` - отлив.

## Как работает бот
* координаты по названию локации определяются через Nominatim;
* часовой пояс определяется через Open-Meteo API;
* расписание приливов берётся из Stormglass API;
* данные кэшируются в `tides_cache.json`;
* токены и API-ключи хранятся отдельно в `config.php`.

## Стек
PHP, Telegram Bot API, Stormglass API, Open-Meteo API, Nominatim, JSON cache.

## Config
Файл `config.php` не добавлен в репозиторий. Для примера выложен `config.example.php`.


