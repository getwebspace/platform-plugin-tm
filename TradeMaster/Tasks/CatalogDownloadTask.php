<?php

namespace Plugin\TradeMaster\Tasks;

use Alksily\Entity\Collection;
use App\Domain\Tasks\Task;

class CatalogDownloadTask extends Task
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
     * @var \Plugin\TradeMaster\TradeMasterPlugin
     */
    protected $trademaster;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    protected $categoryRepository;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    protected $productRepository;

    /**
     * @var array
     */
    private $downloadImages = [];

    /**
     * @throws \RunTracy\Helpers\Profiler\Exception\ProfilerException
     */
    protected function action(array $args = [])
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->categoryRepository = $this->entityManager->getRepository(\App\Domain\Entities\Catalog\Category::class);
        $this->productRepository = $this->entityManager->getRepository(\App\Domain\Entities\Catalog\Product::class);

        $catalog = [
            'categories' => collect($this->categoryRepository->findBy([
                'export' => 'trademaster',
                'status' => \App\Domain\Types\Catalog\CategoryStatusType::STATUS_WORK,
            ])),
            'products' => collect($this->productRepository->findBy([
                'export' => 'trademaster',
                'status' => \App\Domain\Types\Catalog\ProductStatusType::STATUS_WORK,
            ])),
        ];

        try {
            $this->setProgress(1);
            \RunTracy\Helpers\Profiler\Profiler::start('task:tm:category');
            $this->category($catalog['categories']);
            \RunTracy\Helpers\Profiler\Profiler::finish('task:tm:category');

            $this->setProgress(33);
            \RunTracy\Helpers\Profiler\Profiler::start('task:tm:product');
            $this->product($catalog['categories'], $catalog['products']);
            \RunTracy\Helpers\Profiler\Profiler::finish('task:tm:product');

            $this->setProgress(66);
            \RunTracy\Helpers\Profiler\Profiler::start('task:tm:remove');
            $this->remove($catalog['categories'], $catalog['products']);
            \RunTracy\Helpers\Profiler\Profiler::finish('task:tm:remove');

            $this->setProgress(99);
            if ($this->downloadImages) {
                // загрузка картинок
                $task = new \Plugin\TradeMaster\Tasks\DownloadImageTask($this->container);
                $task->execute(['list' => $this->downloadImages]);
            }

            $this->setProgress(100);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage(), ['file' => $exception->getFile(), 'trace' => $exception->getTraceAsString()]);

            return $this->setStatusFail();
        }

        return $this->setStatusDone();
    }

    protected function category(Collection &$categories)
    {
        $this->logger->info('Task: TradeMaster get catalog item');

        // параметры отображения категории и товаров
        $template = [
            'category' => $this->getParameter('catalog_category_template', 'catalog.category.twig'),
            'product' => $this->getParameter('catalog_product_template', 'catalog.product.twig'),
        ];
        $pagination = $this->getParameter('catalog_category_pagination', 10);

        $list = $this->trademaster->api(['endpoint' => 'catalog/list']);

        foreach ($list as $item) {
            $data = [
                'external_id' => $item['idZvena'],
                'parent' => \Ramsey\Uuid\Uuid::NIL,
                'title' => $item['nameZvena'],
                'order' => $item['poryadok'],
                'description' => urldecode($item['opisanie']),
                'address' => $item['link'],
                'field1' => $item['ind1'],
                'field2' => $item['ind2'],
                'field3' => $item['ind3'],
                'template' => $template,
                'children' => true,
                'meta' => [
                    'title' => $item['nameZvena'],
                    'description' => strip_tags(urldecode($item['opisanie'])),
                ],
                'pagination' => $pagination,
                'export' => 'trademaster',
                'buf' => $item['idParent'],
            ];

            $result = \App\Domain\Filters\Catalog\Category::check($data);

            if ($result === true) {
                $model = $categories->firstWhere('external_id', $item['idZvena']);
                if (!$model) {
                    $categories[] = $model = new \App\Domain\Entities\Catalog\Category();
                    $this->entityManager->persist($model);
                }
                $model->replace($data);

                if ($this->getParameter('file_is_enabled', 'no') === 'yes') {
                    $this->downloadImages[] = ['photo' => $item['foto'], 'type' => 'category', 'uuid' => $model->uuid->toString()];
                }
            } else {
                $this->logger->warning('TradeMaster: invalid category data', $result);
            }
        }

        // обрабатываем связи
        foreach ($categories as $model) {
            /** @var \App\Domain\Entities\Catalog\Category $model */
            if (+$model->buf) {
                $model->set('parent', $categories->firstWhere('external_id', $model->buf)->get('uuid'));
            } else {
                $model->set('parent', \Ramsey\Uuid\Uuid::fromString(\Ramsey\Uuid\Uuid::NIL));
            }
        }

        // обрабатываем адреса
        if ($this->getParameter('common_auto_generate_address', 'no') === 'yes') {
            foreach ($categories as $model) {
                /**
                 * @var \App\Domain\Entities\Catalog\Category $category
                 * @var \App\Domain\Entities\Catalog\Category $model
                 */
                $category = $categories->firstWhere('uuid', $model->parent);

                if ($category && !str_starts_with($category->address, $model->address)) {
                    $model->address = $category->address . '/' . $model->address;
                }
            }
        }
    }

    protected function product(Collection &$categories, Collection &$products)
    {
        $this->logger->info('Task: TradeMaster get product item');

        $count = $this->trademaster->api(['endpoint' => 'item/count']);

        if ($count) {
            $count = (int) $count['count'];
            $i = 0;
            $step = 100;
            $go = true;

            // получаем данные
            while ($go) {
                $list = $this->trademaster->api([
                    'endpoint' => 'item/list',
                    'params' => [
                        'sklad' => $this->getParameter('TradeMasterPlugin_storage', 0),
                        'offset' => $i * $step,
                        'limit' => $step,
                    ],
                ]);

                // полученные данные проверяем и записываем в модели товара
                foreach ($list as $item) {
                    $data = [
                        'external_id' => $item['idTovar'],
                        'category' => \Ramsey\Uuid\Uuid::NIL,
                        'title' => trim($item['name']),
                        'order' => $item['poryadok'],
                        'description' => trim(urldecode($item['opisanie'])),
                        'extra' => trim(urldecode($item['opisanieDop'])),
                        'address' => $item['link'],
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
                        'buf' => 1,
                    ];

                    $result = \App\Domain\Filters\Catalog\Product::check($data);

                    if ($result === true) {
                        /**
                         * @var \App\Domain\Entities\Catalog\Category $category
                         * @var \App\Domain\Entities\Catalog\Product  $model
                         */
                        $model = $products->firstWhere('external_id', $item['idTovar']);
                        if (!$model) {
                            $products[] = $model = new \App\Domain\Entities\Catalog\Product();
                            $this->entityManager->persist($model);
                        }

                        if (($category = $categories->firstWhere('external_id', $item['vStrukture'])) !== null) {
                            $data['category'] = $category->uuid;

                            if ($this->getParameter('common_auto_generate_address', 'no') === 'yes') {
                                $data['address'] = $category->address . '/' . $data['address'];
                            }
                        }

                        $model->replace($data);

                        if ($item['foto'] && $this->getParameter('file_is_enabled', 'no') === 'yes') {
                            $this->downloadImages[] = ['photo' => $item['foto'], 'type' => 'product', 'uuid' => $model->uuid->toString()];
                        }
                    } else {
                        $this->logger->warning('TradeMaster: invalid product data', $result);
                    }
                }

                $i++;
                $go = $step * $i <= $count;
            }
        };
    }

    protected function remove(Collection &$categories, Collection &$products)
    {
        $this->logger->info('Task: TradeMaster remove old categories and products');

        // удаление моделей категорий которые не получили обновление в процессе синхронизации
        foreach ($categories->where('buf', null) as $model) {
            /** @var \App\Domain\Entities\Catalog\Category $model */
            $childCategoriesUuid = \App\Domain\Entities\Catalog\Category::getChildren($categories, $model)->pluck('uuid')->all();

            // удаление вложенных категорий
            foreach ($categories->whereIn('uuid', $childCategoriesUuid) as $category) {
                /** @var \App\Domain\Entities\Catalog\Category $category */
                $category->set('status', \App\Domain\Types\Catalog\CategoryStatusType::STATUS_DELETE);
            }

            // удаление продуктов
            foreach ($products->whereIn('uuid', $childCategoriesUuid) as $product) {
                /** @var \App\Domain\Entities\Catalog\Product $product */
                $product->set('status', \App\Domain\Types\Catalog\ProductStatusType::STATUS_DELETE);
            }

            $model->set('status', \App\Domain\Types\Catalog\CategoryStatusType::STATUS_DELETE);
        }

        // удаление моделей продуктов которые не получили обновление в процессе синхронизации
        foreach ($products->where('status', \App\Domain\Types\Catalog\CategoryStatusType::STATUS_WORK)->where('buf', null) as $product) {
            /** @var \App\Domain\Entities\Catalog\Product $product */
            $product->set('status', \App\Domain\Types\Catalog\ProductStatusType::STATUS_DELETE);
        }
    }
}
