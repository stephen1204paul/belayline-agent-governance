<?php

/**
 * Test-only stand-ins for the WooCommerce CRUD classes the Woo change adapters
 * drive (WC_Data and its product, coupon, customer and shipping zone
 * subclasses, WC_Tax). Hand-rolled to mirror the contract the adapters rely on,
 * as verified in WooCommerce source: get_data() is the PERSISTED data,
 * get_changes() the pending edits, set_props() skips null, set_prop() records a
 * change only when the value differs, save() fires the before/after object-save
 * actions and writes the backing post through the same hooks WordPress would,
 * and a product's `price` is recomputed only when a price prop is in the save.
 *
 * Deliberately NOT defining a `WooCommerce` class: WooIntegrationTest relies
 * on class_exists('WooCommerce') being false until it defines its own stub.
 * State lives in $GLOBALS['wc_fake_db'] (group => id => row).
 */

declare(strict_types=1);

if (!function_exists('maybe_unserialize')) {
    /** @param mixed $value @return mixed */
    function maybe_unserialize($value)
    {
        return $value;
    }
}

if (!class_exists('WC_DateTime', false)) {
    class WC_DateTime extends DateTime
    {
    }

    class WC_Meta_Data
    {
        public function __construct(private string $key, private mixed $value)
        {
        }

        /** @return array<string, mixed> */
        public function get_data(): array
        {
            return ['id' => 0, 'key' => $this->key, 'value' => $this->value];
        }
    }

    class WC_Fake_Data_Store
    {
        /** @return list<object> */
        public function read_meta(WC_Data $object): array
        {
            $rows = [];
            foreach ($GLOBALS['wc_fake_db'][$object->fake_group()][$object->get_id()]['meta'] ?? [] as $key => $value) {
                $rows[] = (object) ['meta_key' => $key, 'meta_value' => $value];
            }

            return $rows;
        }
    }

    abstract class WC_Data
    {
        protected string $object_type = 'data';
        protected string $group = 'data';
        protected string $post_type = '';
        protected int $id = 0;
        /** @var array<string, mixed> */
        protected array $data = [];
        /** @var array<string, mixed> */
        protected array $changes = [];
        /** @var array<string, mixed> */
        protected array $meta = [];

        public function __construct(int $id = 0)
        {
            $row = $id > 0 ? ($GLOBALS['wc_fake_db'][$this->group][$id] ?? null) : null;
            if ($row !== null) {
                $this->id = $id;
                $this->data = array_merge($this->data, $row['data']);
                $this->meta = $row['meta'];
            }
        }

        public function fake_group(): string
        {
            return $this->group;
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_type(): string
        {
            return 'simple';
        }

        public function get_data_store(): WC_Fake_Data_Store
        {
            return new WC_Fake_Data_Store();
        }

        /** @return array<string, mixed> */
        public function get_data(): array
        {
            return array_merge(['id' => $this->id], $this->data, ['meta_data' => $this->get_meta_data()]);
        }

        /** @return array<string, mixed> */
        public function get_changes(): array
        {
            return $this->changes;
        }

        /** @return list<WC_Meta_Data> */
        public function get_meta_data(): array
        {
            $out = [];
            foreach ($this->meta as $key => $value) {
                $out[] = new WC_Meta_Data((string) $key, $value);
            }

            return $out;
        }

        public function update_meta_data(string $key, mixed $value): void
        {
            $this->meta[$key] = $value;
        }

        public function delete_meta_data(string $key): void
        {
            unset($this->meta[$key]);
        }

        /** @param array<string, mixed> $props */
        public function set_props(array $props): bool
        {
            foreach ($props as $prop => $value) {
                if ($value === null || $prop === 'meta_data') {
                    continue;
                }
                $this->{'set_' . $prop}($value);
            }

            return true;
        }

        protected function set_prop(string $prop, mixed $value): void
        {
            if ($value !== $this->data[$prop] || array_key_exists($prop, $this->changes)) {
                $this->changes[$prop] = $value;
            }
        }

        /** @param list<mixed> $args */
        public function __call(string $name, array $args): mixed
        {
            if (preg_match('/^set_(billing|shipping)_(.+)$/', $name, $m) === 1) {
                $current = $this->changes[$m[1]] ?? $this->data[$m[1]];
                $current[$m[2]] = $args[0];
                $this->changes[$m[1]] = $current;

                return null;
            }
            if (str_starts_with($name, 'set_') && array_key_exists(substr($name, 4), $this->data)) {
                $this->set_prop(substr($name, 4), $args[0]);

                return null;
            }
            if (str_starts_with($name, 'get_')) {
                return $this->data[substr($name, 4)] ?? null;
            }

            throw new BadMethodCallException($name);
        }

        public function save(): int
        {
            wpas_test_fire('woocommerce_before_' . $this->object_type . '_object_save', $this, null);

            $new = $this->id === 0;
            if ($new) {
                $GLOBALS['wc_fake_seq'] = ($GLOBALS['wc_fake_seq'] ?? 100) + 1;
                $this->id = $GLOBALS['wc_fake_seq'];
            }
            $changed = array_keys($this->changes);
            $this->data = array_replace_recursive($this->data, $this->changes);
            $this->changes = [];
            $this->recompute($new, $changed);
            $GLOBALS['wc_fake_db'][$this->group][$this->id] = ['data' => $this->data, 'meta' => $this->meta, 'class' => static::class];
            $this->writePost($new);

            wpas_test_fire('woocommerce_after_' . $this->object_type . '_object_save', $this, null);

            return $this->id;
        }

        public function delete(bool $force = false): mixed
        {
            $check = apply_filters('woocommerce_pre_delete_' . $this->object_type, null, $this, $force);
            if ($check !== null) {
                return $check;
            }
            if ($this->post_type !== '' && !$force) {
                wpas_test_fire('wp_trash_post', $this->id);
                $this->data['status'] = 'trash';
                $GLOBALS['wc_fake_db'][$this->group][$this->id]['data'] = $this->data;
                $this->writePost(false);

                return true;
            }
            unset($GLOBALS['wc_fake_db'][$this->group][$this->id], $GLOBALS['wpas_test_posts'][$this->id]);
            $this->id = 0;

            return true;
        }

        /** @param list<string> $changed */
        protected function recompute(bool $new, array $changed): void
        {
        }

        protected function writePost(bool $new): void
        {
            if ($this->post_type === '') {
                return;
            }
            $old = $GLOBALS['wpas_test_posts'][$this->id] ?? null;
            $post = (object) [
                'ID' => $this->id,
                'post_title' => (string) ($this->data['name'] ?? $this->data['code'] ?? ''),
                'post_type' => $this->post_type,
                'post_status' => (string) ($this->data['status'] ?? 'publish'),
                'post_password' => '',
            ];
            $GLOBALS['wpas_test_posts'][$this->id] = $post;
            // Like WooCommerce: the post is written (and its hook fired) first, its meta after.
            if ($old === null) {
                wpas_test_fire('wp_insert_post', $this->id, $post, false);
            } else {
                wpas_test_fire('post_updated', $this->id, $post, $old);
            }
            foreach ($this->meta as $key => $value) {
                update_post_meta($this->id, (string) $key, $value);
            }
            update_post_meta($this->id, '_price', (string) ($this->data['price'] ?? ''));
        }
    }

    class WC_Product extends WC_Data
    {
        protected string $object_type = 'product';
        protected string $group = 'product';
        protected string $post_type = 'product';
        /** @var array<string, mixed> */
        protected array $data = [
            'name' => '', 'slug' => '', 'status' => 'publish', 'regular_price' => '', 'sale_price' => '', 'price' => '',
            'date_on_sale_from' => null, 'date_on_sale_to' => null, 'date_modified' => null, 'total_sales' => 0,
            'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'parent_id' => 0,
            'description' => '', 'downloads' => [],
        ];

        public function set_date_on_sale_from(mixed $value): void
        {
            $this->set_prop('date_on_sale_from', empty($value) ? null : new WC_DateTime(is_string($value) ? $value : '@' . $value));
        }

        public function set_date_on_sale_to(mixed $value): void
        {
            $this->set_prop('date_on_sale_to', empty($value) ? null : new WC_DateTime(is_string($value) ? $value : '@' . $value));
        }

        /** The data store derives `price` only when a price prop is part of the save. */
        protected function recompute(bool $new, array $changed): void
        {
            if (!$new && array_intersect(['regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to'], $changed) === []) {
                return;
            }
            $GLOBALS['wc_fake_price_recomputes'] = ($GLOBALS['wc_fake_price_recomputes'] ?? 0) + 1;
            $sale = $this->data['sale_price'];
            $regular = $this->data['regular_price'];
            $this->data['price'] = ($sale !== '' && (float) $sale < (float) $regular) ? $sale : $regular;
        }
    }

    class WC_Product_Variable extends WC_Product
    {
        public function get_type(): string
        {
            return 'variable';
        }
    }

    class WC_Product_Variation extends WC_Product
    {
        protected string $post_type = 'product_variation';

        public function get_type(): string
        {
            return 'variation';
        }

        public function get_parent_id(): int
        {
            return (int) $this->data['parent_id'];
        }
    }

    class WC_Coupon extends WC_Data
    {
        protected string $group = 'coupon';
        protected string $post_type = 'shop_coupon';
        /** @var array<string, mixed> */
        protected array $data = [
            'code' => '', 'amount' => '0', 'status' => 'publish', 'discount_type' => 'fixed_cart', 'usage_count' => 0,
            'date_expires' => null, 'date_modified' => null, 'product_ids' => [], 'used_by' => null,
        ];
    }

    class WC_Customer extends WC_Data
    {
        protected string $object_type = 'customer';
        protected string $group = 'customer';
        /** @var array<string, mixed> */
        protected array $data = [
            'email' => '', 'first_name' => '', 'last_name' => '', 'display_name' => '', 'role' => 'customer', 'username' => '',
            'billing' => ['first_name' => '', 'city' => ''], 'shipping' => ['first_name' => '', 'city' => ''],
            'is_paying_customer' => false, 'date_modified' => null,
        ];

        public function set_password(string $password): void
        {
            $GLOBALS['wc_fake_passwords'][$this->id] = $password;
        }
    }

    class WC_Shipping_Zone extends WC_Data
    {
        protected string $object_type = 'shipping_zone';
        protected string $group = 'zone';
        /** @var array<string, mixed> */
        protected array $data = ['zone_name' => '', 'zone_order' => 0, 'zone_locations' => []];

        public function set_zone_locations(mixed $locations): void
        {
            if ($this->id !== 0) {
                $this->set_prop('zone_locations', $locations);
            }
        }
    }

    class WC_Abstract_Order
    {
        public function __construct(private string $status = 'pending', private int $id = 0)
        {
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function get_total(): string
        {
            return '10.00';
        }
    }

    class WC_Order_Refund extends WC_Abstract_Order
    {
        public function get_amount(): string
        {
            return '4.00';
        }
    }

    class WC_Tax
    {
        /** @return array<string, mixed>|null */
        public static function _get_tax_rate(int $id, string $output = 'ARRAY_A'): ?array
        {
            return $GLOBALS['wc_fake_tax'][$id] ?? null;
        }

        /** @param array<string, mixed> $row */
        public static function _insert_tax_rate(array $row): int
        {
            $GLOBALS['wc_fake_seq'] = ($GLOBALS['wc_fake_seq'] ?? 100) + 1;
            $id = $GLOBALS['wc_fake_seq'];
            $GLOBALS['wc_fake_tax'][$id] = ['tax_rate_id' => $id] + $row;
            wpas_test_fire('woocommerce_tax_rate_added', $id, $row);

            return $id;
        }

        /** @param array<string, mixed> $row */
        public static function _update_tax_rate(int $id, array $row): void
        {
            $GLOBALS['wc_fake_tax'][$id] = array_merge($GLOBALS['wc_fake_tax'][$id] ?? [], $row);
            wpas_test_fire('woocommerce_tax_rate_updated', $id, $row);
        }

        public static function _delete_tax_rate(int $id): void
        {
            unset($GLOBALS['wc_fake_tax'][$id]);
        }

        public static function _update_tax_rate_postcodes(int $id, string $postcodes): void
        {
            $GLOBALS['wc_fake_tax_loc'][$id]['postcode'] = $postcodes;
        }

        public static function _update_tax_rate_cities(int $id, string $cities): void
        {
            $GLOBALS['wc_fake_tax_loc'][$id]['city'] = $cities;
        }
    }

    /** @return WC_Product|false */
    function wc_get_product(int $id)
    {
        $row = $GLOBALS['wc_fake_db']['product'][$id] ?? null;

        return $row === null ? false : new $row['class']($id);
    }

    function wc_get_order(int $id): WC_Abstract_Order
    {
        return new WC_Order_Refund();
    }
}
