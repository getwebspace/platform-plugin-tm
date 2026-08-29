<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Models\CatalogCategory;
use App\Domain\Models\CatalogProduct;
use App\Domain\Models\File;
use App\Domain\Service\File\FileService;
use Plugin\TradeMaster\TradeMasterPlugin;

/**
 * Downloads the photos the catalog sync found to be out of date
 *
 * Every remote file is fetched once no matter how many products share it, and the
 * fetches run several at a time - the job is network bound, one file at a time is
 * what used to make it take hours
 */
class DownloadImageTask extends AbstractTask
{
    public const TITLE = 'Загрузка изображений из ТМ';

    /**
     * Files downloaded at once
     */
    protected const CONCURRENCY = 8;

    protected const CONNECT_TIMEOUT = 10;
    protected const TIMEOUT = 60;

    /**
     * Entity type of a queue entry
     */
    protected const MODELS = [
        'category' => CatalogCategory::class,
        'product' => CatalogProduct::class,
    ];

    protected TradeMasterPlugin $trademaster;

    protected FileService $fileService;

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

    protected function action(array $args = []): void
    {
        if ($this->parameter('file_is_enabled', 'no') !== 'yes') {
            $this->setStatusCancel('File storage is disabled');

            return;
        }

        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->fileService = $this->container->get(FileService::class);

        [$entities, $names] = $this->prepare((array) ($args['list'] ?? []));

        if (!$entities) {
            $this->setStatusDone();

            return;
        }

        $this->logger->info('Task: TradeMaster download images', [
            'entities' => count($entities),
            'files' => count($names),
        ]);

        $files = $this->store($names);
        $convert = [];

        foreach ($files as $file) {
            // no resized copy yet, hand it over to the image processor
            if (str_starts_with($file->type, 'image/') && $file->internal_path('middle') === $file->internal_path()) {
                $convert[] = $file->uuid;
            }
        }

        $this->attach($entities, $files);

        if ($convert) {
            $task = new \App\Domain\Tasks\ConvertImageTask($this->container);
            $task->execute(['uuid' => $convert]);

            AbstractTask::worker($task);
        }

        $this->container->get(\App\Application\PubSub::class)->publish('task:tm:download:image');
        $this->container->get(\App\Application\PubSub::class)->publish('task:catalog:import');

        $this->setStatusDone();
    }

    /**
     * Split the queue into entities and the set of files they need
     *
     * @return array [list of `['type' => .., 'uuid' => .., 'names' => [..]]`, unique remote names]
     */
    protected function prepare(array $list): array
    {
        $entities = [];
        $names = [];

        foreach ($list as $item) {
            $type = (string) ($item['type'] ?? '');
            $uuid = (string) ($item['uuid'] ?? '');
            $photo = (string) ($item['photo'] ?? '');

            if ($uuid === '' || $photo === '' || !isset(static::MODELS[$type])) {
                continue;
            }

            $entity = ['type' => $type, 'uuid' => $uuid, 'names' => []];

            foreach (explode(';', $photo) as $name) {
                if (($name = trim($name)) !== '') {
                    $entity['names'][] = $name;
                    $names[$name] = true;
                }
            }

            if ($entity['names']) {
                $entities[] = $entity;
            }
        }

        return [$entities, array_keys($names)];
    }

    /**
     * Download and store every file
     *
     * @return array<string, File> stored file by remote name
     */
    protected function store(array $names): array
    {
        $output = [];
        $chunks = array_chunk($names, static::CONCURRENCY);

        foreach ($chunks as $index => $chunk) {
            foreach ($this->download($chunk) as $name => $path) {
                $file = $this->fileService->createFromPath($path, $name);

                if ($file) {
                    $output[$name] = $file;
                } else {
                    @unlink($path);
                    $this->logger->warning('TradeMaster: file not stored', ['name' => $name]);
                }
            }

            $this->setProgress($index + 1, count($chunks));
        }

        return $output;
    }

    /**
     * Fetch a batch of remote files into the cache directory
     *
     * @return array<string, string> local temporary path by remote name
     */
    protected function download(array $names): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($names as $name) {
            $path = CACHE_DIR . '/tm_' . uniqid('', true);
            $stream = @fopen($path, 'wb');

            if (!$stream) {
                continue;
            }

            $handle = curl_init();
            curl_setopt_array($handle, [
                CURLOPT_URL => $this->trademaster->getFilePath($name),
                CURLOPT_FILE => $stream,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => static::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => static::TIMEOUT,
            ]);

            $handles[$name] = [$handle, $stream, $path];
            curl_multi_add_handle($multi, $handle);
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $output = [];

        foreach ($handles as $name => [$handle, $stream, $path]) {
            $failed = curl_errno($handle) !== 0 || (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200;

            curl_multi_remove_handle($multi, $handle);
            fclose($stream);

            if ($failed || !@filesize($path)) {
                @unlink($path);
                $this->logger->warning('TradeMaster: file not loaded', ['name' => $name]);

                continue;
            }

            $output[$name] = $path;
        }

        curl_multi_close($multi);

        return $output;
    }

    /**
     * Replace the file list of every entity in one pass per table
     *
     * @param array<string, File> $files
     */
    protected function attach(array $entities, array $files): void
    {
        $detach = [];
        $rows = [];

        foreach ($entities as $entity) {
            $model = static::MODELS[$entity['type']];
            $attached = [];

            foreach ($entity['names'] as $name) {
                $file = $files[$name] ?? null;

                // keep whatever is attached when nothing could be downloaded
                if ($file && !isset($attached[$file->uuid])) {
                    $attached[$file->uuid] = true;
                    $rows[] = [
                        'file_uuid' => $file->uuid,
                        'entity_uuid' => $entity['uuid'],
                        'object_type' => $model,
                        'order' => count($attached),
                        'comment' => '',
                    ];
                }
            }

            if ($attached) {
                $detach[$model][] = $entity['uuid'];
            }
        }

        if (!$rows) {
            return;
        }

        $this->db->transaction(function () use ($detach, $rows): void {
            foreach ($detach as $model => $uuids) {
                foreach (array_chunk($uuids, 500) as $chunk) {
                    $this->db->table('file_related')
                        ->where('object_type', $model)
                        ->whereIn('entity_uuid', $chunk)
                        ->delete();
                }
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                $this->db->table('file_related')->insert($chunk);
            }
        });
    }
}
