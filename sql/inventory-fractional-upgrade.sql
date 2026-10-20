-- Allow fractional stock and material-to-product yields.
--
-- Run after sql\inventory-upgrade.sql:
--     mysql -u root capstone < sql\inventory-fractional-upgrade.sql
--
-- Existing balances, movements and recipes keep their values. Existing recipe rows
-- produce one catalogue unit by default, preserving their current meaning.

USE capstone;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS recipe_yield DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS materials (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name              VARCHAR(120) NOT NULL,
    unit              VARCHAR(40) NOT NULL DEFAULT 'piece',
    stock_on_hand     DECIMAL(12,3) NOT NULL DEFAULT 0,
    low_stock_threshold DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_material_name (name),
    KEY idx_materials_stock (is_active, stock_on_hand)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_materials (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    quantity    DECIMAL(12,3) UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_product_materials (product_id, material_id),
    KEY idx_product_materials_material (material_id),
    CONSTRAINT fk_product_materials_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_product_materials_material
        FOREIGN KEY (material_id) REFERENCES materials (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS material_moves (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    material_id   INT UNSIGNED NULL,
    material_name VARCHAR(120) NOT NULL,
    reason        ENUM('opening','received','used','returned','adjusted','wasted') NOT NULL,
    delta         DECIMAL(12,3) NOT NULL,
    balance_after DECIMAL(12,3) NOT NULL,
    order_id      INT UNSIGNED NULL,
    note          VARCHAR(255) NOT NULL DEFAULT '',
    user_id       INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_material_moves_material (material_id, id),
    KEY idx_material_moves_order (order_id),
    CONSTRAINT fk_material_moves_material
        FOREIGN KEY (material_id) REFERENCES materials (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_material_moves_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_material_moves_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_material_moves_delta CHECK (delta <> 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;