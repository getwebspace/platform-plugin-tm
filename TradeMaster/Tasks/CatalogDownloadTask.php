<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractService;
use App\Domain\AbstractTask;
use App\Domain\Service\Catalog\CategoryService;
use App\Domain\Service\Catalog\CategoryService as CatalogCategoryService;
use App\Domain\Service\Catalog\Exception\AddressAlreadyExistsException;
use App\Domain\Service\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Service\Catalog\Exception\MissingTitleValueException;
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
     * @var array
     */
    private array $downloadImages = [];

    protected static function map(int $x, int $in_min, int $in_max, int $out_min, int $out_max): float
    {
        return ($x - $in_min) * ($out_max - $out_min) / ($in_max - $in_min) + $out_min;
    }

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

        try {
            $categories = $this->categoryService->read(['export' => 'trademaster']);
            $products = $this->productService->read(['export' => 'trademaster']);

            $this->setProgress(1);
            $this->category($categories);

            $this->setProgress(20);
            $this->product($categories, $products);

            $this->setProgress(90);
            $this->remove($categories, $products);

            $this->setProgress(95);

            if ($this->downloadImages) {
                // download images
                $task = new \Plugin\TradeMaster\Tasks\DownloadImageTask($this->container);
                $task->execute(['list' => $this->downloadImages]);

                // run worker
                \App\Domain\AbstractTask::worker($task);
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

    protected function category(Collection &$categories): void
    {
        $this->logger->info('Task: TradeMaster get catalog item');

        // saved parameters of view for category and products
        $template = [
            'category' => $this->parameter('catalog_category_template', 'catalog.category.twig'),
            'product' => $this->parameter('catalog_product_template', 'catalog.product.twig'),
        ];
        $pagination = $this->parameter('catalog_category_pagination', 10);

        $list = $this->trademaster->api([
            'endpoint' => 'catalog/list',
            'params' => [
                'link' => $this->parameter('TradeMasterPlugin_category_link', ''),
            ],
        ]);

        foreach ($list as $index => $item) {
            $data = [
                'status' => \App\Domain\Types\Catalog\CategoryStatusType::STATUS_WORK,
                'external_id' => $item['idZvena'],
                'parent' => \Ramsey\Uuid\Uuid::NIL,
                'title' => $item['nameZvena'],
                'order' => $item['poryadok'],
                'description' => urldecode($item['opisanie']),
                'field1' => $item['ind1'],
                'field2' => $item['ind2'],
                'field3' => $item['ind3'],
                'template' => $template,
                'sort' => [
                    'by' => $this->parameter('catalog_sort_by', 'title'),
                    'direction' => $this->parameter('catalog_sort_direction', 'ASC'),
                ],
                'meta' => [
                    'title' => $item['nameZvena'],
                    'description' => strip_tags(urldecode($item['opisanie'])),
                ],
                'pagination' => $pagination,
                'export' => 'trademaster',
            ];

            /**
             * @var \App\Domain\Entities\Catalog\Category $category
             */
            $category = $categories->firstWhere('external_id', $item['idZvena']);

            try {
                switch (true) {
                    case !empty($category):
                        $this->categoryService->update($category, $data);

                        break;

                    default:
                        $categories[] = $category = $this->categoryService->create($data);

                }
            } catch (MissingTitleValueException $e) {
                $this->logger->warning('TradeMaster: category title wrong value', $data);
            } catch (TitleAlreadyExistsException $e) {
                $this->logger->warning('TradeMaster: category title exist', $data);

                $category = $categories->firstWhere('title', $data['title']);

                if (!empty($category)) {
                    try {
                        $this->categoryService->update($category, $data);
                        $this->logger->warning('TradeMaster: category updated', $data);
                    } catch (TitleAlreadyExistsException $e) {
                        $this->logger->warning('TradeMaster: category ignored', $data);
                    }
                }
            } catch (AddressAlreadyExistsException $e) {
                $this->logger->warning('TradeMaster: category address exist', $data);
            }

            if (!empty($category)) {
                $category->buf = $item['idParent'];

                // add category photos
                if ($item['foto'] && $this->parameter('file_is_enabled', 'no') === 'yes') {
                    $this->downloadImages[] = [
                        'photo' => $item['foto'],
                        'type' => 'category',
                        'uuid' => $category->getUuid()->toString(),
                    ];
                }
            }
        }

        // find parent category
        foreach ($categories as $category) {
            if (+$category->buf) {
                if (($parent = $categories->firstWhere('external_id', $category->buf)) !== null) {
                    /** @var \App\Domain\Entities\Catalog\Category $parent */
                    $this->categoryService->update($category, ['parent' => $parent->getUuid()]);
                } else {
                    $this->categoryService->update($category, ['status' => \App\Domain\Types\Catalog\CategoryStatusType::STATUS_DELETE]);
                    $this->logger->warning('TradeMaster: parent category not found, delete', ['parent' => $category->buf]);
                }
            }
        }

        // process addresses
        if ($this->parameter('common_auto_generate_address', 'no') === 'yes') {
            $this->logger->info('TradeMaster: generate addresses');
            $this->categoryFixAddress($this->categoryService, $categories);
            $this->logger->info('TradeMaster: generate addresses (done)');
        }
    }

    /**
     * @param CategoryService|ProductService                                                  $service
     * @param Collection                                                                      $list
     * @param \App\Domain\Entities\Catalog\Category|\App\Domain\Entities\Catalog\Product|null $parent
     */
    private function categoryFixAddress($service, Collection $list, $parent = null)
    {
        /** @var \App\Domain\Entities\Catalog\Category|null $category */
        foreach ($list->where('parent', is_null($parent) ? \Ramsey\Uuid\Uuid::NIL : $parent->getUuid()) as $category) {
            try {
                $address = [];

                if ($parent) {
                    $address[] = $parent->getAddress();
                }

                $address[] = $category->setAddress(str_replace('/', '-', $category->getTitle()))->getAddress();
                $service->update($category, ['address' => implode('/', $address)]);
            } catch (AddressAlreadyExistsException $e) {
                $this->logger->warning('TradeMaster: error when update category address', ['uuid' => $category->getUuid()->toString()]);
            }

            $this->categoryFixAddress($service, $list, $category);
        }
    }

    protected function product(Collection &$categories, Collection &$products): void
    {
        $this->logger->info('Task: TradeMaster get product item');

        $count = $this->trademaster->api([
            'endpoint' => 'item/count',
            'params' => [
                'link' => $this->parameter('TradeMasterPlugin_category_link', ''),
            ],
        ]);

        if ($count && $count['count']) {
            $count = (int) $count['count'];
            $i = 0;
            $step = 100;
            $go = true;

            // fetch data
            while ($go) {
                $list = $this->trademaster->api([
                    'endpoint' => 'item/list',
                    'params' => [
                        'sklad' => $this->parameter('TradeMasterPlugin_storage', 0),
                        'offset' => $i * $step,
                        'limit' => $step,
                        'link' => $this->parameter('TradeMasterPlugin_category_link', ''),
                    ],
                ]);

                // полученные данные проверяем и записываем в модели товара
                foreach ($list as $index => $item) {
                    $data = [
                        'status' => \App\Domain\Types\Catalog\ProductStatusType::STATUS_WORK,
                        'external_id' => $item['idTovar'],
                        'category' => \Ramsey\Uuid\Uuid::NIL,
                        'title' => trim($item['name']),
                        'order' => $item['poryadok'],
                        'description' => trim(urldecode($item['opisanie'])),
                        'extra' => trim(urldecode($item['opisanieDop'])),
                        'field1' => $item['ind1'],
                        'field2' => $item['ind2'],
                        'field3' => $item['ind3'],
                        'field4' => $item['ind4'],
                        'field5' => $item['ind5'],
                        'vendorcode' => $item['artikul'],
                        'barcode' => $item['strihKod'],
                        'priceFirst' => $item['sebestomost'],
                        'price' => $item['price'],
                        'priceWholesale' => $item['opt_price'],
                        'unit' => rtrim($item['edIzmer'], '.'),
                        'volume' => $item['ves'],
                        'country' => $item['strana'],
                        'manufacturer' => $item['proizv'],
                        'tags' => $item['tags'],
                        'date' => new \DateTime(trim($item['changeDate'])),
                        'meta' => [
                            'title' => $item['name'],
                            'description' => strip_tags(urldecode($item['opisanie'])),
                        ],
                        'stock' => $item['kolvo'],
                        'export' => 'trademaster',
                    ];

                    // find parent category
                    if (($category = $categories->firstWhere('external_id', $item['vStrukture'])) !== null) {
                        /** @var \App\Domain\Entities\Catalog\Category $category */
                        $data['category'] = $category->getUuid()->toString();
                    }

                    try {
                        /** @var \App\Domain\Entities\Catalog\Product $product */
                        $product = $products->firstWhere('external_id', $item['idTovar']);
                        switch (true) {
                            case $product:
                                $this->productService->update($product, $data);

                                break;

                            default:
                                $products[] = $product = $this->productService->create($data);
                        }
                    } catch (MissingTitleValueException $e) {
                        $this->logger->warning('TradeMaster: product title wrong value', $data);
                    } catch (TitleAlreadyExistsException $e) {
                        $this->logger->warning('TradeMaster: product title exist', $data);

                        $product = $products->firstWhere('title', $data['title']);

                        if (!empty($product)) {
                            try {
                                $this->productService->update($product, $data);
                                $this->logger->warning('TradeMaster: product updated', $data);
                            } catch (TitleAlreadyExistsException $e) {
                                $this->logger->warning('TradeMaster: product ignored', $data);
                            }
                        }
                    } catch (AddressAlreadyExistsException $e) {
                        $this->logger->warning('TradeMaster: product address exist', $data);
                    }

                    if ($product) {
                        $product->buf = 1;

                        // process product address
                        if ($this->parameter('common_auto_generate_address', 'no') === 'yes') {
                            try {
                                if (($category = $categories->firstWhere('uuid', $product->getCategory())) !== null) {
                                    /** @var \App\Domain\Entities\Catalog\Product $product */
                                    $this->productService->update($product, ['address' => $category->getAddress() . '/' . $product->setAddress(str_replace('/', '-', $product->getTitle()))->getAddress()]);
                                }
                            } catch (AddressAlreadyExistsException $e) {
                                $this->logger->warning('TradeMaster: error when update product address', ['uuid' => $product->getUuid()->toString()]);
                            }
                        }

                        // add product photos
                        if ($item['foto'] && $this->parameter('file_is_enabled', 'no') === 'yes') {
                            $this->downloadImages[] = [
                                'photo' => $item['foto'],
                                'type' => 'product',
                                'uuid' => $product->getUuid()->toString(),
                            ];
                        }
                    }

                    $this->setProgress(static::map($step * $i + $index, 0, $count, 21, 89));
                }

                $i++;
                $go = $step * $i <= $count;
            }
        }
    }

    protected function remove(Collection &$categories, Collection &$products): void
    {
        $this->logger->info('Task: TradeMaster remove old categories and products');

        // удаление моделей категорий которые не получили обновление в процессе синхронизации
        foreach ($categories->where('buf', null) as $category) {
            /** @var \App\Domain\Entities\Catalog\Category $category */
            $childCategoriesUuid = $category->getNested($categories)->pluck('uuid')->all();

            // удаление вложенных категорий
            foreach ($categories->whereIn('uuid', $childCategoriesUuid) as $subCategory) {
                $this->categoryService->update($subCategory, ['status' => \App\Domain\Types\Catalog\CategoryStatusType::STATUS_DELETE]);
            }

            // удаление продуктов в категории
            foreach ($products->whereIn('category', $childCategoriesUuid) as $product) {
                $this->productService->update($product, ['status' => \App\Domain\Types\Catalog\ProductStatusType::STATUS_DELETE]);
            }

            $this->categoryService->update($category, ['status' => \App\Domain\Types\Catalog\CategoryStatusType::STATUS_DELETE]);
        }

        // удаление моделей продуктов которые не получили обновление в процессе синхронизации
        foreach ($products->where('status', \App\Domain\Types\Catalog\CategoryStatusType::STATUS_WORK)->where('buf', null) as $product) {
            $this->productService->update($product, ['status' => \App\Domain\Types\Catalog\ProductStatusType::STATUS_DELETE]);
        }
    }
}
