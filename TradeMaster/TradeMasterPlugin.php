<?php declare(strict_types=1);

namespace Plugin\TradeMaster;

use App\Domain\AbstractPlugin;
use App\Domain\Entities\User;
use App\Domain\Service\Catalog\Exception\OrderNotFoundException;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;
use Psr\Container\ContainerInterface;
use Slim\Http\Request;
use Slim\Http\Response;

class TradeMasterPlugin extends AbstractPlugin
{
    const NAME = 'TradeMasterPlugin';
    const TITLE = 'TradeMaster';
    const DESCRIPTION = 'Плагин реализует функционал интеграции с системой торгово-складского учета.';
    const AUTHOR = 'Aleksey Ilyin';
    const AUTHOR_SITE = 'https://u4et.ru/trademaster';
    const VERSION = '4.1';

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->setTemplateFolder(__DIR__ . '/templates');
        $this->setHandledRoute(
            'common:catalog:cart',
            'cup:catalog:product:edit',
            'cup:catalog:data:import'
        );
        $this->addTwigExtension(\Plugin\TradeMaster\TradeMasterPluginTwigExt::class);
        $this->addToolbarItem(['twig' => 'trademaster.twig']);

        $this->addSettingsField([
            'label' => 'Ключ доступа к API',
            'description' => 'Введите полученный вами ключ',
            'type' => 'text',
            'name' => 'key',
        ]);

        if ($this->parameter('TradeMasterPlugin_key', '') !== '') {
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
                'description' => 'Дополнительная проверка наличия продукта на складе',
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
        }

        // api for plugin config
        $this
            ->map([
                'methods' => ['get'],
                'pattern' => '/api/trademaster/config',
                'handler' => function (Request $req, Response $res) use ($container) {
                    return $res->withJson(
                        array_merge(
                            ['scheme' => collect($this->api(['endpoint' => 'object/getScheme']))->pluck('shema', 'idShema')->all()],
                            ['storage' => collect($this->api(['endpoint' => 'object/getStorage']))->pluck('nameSklad', 'idSklad')->all()],
                            ['checkout' => collect($this->api(['endpoint' => 'object/moneyOwn']))->pluck('naimenovanie', 'idDenSred')->all()],
                            ['legal' => collect($this->api(['endpoint' => 'object/legalsOwn']))->pluck('name', 'idUrllico')->all()],
                            ['contractor' => collect($this->api(['endpoint' => 'object/legalsKontr']))->pluck('name', 'idUrllico')->all()],
                            ['user' => collect($this->api(['endpoint' => 'object/getLogin']))->pluck('login', 'id')->all()],
                        )
                    );
                },
            ])
            ->setName('api:tm:config');

        // proxy method for TM api
        $this
            ->map([
                'methods' => ['get', 'post'],
                'pattern' => '/api/trademaster/proxy',
                'handler' => function (Request $req, Response $res) use ($container) {
                    $default = [
                        'endpoint' => '',
                        'params' => [],
                    ];
                    $data = array_merge($default, $req->getParams());

                    if ($data) {
                        return $res->withJson(
                            $this->api([
                                'endpoint' => $data['endpoint'],
                                'params' => $data['params'],
                                'method' => $req->isPost() ? 'POST' : 'GET',
                            ])
                        );
                    }

                    return $res->withJson([]);
                },
            ])
            ->setName('api:tm:proxy');
    }

    public function after(Request $request, Response $response, string $routeName): Response
    {
        if ($this->parameter('TradeMasterPlugin_key', '') !== '') {
            switch ($routeName) {
                case 'common:catalog:cart':
                    if ($request->isPost()) {
                        $this->entityManager->clear();
                        $catalogOrderService = CatalogOrderService::getWithContainer($this->container);

                        try {
                            $idKontakt = $request->getParam('idKontakt', '');
                            $numberDoc = $request->getParam('numberDoc', '');
                            $numberDocStr = $request->getParam('numberDocStr', '');
                            $type = $request->getParam('type', '');
                            $passport = $request->getParam('passport', '');
                            $order = $catalogOrderService->read([
                                'user' => $request->getAttribute('user', null),
                                'external_id' => [''],
                                'order' => ['date' => 'desc'],
                                'limit' => 1,
                            ])->first();

                            if ($order) {
                                // add task send to TradeMaster
                                $task = new \Plugin\TradeMaster\Tasks\SendOrderTask($this->container);
                                $task->execute([
                                    'uuid' => $order->getUuid(),
                                    'idKontakt' => $idKontakt,
                                    'numberDoc' => $numberDoc,
                                    'numberDocStr' => $numberDocStr,
                                    'type' => $type,
                                    'passport' => $passport,
                                ]);

                                // run worker
                                \App\Domain\AbstractTask::worker($task);

                                sleep(5); // костыль

                                if (in_array($type, ['rezervTel', 'reserve']) || ($numberDoc && $numberDocStr && $idKontakt)) {
                                    $uuid = $order->getUuid();
                                    $this->entityManager->clear();
                                    $order = $catalogOrderService->read(['uuid' => $uuid]);

                                    if (!$order->getExternalId()) {
                                        $response = $response->withJson(['exception' => $order->getSystem()]);
                                        $catalogOrderService->delete($order);

                                        return $response;
                                    }
                                }
                            }
                        } catch (OrderNotFoundException $e) {
                            // nothing
                        }
                    }

                    break;

                case 'cup:catalog:product:edit':
                case 'cup:catalog:data:import':
                    if ($request->isPost() && $this->parameter('TradeMasterPlugin_auto_update', 'off') === 'on') {
                        // add task upload products
                        $task = new \Plugin\TradeMaster\Tasks\CatalogUploadTask($this->container);
                        $task->execute(['only_updated' => true]);
                    }

                    break;
            }
        }

        return $response;
    }

    /**
     * @param array $data
     *
     * @return mixed
     */
    public function api(array $data = [])
    {
        $default = [
            'endpoint' => '',
            'params' => [],
            'method' => 'GET',
        ];
        $data = array_merge($default, $data);
        $data['method'] = mb_strtoupper($data['method']);

        // $this->logger->info('TradeMaster: API access', ['endpoint' => $data['endpoint']]);
        // $this->logger->info('TradeMaster: API data', ['data' => json_encode($data['params'], JSON_UNESCAPED_UNICODE)]);

        if (($key = $this->parameter('TradeMasterPlugin_key')) !== null) {
            $pathParts = [
                $this->parameter('TradeMasterPlugin_host'),
                'v' . $this->parameter('TradeMasterPlugin_version'),
                $data['endpoint'],
            ];

            if ($data['method'] === 'GET') {
                $data['params']['apikey'] = $key;
                $path = implode('/', $pathParts) . '?' . http_build_query($data['params']);

                $result = file_get_contents($path);
            } else {
                $path = implode('/', $pathParts) . '?' . http_build_query(['apikey' => $key]);

                $result = file_get_contents($path, false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-type: application/x-www-form-urlencoded',
                        'content' => http_build_query($data['params']),
                        'timeout' => 60,
                    ],
                ]));
            }

            // $this->logger->info('TradeMaster: API result', ['output' => $result]);

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
