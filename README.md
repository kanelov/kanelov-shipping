# Kanelov Shipping

WooCommerce плъгин за доставка с Еконт (офис, Еконтомат, адрес) и Box Now (автомат) с товарителници от поръчката.
Еконт работи през JSON API на ee.econt.com, Box Now през Partner API (OAuth2). Ядрото е с интерфейс за куриери.

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

## Box Now

1. WooCommerce > Настройки > Доставка > **Box Now** (менюто „Доставки“ > Box Now): Client ID, Client Secret, Partner ID,
   среда „реална“, Запази, после „Тест на връзката“. Тестът зарежда складовете и разрешенията от Box Now и сваля автоматите.
2. „Изпращане от“: изберете склада (Warehouse ID от писмото на Box Now), попълнете подател, Запази.
3. WooCommerce > Настройки > Доставка > Зони: добавете метод „Box Now“ в зоната за България и задайте цената.
4. „Тестов режим“ показва Box Now само на администратори; изключете го, когато сте готови.
5. В поръчката кутията „Box Now“ създава пратката, отваря PDF етикета, проследява и отказва. Box Now взима пратките от склада ви.

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
src/Carrier/BoxNow/BoxNowApi         HTTP клиент за Partner API (OAuth2 токен в transient, X-PartnerID)
src/Carrier/BoxNow/BoxNowLockers     автомати в таблица от публичния JSON на Box Now, търсене по град/най-близки
src/Carrier/BoxNow/BoxNowLabelBuilder чисто построяване на заявката delivery-requests (unit тестове)
src/Carrier/BoxNow/BoxNowCarrier     заявка за доставка, PDF етикет (в uploads), отказ, статус
src/Carrier/BoxNow/BoxNowShippingMethod WC метод „Box Now“: една ставка до автомат + глобални настройки
src/Checkout/BoxNowFormView          полета за избор на автомат (префикс ksbn_), общ JS с Еконт
src/Rest/BoxNowSearchController      публични REST маршрути за търсене на автомати (kanelov-shipping/v1/boxnow/*)
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
