<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Entities\Catalog\Order;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;
use App\Domain\Service\Catalog\Exception\OrderNotFoundException;
use App\Domain\Service\User\Exception\UserNotFoundException;
use App\Domain\Service\User\UserService;
use DateTimeZone;
use Plugin\TradeMaster\TradeMasterPlugin;

class SendOrderTask extends AbstractTask
{
    public const TITLE = 'Отправка заказа в ТМ';

    public function execute(array $params = []): \App\Domain\Models\Task
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

    protected function action(array $args = [])
    {
        /** @var TradeMasterPlugin $trademaster */
        $trademaster = $this->container->get('TradeMasterPlugin');
        $catalogOrderService = $this->container->get(CatalogOrderService::class);

        try {
            /** @var Order $order */
            $order = $catalogOrderService->read(['uuid' => $args['uuid']]);

            if ($order) {
                if ($order->external_id) {
                    return $this->setStatusCancel();
                }

                // получение пользователя
                $user = $order->user;

                $products = [];

                // готовим список товаров
                foreach ($order->products->where('external_id', '!=', '') as $product) {
                    $products[] = [
                        'id' => $product->external_id,
                        'name' => $product->title,
                        'quantity' => $product->totalCount(),
                        'price' => $product->totalSum(),
                    ];
                }

                // адрес страницы заказа
                $so = $this->parameter('common_homepage', '') . 'cart/done/' . $order->uuid;

                $result = $trademaster->api([
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
                        'nameKontakt' => $order->delivery['client'] ?? '',
                        'adresKontakt' => $order->delivery['address'] ?? '',
                        'telefonKontakt' => $order->phone,
                        'other1Kontakt' => $order->email,
                        'other2Kontakt' => !empty($args['passport']) ? $args['passport'] : ($user ? $user->getAdditional() : ''),
                        'dateDost' => $order->shipping->format('Y-m-d\TH:i:s'),
                        'komment' => $order->comment,
                        'tovarJson' => json_encode($products, JSON_UNESCAPED_UNICODE),
                        'idKontakt' => $args['idKontakt'],
                        'nomDoc' => $args['numberDoc'],
                        'nomerStr' => $args['numberDocStr'],
                        'nalich' => 0,
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
                                    'to' => $order->email ? $order->email : $this->parameter('mail_from', ''),
                                    'bcc' => $order->email ? $this->parameter('mail_from', '') : null,
                                    'template' => $this->render($tpl, ['order' => $order]),
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
