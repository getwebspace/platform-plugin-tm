<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Actions;

use App\Domain\AbstractAction;
use Plugin\TradeMaster\TradeMasterPlugin;

class ConfigLoader extends AbstractAction
{
    protected function action(): \Slim\Psr7\Response
    {
        $default = [
            'key' => ''
        ];
        $data = array_merge($default, ($this->request->getParsedBody() ?? []), ($this->request->getQueryParams() ?? []));

        /** @var TradeMasterPlugin $tm */
        $tm = $this->container->get('TradeMasterPlugin');

        $array = array_merge(
            ['scheme' => collect($tm->api(['endpoint' => 'object/getScheme'], $data['key']))->pluck('shema', 'idShema')->all()],
            ['storage' => collect($tm->api(['endpoint' => 'object/getStorage'], $data['key']))->pluck('nameSklad', 'idSklad')->all()],
            ['checkout' => collect($tm->api(['endpoint' => 'object/moneyOwn'], $data['key']))->pluck('naimenovanie', 'idDenSred')->all()],
            ['legal' => collect($tm->api(['endpoint' => 'object/legalsOwn'], $data['key']))->pluck('name', 'idUrllico')->all()],
            ['contractor' => collect($tm->api(['endpoint' => 'object/legalsKontr'], $data['key']))->pluck('name', 'idUrllico')->all()],
            ['user' => collect($tm->api(['endpoint' => 'object/getLogin'], $data['key']))->pluck('login', 'id')->all()],
        );

        return $this->respondWithJson($array);
    }
}
