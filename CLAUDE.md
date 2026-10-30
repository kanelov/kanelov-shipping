# Kanelov Shipping

WooCommerce плъгин за доставка с Еконт и Box Now (класически чекаут, PHP 8.1+, HPOS). Общува се на български.

- **Текущ план:** `docs/SALES-PLAN.md` — подготовка на плъгина за продажба. Следващата работа започва оттам.
- Тестове: `COMPOSER_ALLOW_SUPERUSER=1 composer install && COMPOSER_ALLOW_SUPERUSER=1 composer test`; преди издание и `php -l` на всички PHP файлове.
- Издание: вдигни версията в `kanelov-shipping.php` (заглавката и `KS_VERSION`), слей в `main`, пусни workflow „Release“ (`release.yml`, ref `main`). Сайтовете взимат версията през Плъгини > „Обнови“ (`src/Updater.php`).
- Плъгинът работи на живо на artkartini.bg, artidea.bg, teniskata.bg и ikoni-podarak.bg: промени по чекаута и поръчките се проверяват внимателно преди издание.
