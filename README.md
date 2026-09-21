# Kanelov Shipping

WooCommerce плъгин за доставка с Еконт (офис, Еконтомат, адрес) и генериране на товарителници през JSON API на Еконт.
Ядрото е с интерфейс за куриери, за да се добавят BoxNow и други по същия модел.

## Изисквания

- WordPress 6.5+, WooCommerce 9+ (тествано с 11.x), PHP 8.1+ (тествано с 8.3/8.4), HPOS.
- API потребител от ee.econt.com (Профил > Интеграция за онлайн магазини). За демо средата: demo / demo.

## Инсталиране на нов сайт

1. Изтеглете zip файла на последната версия от [Releases](https://github.com/kanelov/kanelov-shipping/releases) (`kanelov-shipping-X.Y.Z.zip`).
2. В сайта: Плъгини > Добави нов > Качване на плъгин > изберете zip файла > Инсталирай > Активирай.
   Нужен е активен WooCommerce. Плъгинът може да е активен и на сайт без продукти, нищо не се показва, докато няма поръчки.
3. WooCommerce > Настройки > Доставка > **Еконт** (или менюто „Еконт“ в страничната лента):
   потребител и парола от ee.econt.com, среда „реална“, бутон „Тест на връзката“, после „Обнови профила“
   и „Обнови офисите и Еконтоматите“ (сваля офисите в локална таблица, отнема около минута).
4. Настройте цените (офис, Еконтомат, адрес), наложения платеж и другите опции в същата страница.
5. WooCommerce > Настройки > Доставка > Зони: във вашата зона за България добавете метод „Еконт“.
6. Обновяванията идват от GitHub: Плъгини > „Обнови“ при нова версия, или „Провери за обновления“ под плъгина.

Всеки сайт има собствени настройки и таблици с офиси; няколко сайта с един и същ Еконт профил не си пречат.

## Структура

```
kanelov-shipping.php            зареждане, HPOS/Blocks декларации
src/Plugin.php                  свързване на компонентите
src/Installer.php               таблици за градове и офиси
src/Updater.php                 обновления от GitHub Releases (бутон „Обнови“ в Плъгини)
src/Carrier/                    интерфейс за куриери, DeliveryData, резултати
src/Carrier/Econt/EcontApi           HTTP клиент за JSON API (Basic auth, TLS проверка)
src/Carrier/Econt/EcontSettings      четене на настройките
src/Carrier/Econt/EcontProfile       профил, адреси, споразумения за НП (кеш в option)
src/Carrier/Econt/EcontNomenclature  градове/офиси в таблици, улици/квартали в transient, Action Scheduler
src/Carrier/Econt/EcontLabelBuilder  чисто построяване на заявката createLabel (unit тестове)
src/Carrier/Econt/EcontCarrier       калкулация, създаване, изтриване, проследяване
src/Carrier/Econt/EcontShippingMethod  WC метод за доставка: една ставка с цена по избрания вид + глобални настройки
src/Rest/EcontSearchController  публични REST маршрути за търсене (kanelov-shipping/v1/econt/*)
src/Admin/SettingsActions       бутони: тест, обнови профила, обнови офисите и Еконтоматите
src/Admin/OrdersList            колона „Еконт“ с бутон за товарителница, масово създаване по дата
src/Admin/OrderMetabox          кутия „Еконт“ в поръчката: доставка, опции, създай/изтрий/PDF/проследи
src/Order/OrderMeta             мета ключове в поръчката (_ks_delivery, _ks_shipment)
tests/                          PHPUnit за чистата логика
```

## Разработка

```
composer install
composer test
```
