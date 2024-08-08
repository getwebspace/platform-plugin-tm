<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Service\Catalog\CategoryService;
use App\Domain\Service\Catalog\ProductService;
use App\Domain\Service\File\FileService;
use Plugin\TradeMaster\TradeMasterPlugin;

class DownloadImageTask extends AbstractTask
{
    public const TITLE = 'Загрузка изображений из ТМ';

    public function execute(array $params = []): \App\Domain\Models\Task
    {
        $default = [
            'list' => [
                /*[ 'photo' => '', 'type' => '', 'uuid' => '' ],*/
            ],
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
     * @var FileService
     */
    protected FileService $fileService;

    /**
     * @var array
     */
    private array $convertImageUuids = [];

    protected function action(array $args = []): void
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->categoryService = $this->container->get(CategoryService::class);
        $this->productService = $this->container->get(ProductService::class);
        $this->fileService = $this->container->get(FileService::class);

        if ($this->parameter('file_is_enabled', 'no') === 'yes') {
            foreach ($args['list'] as $index => $item) {
                if ($item['photo']) {
                    /**
                     * @var \App\Domain\Models\CatalogCategory|\App\Domain\Models\CatalogProduct $model
                     */
                    switch ($item['type']) {
                        case 'category':
                            $entity = $this->categoryService->read(['uuid' => $item['uuid']]);

                            break;
                        case 'product':
                            $entity = $this->productService->read(['uuid' => $item['uuid']]);

                            break;
                    }

                    if (!empty($entity)) {
                        $sync = [];

                        foreach (explode(';', $item['photo']) as $i => $name) {
                            $path = $this->trademaster->getFilePath($name);

                            if (($file = $this->fileService->createFromPath($path)) !== null) {
                                $sync[$file->uuid] = [
                                    'order' => count($sync) + 1,
                                    'comment' => '',
                                ];

                                if ($file->internal_path('full') === $file->internal_path('middle')) {
                                    // is image
                                    if (str_starts_with($file->type, 'image/')) {
                                        $this->convertImageUuids[] = $file->uuid;
                                    }
                                }
                            } else {
                                $this->logger->warning('TradeMaster: file not loaded', ['path' => $path]);
                            }
                        }

                        if ($sync) {
                            $entity->files()->sync($sync);
                        }
                    } else {
                        $this->logger->warning('TradeMaster: entity not found and file not loaded', [
                            'type' => $item['type'],
                            'uuid' => $item['uuid'],
                        ]);
                    }
                }

                $this->setProgress($index, count($args['list']));
            }
        }

        if ($this->convertImageUuids) {
            // add task convert
            $task = new \App\Domain\Tasks\ConvertImageTask($this->container);
            $task->execute(['uuid' => $this->convertImageUuids]);

            // run worker
            \App\Domain\AbstractTask::worker($task);
        }

        $this->container->get(\App\Application\PubSub::class)->publish('task:tm:download:image');
        $this->container->get(\App\Application\PubSub::class)->publish('task:catalog:import');

        $this->setStatusDone();
    }
}
