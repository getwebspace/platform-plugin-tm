<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;

class SendOrderTask extends AbstractTask
{
    public const TITLE = 'Отправка заказа в ТМ';

    public function execute(array $params = []): \App\Domain\Entities\Task
    {
        $default = [
            'item' => '',
        ];
        $params = array_merge($default, $params);

        return parent::execute($params);
    }

    /**
     * @var \Plugin\TradeMaster\TradeMasterPlugin
     */
    protected $trademaster;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    protected $productRepository;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    protected $orderRepository;

    protected function action(array $args = [])
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $catalogOrderService = CatalogOrderService::getWithContainer($this->container);
        $order = $catalogOrderService->read(['uuid' => $args['uuid']]);

        if ($order) {
            if ($order->getExternalId()) {
                return $this->setStatusCancel();
            }

            $productService = \App\Domain\Service\Catalog\ProductService::getWithContainer($this->container);
            $products = [];

            /** @var \App\Domain\Entities\Catalog\Product $model */
            foreach ($productService->read(['uuid' => array_keys($order->getList())]) as $model) {
                if ($model->getExternalId()) {
                    $quantity = $order->getList()[$model->getUuid()->toString()];
                    $products[] = [
                        'id' => $model->getExternalId(),
                        'name' => $model->getTitle(),
                        'quantity' => $quantity,
                        'price' => (float) $model->getPrice() * $quantity,
                    ];
                }
            }

            $result = $this->trademaster->api([
                'method' => 'POST',
                'endpoint' => 'order/cart/anonym',
                'params' => [
                    'sklad' => $this->parameter('TradeMasterPlugin_storage'),
                    'urlico' => $this->parameter('TradeMasterPlugin_legal'),
                    'ds' => $this->parameter('TradeMasterPlugin_checkout'),
                    'kontragent' => $this->parameter('TradeMasterPlugin_contractor'),
                    'shema' => $this->parameter('TradeMasterPlugin_scheme'),
                    'valuta' => $this->parameter('TradeMasterPlugin_currency'),
                    'userID' => $this->parameter('TradeMasterPlugin_user'),
                    'nameKontakt' => $order->getDelivery()['client'] ?? '',
                    'adresKontakt' => $order->getDelivery()['address'] ?? '',
                    'telefonKontakt' => $order->getPhone(),
                    'other1Kontakt' => $order->getEmail(),
                    'dateDost' => $order->getShipping()->format('Y-m-d H:i:s'),
                    'komment' => $order->getComment(),
                    'tovarJson' => json_encode($products, JSON_UNESCAPED_UNICODE),
                ],
            ]);

            if ($result && !empty($result['nomerZakaza'])) {
                $catalogOrderService->update($order, ['external_id' => $result['nomerZakaza']]);
                $products = $productService->read(['uuid' => array_keys($order->getList())]);

                // письмо клиенту
                if (
                    $order->getEmail() &&
                    ($tpl = $this->parameter('TradeMasterPlugin_mail_client_template', '')) !== ''
                ) {
                    // add task send client mail
                    $task = new \App\Domain\Tasks\SendMailTask($this->container);
                    $task->execute([
                        'to' => $order->getEmail(),
                        'body' => $this->render($tpl, ['order' => $order, 'products' => $products]),
                        'isHtml' => true,
                    ]);
                }

                return $this->setStatusDone();
            }
        }

        return $this->setStatusFail();
    }
}
