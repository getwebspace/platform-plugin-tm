TradeMaster для WebSpace Engine
====
####(Плагин)

Плагин реализует функционал интеграции с системой торгово-складского учета.

#### Установка
Поместить в папку `plugin` и подключить в `index.php` добавив строку:
```php
// tm plugin
$plugins->register(new \Plugin\TradeMaster\TradeMasterPlugin($container));
```


#### License
Licensed under the MIT license. See [License File](LICENSE.md) for more information.
