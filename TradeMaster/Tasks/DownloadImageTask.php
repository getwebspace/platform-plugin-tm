<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Service\Catalog\CategoryService;
use App\Domain\Service\Catalog\ProductService;
use App\Domain\Service\File\FileRelationService;
use App\Domain\Service\File\FileService;
use Plugin\TradeMaster\TradeMasterPlugin;

class DownloadImageTask extends AbstractTask
{
    public const TITLE = 'Загрузка изображений из ТМ';

    public function execute(array $params = []): \App\Domain\Entities\Task
    {
        $default = [
            'list' => [
                /*[
                    'photo' => '',
                    'type' => '',
                    'uuid' => '',
                ],*/
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
     * @var FileRelationService
     */
    protected FileRelationService $fileRelationService;

    /**
     * @var array
     */
    private array $convertImageUuids = [];

    protected function action(array $args = []): void
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->categoryService = CategoryService::getWithContainer($this->container);
        $this->productService = ProductService::getWithContainer($this->container);
        $this->fileService = FileService::getWithContainer($this->container);
        $this->fileRelationService = FileRelationService::getWithContainer($this->container);

        if ($this->parameter('file_is_enabled', 'no') === 'yes') {
            foreach ($args['list'] as $index => $item) {
                if ($item['photo']) {
                    /**
                     * @var \App\Domain\Entities\Catalog\Category|\App\Domain\Entities\Catalog\Product $model
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
                        if ($entity->hasFiles()) {
                            $entity->clearFiles();
                        }

                        foreach (explode(';', $item['photo']) as $i => $name) {
                            $path = $this->trademaster->getFilePath($name);

                            if (($file = $this->fileService->createFromPath($path)) !== null) {
                                $this->fileRelationService->create([
                                    'entity' => $entity,
                                    'file' => $file,
                                    'order' => $i + 1,
                                ]);

                                if ($file->getInternalPath('full') === $file->getInternalPath('middle')) {
                                    // is image
                                    if (str_start_with($file->getType(), 'image/')) {
                                        $this->convertImageUuids[] = $file->getUuid();
                                    }
                                }
                            } else {
                                $this->logger->warning('TradeMaster: file not loaded', ['path' => $path]);
                            }
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

        $this->setStatusDone();
    }
}
