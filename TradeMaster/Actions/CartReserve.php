<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Actions;

use App\Application\Actions\Common\Catalog\CatalogAction;
use App\Domain\Service\Catalog\Exception\ProductNotFoundException;
use Plugin\TradeMaster\TradeMasterPlugin;
use Psr\Container\ContainerInterface;

class CartReserve extends CatalogAction
{
    protected TradeMasterPlugin $trademaster;

    /**
     * {@inheritdoc}
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->trademaster = $this->container->get('TradeMasterPlugin');
    }

    protected function action(): \Slim\Psr7\Response
    {
        $data = [
            'user' => $this->request->getAttribute('user', null),

            'delivery' => $this->getParam('delivery', []),
            'phone' => $this->getParam('phone'),
            'email' => $this->getParam('email'),
            'comment' => $this->getParam('comment', ''),
            'shipping' => $this->getParam('shipping'),
            'products' => $this->getParam('products', []),
            'system' => $this->getParam('system', ''),

            'type' => $this->getParam('type', ''),
            'idKontakt' => $this->getParam('idKontakt', ''),
            'idDenSred' => $this->getParam('idDenSred', $this->parameter('TradeMasterPlugin_checkout')),
            'idKontragent' => $this->getParam('idKontragent', $this->parameter('TradeMasterPlugin_contractor')),
            'passport' => $this->getParam('passport', ''),
            'numberDoc' => $this->getParam('numberDoc', ''),
            'numberDocStr' => $this->getParam('numberDocStr', ''),
        ];

        if ($this->isRecaptchaChecked()) {
            // адрес в несколько строк из корзины
            if (is_array($data['delivery']['address'])) {
                if ($this->parameter('catalog_order_address', 'off') === 'on') {
                    ksort($data['delivery']['address']);
                }
                $data['delivery']['address'] = implode(', ', $data['delivery']['address']);
            }

            // выбор куда отправлять заказ
            switch (true) {
                case in_array($data['type'], ['rezervTel', 'reserve'], true):
                {
                    $endpoint = 'order/cart/rezervTel';

                    if (!empty($data['numberDoc'])) {
                        $endpoint = 'custom/addRezervTovarTblKontaktSite';
                    }
                    break;
                }
                case in_array($data['type'], ['kpTel', 'order'], true):
                {
                    $endpoint = 'order/cart/kpTel';
                    break;
                }
                default:
                {
                    $endpoint = 'order/cart/anonym';
                }
            }

            $products = [];

            // список продуктов
            foreach ($data['products'] as $uuid => $opts) {
                try {
                    $count = (float) ($opts['count'] ?? 0);
                    $product = $this->catalogProductService->read([
                        'uuid' => $uuid,
                        'export' => 'trademaster',
                    ]);

                    $price = $product->getPrice();

                    if ($this->parameter('TradeMasterPlugin_price_select', 'off') === 'on' && $data['user']) {
                        $price = $product->getPriceWholesale();
                    }

                    $products[] = [
                        'id' => $product->getExternalId(),
                        'name' => $product->getTitle(),
                        'quantity' => $count,
                        'price' => (float) $price * $count,
                    ];
                } catch (ProductNotFoundException $e) {
                    continue;
                }
            }

            // проверка наличия, в зависимости от параметра
            switch ($this->parameter('TradeMasterPlugin_check_stock', 'on')) {
                default:
                case 'on':
                    $nalich = 1;
                    break;
                case 'user-only':
                    $nalich = ($data['user'] ? 1 : 0);
                    break;
                case 'off':
                    $nalich = 0;
                    break;
            }

            $result = $this->trademaster->api([
                'method' => 'POST',
                'endpoint' => $endpoint,
                'params' => [
                    'sklad' => $this->parameter('TradeMasterPlugin_storage'),
                    'urlico' => $this->parameter('TradeMasterPlugin_legal'),
                    'ds' => $data['idDenSred'],
                    'kontragent' => $data['idKontragent'],
                    'shema' => $this->parameter('TradeMasterPlugin_scheme'),
                    'valuta' => $this->parameter('TradeMasterPlugin_currency'),
                    'userID' => $this->parameter('TradeMasterPlugin_user'),
                    'nameKontakt' => $data['delivery']['client'] ?? '',
                    'adresKontakt' => $data['delivery']['address'] ?? '',
                    'telefonKontakt' => $data['phone'],
                    'other1Kontakt' => $data['email'],
                    'other2Kontakt' => $data['passport'] ?: ($data['user'] ? $data['user']->getAdditional() : ''),
                    'dateDost' => $data['shipping'],
                    'komment' => $data['comment'],
                    'tovarJson' => json_encode($products, JSON_UNESCAPED_UNICODE),
                    'idKontakt' => $data['idKontakt'],
                    'nomDoc' => $data['numberDoc'],
                    'nomerStr' => $data['numberDocStr'],
                    'nalich' => $nalich,
                    'so' => '',
                ],
            ]);

            if ($result) {
                if (
                    (
                        is_array($result) && count($result) === 1 && !empty($result[0]['nomerZakaza'])
                    ) || !empty($result['nomerZakaza'])
                ) {
                    if (is_array($result) && isset($result[0])) {
                        $result = $result[0]; // fix array
                    }
                    if ($result['nomerZakaza'] != '-1') {
                        $order = $this->catalogOrderService->create(array_merge($data, [
                            'external_id' => $result['nomerZakaza'],
                            'export' => 'trademaster',
                        ]));

                        // notify to admin and user
                        if ($this->parameter('notification_is_enabled', 'yes') === 'yes') {
                            $this->notificationService->create([
                                'title' => __('Добавлен заказ') . ': ' . $order->getSerial(),
                                'params' => [
                                    'order_uuid' => $order->getUuid(),
                                ],
                            ]);

                            if ($data['user']) {
                                $this->notificationService->create([
                                    'user_uuid' => $data['user']->getUuid(),
                                    'title' => __('Добавлен заказ') . ': ' . $order->getSerial(),
                                    'params' => [
                                        'order_uuid' => $order->getUuid(),
                                    ],
                                ]);
                            }
                        }

                        // письмо клиенту и админу
                        if (($tpl = $this->parameter('TradeMasterPlugin_mail_client_template', '')) !== '') {
                            // add task send client mail
                            $task = new \App\Domain\Tasks\SendMailTask($this->container);
                            $task->execute([
                                'to' => $order->getEmail() ?: $this->parameter('mail_from', ''),
                                'bcc' => $order->getEmail() ? $this->parameter('mail_from', '') : null,
                                'template' => $this->render($tpl, ['order' => $order]),
                                'isHtml' => true,
                            ]);

                            // run worker
                            \App\Domain\AbstractTask::worker($task);
                        }

                        if (
                            (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') && !empty($_SERVER['HTTP_REFERER'])
                        ) {
                            $this->response = $this->response->withHeader('Location', '/cart/done/' . $order->getUuid())->withStatus(301);
                        }

                        return $this->respondWithJson(['redirect' => '/cart/done/' . $order->getUuid()]);
                    }
                }

                return $this->respondWithJson(['exception' => $result]);
            }

            return $this->respondWithJson(['error' => 'Internal error']);
        }

        return $this->response->withStatus(400, 'Google reCAPTHCA');
    }
}
