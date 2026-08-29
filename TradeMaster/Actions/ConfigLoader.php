<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Actions;

use App\Domain\AbstractAction;
use Plugin\TradeMaster\TradeMasterPlugin;

class ConfigLoader extends AbstractAction
{
    /**
     * Настройки плагина: поле => [endpoint, значение, ключ]
     */
    protected const FIELDS = [
        'scheme' => ['object/getScheme', 'shema', 'idShema'],
        'storage' => ['object/getStorage', 'nameSklad', 'idSklad'],
        'checkout' => ['object/moneyOwn', 'naimenovanie', 'idDenSred'],
        'legal' => ['object/legalsOwn', 'name', 'idUrllico'],
        'contractor' => ['object/legalsKontr', 'name', 'idUrllico'],
        'user' => ['object/getLogin', 'login', 'id'],
    ];

    protected function action(): \Slim\Psr7\Response
    {
        $data = array_merge(
            ['key' => ''],
            (array) ($this->request->getParsedBody() ?? []),
            $this->request->getQueryParams(),
        );

        /** @var TradeMasterPlugin $tm */
        $tm = $this->container->get('TradeMasterPlugin');

        // все шесть справочников одним заходом, а не по очереди
        $responses = $tm->apiBatch(
            array_map(fn (array $field) => ['endpoint' => $field[0]], static::FIELDS),
            $data['key'],
            count(static::FIELDS),
        );

        $output = [];

        foreach (static::FIELDS as $name => [, $value, $key]) {
            $output[$name] = collect($responses[$name] ?? [])->pluck($value, $key)->all();
        }

        return $this->respondWithJson($output);
    }
}
