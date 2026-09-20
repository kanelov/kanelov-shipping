# Kanelov Shipping

WooCommerce плъгин за доставка с Еконт (офис, Еконтомат, адрес) и генериране на товарителници през JSON API на Еконт.
Ядрото е с интерфейс за куриери, за да се добавят BoxNow и други по същия модел.

## Изисквания

- WordPress 6.5+, WooCommerce 9+ (тествано с 11.x), PHP 8.1+ (тествано с 8.3/8.4), HPOS.
- API потребител от ee.econt.com (Профил > Интеграция за онлайн магазини). За демо средата: demo / demo.

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
