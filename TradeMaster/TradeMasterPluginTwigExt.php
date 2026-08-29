<?php declare(strict_types=1);

namespace Plugin\TradeMaster;

use App\Domain\AbstractExtension;
use App\Domain\Models\CatalogOrder;
use App\Domain\Models\User;
use App\Domain\Service\Catalog\Exception\OrderNotFoundException;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;
use Illuminate\Support\Collection;

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
            new \Twig\TwigFunction('tm_ingrids', [$this, 'tm_ingrids']),
            new \Twig\TwigFunction('tm_filter', [$this, 'tm_filter']),
        ];
    }

    public function tm_api($endpoint, array $params = [], $method = 'GET'): array
    {
        /** @var TradeMasterPlugin $trademaster */
        $trademaster = $this->container->get('TradeMasterPlugin');

        return $trademaster->api([
            'endpoint' => $endpoint,
            'params' => $params,
            'method' => $method,
        ]);
    }

    public function tm_order_external(string $id, ?User $user = null): ?CatalogOrder
    {
        try {
            return $this->container->get(CatalogOrderService::class)->read([
                'external_id' => $id,
                'user' => $user,
            ]);
        } catch (OrderNotFoundException $e) {
            return null;
        }
    }

    public function tm_ingrids($list): Collection
    {
        return collect($list)
            ->map(fn ($el) => $el->attributes->where('group', 'TM: Ind5'))
            ->flatten()
            ->unique('address')
            ->sortBy('title')
            ->pluck('address', 'title');
    }

    public function tm_filter($list, $field = '', $value = ''): Collection
    {
        $value = mb_strtolower((string) $value);

        return collect($list)
            ->filter(fn ($item) => str_contains(mb_strtolower((string) data_get($item, $field)), $value))
            ->sortBy('category.order')
            ->groupBy('address');
    }
}
