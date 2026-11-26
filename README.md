TradeMaster for WebSpace Engine
====
####(Plugin)

The plugin implements the functionality of integration with the system of trade and warehouse accounting.

#### Установка
Place it in the `plugin` folder and include it in `installed.php` by adding the line:
```php
// tm plugin
$plugins->register(\Plugin\TradeMaster\TradeMasterPlugin::class);
```

#### События для других плагинов
Перед отправкой в TradeMaster отправляются только товары с `external_id`. Плагин, у которого есть
товары, неизвестные TM, может создать их и присвоить ID до отправки:

- `tm:order:before-send` — данные: `CatalogOrder`; после обработчиков товары заказа перечитываются
- `tm:cart:before-reserve` — данные: список uuid товаров корзины (резерв и КП, синхронно в запросе)

#### License
Licensed under the MIT license. See [License File](LICENSE.md) for more information.
