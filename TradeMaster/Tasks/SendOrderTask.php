<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;
use App\Domain\Service\Catalog\Exception\OrderNotFoundException;
use App\Domain\Service\User\Exception\UserNotFoundException;
use App\Domain\Service\User\UserService;

class SendOrderTask extends AbstractTask
{
    public const TITLE = 'Отправка заказа в ТМ';

    public function execute(array $params = []): \App\Domain\Entities\Task
    {
        $default = [
            'uuid' => '',
            'idKontakt' => '',
            'numberDoc' => '',
            'numberDocStr' => '',
            'type' => '',
            'passport' => '',
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

        try {
            $order = $catalogOrderService->read(['uuid' => $args['uuid']]);

            if ($order) {
                if ($order->getExternalId()) {
                    return $this->setStatusCancel();
                }

                // получение пользователя
                $user = $order->getUser();

                $productService = \App\Domain\Service\Catalog\ProductService::getWithContainer($this->container);
                $products = [];

                /** @var \App\Domain\Entities\Catalog\Product $model */
                foreach ($productService->read(['uuid' => array_keys($order->getList())]) as $model) {
                    if ($model->getExternalId()) {
                        $quantity = $order->getList()[$model->getUuid()->toString()];
                        $price = $model->getPrice();

                        if ($this->parameter('TradeMasterPlugin_price_select', 'off') === 'on' && $user) {
                            $price = $model->getPriceWholesale();
                        }

                        $products[] = [
                            'id' => $model->getExternalId(),
                            'name' => $model->getTitle(),
                            'quantity' => $quantity,
                            'price' => (float) $price * $quantity,
                        ];
                    }
                }

                // выбор куда отправлять заказ
                switch (true) {
                    case in_array($args['type'], ['rezervTel', 'reserve'], true):
                    {
                        $endpoint = 'order/cart/rezervTel';

                        if (!empty($args['numberDoc'])) {
                            $endpoint = 'custom/addRezervTovarTblKontaktSite';
                        }
                        break;
                    }
                    case in_array($args['type'], ['kpTel', 'order'], true):
                    {
                        $endpoint = 'order/cart/kpTel';
                        break;
                    }
                    default:
                    {
                        $endpoint = 'order/cart/anonym';
                    }
                }

                $result = $this->trademaster->api([
                    'method' => 'POST',
                    'endpoint' => $endpoint,
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
                        'other2Kontakt' => !empty($args['passport']) ? $args['passport'] : ($user ? $user->getAdditional() : ''),
                        'dateDost' => $order->getShipping()->format('Y-m-d H:i:s'),
                        'komment' => $order->getComment(),
                        'tovarJson' => json_encode($products, JSON_UNESCAPED_UNICODE),
                        'idKontakt' => $args['idKontakt'],
                        'nomDoc' => $args['numberDoc'],
                        'nomerStr' => $args['numberDocStr'],
                        'nalich' => $this->parameter('TradeMasterPlugin_check_stock', 'on') === 'on' ? 1 : 0,
                    ],
                ]);

                if ($result) {
                    if (
                        (is_array($result) && count($result) === 1 && !empty($result[0]['nomerZakaza'])) ||
                        !empty($result['nomerZakaza'])
                    ) {
                        if (is_array($result) && isset($result[0])) {
                            $result = $result[0]; // fix array
                        }
                        if ($result['nomerZakaza'] != '-1') {
                            $catalogOrderService->update($order, ['external_id' => $result['nomerZakaza']]);
                            $products = $productService->read(['uuid' => array_keys($order->getList())]);

                            // письмо клиенту и админу
                            if (
                                $order->getEmail() &&
                                ($tpl = $this->parameter('TradeMasterPlugin_mail_client_template', '')) !== ''
                            ) {
                                // add task send client mail
                                $task = new \App\Domain\Tasks\SendMailTask($this->container);
                                $task->execute([
                                    'to' => $order->getEmail(),
                                    'bcc' => $this->parameter('smtp_from', ''),
                                    'body' => $this->render($tpl, ['order' => $order, 'products' => $products]),
                                    'isHtml' => true,
                                ]);

                                // run worker
                                \App\Domain\AbstractTask::worker($task);
                            }
                        } else {
                            $catalogOrderService->update($order, [
                                'system' => is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : $result,
                            ]);
                        }
                    } else {
                        $catalogOrderService->update($order, [
                            'system' => is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : $result,
                        ]);
                    }

                    return $this->setStatusDone(is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : $result);
                }
            }
        } catch (OrderNotFoundException $e) {
            return $this->setStatusCancel('Order not found');
        }

        return $this->setStatusFail('Task cancelled');
    }
}
