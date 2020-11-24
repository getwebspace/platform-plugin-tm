<?php declare(strict_types=1);

namespace Plugin\TradeMaster;

use App\Domain\AbstractExtension;

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
}
