<?php declare(strict_types=1);

namespace Plugin\TradeMaster\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Casts\Catalog\Attribute\Type as AttributeType;
use App\Domain\Casts\Catalog\Status;
use App\Domain\Models\CatalogAttribute;
use App\Domain\Models\CatalogCategory;
use App\Domain\Models\CatalogProduct;
use App\Domain\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Plugin\TradeMaster\TradeMasterPlugin;

/**
 * Pulls the whole TradeMaster catalog into the local one
 *
 * The sync is a diff, not a rebuild: everything already stored is read once into
 * memory, every remote row is turned into the exact database row it would become,
 * and only the rows that actually differ are written - in batches, inside short
 * transactions. Nothing is marked deleted up front, so the storefront keeps
 * serving the previous catalog until the new one is in place
 */
class CatalogDownloadTask extends AbstractTask
{
    public const TITLE = 'Загрузка каталога ТМ';

    /**
     * Remote rows per API request
     */
    protected const PAGE_SIZE = 250;
    protected const RELATED_PAGE_SIZE = 500;

    /**
     * API requests in flight at once
     */
    protected const CONCURRENCY = 4;

    /**
     * Rows per INSERT statement, keeps the bound parameter count well inside
     * what both sqlite and mysql accept
     */
    protected const WRITE_CHUNK = 50;

    /**
     * Columns this task owns
     *
     * Everything else on a product (tax, discount, special, quantity, ...) belongs
     * to the admin panel and must survive a sync untouched
     */
    protected const CATEGORY_COLUMNS = [
        'title', 'address', 'description', 'parent_uuid', 'pagination', 'sort',
        'meta', 'template', 'order', 'specifics', 'status', 'external_id', 'export',
    ];

    protected const PRODUCT_COLUMNS = [
        'title', 'address', 'description', 'extra', 'vendorcode', 'barcode',
        'priceFirst', 'price', 'priceWholesale', 'dimension', 'country',
        'manufacturer', 'stock', 'meta', 'category_uuid', 'order', 'status',
        'external_id', 'export', 'date',
    ];

    protected TradeMasterPlugin $trademaster;

    protected string $link = '';

    protected bool $with_files = false;

    /**
     * Attribute uuid by lowercased title, and by address under an `@` prefix
     */
    private array $attributes = [];

    /**
     * Attribute uuid of the four TradeMaster indexed fields
     */
    private array $fields = [];

    /**
     * Entities whose remote photo list no longer matches what is attached
     */
    private array $images = [];

    protected function action(array $args = []): bool
    {
        $this->trademaster = $this->container->get('TradeMasterPlugin');
        $this->link = (string) $this->parameter('TradeMasterPlugin_category_link', '');
        $this->with_files = $this->parameter('file_is_enabled', 'no') === 'yes';

        try {
            $this->setProgress(1);
            $this->loadAttributes();

            $this->setProgress(5);
            $categories = $this->syncCategories();

            if (!$categories) {
                // an empty answer would wipe the catalog, leave it as it is
                return $this->setStatusFail('TradeMaster: empty catalog list');
            }

            $products = $this->syncProducts($categories);

            $this->setProgress(85);
            $this->syncRelated($products);

            $this->setProgress(95);
            $this->queueTasks();

            $this->logger->info('Task: TradeMaster sync done', [
                'categories' => count($categories),
                'products' => count($products),
                'images' => count($this->images),
            ]);

            $this->setProgress(100);
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage(), [
                'n' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->setStatusFail($exception->getMessage());
        }

        return $this->setStatusDone();
    }

    // attributes

    /**
     * Read every attribute once, so a product never has to look one up
     */
    protected function loadAttributes(): void
    {
        foreach (CatalogAttribute::query()->get(['uuid', 'title', 'address']) as $attribute) {
            $this->attributes[$this->key($attribute->title)] = $attribute->uuid;
            $this->attributes['@' . $attribute->address] = $attribute->uuid;
        }

        for ($i = 1; $i <= 4; $i++) {
            $this->fields[$i] = $this->attribute("field{$i}", "TM: Ind{$i}");
        }
    }

    protected function key(string $title): string
    {
        return mb_strtolower(trim($title));
    }

    /**
     * Uuid of an attribute, created on first sight
     */
    protected function attribute(string $title, string $group, string $type = AttributeType::STRING): ?string
    {
        $key = $this->key($title);

        if (isset($this->attributes[$key])) {
            return $this->attributes[$key];
        }

        $attribute = new CatalogAttribute();
        $attribute->fill([
            'title' => $title,
            'address' => $title,
            'group' => $group,
            'type' => $type,
        ]);

        // the address cast slugifies the title, two titles may well collapse into one
        $address = '@' . $attribute->getAttributes()['address'];

        if (isset($this->attributes[$address])) {
            return $this->attributes[$key] = $this->attributes[$address];
        }

        try {
            $attribute->save();
        } catch (\Throwable $e) {
            $found = CatalogAttribute::query()->where('address', $attribute->getAttributes()['address'])->first();

            if (!$found) {
                $this->logger->warning('TradeMaster: attribute not created', ['title' => $title, 'group' => $group]);

                return null;
            }

            $attribute = $found;
        }

        return $this->attributes[$address] = $this->attributes[$key] = $attribute->uuid;
    }

    // categories

    /**
     * @return array category `['uuid' => .., 'address' => ..]` by remote id
     */
    protected function syncCategories(): array
    {
        $list = $this->trademaster->api([
            'endpoint' => 'catalog/list',
            'params' => ['link' => $this->link],
        ]);

        $this->logger->info('Task: TradeMaster get catalog list', ['count' => count($list)]);

        if (!$list) {
            return [];
        }

        // one bucket per parent turns the recursion below into a single pass
        $tree = [];

        foreach ($list as $item) {
            $tree[(string) ($item['idParent'] ?? 0)][] = $item;
        }

        $existing = $this->db->table('catalog_category')->where('export', 'trademaster')->get()->keyBy('external_id');
        $photos = $this->loadFileNames(CatalogCategory::class);

        $rows = [];
        $categories = [];
        $this->walkCategories($tree, '0', null, $existing, $photos, $rows, $categories);

        $this->db->transaction(function () use ($rows, $existing, $categories): void {
            $this->write('catalog_category', $rows, static::CATEGORY_COLUMNS);

            // categories the API no longer returns
            $seen = array_flip(array_column($categories, 'uuid'));
            $missing = [];

            foreach ($existing as $row) {
                if (!isset($seen[$row->uuid]) && $row->status !== Status::DELETE) {
                    $missing[] = $row->uuid;
                }
            }

            foreach (array_chunk($missing, 500) as $chunk) {
                $this->db->table('catalog_category')->whereIn('uuid', $chunk)->update(['status' => Status::DELETE]);
            }
        });

        $this->attachCategoryAttributes(array_column($categories, 'uuid'));

        $this->logger->info('Task: TradeMaster categories synced', [
            'total' => count($categories),
            'changed' => count($rows),
        ]);

        return $categories;
    }

    private function walkCategories(array $tree, string $parent_id, ?array $parent, Collection $existing, array $photos, array &$rows, array &$categories): void
    {
        foreach ($tree[$parent_id] ?? [] as $item) {
            $id = (string) ($item['idZvena'] ?? '');
            $title = trim((string) ($item['nameZvena'] ?? ''));

            if ($id === '' || $title === '') {
                $this->logger->warning('TradeMaster: category title wrong value', ['item' => $item]);

                continue;
            }

            $description = urldecode((string) ($item['opisanie'] ?? ''));
            $current = $existing[$id] ?? null;
            $uuid = $current->uuid ?? $this->uuid(new CatalogCategory());

            $row = $this->row(new CatalogCategory(), [
                'title' => $title,
                'address' => $title,
                'description' => $description,
                'parent_uuid' => $parent['uuid'] ?? null,
                'pagination' => (int) $this->parameter('catalog_category_pagination', 10),
                'sort' => [
                    'by' => $this->parameter('catalog_sort_by', 'title'),
                    'direction' => $this->parameter('catalog_sort_direction', 'ASC'),
                ],
                'meta' => [
                    'title' => $title,
                    'description' => strip_tags($description),
                ],
                'template' => [
                    'category' => $this->parameter('catalog_category_template', 'catalog.category.twig'),
                    'product' => $this->parameter('catalog_product_template', 'catalog.product.twig'),
                ],
                'order' => (int) ($item['poryadok'] ?? 1),
                'specifics' => [
                    'ind1' => $item['ind1'] ?? '',
                    'ind2' => $item['ind2'] ?? '',
                    'ind3' => $item['ind3'] ?? '',
                ],
                'status' => Status::WORK,
                'external_id' => $id,
                'export' => 'trademaster',
            ], static::CATEGORY_COLUMNS);

            $row['uuid'] = $uuid;

            if (!$current || !$this->same($row, $current)) {
                $rows[] = $row;
            }

            $node = ['uuid' => $uuid, 'address' => $row['address']];
            $categories[$id] = $node;

            $this->queueImage('category', $uuid, (string) ($item['foto'] ?? ''), $photos[$uuid] ?? []);
            $this->walkCategories($tree, $id, $node, $existing, $photos, $rows, $categories);
        }
    }

    /**
     * Make sure every synced category carries the four indexed fields
     */
    protected function attachCategoryAttributes(array $uuids): void
    {
        $attributes = array_values(array_filter($this->fields));

        if (!$uuids || !$attributes) {
            return;
        }

        $attached = [];

        foreach (array_chunk($uuids, 500) as $chunk) {
            foreach ($this->db->table('catalog_attribute_category')->whereIn('category_uuid', $chunk)->get() as $row) {
                $attached[$row->category_uuid . '|' . $row->attribute_uuid] = true;
            }
        }

        $rows = [];

        foreach ($uuids as $uuid) {
            foreach ($attributes as $attribute) {
                if (!isset($attached[$uuid . '|' . $attribute])) {
                    $rows[] = ['category_uuid' => $uuid, 'attribute_uuid' => $attribute];
                }
            }
        }

        foreach (array_chunk($rows, static::WRITE_CHUNK) as $chunk) {
            $this->db->table('catalog_attribute_category')->insert($chunk);
        }
    }

    // products

    /**
     * @return array product uuid by remote id
     */
    protected function syncProducts(array $categories): array
    {
        $response = $this->trademaster->api([
            'endpoint' => 'item/count',
            'params' => ['link' => $this->link],
        ]);
        $count = (int) ($response['count'] ?? 0);

        $this->logger->info('Task: TradeMaster get product count', ['count' => $count]);

        if ($count <= 0) {
            return [];
        }

        $existing = $this->db->table('catalog_product')->where('export', 'trademaster')->get()->keyBy('external_id');
        $pivots = $this->loadProductAttributes();
        $photos = $this->loadFileNames(CatalogProduct::class);

        $index = [];
        $complete = true;
        $pages = (int) ceil($count / static::PAGE_SIZE);
        $storage = $this->parameter('TradeMasterPlugin_storage', 0);

        for ($page = 0; $page < $pages; $page += static::CONCURRENCY) {
            $requests = [];

            for ($i = $page; $i < min($pages, $page + static::CONCURRENCY); $i++) {
                $requests[] = [
                    'endpoint' => 'item/list',
                    'params' => [
                        'sklad' => $storage,
                        'offset' => $i * static::PAGE_SIZE,
                        'limit' => static::PAGE_SIZE,
                        'link' => $this->link,
                    ],
                ];
            }

            foreach ($this->trademaster->apiBatch($requests, null, static::CONCURRENCY) as $list) {
                if ($list === null) {
                    // a page we never got back is not a page of deleted products
                    $complete = false;

                    continue;
                }

                $this->processProducts($list, $categories, $existing, $pivots, $photos, $index);
            }

            $this->setProgress(min(80, 20 + (int) round(($page + static::CONCURRENCY) / $pages * 60)));
        }

        // products the API no longer returns, but only when we saw all of it
        $missing = [];

        if ($complete) {
            $seen = array_flip($index);

            foreach ($existing as $row) {
                if (!isset($seen[$row->uuid]) && $row->status !== Status::DELETE) {
                    $missing[] = $row->uuid;
                }
            }

            foreach (array_chunk($missing, 500) as $chunk) {
                $this->db->table('catalog_product')->whereIn('uuid', $chunk)->update(['status' => Status::DELETE]);
            }
        } else {
            $this->logger->warning('Task: TradeMaster product list incomplete, nothing removed');
        }

        $this->logger->info('Task: TradeMaster products synced', [
            'total' => count($index),
            'removed' => count($missing),
        ]);

        return $index;
    }

    private function processProducts(array $list, array $categories, Collection $existing, array &$pivots, array $photos, array &$index): void
    {
        $rows = [];
        $clear = [];
        $values = [];
        $now = datetime();

        foreach ($list as $item) {
            $id = (string) ($item['idTovar'] ?? '');
            $title = trim((string) ($item['name'] ?? ''));
            $category = $categories[(string) ($item['vStrukture'] ?? '')] ?? null;

            if ($id === '' || isset($index[$id])) {
                continue;
            }
            if ($title === '') {
                $this->logger->warning('TradeMaster: product title wrong value', ['external_id' => $id]);

                continue;
            }
            if (!$category) {
                $this->logger->warning('TradeMaster: category not found', [
                    'title' => $title,
                    'vStrukture' => $item['vStrukture'] ?? '',
                    'external_id' => $id,
                ]);

                continue;
            }

            $current = $existing[$id] ?? null;
            $uuid = $current->uuid ?? $this->uuid(new CatalogProduct());
            $index[$id] = $uuid;

            $description = trim(urldecode((string) ($item['opisanie'] ?? '')));

            $row = $this->row(new CatalogProduct(), [
                'title' => $title,
                'address' => $category['address'] . '/' . $title,
                'description' => $description,
                'extra' => trim(urldecode((string) ($item['opisanieDop'] ?? ''))),
                'vendorcode' => (string) ($item['artikul'] ?? ''),
                'barcode' => (string) ($item['strihKod'] ?? ''),
                'priceFirst' => $item['sebestomost'] ?? 0,
                'price' => $item['price'] ?? 0,
                'priceWholesale' => $item['opt_price'] ?? 0,
                'dimension' => [
                    'weight' => (float) ($item['ves'] ?? 0),
                    'weight_class' => rtrim((string) ($item['edIzmer'] ?? ''), '.'),
                ],
                'country' => (string) ($item['strana'] ?? ''),
                'manufacturer' => (string) ($item['proizv'] ?? ''),
                'stock' => $item['kolvo'] ?? 0,
                'meta' => [
                    'title' => $title,
                    'description' => strip_tags($description),
                ],
                'category_uuid' => $category['uuid'],
                'order' => (int) ($item['poryadok'] ?? 1),
                'status' => Status::WORK,
                'external_id' => $id,
                'export' => 'trademaster',
                'date' => $now,
            ], static::PRODUCT_COLUMNS);

            $row['uuid'] = $uuid;

            if (!$current || !$this->same($row, $current)) {
                $rows[] = $row;
            }

            $attributes = $this->productAttributes($item);

            if (($pivots[$uuid] ?? []) != $attributes) {
                $pivots[$uuid] = $attributes;
                $clear[] = $uuid;

                foreach ($attributes as $attribute => $value) {
                    $values[] = ['product_uuid' => $uuid, 'attribute_uuid' => $attribute, 'value' => $value];
                }
            }

            $this->queueImage('product', $uuid, (string) ($item['foto'] ?? ''), $photos[$uuid] ?? []);
        }

        if (!$rows && !$clear) {
            return;
        }

        $this->db->transaction(function () use ($rows, $clear, $values): void {
            $this->write('catalog_product', $rows, static::PRODUCT_COLUMNS);

            foreach (array_chunk($clear, 500) as $chunk) {
                $this->db->table('catalog_attribute_product')->whereIn('product_uuid', $chunk)->delete();
            }
            foreach (array_chunk($values, static::WRITE_CHUNK) as $chunk) {
                $this->db->table('catalog_attribute_product')->insert($chunk);
            }
        });
    }

    /**
     * `ind1`..`ind4` are plain values, `ind5` is a comma separated list of flags
     */
    protected function productAttributes(array $item): array
    {
        $output = [];

        for ($n = 1; $n <= 4; $n++) {
            $value = trim((string) ($item["ind{$n}"] ?? ''));

            if ($value !== '' && !empty($this->fields[$n])) {
                $output[$this->fields[$n]] = $value;
            }
        }

        foreach (explode(',', (string) ($item['ind5'] ?? '')) as $name) {
            $name = mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');

            if ($name !== '' && ($uuid = $this->attribute($name, 'TM: Ind5', AttributeType::BOOLEAN))) {
                $output[$uuid] = 'yes';
            }
        }

        return $output;
    }

    protected function loadProductAttributes(): array
    {
        $output = [];

        $rows = $this->db
            ->table('catalog_attribute_product as cap')
            ->join('catalog_product as cp', 'cp.uuid', '=', 'cap.product_uuid')
            ->where('cp.export', 'trademaster')
            ->get(['cap.product_uuid', 'cap.attribute_uuid', 'cap.value']);

        foreach ($rows as $row) {
            $output[$row->product_uuid][$row->attribute_uuid] = (string) $row->value;
        }

        return $output;
    }

    // related products

    /**
     * @param array $products product uuid by remote id
     */
    protected function syncRelated(array $products): void
    {
        if (!$products) {
            return;
        }

        $desired = [];
        $offset = 0;
        $done = false;

        while (!$done) {
            $requests = [];

            for ($i = 0; $i < static::CONCURRENCY; $i++) {
                $requests[] = [
                    'endpoint' => 'item/soput',
                    'params' => [
                        'offset' => $offset + $i * static::RELATED_PAGE_SIZE,
                        'limit' => static::RELATED_PAGE_SIZE,
                    ],
                ];
            }

            foreach ($this->trademaster->apiBatch($requests, null, static::CONCURRENCY) as $list) {
                if ($list === null) {
                    // half the relations would look like relations to remove
                    $this->logger->warning('Task: TradeMaster related list incomplete, skipped');

                    return;
                }

                // a short page means the last one was reached
                if (count($list) < static::RELATED_PAGE_SIZE) {
                    $done = true;
                }

                foreach ($list as $item) {
                    $product = $products[(string) ($item['idTovar1'] ?? '')] ?? null;
                    $related = $products[(string) ($item['idTovar2'] ?? '')] ?? null;
                    $count = (float) ($item['kolvo'] ?? 0);

                    if ($product && $related && $product !== $related && $count > 0) {
                        $desired[$product][$related] = $count;
                    }
                }
            }

            $offset += static::CONCURRENCY * static::RELATED_PAGE_SIZE;
        }

        $existing = [];

        $rows = $this->db
            ->table('catalog_product_related as r')
            ->join('catalog_product as p', 'p.uuid', '=', 'r.product_uuid')
            ->where('p.export', 'trademaster')
            ->get(['r.product_uuid', 'r.related_uuid', 'r.count']);

        foreach ($rows as $row) {
            $existing[$row->product_uuid][$row->related_uuid] = (float) $row->count;
        }

        $clear = [];
        $values = [];

        foreach (array_keys($desired + $existing) as $uuid) {
            $want = $desired[$uuid] ?? [];

            if ($want == ($existing[$uuid] ?? [])) {
                continue;
            }

            $clear[] = $uuid;

            foreach ($want as $related => $count) {
                $values[] = ['product_uuid' => $uuid, 'related_uuid' => $related, 'count' => $count];
            }
        }

        $this->logger->info('Task: TradeMaster related synced', ['changed' => count($clear)]);

        if (!$clear) {
            return;
        }

        $this->db->transaction(function () use ($clear, $values): void {
            foreach (array_chunk($clear, 500) as $chunk) {
                $this->db->table('catalog_product_related')->whereIn('product_uuid', $chunk)->delete();
            }
            foreach (array_chunk($values, static::WRITE_CHUNK) as $chunk) {
                $this->db->table('catalog_product_related')->insert($chunk);
            }
        });
    }

    // images

    /**
     * Remember an entity whose photos differ from what is already attached
     *
     * Re-downloading every image of every product on every sync is what used to
     * make the file store the slowest part of the job
     */
    protected function queueImage(string $type, string $uuid, string $photo, array $attached): void
    {
        if (!$this->with_files || $photo === '') {
            return;
        }

        $names = [];

        foreach (explode(';', $photo) as $name) {
            if (($name = trim($name)) !== '') {
                $names[] = $this->filename($name);
            }
        }

        if (!$names || $names === $attached) {
            return;
        }

        $this->images[] = ['photo' => $photo, 'type' => $type, 'uuid' => $uuid];
    }

    /**
     * Name a remote file gets once it is stored locally
     */
    protected function filename(string $name): string
    {
        $info = pathinfo($name);
        $ext = isset($info['extension']) ? mb_strtolower($info['extension']) : '';

        return File::prepareName($info['filename'] ?? $name) . ($ext ? '.' . $ext : '');
    }

    /**
     * @return array attached file names by entity uuid, in attachment order
     */
    protected function loadFileNames(string $type): array
    {
        $output = [];

        $rows = $this->db
            ->table('file_related as fr')
            ->join('file as f', 'f.uuid', '=', 'fr.file_uuid')
            ->where('fr.object_type', $type)
            ->orderBy('fr.order')
            ->get(['fr.entity_uuid', 'f.name', 'f.ext']);

        foreach ($rows as $row) {
            $output[$row->entity_uuid][] = $row->name . ($row->ext ? '.' . $row->ext : '');
        }

        return $output;
    }

    protected function queueTasks(): void
    {
        if ($this->images) {
            $task = new DownloadImageTask($this->container);
            $task->execute(['list' => $this->images]);

            AbstractTask::worker($task);
        }

        if ($this->parameter('TradeMasterPlugin_search', 'off') === 'on') {
            $task = new \App\Domain\Tasks\SearchIndexTask($this->container);
            $task->execute();

            AbstractTask::worker($task);
        }
    }

    // storage helpers

    /**
     * Turn a set of values into the exact database row the model would store,
     * without touching the database
     */
    protected function row(Model $model, array $data, array $columns): array
    {
        $model->fill($data);

        return array_intersect_key($model->getAttributes(), array_flip($columns));
    }

    protected function uuid(Model $model): string
    {
        return $model->newUniqueId();
    }

    /**
     * Is the stored row already what we are about to write?
     */
    protected function same(array $row, object $current): bool
    {
        foreach ($row as $column => $value) {
            if ($column === 'uuid' || $column === 'date') {
                continue;
            }

            $before = $current->{$column} ?? null;

            if ((string) $before === (string) $value) {
                continue;
            }

            // decimals come back from the driver formatted, 10 and 10.00 are equal
            if (is_numeric($before) && is_numeric($value) && (float) $before === (float) $value) {
                continue;
            }

            return false;
        }

        return true;
    }

    protected function write(string $table, array $rows, array $columns): void
    {
        foreach (array_chunk($rows, static::WRITE_CHUNK) as $chunk) {
            $this->db->table($table)->upsert($chunk, ['uuid'], $columns);
        }
    }
}
