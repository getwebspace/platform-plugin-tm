<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractException;
use App\Domain\AbstractTask;
use App\Domain\Entities\Catalog\Category;
use App\Domain\Service\Catalog\AttributeService;
use App\Domain\Service\Catalog\CategoryService;
use App\Domain\Service\Catalog\CategoryService as CatalogCategoryService;
use App\Domain\Service\Catalog\Exception\AddressAlreadyExistsException;
use App\Domain\Service\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Service\Catalog\Exception\MissingTitleValueException;
use App\Domain\Service\Catalog\Exception\ProductNotFoundException;
use App\Domain\Service\Catalog\Exception\TitleAlreadyExistsException;
use App\Domain\Service\Catalog\ProductService;
use App\Domain\Service\Catalog\ProductService as CatalogProductService;
use Plugin\TradeMaster\TradeMasterPlugin;
use Illuminate\Support\Collection;

class CatalogDownloadTask extends AbstractTask
{
    public const TITLE = 'Загрузка каталога ТМ';

    public function execute(array $params = []): \App\Domain\Entities\Task
    {
        $default = [
            // nothing
        ];
        $params = array_merge($default, $params);

        return parent::execute($params);
    }

    /**
     * @var TradeMasterPlugin
     */
    protected TradeMasterPlugin $trademaster;

    /**
     * @var CategoryService
     */
    protected CategoryService $categoryService;

    /**
     * @var ProductService
     */
    protected ProductService $productService;

    /**
     * @var AttributeService
     */
    protected AttributeService $attributeService;

    /**
     * @var array
     */
    private array $downloadImages = [];

    /**
     * @param array $args
     *
     * @return bool
     */
    protected function action(array $args = [])
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->categoryService = $this->container->get(CatalogCategoryService::class);
        $this->productService = $this->container->get(CatalogProductService::class);
        $this->attributeService = $this->container->get(AttributeService::class);

        try {
            $this->setProgress(1);
            $attributes = $this->generate();

            $this->setProgress(25);
            $categories = $this->category($attributes);

            $this->setProgress(50);
            $this->product($attributes, $categories);

            $this->setProgress(80);
            $this->product_related();

            $this->setProgress(95);

            if ($this->parameter('file_is_enabled', 'no') === 'yes') {
                if ($this->downloadImages) {
                    // download images
                    $task = new \Plugin\TradeMaster\Tasks\DownloadImageTask($this->container);
                    $task->execute(['list' => $this->downloadImages]);

                    // run worker
                    \App\Domain\AbstractTask::worker($task);
                }
            }

            if ($this->parameter('TradeMasterPlugin_search', 'off') === 'on') {
                // reindex search
                $task = new \App\Domain\Tasks\SearchIndexTask($this->container);
                $task->execute();

                // run worker
                \App\Domain\AbstractTask::worker($task);
            }

            $this->setProgress(100);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage(), ['n' => get_class($exception), 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'trace' => $exception->getTraceAsString()]);

            return $this->setStatusFail();
        }

        return $this->setStatusDone();
    }

    protected function generate(): array
    {
        $this->logger->info('Task: TradeMaster check fields attributes');

        $attributes = [];

        for ($i = 1; $i <= 5; $i++) {
            try {
                if (($attr = $this->attributeService->read(['address' => "field{$i}"])) !== null) {
                    $attributes[$i] = $attr;
                    $this->logger->info("Task: TradeMaster field{$i} exist");
                }
            } catch (AbstractException $e) {
                $this->logger->info("Task: TradeMaster create field{$i}");

                $attributes[$i] = $this->attributeService->create([
                    'title' => "Индивидуальное поле {$i}",
                    'address' => "field{$i}",
                    'type' => \App\Domain\Types\Catalog\AttributeTypeType::TYPE_STRING,
                ]);
            }
        }

        return $attributes;
    }

    protected function category(array $attributes): Collection
    {
        $this->logger->info('Task: TradeMaster get catalog items');

        $list = collect(
            $this->trademaster->api([
                'endpoint' => 'catalog/list',
                'params' => [
                    'link' => $this->parameter('TradeMasterPlugin_category_link', ''),
                ],
            ])
        );

        if ($list->count()) {
            $this->categoryService->createQueryBuilder('c')
                ->update()
                ->where('c.export = :export')
                ->setParameter('export', 'trademaster')
                ->set('c.status', ':status')
                ->setParameter('status', \App\Domain\Types\Catalog\CategoryStatusType::STATUS_DELETE)
                ->getQuery()
                ->execute();
        }

        return $this->category_process($list, $attributes);
    }

    private function category_process(Collection $list, array $attributes, int $idParent = 0, Category $parent = null): Collection
    {
        $output = collect();

        foreach ($list->where('idParent', $idParent) as $item) {
            $data = [
                'parent' => $parent,
                'title' => trim($item['nameZvena']),
                'description' => urldecode($item['opisanie']),
                'sort' => [
                    'by' => $this->parameter('catalog_sort_by', 'title'),
                    'direction' => $this->parameter('catalog_sort_direction', 'ASC'),
                ],
                'meta' => [
                    'title' => $item['nameZvena'],
                    'description' => strip_tags(urldecode($item['opisanie'])),
                ],
                'pagination' => +$this->parameter('catalog_category_pagination', 10),
                'template' => [
                    'category' => $this->parameter('catalog_category_template', 'catalog.category.twig'),
                    'product' => $this->parameter('catalog_product_template', 'catalog.product.twig'),
                ],
                'attributes' => $attributes,
                'order' => $item['poryadok'],
                'external_id' => $item['idZvena'],
                'export' => 'trademaster',
                'system' => json_encode(
                    ['ind1' => $item['ind1'], 'ind2' => $item['ind2'], 'ind3' => $item['ind3']],
                    JSON_UNESCAPED_UNICODE
                ),
                'status' => \App\Domain\Types\Catalog\CategoryStatusType::STATUS_WORK,
            ];

            try {
                try {
                    $category = $this->categoryService->read(['external_id' => $item['idZvena']]);

                    if ($category) {
                        $category = $this->categoryService->update($category, $data);
                    }
                } catch (CategoryNotFoundException $e) {
                    $category = $this->categoryService->create($data);
                }

                // add category photos
                if ($item['foto']) {
                    $this->downloadImages[] = [
                        'photo' => $item['foto'],
                        'type' => 'category',
                        'uuid' => $category->getUuid()->toString(),
                    ];
                }

                // save in list
                $output[] = $category;
                $output = $output->merge($this->category_process($list, $attributes, +$item['idZvena'], $category));
            } catch (MissingTitleValueException $e) {
                $this->logger->warning('TradeMaster: category title wrong value', $data);
            } catch (TitleAlreadyExistsException $e) {
                $this->logger->warning('TradeMaster: category title exist', $data);
            } catch (AddressAlreadyExistsException $e) {
                $this->logger->warning('TradeMaster: category address exist', $data);
            }
        }

        return $output;
    }

    protected function product(array $attributes, Collection $categories): void
    {
        $this->logger->info('Task: TradeMaster get product item');

        $count = $this->trademaster->api([
            'endpoint' => 'item/count',
            'params' => [
                'link' => $this->parameter('TradeMasterPlugin_category_link', ''),
            ],
        ]);

        if ($count && $count['count']) {
            $this->productService->createQueryBuilder('c')
                ->update()
                ->where('c.export = :export')
                ->setParameter('export', 'trademaster')
                ->set('c.status', ':status')
                ->setParameter('status', \App\Domain\Types\Catalog\ProductStatusType::STATUS_DELETE)
                ->getQuery()
                ->execute();

            $count = (int) $count['count'];
            $i = 0;
            $step = 100;
            $go = true;

            // fetch data
            while ($go) {
                sleep(3);

                $list = $this->trademaster->api([
                    'endpoint' => 'item/list',
                    'params' => [
                        'sklad' => $this->parameter('TradeMasterPlugin_storage', 0),
                        'offset' => $i * $step,
                        'limit' => $step,
                        'link' => $this->parameter('TradeMasterPlugin_category_link', ''),
                    ],
                ]);

                $this->product_process($list, $categories, $attributes);
                $this->setProgress(static::map($i * $step, 0, $count, 51, 79));

                $i++;
                $go = $step * $i <= $count;
            }
        }
    }

    private function product_process(array $list, Collection $categories, array $attributes): void
    {
        foreach ($list as $item) {
            if (($category = $categories->firstWhere('external_id', $item['vStrukture'])) !== null) {
                $data = [
                    'title' => trim($item['name']),
                    'description' => trim(urldecode($item['opisanie'])),
                    'extra' => trim(urldecode($item['opisanieDop'])),
                    'vendorcode' => $item['artikul'],
                    'barcode' => $item['strihKod'],
                    'priceFirst' => $item['sebestomost'],
                    'price' => $item['price'],
                    'priceWholesale' => $item['opt_price'],
                    'dimension' => [
                        'weight' => $item['ves'],
                        'weight_class' => rtrim($item['edIzmer'], '.'),
                    ],
                    'country' => $item['strana'],
                    'manufacturer' => $item['proizv'],
                    'stock' => $item['kolvo'],
                    'meta' => [
                        'title' => $item['name'],
                        'description' => strip_tags(urldecode($item['opisanie'])),
                    ],
                    'category' => $category,
                    'order' => $item['poryadok'],
                    'status' => \App\Domain\Types\Catalog\ProductStatusType::STATUS_WORK,
                    'external_id' => $item['idTovar'],
                    'attributes' => [],
                    'relation' => [],
                    'export' => 'trademaster',
                ];

                for ($n = 1; $n <= 5; $n++) {
                    $data['attributes'][$attributes[$n]->getUuid()->toString()] = $item["ind{$n}"];
                }

                try {
                    try {
                        $product = $this->productService->read(['external_id' => $item['idTovar']]);

                        if ($product) {
                            $product = $this->productService->update($product, $data);
                        }
                    } catch (ProductNotFoundException $e) {
                        $product = $this->productService->create($data);
                    }

                    // process address
                    $this->productService->update($product, [
                        'address' => $category->getAddress() . '/' . $product->setAddress(str_replace('/', '-', $product->getTitle()))->getAddress()
                    ]);

                    // add product photos
                    if ($item['foto']) {
                        $this->downloadImages[] = [
                            'photo' => $item['foto'],
                            'type' => 'product',
                            'uuid' => $product->getUuid()->toString(),
                        ];
                    }
                } catch (MissingTitleValueException $e) {
                    $this->logger->warning('TradeMaster: product title wrong value', $data);
                } catch (AddressAlreadyExistsException $e) {
                    $this->logger->warning('TradeMaster: product address exist', $data);
                }
            } else {
                $this->logger->warning('TradeMaster: category not found', [
                    'title' => trim($item['name']),
                    'vStrukture' => $item['vStrukture'],
                    'external_id' => $item['idTovar'],
                ]);
            }
        }
    }

    protected function product_related(): void
    {
        $i = 0;
        $step = 100;
        $go = true;

        // fetch data
        while ($go) {
            sleep(3);

            $list = $this->trademaster->api([
                'endpoint' => 'item/soput',
                'params' => [
                    'offset' => $i * $step,
                    'limit' => $step,
                ],
            ]);
            $count = count($list);

            $this->logger->info("Task: TradeMaster check related {$count}");

            foreach ($list as $item) {
                try {
                    $relations = [];
                    $product = $this->productService->read(['external_id' => $item['idTovar1']]);

                    if ($product->hasRelations()) {
                        foreach ($product->getRelations() as $relation) {
                            $relations[$relation->getRelated()->getUuid()->toString()] = $relation->getCount();
                        }
                    }

                    $related = $this->productService->read(['external_id' => $item['idTovar2']]);
                    $relations[$related->getUuid()->toString()] = $item['kolvo'];

                    $this->productService->update($product, [
                        'relation' => $relations,
                    ]);
                } catch (ProductNotFoundException $e) {
                    $this->logger->info("Task: TradeMaster relation product not found", [
                        'product' => $item['idTovar1'],
                        'related' => $item['idTovar2'],
                    ]);
                }
            }

            $i++;
            $go = $count >= $step;
        }
    }

    protected static function map(int $x, int $in_min, int $in_max, int $out_min, int $out_max): float
    {
        return ($x - $in_min) * ($out_max - $out_min) / ($in_max - $in_min) + $out_min;
    }
}
