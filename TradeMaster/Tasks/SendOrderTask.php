<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;
use App\Domain\Service\Catalog\Exception\OrderNotFoundException;
use App\Domain\Service\User\Exception\UserNotFoundException;
use App\Domain\Service\User\UserService;
use DateTimeZone;

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

                $products = [];

                // готовим список товаров
                foreach ($order->getProducts()->where('external_id', '!=', '') as $product) {
                    $price = $product->getPrice();

                    if ($this->parameter('TradeMasterPlugin_price_select', 'off') === 'on' && $user) {
                        $price = $product->getPriceWholesale();
                    }

                    $products[] = [
                        'id' => $product->getExternalId(),
                        'name' => $product->getTitle(),
                        'quantity' => $product->getCount(),
                        'price' => (float) $price * $product->getCount(),
                    ];
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

                // проверка наличия, в зависимости от параметра
                switch ($this->parameter('TradeMasterPlugin_check_stock', 'on')){
                    default:
                    case 'on': $nalich = 1; break;
                    case 'user-only': $nalich = ($user ? 1 : 0); break;
                    case 'off': $nalich = 0; break;
                }

                // адрес страницы заказа
                $so = $this->parameter('common_homepage', '') . 'cart/done/' . $order->getUuid()->toString();

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
                        'dateDost' => $order
                            ->getShipping()
                            ->setTimezone(new DateTimeZone($this->parameter('common_timezone', 'UTC'))),
                        'komment' => $order->getComment(),
                        'tovarJson' => json_encode($products, JSON_UNESCAPED_UNICODE),
                        'idKontakt' => $args['idKontakt'],
                        'nomDoc' => $args['numberDoc'],
                        'nomerStr' => $args['numberDocStr'],
                        'nalich' => $nalich,
                        'so' => $so,
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

                            // письмо клиенту и админу
                            if (($tpl = $this->parameter('TradeMasterPlugin_mail_client_template', '')) !== '') {
                                // add task send client mail
                                $task = new \App\Domain\Tasks\SendMailTask($this->container);
                                $task->execute([
                                    'to' => $order->getEmail() ? $order->getEmail() : $this->parameter('smtp_from', ''),
                                    'bcc' => $order->getEmail() ? $this->parameter('smtp_from', '') : null,
                                    'body' => $this->render($tpl, ['order' => $order]),
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
