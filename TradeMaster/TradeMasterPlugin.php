<?php declare(strict_types=1);

namespace Plugin\TradeMaster;

use App\Domain\AbstractPlugin;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class TradeMasterPlugin extends AbstractPlugin
{
    const NAME = 'TradeMasterPlugin';
    const TITLE = 'TradeMaster';
    const DESCRIPTION = 'Плагин реализует функционал интеграции с системой торгово-складского учета.';
    const AUTHOR = 'Aleksey Ilyin';
    const AUTHOR_SITE = 'https://u4et.ru/trademaster';
    const VERSION = '6.3';

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $self = $this;

        $this->setTemplateFolder(__DIR__ . '/templates');
        $this->addToolbarItem(['twig' => 'trademaster.twig']);
        $this->addTwigExtension(\Plugin\TradeMaster\TradeMasterPluginTwigExt::class);

        $this->addSettingsField([
            'label' => 'Ключ доступа к API',
            'description' => 'Введите полученный вами ключ',
            'type' => 'text',
            'name' => 'key',
        ]);

        $this->addSettingsField([
            'label' => 'Хост API',
            'type' => 'text',
            'name' => 'host',
            'args' => [
                'value' => 'https://api.trademaster.pro',
                'readonly' => true,
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Версия API',
            'type' => 'text',
            'name' => 'version',
            'args' => [
                'value' => '2',
                'readonly' => true,
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Валюта API',
            'type' => 'text',
            'name' => 'currency',
            'args' => [
                'value' => 'RUB',
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Хост кеш файлов',
            'type' => 'text',
            'name' => 'cache_host',
            'args' => [
                'value' => 'https://trademaster.pro',
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Папка на хосте с кеш файлами',
            'type' => 'text',
            'name' => 'cache_folder',
        ]);

        $this->addSettingsField([
            'label' => '',
            'type' => 'button',
            'name' => 'update',
            'args' => [
                'class' => ['btn', 'btn-info'],
                'value' => 'Загрузить параметры из API',
            ],
        ]);

        // saved config from API
        $config = json_decode($this->parameter('TradeMasterPlugin_config', '[]'), true);

        $this->addSettingsField([
            'label' => 'Склад',
            'type' => 'select',
            'name' => 'storage',
            'args' => [
                'option' => $config['storage'] ?? [],
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Юр. Лицо',
            'type' => 'select',
            'name' => 'legal',
            'args' => [
                'option' => $config['legal'] ?? [],
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Счет',
            'type' => 'select',
            'name' => 'checkout',
            'args' => [
                'option' => $config['checkout'] ?? [],
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Контрактор',
            'type' => 'select',
            'name' => 'contractor',
            'args' => [
                'option' => $config['contractor'] ?? [],
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Схема',
            'type' => 'select',
            'name' => 'scheme',
            'args' => [
                'option' => $config['scheme'] ?? [],
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Пользователь ID',
            'type' => 'select',
            'name' => 'user',
            'args' => [
                'option' => $config['user'] ?? [],
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Структура БД',
            'type' => 'select',
            'name' => 'struct',
            'args' => [
                'selected' => 'off',
                'option' => [
                    '0' => 'Простая',
                    '1' => 'Сложная',
                ],
            ],
        ]);
        $this->addSettingsField([
            'label' => '',
            'type' => 'textarea',
            'name' => 'config',
            'args' => [
                'value' => $this->parameter('TradeMasterPlugin_config', '[]'),
                'style' => 'display: none;',
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Категория по-умолчанию',
            'description' => 'Выгружает указанную категорию, вложенные подкатегории и товары',
            'type' => 'text',
            'name' => 'category_link',
        ]);

        $this->addSettingsField([
            'label' => 'Обновлять продукты в TM',
            'description' => 'Выгружать продукты автоматически после каждого изменения',
            'type' => 'select',
            'name' => 'auto_update',
            'args' => [
                'selected' => 'off',
                'option' => [
                    'off' => 'Нет',
                    'on' => 'Да',
                ],
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Обновлять поисковый индекс',
            'description' => 'Включить чтобы обновить индекс после синхронизации',
            'type' => 'select',
            'name' => 'search',
            'args' => [
                'selected' => 'off',
                'option' => [
                    'off' => 'Выключена',
                    'on' => 'Включена',
                ],
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Шаблон письма клиенту',
            'description' => 'Если значения нет, письмо не будет отправляться',
            'type' => 'text',
            'name' => 'mail_client_template',
            'args' => [
                'placeholder' => 'catalog.mail.client.twig',
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Шаблон письма заказа',
            'description' => 'Если значения нет, письмо не будет отправляться',
            'type' => 'text',
            'name' => 'mail_order_template',
            'args' => [
                'placeholder' => 'catalog.mail.order.twig',
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Оптовая стоимость',
            'description' => 'Для зарегистрированных пользователей отправлять оптовую стоимость продукта',
            'type' => 'select',
            'name' => 'price_select',
            'args' => [
                'selected' => 'off',
                'option' => [
                    'off' => 'Использовать розничную',
                    'on' => 'Да',
                ],
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Проверка наличия',
            'description' => 'При работе с резервами будет проходить дополнительная проверка наличия продукта на складе',
            'type' => 'select',
            'name' => 'check_stock',
            'args' => [
                'selected' => 'on',
                'option' => [
                    'on' => 'Для всех',
                    'user-only' => 'Только для пользователей',
                    'off' => 'Нет',
                ],
            ],
        ]);

        // TM API Proxy
        $this
            ->map([
                'methods' => ['get', 'post'],
                'pattern' => '/api/tm/proxy',
                'handler' => \Plugin\TradeMaster\Actions\APIProxy::class,
            ])
            ->setName('api:tm:proxy');

        // api for plugin config
        $this
            ->map([
                'methods' => ['get', 'post'],
                'pattern' => '/cup/api/tm/config',
                'handler' => \Plugin\TradeMaster\Actions\ConfigLoader::class,
            ])
            ->setName('cup:tm:config');

        // send order reserve
        $this
            ->map([
                'methods' => ['post'],
                'pattern' => '/cart/reserve',
                'handler' => \Plugin\TradeMaster\Actions\CartReserve::class,
            ])
            ->setName('common:tm:reserve');

        // send order confirm
        $this
            ->map([
                'methods' => ['post'],
                'pattern' => '/cart/confirm',
                'handler' => \Plugin\TradeMaster\Actions\CartConfirm::class
            ])
            ->setName('common:tm:confirm');

        // api external sync
        $this
            ->map([
                'methods' => ['post'],
                'pattern' => '/api/tm/sync',
                'handler' => function (Request $req, Response $res) use ($container) {
                    // add task download products
                    $task = new \Plugin\TradeMaster\Tasks\CatalogDownloadTask($container);
                    $task->execute();

                    // run worker
                    \App\Domain\AbstractTask::worker($task);

                    $res->getBody()->write('Ok');

                    return $res->withHeader('Content-Type', 'text/plain');
                },
            ])
            ->setName('api:tm:sync');

        // subscribe events
        $this
            ->subscribe(['common:catalog:order:create', 'api:catalog:order:create'], [$self, 'order_send'])
            ->subscribe(['cup:catalog:product:edit', 'task:catalog:import'], [$self, 'upload_products'])
            ->subscribe('tm:order:oplata', [$self, 'order_oplata']);
    }

    public final function order_send($order)
    {
        if (
            $this->parameter('TradeMasterPlugin_key', '') !== '' &&
            $order
        ) {
            $task = new \Plugin\TradeMaster\Tasks\SendOrderTask($this->container);
            $task->execute([
                'uuid' => $order->getUuid(),
                'idKontakt' => ($_REQUEST['idKontakt'] ?? ''),
                'numberDoc' => ($_REQUEST['numberDoc'] ?? ''),
                'numberDocStr' => ($_REQUEST['numberDocStr'] ?? ''),
                'type' => ($_REQUEST['type'] ?? ''),
                'passport' => ($_REQUEST['passport'] ?? ''),
            ]);

            // run worker
            \App\Domain\AbstractTask::worker($task);
        }
    }

    public final function upload_products()
    {
        if (
            $this->parameter('TradeMasterPlugin_key', '') !== '' &&
            $this->parameter('TradeMasterPlugin_auto_update', 'off') === 'on'
        ) {
            // add task upload products
            $task = new \Plugin\TradeMaster\Tasks\CatalogUploadTask($this->container);
            $task->execute(['only_updated' => true]);

            // run worker
            \App\Domain\AbstractTask::worker($task);
        }
    }

    public final function order_oplata($order)
    {
        if (
            $this->parameter('TradeMasterPlugin_key', '') !== '' &&
            $order
        ) {
            $this->api([
                'endpoint' => 'order/oplata',
                'params' => [
                    'nomerZakaza' => $order->getExternalId(),
                    'userID' => $this->parameter('TradeMasterPlugin_user'),
                    'checkoutCard' => $this->parameter('TradeMasterPlugin_checkout'),
                    'kontragent' => $this->parameter('TradeMasterPlugin_contractor'),
                ],
            ]);
        }
    }

    /**
     * @param array $data
     *
     * @return mixed
     */
    public function api(array $data = [], ?string $apikey = null)
    {
        $default = [
            'endpoint' => '',
            'params' => [],
            'method' => 'GET',
        ];
        $data = array_merge($default, $data);
        $data['method'] = mb_strtoupper($data['method']);

        if (!$apikey) {
            $apikey = $this->parameter('TradeMasterPlugin_key', '');
        }
        if ($apikey) {
            $pathParts = [
                $this->parameter('TradeMasterPlugin_host', 'https://api.trademaster.pro'),
                'v' . $this->parameter('TradeMasterPlugin_version', '2'),
                $data['endpoint'],
            ];

            if ($data['method'] === 'GET') {
                $data['params']['apikey'] = $apikey;
                $path = implode('/', $pathParts) . '?' . http_build_query($data['params']);

                $result = file_get_contents($path);
            } else {
                $path = implode('/', $pathParts) . '?' . http_build_query(['apikey' => $apikey]);

                $result = file_get_contents($path, false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-type: application/x-www-form-urlencoded',
                        'content' => http_build_query($data['params']),
                        'timeout' => 60,
                    ],
                ]));
            }

            #$this->logger->info('TradeMaster: API apikey', ['apikey' => $apikey]);
            #$this->logger->info('TradeMaster: API url', ['path' => $path]);
            #$this->logger->info('TradeMaster: API data', ['data' => $data['params']]);
            #$this->logger->info('TradeMaster: API result', ['output' => $result]);

            return $result ? json_decode($result, true) : [];
        }

        return [];
    }

    /**
     * Возвращает путь до удаленного файла по имени файла
     *
     * @param string $name
     *
     * @return string
     */
    public function getFilePath(string $name)
    {
        return $this->parameter('TradeMasterPlugin_cache_host') . '/tradeMasterImages/' . $this->parameter('TradeMasterPlugin_cache_folder') . '/' . trim(rawurlencode($name));
    }
}
