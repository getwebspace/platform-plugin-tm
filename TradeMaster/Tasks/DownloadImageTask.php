<?php

namespace Plugin\TradeMaster\Tasks;

use App\Domain\Tasks\Task;

class DownloadImageTask extends Task
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
     * @var \Plugin\TradeMaster\TradeMasterPlugin
     */
    protected $trademaster;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    private $catalogCategoryRepository;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    private $catalogProductRepository;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    protected $fileRepository;

    /**
     * @var array
     */
    private $convertImageUuids = [];

    protected function action(array $args = [])
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->catalogCategoryRepository = $this->entityManager->getRepository(\App\Domain\Entities\Catalog\Category::class);
        $this->catalogProductRepository = $this->entityManager->getRepository(\App\Domain\Entities\Catalog\Product::class);
        $this->fileRepository = $this->entityManager->getRepository(\App\Domain\Entities\File::class);

        if ($this->getParameter('file_is_enabled', 'no') === 'yes') {
            foreach ($args['list'] as $index => $item) {
                if ($item['photo']) {
                    /**
                     * @var \App\Domain\Entities\Catalog\Category|\App\Domain\Entities\Catalog\Product $model
                     */
                    switch ($item['type']) {
                        case 'category':
                            $entity = $this->catalogCategoryRepository->findOneBy(['uuid' => $item['uuid']]);
                            break;
                        case 'product':
                            $entity = $this->catalogProductRepository->findOneBy(['uuid' => $item['uuid']]);
                            break;
                    }

                    if (!empty($entity)) {
                        if ($entity->hasFiles()) {
                            $entity->clearFiles();
                        }

                        foreach (explode(';', $item['photo']) as $name) {
                            $path = $this->trademaster->getFilePath($name);

                            if (($model = \App\Domain\Entities\File::getFromPath($path)) !== null) {
                                $entity->addFile($model);
                                $this->entityManager->persist($model);

                                // is image
                                if (\Alksily\Support\Str::start('image/', $model->type)) {
                                    $this->convertImageUuids[] = $model->uuid;
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
        }

        $this->setStatusDone();
    }
}
