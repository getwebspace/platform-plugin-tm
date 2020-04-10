<?php

namespace Plugin\TradeMaster\Tasks;

use Alksily\Entity\Collection;
use App\Domain\Tasks\Task;

class CatalogUploadTask extends Task
{
    public const TITLE = 'Выгрузка каталога ТМ';

    public function execute(array $params = []): \App\Domain\Entities\Task
    {
        $default = [
            // nothing
        ];
        $params = array_merge($default, $params);

        return parent::execute($params);
    }

    /**
     * @var \Plugin\TradeMaster\TradeMasterPlugin
     */
    protected $trademaster;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    protected $productRepository;

    /**
     * @throws \RunTracy\Helpers\Profiler\Exception\ProfilerException
     */
    protected function action(array $args = [])
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->productRepository = $this->entityManager->getRepository(\App\Domain\Entities\Catalog\Product::class);

        $products = collect($this->productRepository->findBy([
            'export' => 'trademaster',
            'status' => \App\Domain\Types\Catalog\ProductStatusType::STATUS_WORK,
        ]));

        $step = 200;
        foreach ($products->chunk($step) as $index => $chunk) {
            $response = $this->trademaster->api([
                'method' => 'POST',
                'endpoint' => 'item/updateTovarSite',
                'params' => [
                    'tovarxml' => $this->getPruductXML($chunk),
                ],
            ]);
            $this->logger->info('TradeMaster: upload catalog data', ['response' => $response, 'xml' => $this->getPruductXML($chunk)]);

            return $this->setStatusDone();
            $this->setProgress($index, $products->count() / $step);
        }

        $this->setStatusDone();
    }

    protected function getPruductXML(Collection $products)
    {
        $output = "<Attributes>";

        $host = rtrim($this->getParameter('common_homepage', false), '/');

        /** @var \App\Domain\Entities\Catalog\Product $product */
        foreach ($products as $product) {
            $images = [];
            /** @var \App\Domain\Entities\File $file */
            foreach ($product->getFiles() as $file) {
                $images[] = $host . $file->getPublicPath();
            }
            $images = implode(',', $images);

            $output .= '
    <ProductAttribute idTovar="' . $product->external_id . '">
        <ProductAttributeValue>
            <name>' . $product->title . '</name>
            <opisanie>' . $product->description . '</opisanie>
            <opisanieDop>' . $product->extra . '</opisanieDop>
            <artikul>' . $product->vendorcode . '</artikul>
            <edIzmer>' . $product->unit . '</edIzmer>
            <strihKod>' . $product->barcode . '</strihKod>
            <poryadok>' . $product->order . '</poryadok>
            <foto>' . $images . '</foto>
            <link>' . $product->address . '</link>
            <sebestoim>' . $product->priceFirst . '</sebestoim>
            <price>' . $product->price . '</price>
            <opt_price>' . $product->priceWholesale . '</opt_price>
            <kolvo>' . $product->stock . '</kolvo>
            <ind1>' . $product->field1 . '</ind1>
            <ind2>' . $product->field2 . '</ind2>
            <ind3>' . $product->field3 . '</ind3>
            <ind4>' . $product->field4 . '</ind4>
            <ind5>' . $product->field5 . '</ind5>
            <tags>' . $product->tags . '</tags>
            <ves>' . $product->volume . '</ves>
            <proizv>' . $product->manufacturer . '</proizv>
            <strana>' . $product->country . '</strana>
        </ProductAttributeValue>
    </ProductAttribute>';
            break;
        }

        $output .= "</Attributes>";

        return trim($output);
    }
}
