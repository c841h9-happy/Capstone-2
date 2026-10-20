-- Example raw material and two yield-based product recipes.
--
-- Run after sql\inventory-fractional-upgrade.sql and the product/component seeds:
--     mysql -u root capstone < sql\materials-seed.sql
--
-- Safe to run twice. Existing material names and any product that already has a
-- materials recipe are left alone. Stock starts at zero; set the real count in Admin.

USE capstone;

INSERT INTO materials (name, unit)
SELECT 'Red Fuzzy Wire', 'strand'
WHERE NOT EXISTS (
    SELECT 1 FROM materials WHERE name = 'Red Fuzzy Wire'
);

UPDATE products p
JOIN (
    SELECT 'Single Fuzzy Rose' AS name, 'flower' AS kind, 2.000 AS recipe_yield
    UNION ALL SELECT 'Red Rose Half Dozen', 'bouquet', 6.000
) AS seed ON seed.name = p.name AND seed.kind = p.kind
SET p.recipe_yield = seed.recipe_yield
WHERE NOT EXISTS (
    SELECT 1 FROM product_materials pm WHERE pm.product_id = p.id
);

INSERT INTO product_materials (product_id, material_id, quantity)
SELECT p.id, m.id, seed.quantity
FROM (
    SELECT 'Single Fuzzy Rose' AS name, 'flower' AS kind, 1.000 AS quantity
    UNION ALL SELECT 'Red Rose Half Dozen', 'bouquet', 3.000
) AS seed
JOIN products p ON p.name = seed.name AND p.kind = seed.kind
JOIN materials m ON m.name = 'Red Fuzzy Wire'
WHERE NOT EXISTS (
    SELECT 1 FROM product_materials pm WHERE pm.product_id = p.id
);
