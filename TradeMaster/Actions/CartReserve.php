<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Actions;

use App\Application\Actions\Common\Catalog\CatalogAction;
use App\Domain\AbstractTask;
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
        if (!$this->isRecaptchaChecked()) {
            return $this->response->withStatus(400, 'Google reCAPTHCA');
        }

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

        // адрес в несколько строк из корзины
        if (is_array($data['delivery']['address'] ?? null)) {
            if ($this->parameter('catalog_order_address', 'off') === 'on') {
                ksort($data['delivery']['address']);
            }

            $data['delivery']['address'] = implode(', ', $data['delivery']['address']);
        }

        $result = $this->trademaster->api([
            'method' => 'POST',
            'endpoint' => $this->endpoint($data['type'], $data['numberDoc']),
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
                'tovarJson' => json_encode($this->products($data), JSON_UNESCAPED_UNICODE),
                'idKontakt' => $data['idKontakt'],
                'nomDoc' => $data['numberDoc'],
                'nomerStr' => $data['numberDocStr'],
                'nalich' => $this->checkStock($data['user']),
                'so' => '',
            ],
        ]);

        if (!$result) {
            return $this->respondWithJson(['error' => 'Internal error']);
        }

        if (($number = TradeMasterPlugin::orderNumber($result)) === null) {
            return $this->respondWithJson(['exception' => $result]);
        }

        $order = $this->catalogOrderService->create(array_merge($data, [
            'external_id' => $number,
            'export' => 'trademaster',
        ]));

        // письмо клиенту и админу
        if (($tpl = $this->parameter('TradeMasterPlugin_mail_client_template', '')) !== '') {
            $task = new \App\Domain\Tasks\SendMailTask($this->container);
            $task->execute([
                'to' => $order->email ?: $this->parameter('mail_from', ''),
                'bcc' => $order->email ? $this->parameter('mail_from', '') : null,
                'template' => $this->render($tpl, ['order' => $order]),
                'isHtml' => true,
            ]);

            AbstractTask::worker($task);
        }

        if (
            (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest')
            && !empty($_SERVER['HTTP_REFERER'])
        ) {
            $this->response = $this->response->withHeader('Location', '/cart/done/' . $order->uuid)->withStatus(301);
        }

        return $this->respondWithJson(['redirect' => '/cart/done/' . $order->uuid]);
    }

    /**
     * Куда отправлять заказ
     */
    protected function endpoint(string $type, string $numberDoc): string
    {
        return match (true) {
            in_array($type, ['rezervTel', 'reserve'], true) => $numberDoc !== ''
                ? 'custom/addRezervTovarTblKontaktSite'
                : 'order/cart/rezervTel',
            in_array($type, ['kpTel', 'order'], true) => 'order/cart/kpTel',
            default => 'order/cart/anonym',
        };
    }

    /**
     * Список продуктов заказа
     */
    protected function products(array $data): array
    {
        $wholesale = $this->parameter('TradeMasterPlugin_price_select', 'off') === 'on' && $data['user'];
        $products = [];

        foreach ((array) $data['products'] as $uuid => $opts) {
            try {
                $count = (float) ($opts['count'] ?? 0);
                $product = $this->catalogProductService->read([
                    'uuid' => $uuid,
                    'export' => 'trademaster',
                ]);

                $products[] = [
                    'id' => $product->external_id,
                    'name' => $product->title,
                    'quantity' => $count,
                    'price' => (float) ($wholesale ? $product->priceWholesale : $product->price) * $count,
                ];
            } catch (ProductNotFoundException $e) {
                // nothing
            }
        }

        return $products;
    }

    /**
     * Проверка наличия, в зависимости от параметра
     */
    protected function checkStock(mixed $user): int
    {
        return match ($this->parameter('TradeMasterPlugin_check_stock', 'on')) {
            'off' => 0,
            'user-only' => $user ? 1 : 0,
            default => 1,
        };
    }
}
