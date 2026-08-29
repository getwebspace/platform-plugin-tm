<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Casts\Catalog\Status;
use App\Domain\Models\CatalogProduct;
use Illuminate\Support\Collection;
use Plugin\TradeMaster\TradeMasterPlugin;

/**
 * Pushes the local catalog back into TradeMaster
 */
class CatalogUploadTask extends AbstractTask
{
    public const TITLE = 'Выгрузка каталога ТМ';

    /**
     * Products per request, and requests in flight at once
     */
    protected const PAGE_SIZE = 100;
    protected const CONCURRENCY = 4;

    /**
     * Window `only_updated` looks back over
     */
    protected const RECENT = '-5 minutes';

    protected TradeMasterPlugin $trademaster;

    public function execute(array $params = []): \App\Domain\Models\Task
    {
        $default = [
            'only_updated' => false,
        ];
        $params = array_merge($default, $params);

        return parent::execute($params);
    }

    protected function action(array $args = []): void
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');

        $query = CatalogProduct::query()
            ->where('export', 'trademaster')
            ->where('status', Status::WORK)
            ->with(['files', 'attributes'])
            ->orderBy('uuid');

        // recently touched products only, filtered by the database rather than by
        // loading the whole catalog and throwing most of it away
        if ($args['only_updated'] === true) {
            $query->where('date', '>', datetime()->modify(static::RECENT));
        }

        $count = (clone $query)->toBase()->count();

        if (!$count) {
            $this->setStatusDone();

            return;
        }

        $this->logger->info('TradeMaster: upload catalog', ['count' => $count]);

        $done = 0;
        $requests = [];

        $query->chunk(static::PAGE_SIZE, function (Collection $chunk) use (&$requests, &$done, $count): void {
            $requests[] = [
                'method' => 'POST',
                'endpoint' => 'item/updateTovarSite',
                'params' => ['tovarxml' => $this->xml($chunk)],
            ];

            if (count($requests) >= static::CONCURRENCY) {
                $this->send($requests);
                $done += static::CONCURRENCY * static::PAGE_SIZE;
                $requests = [];

                $this->setProgress($done, $count);
            }
        });

        if ($requests) {
            $this->send($requests);
        }

        $this->setProgress(100);
        $this->setStatusDone();
    }

    protected function send(array $requests): void
    {
        foreach ($this->trademaster->apiBatch($requests, null, static::CONCURRENCY) as $index => $response) {
            if ($response === null) {
                $this->logger->warning('TradeMaster: upload chunk failed', ['chunk' => $index]);

                continue;
            }

            $this->logger->info('TradeMaster: upload catalog data', ['response' => $response]);
        }
    }

    protected function xml(Collection $products): string
    {
        $output = '<Attributes>';

        /** @var CatalogProduct $product */
        foreach ($products as $product) {
            $attributes = $product->getRelationValue('attributes');
            $images = $product->files->map(fn ($file) => $file->filename())->implode(',');

            $output .= '<ProductAttribute idTovar="' . $this->escape($product->external_id) . '">';
            $output .= '<ProductAttributeValue>';
            $output .= $this->tag('name', $product->title);
            $output .= $this->tag('opisanie', $product->description);
            $output .= $this->tag('opisanieDop', $product->extra);
            $output .= $this->tag('artikul', $product->vendorcode);
            $output .= $this->tag('strihKod', $product->barcode);
            $output .= $this->tag('poryadok', $product->order);
            $output .= $this->tag('foto', $images);
            $output .= $this->tag('link', $product->address);
            $output .= $this->tag('sebestoim', $product->priceFirst);
            $output .= $this->tag('price', $product->price);
            $output .= $this->tag('opt_price', $product->priceWholesale);
            $output .= $this->tag('kolvo', $product->stock);

            for ($i = 1; $i <= 4; $i++) {
                $attribute = $attributes->firstWhere('address', "field{$i}");
                $output .= $this->tag("ind{$i}", $attribute?->value() ?? '');
            }

            $output .= $this->tag('ves', $product->weight());
            $output .= $this->tag('proizv', $product->manufacturer);
            $output .= $this->tag('strana', $product->country);
            $output .= '</ProductAttributeValue>';
            $output .= '</ProductAttribute>';
        }

        return $output . '</Attributes>';
    }

    protected function tag(string $name, mixed $value): string
    {
        return '<' . $name . '>' . $this->escape($value) . '</' . $name . '>';
    }

    /**
     * Product texts carry `&`, `<` and quotes, unescaped they break the document
     */
    protected function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
