<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Models\CatalogOrder;
use App\Domain\Service\Catalog\Exception\OrderNotFoundException;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;
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

    protected function action(array $args = []): bool
    {
        /** @var TradeMasterPlugin $trademaster */
        $trademaster = $this->container->get('TradeMasterPlugin');
        $catalogOrderService = $this->container->get(CatalogOrderService::class);

        try {
            /** @var CatalogOrder $order */
            $order = $catalogOrderService->read(['uuid' => $args['uuid']]);
        } catch (OrderNotFoundException $e) {
            return $this->setStatusCancel('Order not found');
        }

        if ($order->external_id) {
            return $this->setStatusCancel('Order already sent');
        }

        $user = $order->user;
        $products = [];

        foreach ($order->products->where('external_id', '!=', '') as $product) {
            $products[] = [
                'id' => $product->external_id,
                'name' => $product->title,
                'quantity' => $product->totalCount(),
                'price' => $product->totalSum(),
            ];
        }

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
                'other2Kontakt' => $args['passport'] ?: ($user ? $user->getAdditional() : ''),
                'dateDost' => $order->shipping->format('Y-m-d\TH:i:s'),
                'komment' => $order->comment,
                'tovarJson' => json_encode($products, JSON_UNESCAPED_UNICODE),
                'idKontakt' => $args['idKontakt'],
                'nomDoc' => $args['numberDoc'],
                'nomerStr' => $args['numberDocStr'],
                'nalich' => 0,
                // order page address
                'so' => $this->parameter('common_homepage', '') . 'cart/done/' . $order->uuid,
            ],
        ]);

        if (!$result) {
            return $this->setStatusFail('Task cancelled');
        }

        $output = json_encode($result, JSON_UNESCAPED_UNICODE);

        if (($number = TradeMasterPlugin::orderNumber($result)) === null) {
            $catalogOrderService->update($order, ['system' => $output]);

            return $this->setStatusDone($output);
        }

        $catalogOrderService->update($order, ['external_id' => $number]);

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

        return $this->setStatusDone($output);
    }
}
