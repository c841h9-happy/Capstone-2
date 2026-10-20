<?php

declare(strict_types=1);

/*
 * components.php is required for fetchComponentsByKind(), componentShape(),
 * componentColourHex(), componentKindLabel() and -- through products.php, which it pulls
 * in -- formatPrice() and CURRENCY_SYMBOL. Nothing here reads the database itself; it is
 * given rows and asked questions about them.
 */
require_once __DIR__ . '/components.php';

/**
 * Turning a query string into a bouquet, and a bouquet into a price.
 *
 * Read-only, and pure apart from taking the parts list as an argument rather than fetching
 * it: that is what lets the whole file be exercised from the CLI with rows made up by hand,
 * and it is why php/customize.php reads the database once at the top and then asks these
 * questions. Saving a design is php/config/designs.php, which is built on top of this file
 * and is the only one of the two that writes.
 *
 * A *design* is one array, and every function here takes or returns it:
 *
 *     [
 *         'blooms' => [ ['part' => <row>, 'quantity' => 4], ['part' => <row>, 'quantity' => 2] ],
 *         'wire' => <row>|null, 'wrap' => <row>|null, 'accent' => <row>|null,
 *         'arrangement' => 'dome',
 *     ]
 *
 * One array rather than a selection plus a loose count, because a bouquet may mix flower
 * heads -- four roses and two sunflowers -- and the flower count is then the sum of the
 * quantities rather than a number of its own. Storing both would be two values free to
 * disagree about the same bouquet, which is the bug DECIMAL(10,2) was chosen to avoid,
 * one field along.
 *
 * The arithmetic is duplicated in js/customizer.js so the total moves while somebody
 * clicks. This copy is the one that decides: the figure a page ships with comes from
 * designTotal(), and the browser only ever redraws it.
 */

/**
 * How many flower heads a bouquet may have, counting every kind in it.
 *
 * One, because a single stem is a real thing to build and the picker should not refuse it.
 * Twenty-four because the spirals the renderer places stems on stop reading as a bouquet
 * somewhere past two dozen, and because a number box with no ceiling is an invitation to
 * type 100000 and watch the browser stop.
 *
 * Six is the default for the same reason the seed has three roses: it is what a small
 * hand-tied bouquet actually is, and it is enough stems to show that the spiral works.
 */
const CUSTOM_BLOOM_MIN = 1;
const CUSTOM_BLOOM_MAX = 24;
const CUSTOM_BLOOM_DEFAULT = 6;

/**
 * How many *different* flower heads one bouquet may hold.
 *
 * Four rather than unbounded. Past that the mix stops being a design decision and becomes a
 * list -- and the picker would need a scrollbar of quantity boxes, each of which is a way to
 * accidentally build a bouquet of one of everything. Four is also what the seed can show off:
 * seven blooms, so there is a real choice to make about which four.
 */
const CUSTOM_MIX_MAX = 4;

/**
 * How the stems are placed. Renderer-only: not one of these changes the price by a centavo.
 *
 * 'dome' first and the default, because it is the arrangement the builder shipped with -- a
 * phyllotaxis fan -- so a design saved or shared before this existed looks exactly as it did.
 */
const DESIGN_ARRANGEMENTS = ['dome', 'round', 'loose'];
const DESIGN_ARRANGEMENT_DEFAULT = 'dome';

/**
 * What each arrangement is called on the page, and what it does.
 *
 * Names a florist would use rather than the geometry underneath: nobody picks
 * "phyllotaxis fan" off a form.
 */
const DESIGN_ARRANGEMENT_LABELS = [
    'dome' => 'Domed',
    'round' => 'Round posy',
    'loose' => 'Loose and airy',
];

const DESIGN_ARRANGEMENT_HINTS = [
    'dome' => 'Taller in the middle, falling away at the edges.',
    'round' => 'Tight and even, every flower at the same height.',
    'loose' => 'Stems at different heights, with room between them.',
];

/**
 * Which arrangement was asked for, or the default for anything else.
 *
 * Same shape as componentKindFilter(): a key into a fixed list, so a hand-edited URL cannot
 * put an unknown value anywhere near the renderer or the ENUM in saved_designs.
 */
function designArrangement(mixed $arrangement): string
{
    if (!is_string($arrangement) || !in_array($arrangement, DESIGN_ARRANGEMENTS, true)) {
        return DESIGN_ARRANGEMENT_DEFAULT;
    }

    return $arrangement;
}

/**
 * What an arrangement is called, falling back to the default's label rather than to ''.
 */
function designArrangementLabel(string $arrangement): string
{
    return DESIGN_ARRANGEMENT_LABELS[$arrangement]
        ?? DESIGN_ARRANGEMENT_LABELS[DESIGN_ARRANGEMENT_DEFAULT];
}

/**
 * What each kind is charged for: once for the bouquet, or once per flower.
 *
 * A bloom is a flower head and a wire is the stem under it, so a design with six flowers
 * buys six of each. A wrap is the paper around the whole bunch and an accent is the ribbon
 * tying it, and a bouquet has one of each however many flowers are in it.
 *
 * Written down as a map rather than an if inside designTotal(), because the same rule has to
 * be stated in the breakdown the page prints and mirrored in js/customizer.js. Three copies
 * of "bloom and wire are per stem" is three places for it to drift.
 */
const DESIGN_PER_STEM_KINDS = ['bloom', 'wire'];

/**
 * True when this kind is charged once per flower rather than once per bouquet.
 */
function designKindIsPerStem(string $kind): bool
{
    return in_array($kind, DESIGN_PER_STEM_KINDS, true);
}

/**
 * A number of flowers, clamped, given whatever arrived in the query string.
 *
 * Clamped rather than refused. This is a URL people edit by hand and paste to each other,
 * and ?count=99 is a request for a big bouquet, not an attack -- answering it with the
 * largest bouquet there is, is more useful than an error page. Junk falls to the default for
 * the same reason: the page always has a bouquet on it.
 *
 * filter_var with FILTER_VALIDATE_INT rather than (int): '6' becomes 6, but 'abc' becomes
 * false rather than 0, and '6.7' becomes false rather than silently 6. Every one of those is
 * a design nobody asked for, so all of them land on the default.
 *
 * An array and a bool are turned away before filter_var sees them. Neither can arrive from a
 * query string -- ?count[]=6 is an array, but PHP hands it over as one and filter_var would
 * raise a notice on it, while a bool cannot be typed into a URL at all. They are refused
 * because the signature says mixed, and filter_var reads true as the number 1, which would
 * quietly answer "yes, some flowers" with the smallest bouquet there is.
 */
function customBloomCount(mixed $count): int
{
    if (is_array($count) || is_bool($count)) {
        return CUSTOM_BLOOM_DEFAULT;
    }

    $parsed = filter_var($count, FILTER_VALIDATE_INT);

    if ($parsed === false) {
        return CUSTOM_BLOOM_DEFAULT;
    }

    return max(CUSTOM_BLOOM_MIN, min(CUSTOM_BLOOM_MAX, $parsed));
}

/**
 * One chosen part per kind, or null for a kind with nothing to choose from.
 *
 * $parts is fetchComponentsByKind()'s output -- every kind present as a key, withdrawn parts
 * already gone. $query is $_GET.
 *
 * An id that is not in $parts falls back to the first part of that kind rather than erroring.
 * There are three ways to arrive at one and none of them is a mistake worth a failure: a
 * hand-edited URL, a shared link naming a part the shop has since withdrawn, and an id that
 * belongs to a real part of a *different* kind (?bloom=8, where 8 is a wire). That last one is
 * why the lookup is inside the kind's own list rather than against the whole table -- a wire
 * chosen as a bloom would otherwise be drawn as a flower head.
 *
 * A kind with no offered parts at all yields null, and every caller has to cope with that: an
 * admin may withdraw every accent, and the builder should then be a bouquet with no ribbon
 * rather than a 500.
 *
 * Its 'bloom' entry is the single flower head a link from before mixing existed asks for.
 * readBloomMix() uses it as the fallback for exactly that reason.
 *
 * @param array<string, array<int, array<string, mixed>>> $parts
 * @return array<string, array<string, mixed>|null>
 */
function readDesignSelection(array $query, array $parts): array
{
    $selection = [];

    foreach (COMPONENT_KINDS as $kind) {
        $available = $parts[$kind] ?? [];

        if ($available === []) {
            $selection[$kind] = null;
            continue;
        }

        // Not (int): an array in the query string -- ?bloom[]=1 -- would raise a notice and
        // cast to 1, which is a real part chosen by a request that never named it.
        $wanted = is_array($query[$kind] ?? null)
            ? false
            : filter_var($query[$kind] ?? null, FILTER_VALIDATE_INT);

        $selection[$kind] = $available[0];

        if ($wanted === false) {
            continue;
        }

        foreach ($available as $part) {
            if ((int) $part['id'] === $wanted) {
                $selection[$kind] = $part;
                break;
            }
        }
    }

    return $selection;
}

/**
 * The flower heads asked for, as id => quantity, exactly as requested and not yet checked.
 *
 * Two shapes are accepted under the one name, because one of them has to be something a
 * plain HTML form can submit and the other has to be something a person can paste:
 *
 *   ?mix[1]=4&mix[5]=2   an array. What the picker's own quantity boxes are named, so the
 *                        no-JavaScript Update button produces it, and what designUrl()
 *                        writes so the form and the share link agree.
 *   ?mix=1x4,5x2         a string. Short enough to read in a chat window, and short enough
 *                        to edit by hand, which people do.
 *
 * A bare id -- ?mix=5 -- is read as one of part 5. It is ambiguous (five of something, or
 * part five?) and this is the reading that cannot silently produce a bouquet of a part
 * nobody named.
 *
 * Nothing here consults the parts list: unknown ids, ceilings and the mix limit are
 * readBloomMix()'s job. This function only answers "what did the request ask for".
 *
 * @return array<int, int> id => quantity, in the order asked for
 */
function bloomMixWanted(mixed $mix): array
{
    $pairs = [];

    if (is_array($mix)) {
        foreach ($mix as $id => $quantity) {
            // A nested array -- ?mix[1][]=4 -- is not a quantity. filter_var would raise a
            // notice on it, so it is turned away here, as customBloomCount() does.
            if (is_array($quantity) || is_bool($quantity)) {
                continue;
            }

            $pairs[] = [$id, $quantity];
        }
    } elseif (is_string($mix) && $mix !== '') {
        foreach (explode(',', $mix) as $item) {
            $bits = preg_split('/x/i', trim($item), 2);
            $pairs[] = [$bits[0] ?? '', $bits[1] ?? '1'];
        }
    }

    $wanted = [];

    foreach ($pairs as [$rawId, $rawQuantity]) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1 || $quantity === false || $quantity < 1) {
            continue;
        }

        // The same part named twice adds up rather than the second winning. ?mix=1x2,1x2 is
        // four of part one, which is the only reading that does not throw half the request
        // away without saying so.
        $wanted[$id] = ($wanted[$id] ?? 0) + $quantity;
    }

    return $wanted;
}

/**
 * The flower heads in the bouquet: a list of ['part' => <row>, 'quantity' => <int>].
 *
 * At most CUSTOM_MIX_MAX entries, every quantity at least 1, and the quantities adding up to
 * no more than CUSTOM_BLOOM_MAX. Empty only when the shop offers no blooms at all.
 *
 * An unknown id inside a mix is *dropped*, where readDesignSelection() would fall back to the
 * first part of the kind. The two are different questions. "Which single wrap?" has to be
 * answered somehow, so an unusable answer becomes the first one. "Which flower heads, and how
 * many of each?" already has other answers in it, and substituting a rose for the withdrawn
 * sunflower would put a flower in the bouquet that nobody chose -- next to the roses they did
 * choose, at a price they did not agree to. Dropping it makes the bouquet smaller, which is
 * visible; substituting makes it wrong, which is not.
 *
 * If dropping empties the mix, or the request named no mix at all, the fallback is the design
 * a link from before mixing existed describes: the single ?bloom= choice, ?count= of them.
 * That is what keeps every URL quarter one shared working unchanged.
 *
 * The mix comes out in the order the shop offers its blooms, not the order the query names
 * them, so ?mix[5]=2&mix[1]=4 and ?mix[1]=4&mix[5]=2 are the same bouquet down to the URL it
 * rewrites itself to. Two reasons. The picker is a column of quantity boxes in offered order,
 * so this is the order somebody is looking at while they type. And js/customizer.js reads
 * those boxes -- it has no way of knowing what order a shared URL happened to be written in,
 * so a rule that depended on it would be a rule the browser's mirrored total could not follow.
 *
 * Over the ceiling, entries are trimmed from the end of that order rather than scaled down.
 * ?mix[1]=20&mix[5]=20 asks for forty stems and gets twenty roses and four sunflowers: as
 * much as fits, from the top of the picker down. Scaling would answer a request for twenty
 * roses with twelve.
 *
 * @param array<string, array<int, array<string, mixed>>> $parts
 * @return array<int, array{part: array<string, mixed>, quantity: int}>
 */
function readBloomMix(array $query, array $parts): array
{
    $available = $parts['bloom'] ?? [];

    if ($available === []) {
        return [];
    }

    $wanted = bloomMixWanted($query['mix'] ?? null);

    $mix = [];
    $used = 0;

    foreach ($available as $part) {
        $id = (int) $part['id'];

        // Not asked for. An id in the query that is not offered -- withdrawn, or invented --
        // never matches anything here, which is the dropping the docblock describes.
        if (!isset($wanted[$id])) {
            continue;
        }

        if (count($mix) >= CUSTOM_MIX_MAX) {
            break;
        }

        $room = CUSTOM_BLOOM_MAX - $used;

        if ($room < 1) {
            break;
        }

        $quantity = min($wanted[$id], $room);
        $used += $quantity;
        $mix[] = ['part' => $part, 'quantity' => $quantity];
    }

    if ($mix !== []) {
        return $mix;
    }

    $single = readDesignSelection($query, $parts)['bloom'] ?? null;

    return $single === null
        ? []
        : [['part' => $single, 'quantity' => customBloomCount($query['count'] ?? null)]];
}

/**
 * The whole design a request describes.
 *
 * The one function php/customize.php calls to turn $_GET into a bouquet, and the shape
 * everything below takes. See the file docblock for the array.
 *
 * @param array<string, array<int, array<string, mixed>>> $parts
 * @return array<string, mixed>
 */
function readDesign(array $query, array $parts): array
{
    $selection = readDesignSelection($query, $parts);

    return [
        'blooms' => readBloomMix($query, $parts),
        'wire' => $selection['wire'],
        'wrap' => $selection['wrap'],
        'accent' => $selection['accent'],
        'foliage' => $selection['foliage'],
        'arrangement' => designArrangement($query['arrangement'] ?? null),
    ];
}

/**
 * How many flowers are in the design: the sum of the mix, not a field of its own.
 *
 * @param array<string, mixed> $design
 */
function designCount(array $design): int
{
    $count = 0;

    foreach ($design['blooms'] ?? [] as $entry) {
        $count += (int) ($entry['quantity'] ?? 0);
    }

    return $count;
}

/**
 * A design built from parts already chosen, rather than from a query string.
 *
 * What php/config/designs.php hands back when it reads a saved bouquet, and what the unit
 * tests build by hand. Everything is put through the same clamps a URL goes through, so a row
 * that has been in the database since before a limit changed cannot produce a design the
 * builder would refuse to draw.
 *
 * @param array<int, array{part: array<string, mixed>, quantity: int}> $blooms
 * @return array<string, mixed>
 */
function makeDesign(
    array $blooms,
    ?array $wire = null,
    ?array $wrap = null,
    ?array $accent = null,
    ?array $foliage = null,
    string $arrangement = DESIGN_ARRANGEMENT_DEFAULT
): array {
    $mix = [];
    $used = 0;

    foreach ($blooms as $entry) {
        $part = $entry['part'] ?? null;
        $quantity = (int) ($entry['quantity'] ?? 0);

        if (!is_array($part) || $quantity < 1 || count($mix) >= CUSTOM_MIX_MAX) {
            continue;
        }

        $quantity = min($quantity, CUSTOM_BLOOM_MAX - $used);

        if ($quantity < 1) {
            break;
        }

        $used += $quantity;
        $mix[] = ['part' => $part, 'quantity' => $quantity];
    }

    return [
        'blooms' => $mix,
        'wire' => $wire,
        'wrap' => $wrap,
        'accent' => $accent,
        'foliage' => $foliage,
        'arrangement' => designArrangement($arrangement),
    ];
}

/**
 * A price column turned into a whole number of centavos.
 *
 * The prices arrive from the column as strings -- '38.00' -- and (float) '38.00' * 6 is the
 * arithmetic DECIMAL(10,2) was chosen to avoid: it is how a total prints as
 * 227.99999999999997, and how a figure recomputed by a later basket ends up a centavo away
 * from the one the customer was shown. So every price becomes an integer before anything is
 * multiplied or added.
 *
 * round() before the cast because '38.00' * 100 in binary floating point is
 * 3799.9999999999995, and (int) truncates.
 */
function designPriceCentavos(mixed $price): int
{
    return (int) round(((float) (string) ($price ?? '0')) * 100);
}

/**
 * Centavos back into a DECIMAL string like '384.00'.
 *
 * Built from the integer rather than by dividing it. number_format($centavos / 100, 2) would
 * give the same answer for every total this shop can reach, but it would put a float back
 * into the one calculation whose whole argument is that there is not one in it.
 */
function designPriceFromCentavos(int $centavos): string
{
    return intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * The design read back as lines, one per flower head plus one for each of the other three.
 *
 * What the no-JavaScript page prints under the stage, what the breakdown beside the total is
 * built from, and what the canvas describes itself as to a screen reader. One function for all
 * three so the three cannot disagree about what is in the bouquet.
 *
 * Each line carries the pieces rather than a finished sentence, so a caller may print
 * "4 x Blush Rose Head" in a table cell and "4 Blush Rose Head" in an aria-label without
 * either of them re-deriving the quantity.
 *
 * The wire's quantity is the whole flower count however the mix is split: every flower head
 * gets one stem, and four roses plus two sunflowers is six stems.
 *
 * @param array<string, mixed> $design
 * @return array<int, array<string, string|int|bool>>
 */
function designSummary(array $design): array
{
    $lines = [];
    $count = designCount($design);

    foreach ($design['blooms'] ?? [] as $entry) {
        $lines[] = designSummaryLine('bloom', $entry['part'], (int) $entry['quantity']);
    }

    foreach (['wire', 'wrap', 'accent', 'foliage'] as $kind) {
        $part = $design[$kind] ?? null;

        if ($part !== null) {
            $lines[] = designSummaryLine($kind, $part, designKindIsPerStem($kind) ? $count : 1);
        }
    }

    return $lines;
}

/**
 * One line of designSummary(). Split out because a bloom line and a wire line differ only in
 * where the quantity came from, and writing the array twice is how the two drift.
 *
 * @param array<string, mixed> $part
 * @return array<string, string|int|bool>
 */
function designSummaryLine(string $kind, array $part, int $quantity): array
{
    $each = (string) ($part['price'] ?? '0');

    return [
        'kind' => $kind,
        'kindLabel' => componentKindLabel($kind),
        // The part's own id, so a caller that has a line does not have to walk the design
        // again to find out which component it came from. designSnapshot() needs it, and
        // pairing lines back to parts by position would be correct today and wrong the first
        // time the order of the summary changed.
        'id' => (int) ($part['id'] ?? 0),
        'name' => (string) ($part['name'] ?? ''),
        'colourHex' => componentColourHex($part),
        'quantity' => $quantity,
        'each' => $each,
        'lineTotal' => designPriceFromCentavos(designPriceCentavos($each) * $quantity),
        'perStem' => designKindIsPerStem($kind),
    ];
}

/**
 * What the whole design costs, in centavos.
 *
 * Built by walking designSummary() rather than by re-reading the design, so the total and the
 * breakdown printed beside it are the same arithmetic. A total that disagrees with the lines
 * it is the sum of is the one bug a customer will always find.
 *
 * @param array<string, mixed> $design
 */
function designTotalCentavos(array $design): int
{
    $total = 0;

    foreach (designSummary($design) as $line) {
        $total += designPriceCentavos($line['each']) * (int) $line['quantity'];
    }

    return $total;
}

/**
 * The same figure as a DECIMAL string, ready for formatPrice() or a price column.
 *
 * A string rather than a float for the reason the column is DECIMAL: this figure is shown to
 * a customer, and one day copied into a basket line. Neither of those may be a rounding of
 * something else.
 *
 * @param array<string, mixed> $design
 */
function designTotal(array $design): string
{
    return designPriceFromCentavos(designTotalCentavos($design));
}

/**
 * The bouquet as one sentence: what the canvas tells a screen reader it is drawing.
 *
 * Built from designSummary() for the same reason designTotal() is -- the picture, the
 * breakdown and the description are then three views of one list rather than three
 * descriptions free to disagree.
 *
 * Per-stem parts carry their quantity ("6 Blush Rose Head"); the wrap and the accent do not,
 * because "1 Kraft Paper Sleeve" is a stock-keeping unit rather than a thing anybody says.
 *
 * The arrangement is named at the end. It changes no price, but it is most of what the
 * picture looks like, and a description of a bouquet that leaves out its shape is describing
 * the parts list instead of the bouquet.
 *
 * @param array<string, mixed> $design
 */
function designSentence(array $design): string
{
    $lines = designSummary($design);

    if ($lines === []) {
        return 'An empty bouquet. Nothing is available to build with right now.';
    }

    $pieces = [];

    foreach ($lines as $line) {
        $pieces[] = $line['perStem']
            ? $line['quantity'] . ' ' . $line['name']
            : (string) $line['name'];
    }

    $arrangement = designArrangementLabel(
        (string) ($design['arrangement'] ?? DESIGN_ARRANGEMENT_DEFAULT)
    );

    return 'A bouquet of ' . implode(', ', $pieces) . '. ' . $arrangement . ' arrangement.';
}

/**
 * The query string that rebuilds this design: what a share link and a permalink both carry.
 *
 * The mix goes out in the array form -- mix[1]=4&mix[5]=2 -- rather than the compact
 * 1x4,5x2, because that is what the picker's own quantity boxes are named. The link a
 * customer copies and the request the no-JavaScript Update button sends are then the same
 * request, and there is only one shape to keep working.
 *
 * http_build_query rather than string concatenation: it is what encodes the brackets and
 * would encode a stray character in a value, and a URL builder that forgets to encode is a
 * URL builder that eventually emits a broken link.
 *
 * Just the query, with no path and no '?': php/config/ has no business deciding which page
 * this belongs to, and url() lives in auth.php, which nothing here requires.
 *
 * @param array<string, mixed> $design
 */
function designQuery(array $design): string
{
    $query = [];

    foreach ($design['blooms'] ?? [] as $entry) {
        $id = (int) ($entry['part']['id'] ?? 0);

        if ($id > 0) {
            $query['mix'][$id] = (int) $entry['quantity'];
        }
    }

    foreach (['wire', 'wrap', 'accent', 'foliage'] as $kind) {
        $id = (int) ($design[$kind]['id'] ?? 0);

        if ($id > 0) {
            $query[$kind] = $id;
        }
    }

    $query['arrangement'] = designArrangement($design['arrangement'] ?? null);

    return http_build_query($query);
}

/**
 * The design with today's prices written into it, ready to be copied somewhere that must not
 * move again.
 *
 * Nothing on the customizer calls this, and that is deliberate: the builder and a saved design
 * both re-price live, which is why saved_designs has no money column in it. What this exists
 * for is the one step after -- the moment a design becomes a basket line. An order is a
 * contract, so the prices in it have to stop tracking product_components, and the copy has to
 * happen exactly once, at a known point, from a known shape. That point is here.
 *
 * Component ids travel with the names for the same reason: a frozen line saying "Blush Rose
 * Head, 38.00" with no id cannot be traced back to the part it was, once the shop has renamed
 * it. A frozen line is a receipt, and a receipt that cannot be reconciled is decoration.
 *
 * Rows are not carried through. A snapshot is a record of what was agreed, and handing on the
 * whole live row would invite the caller to read a price back out of it later -- which is the
 * thing this function exists to stop.
 *
 * @param array<string, mixed> $design
 * @return array<string, mixed>
 */
function designSnapshot(array $design): array
{
    $lines = [];

    foreach (designSummary($design) as $line) {
        $lines[] = [
            'kind' => $line['kind'],
            'componentId' => $line['id'],
            'name' => $line['name'],
            'quantity' => (int) $line['quantity'],
            'each' => $line['each'],
            'lineTotal' => $line['lineTotal'],
        ];
    }

    return [
        'count' => designCount($design),
        'arrangement' => designArrangement($design['arrangement'] ?? null),
        'lines' => $lines,
        'total' => designTotal($design),
    ];
}

/**
 * Everything the browser needs to draw and re-price the bouquet, as one JSON string.
 *
 * It goes into a data- attribute rather than an inline <script>. escape() is
 * htmlspecialchars with ENT_QUOTES, which makes an attribute safe with no further thought,
 * while an inline script needs a different escaping rule and one part description containing
 * "</script>" would end the document.
 *
 * The whole parts list travels, not just the chosen four, because changing a radio has to
 * redraw the bouquet without a round trip -- so the browser needs every colour, shape and
 * price up front. It is a few kilobytes for seventeen parts.
 *
 * 'mix' is the design the server resolved, and it is the browser's fallback for the one case
 * its own reading cannot reproduce: a request whose quantity boxes come to nothing at all,
 * where readBloomMix() falls back to the ?bloom= single-bloom design. Handing the answer over
 * rather than making the browser re-derive it is what keeps the mirrored total from
 * disagreeing with the printed one on a hand-edited URL.
 *
 * 'perStem' is on every part rather than left for the browser to work out from its kind, so
 * DESIGN_PER_STEM_KINDS is stated once, here, and js/customizer.js has no copy of the rule to
 * get wrong.
 *
 * @param array<string, array<int, array<string, mixed>>> $parts
 * @param array<string, mixed> $design
 */
function designPayload(array $parts, array $design): string
{
    $offered = [];

    foreach (COMPONENT_KINDS as $kind) {
        $offered[$kind] = [];

        foreach ($parts[$kind] ?? [] as $part) {
            $offered[$kind][] = [
                'id' => (int) $part['id'],
                'name' => (string) ($part['name'] ?? ''),
                'price' => (string) ($part['price'] ?? '0'),
                'colour' => componentColourHex($part),
                'shape' => componentShape($part),
                'perStem' => designKindIsPerStem($kind),
            ];
        }
    }

    $mix = [];

    foreach ($design['blooms'] ?? [] as $entry) {
        $mix[] = [
            'id' => (int) ($entry['part']['id'] ?? 0),
            'quantity' => (int) $entry['quantity'],
        ];
    }

    $json = json_encode([
        'parts' => $offered,
        'mix' => $mix,
        'arrangement' => designArrangement($design['arrangement'] ?? null),
        // The labels travel too, so the sentence the browser writes into the canvas
        // aria-label ends the same way designSentence() ends -- "Domed arrangement." and not
        // "dome arrangement." -- without js/customizer.js holding its own copy of the words.
        'arrangements' => DESIGN_ARRANGEMENT_LABELS,
        'total' => designTotal($design),
        'min' => CUSTOM_BLOOM_MIN,
        'max' => CUSTOM_BLOOM_MAX,
        'mixMax' => CUSTOM_MIX_MAX,
        'currency' => CURRENCY_SYMBOL,
    ], JSON_UNESCAPED_UNICODE);

    // json_encode returns false on malformed UTF-8, which a column this shop writes should
    // never hold -- but '{}' is a payload readDesign() rejects quietly in the browser, and a
    // page that renders without its preview beats a page that renders 'false' into an
    // attribute.
    return $json === false ? '{}' : $json;
}
