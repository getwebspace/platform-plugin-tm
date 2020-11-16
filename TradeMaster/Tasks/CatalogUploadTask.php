<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Service\Catalog\ProductService;
use Plugin\TradeMaster\TradeMasterPlugin;
use Illuminate\Support\Collection;

class CatalogUploadTask extends AbstractTask
{
    public const TITLE = 'Выгрузка каталога ТМ';

    public function execute(array $params = []): \App\Domain\Entities\Task
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
     * @var ProductService
     */
    protected ProductService $productService;

    /**
     * @throws \RunTracy\Helpers\Profiler\Exception\ProfilerException
     */
    protected function action(array $args = []): void
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->productService = ProductService::getWithContainer($this->container);

        $products = $this->productService->read([
            'export' => 'trademaster',
            'status' => \App\Domain\Types\Catalog\ProductStatusType::STATUS_WORK,
        ]);

        // получение списка недавно обновленных товаров
        if ($args['only_updated'] === true) {
            $buf = collect();
            $now = (new \DateTime('now'))->modify('-5 minutes');

            /** @var \App\Domain\Entities\Catalog\Product $product */
            foreach ($products as $product) {
                if ($product->getDate() > $now) {
                    $buf[] = $product;
                }
            }

            $products = $buf;
            $this->logger->info('TradeMaster: upload only updated products', ['count' => $products->count()]);
        }

        $step = 100;
        foreach ($products->chunk($step) as $index => $chunk) {
            $this->setProgress($index, $products->count() / $step);
            $response = $this->trademaster->api([
                'method' => 'POST',
                'endpoint' => 'item/updateTovarSite',
                'params' => [
                    'tovarxml' => $this->getPruductXML($chunk),
                ],
            ]);
            $this->logger->info('TradeMaster: upload catalog data', ['response' => $response, 'xml' => $this->getPruductXML($chunk)]);
        }

        $this->setStatusDone();
    }

    protected function getPruductXML(Collection $products)
    {
        $output = '<Attributes>';

        $host = rtrim($this->parameter('common_homepage', false), '/');

        /** @var \App\Domain\Entities\Catalog\Product $product */
        foreach ($products as $product) {
            $images = [];
            foreach ($product->getFiles() as $file) {
                /** @var \App\Domain\Entities\File $file */
                $images[] = $host . $file->getPublicPath();
            }
            $images = implode(',', $images);

            $output .= '<ProductAttribute idTovar="' . $product->getExternalId() . '">
                            <ProductAttributeValue>
                                <name>' . $product->getTitle() . '</name>
                                <opisanie>' . $product->getDescription() . '</opisanie>
                                <opisanieDop>' . $product->getExtra() . '</opisanieDop>
                                <artikul>' . $product->getVendorCode() . '</artikul>
                                <edIzmer>' . $product->getUnit() . '</edIzmer>
                                <strihKod>' . $product->getBarCode() . '</strihKod>
                                <poryadok>' . $product->getOrder() . '</poryadok>
                                <foto>' . $images . '</foto>
                                <link>' . $product->getAddress() . '</link>
                                <sebestoim>' . $product->getPriceFirst() . '</sebestoim>
                                <price>' . $product->getPrice() . '</price>
                                <opt_price>' . $product->getPriceWholesale() . '</opt_price>
                                <kolvo>' . $product->getStock() . '</kolvo>
                                <ind1>' . $product->getField1() . '</ind1>
                                <ind2>' . $product->getField2() . '</ind2>
                                <ind3>' . $product->getField3() . '</ind3>
                                <ind4>' . $product->getField4() . '</ind4>
                                <ind5>' . $product->getField5() . '</ind5>
                                <tags>' . $product->getTags() . '</tags>
                                <ves>' . $product->getVolume() . '</ves>
                                <proizv>' . $product->getManufacturer() . '</proizv>
                                <strana>' . $product->getCountry() . '</strana>
                            </ProductAttributeValue>
                        </ProductAttribute>';
        }

        $output .= '</Attributes>';

        return trim($output);
    }
}
