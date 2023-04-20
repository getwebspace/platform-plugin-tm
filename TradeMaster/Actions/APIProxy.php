<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Actions;

use App\Domain\AbstractAction;
use Plugin\TradeMaster\TradeMasterPlugin;

class APIProxy extends AbstractAction
{
    protected function action(): \Slim\Psr7\Response
    {
        $default = [
            'endpoint' => '',
            'params' => [],
        ];
        $data = array_merge($default, $this->request->getQueryParams(), (array) ($this->request->getParsedBody() ?? []));

        if ($data['endpoint']) {
            /** @var TradeMasterPlugin $tm */
            $tm = $this->container->get('TradeMasterPlugin');

            $array = $tm->api([
                'endpoint' => $data['endpoint'],
                'params' => $data['params'],
                'method' => $this->request->getMethod() === 'POST' ? 'POST' : 'GET',
            ]);

            return $this->respondWithJson((array) $array);
        }

        return $this->response->withStatus(405);
    }
}
