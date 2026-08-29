<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Actions;

use App\Domain\AbstractAction;
use Plugin\TradeMaster\TradeMasterPlugin;

class APIProxy extends AbstractAction
{
    protected function action(): \Slim\Psr7\Response
    {
        $data = array_merge(
            ['endpoint' => '', 'params' => []],
            $this->request->getQueryParams(),
            (array) ($this->request->getParsedBody() ?? []),
        );

        if (!$data['endpoint']) {
            return $this->response->withStatus(405);
        }

        /** @var TradeMasterPlugin $tm */
        $tm = $this->container->get('TradeMasterPlugin');

        return $this->respondWithJson($tm->api([
            'endpoint' => $data['endpoint'],
            'params' => (array) $data['params'],
            'method' => $this->request->getMethod() === 'POST' ? 'POST' : 'GET',
        ]));
    }
}
