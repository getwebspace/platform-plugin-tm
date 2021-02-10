<?php declare(strict_types=1);

namespace Plugin\TradeMaster;

use App\Domain\AbstractExtension;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;
use App\Domain\Service\Catalog\ProductService as CatalogProductService;

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
            new \Twig\TwigFunction('tm_order_items_external', [$this, 'tm_order_items_external']),
        ];
    }

    public function tm_api($endpoint, array $params = [], $method = 'GET')
    {
        \RunTracy\Helpers\Profiler\Profiler::start('twig:fn:tm_api');

        $trademaster = $this->container->get('TradeMasterPlugin');
        $result = $trademaster->api([
            'endpoint' => $endpoint,
            'params' => $params,
            'method' => $method,
        ]);

        \RunTracy\Helpers\Profiler\Profiler::finish('twig:fn:tm_api (%s)', $endpoint, ['endpoint' => $endpoint, 'params' => $params, 'method' => $method]);

        return $result;
    }

    public function tm_order_external(string $id)
    {
        \RunTracy\Helpers\Profiler\Profiler::start('twig:fn:order_by_external');

        $catalogOrderService = CatalogOrderService::getWithContainer($this->container);
        $result = $catalogOrderService->read([
            'external_id' => [$id],
            'order' => [
                'date' => 'desc',
            ],
            'limit' => 1,
        ])->first();

        \RunTracy\Helpers\Profiler\Profiler::finish('twig:fn:order_by_external (%s)');

        return $result;
    }

    public function tm_order_items_external(string $id)
    {
        \RunTracy\Helpers\Profiler\Profiler::start('twig:fn:order_items_by_external');

        $catalogOrderService = CatalogOrderService::getWithContainer($this->container);
        $catalogProductService = CatalogProductService::getWithContainer($this->container);
        $result = collect();

        foreach ($catalogOrderService->read(['external_id' => [$id]])->pluck('list') as $list) {
            foreach ($list as $uuid => $count) {
                if ($result->has($uuid)) {
                    $result[$uuid] += $count;
                } else {
                    $result[$uuid] = +$count;
                }
            }
        }
        $products = $catalogProductService->read(['uuid' => $result->keys()->all()]);
        $result = $result->map(fn ($item, $uuid) => ['count' => $item, 'product' => $products->firstWhere('uuid', $uuid)]);

        \RunTracy\Helpers\Profiler\Profiler::finish('twig:fn:order_items_by_external (%s)');

        return $result->values();
    }
}
