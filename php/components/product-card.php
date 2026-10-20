<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/products.php';

/**
 * One product as a tile: picture, kind, name, price.
 *
 * Used by the catalogue grid in php/products.php and the featured row on
 * php/index.php, and it is the thing to reuse anywhere else that lists products --
 * a second, slightly different card is how two pages end up disagreeing about what
 * a product costs.
 *
 * $product is a row from fetchProducts(): id, name, kind, price, image_path.
 *
 * PROMO. When an event covers this product, fetchProducts() has already replaced `price` with the
 * discounted figure and left the original in `list_price` -- see php/config/events.php. So this card
 * prints `price` exactly as it always did, and the only thing it adds is the struck-through original
 * and a badge beside it. The card is not where the discount is decided, and nothing here has to know
 * which event is on.
 *
 * NOTE THE (string) CASTS ON EVERY escape() CALL. This file declares strict_types, and
 * escape() in config/auth.php is `escape(string $value)` -- so handing it the integer
 * promo_percent is a TypeError, which is a FATAL raised half way through rendering a card. The
 * response has already begun, so it still arrives as a 200 with the body simply truncated at the
 * first discounted tile, and a test that only checks the status code reports a healthy page. That
 * is exactly what happened on 2026-10-07 and it cost three failing assertions to find.
 *
 * The whole tile is one link rather than just the name. On a phone the name is a
 * couple of centimetres of tappable text in a tile several times that size, and
 * every miss looks like the page is broken.
 */
function renderProductCard(array $product): void
{
    $imageUrl = productImageUrl($product);
    $name = (string) $product['name'];

    // (int) rather than a truthy check: promo_percent is 0 on every row with no promo, and a row
    // from an older caller that never went through decorateProductPricing() has no such key at all.
    $percent = (int) ($product['promo_percent'] ?? 0);
    $listPrice = (string) ($product['list_price'] ?? $product['price']);
    ?>
    <article class="product-card">
        <a class="product-card-link" href="<?php echo escape(url('php/product.php?id=' . (int) $product['id'])); ?>">
            <div class="product-card-media">
                <?php if ($percent > 0): ?>
                    <p class="product-card-promo"><?php echo escape((string) $percent); ?>% off</p>
                <?php endif; ?>
                <?php if ($imageUrl !== null): ?>
                    <!-- The name is the alt text, and the name is also printed below.
                         That reads as a duplicate to a screen reader, so the image is
                         marked decorative instead -- there is nothing in the picture
                         that the caption does not already say. -->
                    <img src="<?php echo escape($imageUrl); ?>" alt="" loading="lazy">
                <?php else: ?>
                    <!--
                        No photographs exist yet -- image_path is empty on every
                        seeded row, and filling it is the Products module's job along
                        with upload. A drawn placeholder is deliberate: an <img> with
                        a path that goes nowhere gives a broken-image icon, which
                        looks like a bug rather than like a photo that has not been
                        taken.
                    -->
                    <span class="product-card-placeholder" aria-hidden="true">
                        <svg viewBox="0 0 48 48" width="56" height="56" fill="none"
                             stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                            <path d="M24 27 V43"/>
                            <circle cx="24" cy="11" r="6"/>
                            <circle cx="32.6" cy="17.2" r="6"/>
                            <circle cx="29.3" cy="27.3" r="6"/>
                            <circle cx="18.7" cy="27.3" r="6"/>
                            <circle cx="15.4" cy="17.2" r="6"/>
                            <circle cx="24" cy="20" r="4" fill="currentColor" stroke="none"/>
                        </svg>
                    </span>
                <?php endif; ?>
            </div>

            <div class="product-card-body">
                <p class="product-card-kind"><?php echo escape(productKindLabel((string) $product['kind'])); ?></p>
                <h3 class="product-card-name"><?php echo escape($name); ?></h3>
                <?php if ($percent > 0): ?>
                    <p class="product-card-price product-card-price-promo">
                        <?php echo escape(formatPrice((string) $product['price'])); ?>
                        <!--
                            <s> and not CSS line-through alone: the old price being superseded is a
                            fact about the content, not a decoration, and a screen reader announcing
                            two prices with no indication which one applies is the one way this
                            badge could mislead rather than inform. The visually-hidden word is what
                            makes it read correctly out loud -- and the class is .visually-hidden,
                            not .sr-only, which does not exist in this project.
                        -->
                        <span class="visually-hidden">reduced from</span>
                        <s class="product-card-was"><?php echo escape(formatPrice($listPrice)); ?></s>
                    </p>
                <?php else: ?>
                    <p class="product-card-price"><?php echo escape(formatPrice((string) $product['price'])); ?></p>
                <?php endif; ?>
            </div>
        </a>
    </article>
    <?php
}
