<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Service\Catalog\ProductService as CatalogProductService;
use Plugin\TradeMaster\TradeMasterPlugin;
use Illuminate\Support\Collection;

class CatalogUploadTask extends AbstractTask
{
    public const TITLE = 'Выгрузка каталога ТМ';

    public function execute(array $params = []): \App\Domain\Models\Task
    {
        $default = [
            'only_updated' => false,
        ];
        $params = array_merge($default, $params);

        return parent::execute($params);
    }

    /**
     * @var TradeMasterPlugin
     */
    protected TradeMasterPlugin $trademaster;

    /**
     * @var CatalogProductService
     */
    protected CatalogProductService $productService;

    protected function action(array $args = []): void
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->productService = $this->container->get(CatalogProductService::class);

        $products = $this->productService->read([
            'export' => 'trademaster',
            'status' => \App\Domain\Casts\Catalog\Status::WORK,
        ]);

        // получение списка недавно обновленных товаров
        if ($args['only_updated'] === true) {
            $now = datetime()->modify('-5 minutes');
            $products = $products->filter(function (\App\Domain\Models\CatalogProduct $product) use ($now) {
                return $product->date > $now;
            });

            $this->logger->info('TradeMaster: upload only updated products', ['count' => $products->count()]);
        }

        $step = 100;
        foreach ($products->chunk($step) as $index => $chunk) {
            $this->setProgress($index, $products->count() / $step);

            $xml = $this->getPruductXML($chunk);
            $response = $this->trademaster->api([
                'method' => 'POST',
                'endpoint' => 'item/updateTovarSite',
                'params' => [
                    'tovarxml' => $xml,
                ],
            ]);

            $this->logger->info('TradeMaster: upload catalog data', ['response' => $response, 'xml' => $xml]);
        }

        $this->setStatusDone();
    }

    protected function getPruductXML(Collection $products)
    {
        $output = '<Attributes>';

        /** @var \App\Domain\Models\CatalogProduct $product */
        foreach ($products as $product) {
            $images = [];
            foreach ($product->files as $file) {
                /** @var \App\Domain\Models\File $file */
                $images[] = $file->filename();
            }
            $images = implode(',', $images);

            $output .= '<ProductAttribute idTovar="' . $product->external_id . '">';
            $output .= '<ProductAttributeValue>';
            $output .= '<name>' . $product->title . '</name>';
            $output .= '<opisanie>' . $product->description . '</opisanie>';
            $output .= '<opisanieDop>' . $product->extra . '</opisanieDop>';
            $output .= '<artikul>' . $product->vendorcode . '</artikul>';
            $output .= '<strihKod>' . $product->barcode . '</strihKod>';
            $output .= '<poryadok>' . $product->order . '</poryadok>';
            $output .= '<foto>' . $images . '</foto>';
            $output .= '<link>' . $product->address . '</link>';
            $output .= '<sebestoim>' . $product->priceFirst . '</sebestoim>';
            $output .= '<price>' . $product->price . '</price>';
            $output .= '<opt_price>' . $product->priceWholesale . '</opt_price>';
            $output .= '<kolvo>' . $product->stock . '</kolvo>';

            foreach ($product->attributes()->where('address', 'like', 'field%')->getResults() as $i => $attribute) {
                $output .= '<ind' . ($i + 1) . '>' . $attribute->value() . '</ind' . ($i + 1) . '>';
            }

            $output .= '<ves>' . $product->weight() . '</ves>';
            $output .= '<proizv>' . $product->manufacturer . '</proizv>';
            $output .= '<strana>' . $product->country . '</strana>';
            $output .= '</ProductAttributeValue>';
            $output .= '</ProductAttribute>';
        }

        $output .= '</Attributes>';

        return trim($output);
    }
}
