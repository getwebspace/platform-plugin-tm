<?php declare(strict_types=1);

namespace Plugin\TradeMaster;

use App\Domain\AbstractExtension;
use App\Domain\Entities\Catalog\Order;
use App\Domain\Entities\User;
use App\Domain\Service\Catalog\Exception\OrderNotFoundException;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;

class TradeMasterPluginTwigExt extends AbstractExtension
{
    public function getName()
    {
        return 'tm_plugin';
    }

    public function getFunctions()
    {
        return [
            new \Twig\TwigFunction('tm_api', [$this, 'tm_api']),
            new \Twig\TwigFunction('tm_order_external', [$this, 'tm_order_external']),
        ];
    }

    public function tm_api($endpoint, array $params = [], $method = 'GET')
    {
        $trademaster = $this->container->get('TradeMasterPlugin');

        return $trademaster->api([
            'endpoint' => $endpoint,
            'params' => $params,
            'method' => $method,
        ]);
    }

    public function tm_order_external(string $id, ?User $user = null): ?Order
    {
        $catalogOrderService = $this->container->get(CatalogOrderService::class);

        try {
            return $catalogOrderService->read([
                'external_id' => $id,
                'user' => $user,
            ]);
        } catch (OrderNotFoundException $e) {
            return null;
        }
    }
}
