<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractException;
use App\Domain\AbstractTask;
use App\Domain\Entities\Catalog\Category;
use App\Domain\Models\CatalogCategory;
use App\Domain\Models\CatalogProduct;
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

    public function execute(array $params = []): \App\Domain\Models\Task
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
            $attributes = $this->basic_attributes();

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
            $this->logger->error($exception->getMessage(), [
                'n' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->setStatusFail();
        }

        return $this->setStatusDone();
    }

    protected function basic_attributes(): array
    {
        $this->logger->info('Task: TradeMaster check fields attributes');

        $attributes = [];

        for ($i = 1; $i <= 4; $i++) {
            $attributes[$i] = $this->retrieve_attribute("field{$i}", "TM: Ind{$i}");
        }

        return $attributes;
    }

    protected function retrieve_attribute($search, $group = '', $type = \App\Domain\Casts\Catalog\Attribute\Type::STRING)
    {
        try {
            if (($attr = $this->attributeService->read(['address' => $search])) !== null) {
                return $attr->uuid;
            }
        } catch (AbstractException $e) {
            try {
                if (($attr = $this->attributeService->read(['title' => $search])) !== null) {
                    return $attr->uuid;
                }
            } catch (AbstractException $e) {
                $attr = $this->attributeService->create([
                    'title' => $search,
                    'address' => $search,
                    'group' => $group,
                    'type' => $type,
                ]);

                return $attr->uuid;
            }
        }
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
            CatalogCategory::query()
                ->where('export', 'trademaster')
                ->update(['status' => \App\Domain\Casts\Catalog\Status::DELETE]);
        }

        return $this->category_process($list, $attributes);
    }

    private function category_process(Collection $list, array $attributes, int $idParent = 0, CatalogCategory $parent = null): Collection
    {
        $output = collect();

        foreach ($list->where('idParent', $idParent) as $item) {
            $data = [
                'title' => trim($item['nameZvena']),
                'address' => trim($item['nameZvena']),
                'description' => urldecode($item['opisanie']),
                'parent_uuid' => $parent?->uuid,
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
                'specifics' => [
                    'ind1' => $item['ind1'],
                    'ind2' => $item['ind2'],
                    'ind3' => $item['ind3'],
                ],
                'status' => \App\Domain\Casts\Catalog\Status::WORK,
            ];

            try {
                try {
                    $category = $this->categoryService->read([
                        'external_id' => $item['idZvena'],
                        'export' => 'trademaster',
                    ]);

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
                        'uuid' => $category->uuid,
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
            CatalogProduct::query()
                ->where('export', 'trademaster')
                ->update(['status' => \App\Domain\Casts\Catalog\Status::DELETE]);

            $count = (int) $count['count'];
            $i = 0;
            $step = 250;
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
                /** @var CatalogCategory $category */
                $data = [
                    'title' => trim($item['name']),
                    'address' => trim($item['name']),
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
                    'category_uuid' => $category->uuid,
                    'order' => $item['poryadok'],
                    'status' => \App\Domain\Casts\Catalog\Status::WORK,
                    'external_id' => $item['idTovar'],
                    'attributes' => [],
                    'relation' => [],
                    'export' => 'trademaster',
                ];

                for ($n = 1; $n <= 5; $n++) {
                    if ($n < 5) {
                        $data['attributes'][$attributes[$n]] = $item["ind{$n}"];
                    } else {
                        foreach (explode(',', $item["ind{$n}"]) as $name) {
                            $name = mb_convert_case(trim($name), MB_CASE_TITLE, "UTF-8");

                            if ($name) {
                                $uuid = $this->retrieve_attribute($name, "TM: Ind{$n}", \App\Domain\Casts\Catalog\Attribute\Type::BOOLEAN);
                                $data['attributes'][$uuid] = 'yes';
                            }
                        }
                    }
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
                        'address' => implode('/', array_filter([$product->category->address, $product->title ?? uniqid()], fn ($el) => (bool) $el))
                    ]);

                    // add product photos
                    if ($item['foto']) {
                        $this->downloadImages[] = [
                            'photo' => $item['foto'],
                            'type' => 'product',
                            'uuid' => $product->uuid,
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

        $sync = [];

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

            $this->logger->info("Task: TradeMaster check related {$count}", ['offset' => $i * $step, 'limit' => $step]);

            foreach ($list as $item) {
                try {
                    $product = $this->productService->read(['external_id' => $item['idTovar1']]);
                    $buf = [
                        'product' => $product,
                        'relations' => isset($sync[$product->uuid]) ? $sync[$product->uuid]['relations'] : [],
                    ];

                    $related = $this->productService->read(['external_id' => $item['idTovar2']]);
                    $buf['relations'][$related->uuid] = floatval($item['kolvo']);

                    $sync[$product->uuid] = $buf;
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

        $this->logger->info("Task: TradeMaster write relations", ['sync' => $sync]);

        // write relations
        foreach ($sync as $item) {
            $this->productService->update($item['product'], [
                'relations' => $item['relations'],
            ]);
        }
    }

    protected static function map(int $x, int $in_min, int $in_max, int $out_min, int $out_max): float
    {
        return ($x - $in_min) * ($out_max - $out_min) / ($in_max - $in_min) + $out_min;
    }
}
