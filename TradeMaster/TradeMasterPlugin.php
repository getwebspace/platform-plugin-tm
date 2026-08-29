<?php declare(strict_types=1);

namespace Plugin\TradeMaster;

use App\Domain\AbstractPlugin;
use App\Domain\AbstractTask;
use App\Domain\Models\CatalogOrder;
use Plugin\TradeMaster\Tasks\CatalogDownloadTask;
use Plugin\TradeMaster\Tasks\CatalogUploadTask;
use Plugin\TradeMaster\Tasks\SendOrderTask;
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
    const VERSION = '9.0.0';

    /**
     * Seconds to wait for a connection and for a whole API response
     */
    protected const CONNECT_TIMEOUT = 10;
    protected const TIMEOUT = 120;

    /**
     * Repeats of a request that failed on the transport level or with a 5xx/429
     */
    protected const RETRY = 3;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->setTemplateFolder(__DIR__ . '/templates');
        $this->addToolbarItem(['twig' => 'trademaster.twig']);
        $this->addTwigExtension(TradeMasterPluginTwigExt::class);

        // saved config from API, fills the selects below
        $config = json_decode($this->parameter('TradeMasterPlugin_config', '[]'), true) ?: [];

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
            'args' => ['value' => 'https://api.trademaster.pro', 'readonly' => true],
        ]);
        $this->addSettingsField([
            'label' => 'Версия API',
            'type' => 'text',
            'name' => 'version',
            'args' => ['value' => '2', 'readonly' => true],
        ]);
        $this->addSettingsField([
            'label' => 'Валюта API',
            'type' => 'text',
            'name' => 'currency',
            'args' => ['value' => 'RUB'],
        ]);
        $this->addSettingsField([
            'label' => 'Хост кеш файлов',
            'type' => 'text',
            'name' => 'cache_host',
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

        foreach ([
            'storage' => 'Склад',
            'legal' => 'Юр. Лицо',
            'checkout' => 'Счет',
            'contractor' => 'Контрактор',
            'scheme' => 'Схема',
            'user' => 'Пользователь ID',
        ] as $name => $label) {
            $this->addSettingsField([
                'label' => $label,
                'type' => 'select',
                'name' => $name,
                'args' => ['option' => $config[$name] ?? []],
            ]);
        }

        $this->addSettingsField([
            'label' => 'Структура БД',
            'type' => 'select',
            'name' => 'struct',
            'args' => [
                'selected' => 'off',
                'option' => ['0' => 'Простая', '1' => 'Сложная'],
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
                'option' => ['off' => 'Нет', 'on' => 'Да'],
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Обновлять поисковый индекс',
            'description' => 'Включить чтобы обновить индекс после синхронизации',
            'type' => 'select',
            'name' => 'search',
            'args' => [
                'selected' => 'off',
                'option' => ['off' => 'Выключена', 'on' => 'Включена'],
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Шаблон письма клиенту',
            'description' => 'Если значения нет, письмо не будет отправляться',
            'type' => 'text',
            'name' => 'mail_client_template',
            'args' => ['placeholder' => 'catalog.mail.client.twig'],
        ]);
        $this->addSettingsField([
            'label' => 'Шаблон письма заказа',
            'description' => 'Если значения нет, письмо не будет отправляться',
            'type' => 'text',
            'name' => 'mail_order_template',
            'args' => ['placeholder' => 'catalog.mail.order.twig'],
        ]);
        $this->addSettingsField([
            'label' => 'Оптовая стоимость',
            'description' => 'Для зарегистрированных пользователей отправлять оптовую стоимость продукта',
            'type' => 'select',
            'name' => 'price_select',
            'args' => [
                'selected' => 'off',
                'option' => ['off' => 'Использовать розничную', 'on' => 'Да'],
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

        // TM API proxy
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
                'handler' => \Plugin\TradeMaster\Actions\CartConfirm::class,
            ])
            ->setName('common:tm:confirm');

        // api external sync
        $this
            ->map([
                'methods' => ['post'],
                'pattern' => '/api/tm/sync',
                'handler' => function (Request $req, Response $res) use ($container) {
                    $task = new CatalogDownloadTask($container);
                    $task->execute();
                    AbstractTask::worker($task);

                    $res->getBody()->write('Ok');

                    return $res->withHeader('Content-Type', 'text/plain');
                },
            ])
            ->setName('api:tm:sync');

        // subscribe events
        $this
            ->subscribe(['common:catalog:order:create', 'api:catalog:order:create'], [$this, 'order_send'])
            ->subscribe(['cup:catalog:product:edit', 'task:catalog:import'], [$this, 'upload_products'])
            ->subscribe(['plugin:order:payment', 'tm:order:oplata'], [$this, 'order_oplata']);
    }

    public final function order_send(CatalogOrder $order): void
    {
        if ($this->isConfigured() && $order) {
            $task = new SendOrderTask($this->container);
            $task->execute([
                'uuid' => $order->uuid,
                'idKontakt' => ($_REQUEST['idKontakt'] ?? ''),
                'numberDoc' => ($_REQUEST['numberDoc'] ?? ''),
                'numberDocStr' => ($_REQUEST['numberDocStr'] ?? ''),
                'type' => ($_REQUEST['type'] ?? ''),
                'passport' => ($_REQUEST['passport'] ?? ''),
            ]);

            AbstractTask::worker($task);
        }
    }

    public final function upload_products(): void
    {
        if ($this->isConfigured() && $this->parameter('TradeMasterPlugin_auto_update', 'off') === 'on') {
            $task = new CatalogUploadTask($this->container);
            $task->execute(['only_updated' => true]);

            AbstractTask::worker($task);
        }
    }

    public final function order_oplata(CatalogOrder $order): void
    {
        if ($this->isConfigured() && $order) {
            $this->api([
                'endpoint' => 'order/oplata',
                'params' => [
                    'nomerZakaza' => $order->external_id,
                    'userID' => $this->parameter('TradeMasterPlugin_user'),
                    'checkoutCard' => $this->parameter('TradeMasterPlugin_checkout'),
                    'kontragent' => $this->parameter('TradeMasterPlugin_contractor'),
                ],
            ]);
        }
    }

    public function isConfigured(): bool
    {
        return $this->parameter('TradeMasterPlugin_key', '') !== '';
    }

    /**
     * Perform a single API call
     *
     * @return array decoded response, empty when the call could not be made
     */
    public function api(array $data = [], ?string $apikey = null): array
    {
        return (array) ($this->apiBatch([$data], $apikey)[0] ?? []);
    }

    /**
     * Perform several API calls at once
     *
     * The catalog is paginated, and one page is a round trip of its own - waiting
     * for them one by one is what used to make a full sync take hours. Requests
     * are sent together and the results come back in the order they were given
     *
     * @param array $requests list of `api()` argument arrays
     *
     * @return array decoded response per request, indexed like $requests, `null`
     *               where the call kept failing - a caller about to delete
     *               something must tell that apart from an honest empty answer
     */
    public function apiBatch(array $requests, ?string $apikey = null, int $concurrency = 4): array
    {
        $apikey = $apikey ?: $this->parameter('TradeMasterPlugin_key', '');

        if (!$apikey || !$requests) {
            return [];
        }

        $output = [];
        $pending = [];

        foreach ($requests as $index => $request) {
            $pending[$index] = ['request' => $request, 'attempt' => 0];
            $output[$index] = null;
        }

        while ($pending) {
            $retry = [];

            foreach (array_chunk($pending, max(1, $concurrency), true) as $chunk) {
                foreach ($this->send($chunk, $apikey) as $index => $result) {
                    if ($result === null) {
                        $pending[$index]['attempt']++;

                        if ($pending[$index]['attempt'] < static::RETRY) {
                            $retry[$index] = $pending[$index];

                            continue;
                        }

                        $this->logger->warning('TradeMaster: API request failed', [
                            'endpoint' => $pending[$index]['request']['endpoint'] ?? '',
                        ]);

                        continue;
                    }

                    $output[$index] = $result;
                }
            }

            $pending = $retry;

            if ($pending) {
                // let a rate limited or briefly unavailable API breathe
                sleep(1);
            }
        }

        return $output;
    }

    /**
     * Run one window of requests through curl_multi
     *
     * @return array decoded response per index, null for the ones worth retrying
     */
    private function send(array $chunk, string $apikey): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($chunk as $index => $item) {
            $handle = $this->handle($item['request'], $apikey);
            $handles[$index] = $handle;
            curl_multi_add_handle($multi, $handle);
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $output = [];

        foreach ($handles as $index => $handle) {
            $body = curl_multi_getcontent($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            curl_multi_remove_handle($multi, $handle);

            // transport error, throttling or a server side hiccup - worth another try
            if ($body === false || $body === null || $code === 0 || $code === 429 || $code >= 500) {
                $output[$index] = null;

                continue;
            }

            $decoded = json_decode((string) $body, true);
            $output[$index] = is_array($decoded) ? $decoded : [];
        }

        curl_multi_close($multi);

        return $output;
    }

    /**
     * Build a configured curl handle for one API call
     *
     * @return \CurlHandle
     */
    private function handle(array $data, string $apikey)
    {
        $data = array_merge(['endpoint' => '', 'params' => [], 'method' => 'GET'], $data);

        $url = implode('/', [
            rtrim($this->parameter('TradeMasterPlugin_host', 'https://api.trademaster.pro'), '/'),
            'v' . $this->parameter('TradeMasterPlugin_version', '2'),
            ltrim((string) $data['endpoint'], '/'),
        ]);

        $handle = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '', // accept gzip, catalog pages compress well
            CURLOPT_CONNECTTIMEOUT => static::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => static::TIMEOUT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];

        if (mb_strtoupper((string) $data['method']) === 'POST') {
            $options[CURLOPT_URL] = $url . '?' . http_build_query(['apikey' => $apikey]);
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query((array) $data['params']);
        } else {
            $options[CURLOPT_URL] = $url . '?' . http_build_query(((array) $data['params']) + ['apikey' => $apikey]);
        }

        curl_setopt_array($handle, $options);

        return $handle;
    }

    /**
     * Order number out of an API answer
     *
     * The endpoints answer either with the order object or with a single element
     * list holding it, and report a refusal as the number `-1`
     */
    public static function orderNumber(mixed $result): ?string
    {
        if (!is_array($result)) {
            return null;
        }

        if (count($result) === 1 && isset($result[0]) && is_array($result[0])) {
            $result = $result[0];
        }

        $number = (string) ($result['nomerZakaza'] ?? '');

        return $number !== '' && $number !== '-1' ? $number : null;
    }

    /**
     * Returns url of a remote file by its name
     */
    public function getFilePath(string $name): string
    {
        return $this->parameter('TradeMasterPlugin_cache_host') . '/' . $this->parameter('TradeMasterPlugin_cache_folder') . '/' . trim(rawurlencode($name));
    }
}
