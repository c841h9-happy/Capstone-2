<?php

declare(strict_types=1);

/*
 * customizer.php is required for makeDesign(), designArrangement(), CUSTOM_MIX_MAX and
 * CUSTOM_BLOOM_MAX -- and, through it, for components.php's fetchComponent() and
 * getDatabaseConnection(). Everything a saved design is read back into is built by
 * makeDesign(), so a row that predates a limit cannot produce a design the builder refuses
 * to draw.
 */
require_once __DIR__ . '/customizer.php';

/**
 * Keeping a bouquet, and getting it back.
 *
 * The write side of the customizer, and the only file in the module that writes. It owns
 * saved_designs and saved_design_blooms, and nothing else touches either table.
 *
 * Two tables per design, always written together, which is why every write in here is in a
 * transaction: a saved_designs row with no blooms is not a bouquet, it is a name. See
 * sql/designs-upgrade.sql for the shape and for why there is no money in it.
 *
 * *No prices are stored.* A design re-prices from product_components every time it is read,
 * because a recipe is not a quote. designSnapshot() in customizer.php is where the freeze
 * belongs, one step later, when Commerce turns a design into a basket line.
 *
 * Every read here is scoped by user_id in the SQL, not checked afterwards in PHP. A saved
 * design is private, and "SELECT then compare" is how the wrong row gets returned by the one
 * caller that forgets to compare.
 */

/**
 * How many designs one account may keep.
 *
 * A cap rather than none. Nothing here is expensive, but a list is a thing a person reads,
 * and past a couple of dozen a "saved designs" page stops being a list and starts needing a
 * search box -- which is a feature nobody asked for. Twenty is more bouquets than this shop's
 * seventeen parts can make meaningfully different, and hitting it is answered with a message
 * saying which one to delete, not with a silent refusal.
 */
const SAVED_DESIGN_MAX = 20;

/**
 * How long a design's name may be: saved_designs.name is VARCHAR(80).
 *
 * The same number as the column, and stated here so the form can say it before the database
 * has to. mb_strlen counts characters, which is what utf8mb4 VARCHAR counts, so a name of 80
 * accented letters fits even though it is 160 bytes.
 */
const SAVED_DESIGN_NAME_MAX = 80;

/**
 * A name for a bouquet, checked, or the reason it was refused.
 *
 * Trimmed first, and an empty name refused rather than defaulted to "Untitled". A design is
 * something a customer went looking for in a list, and a list of four things called Untitled
 * is a list they cannot use -- so the one moment to insist on a name is when it is being
 * given.
 *
 * The uniqueness check is per account and is a courtesy: uq_saved_designs_user_name is what
 * actually enforces it, and saveDesign() catches the violation, because two tabs can pass
 * this check at the same moment.
 *
 * @return array{errors: array<int, string>, name: string}
 */
function validateDesignName(mixed $name): array
{
    $errors = [];
    $name = is_string($name) ? trim($name) : '';

    if ($name === '') {
        $errors[] = 'Give your bouquet a name, so you can find it again.';
    } elseif (mb_strlen($name) > SAVED_DESIGN_NAME_MAX) {
        $errors[] = 'The name has to be ' . SAVED_DESIGN_NAME_MAX . ' characters or fewer.';
    }

    return ['errors' => $errors, 'name' => $name];
}

/**
 * The id of this account's design with this name, or null.
 *
 * What lets the save form offer "you already have one called that -- replace it?" rather than
 * failing on the unique index. Scoped by user_id in the SQL: a name is unique per account, and
 * a query that found somebody else's design by name would be a leak even if it only ever
 * returned an id.
 */
function findSavedDesignByName(int $userId, string $name): ?int
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT id FROM saved_designs WHERE user_id = :user AND name = :name LIMIT 1'
    );
    $statement->execute(['user' => $userId, 'name' => $name]);

    $id = $statement->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * How many designs this account has kept, for the cap.
 */
function countSavedDesigns(int $userId): int
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT COUNT(*) FROM saved_designs WHERE user_id = :user'
    );
    $statement->execute(['user' => $userId]);

    return (int) $statement->fetchColumn();
}

/**
 * Write a design, replacing the one with the same name if there is one.
 *
 * Returns the design's id. Throws RuntimeException with a sentence fit to show a customer for
 * the two refusals that are not programming errors -- the cap, and a name that was taken
 * between the check and the write.
 *
 * *Replacing* rather than erroring on a repeated name, because that is what a customer means
 * by saving "Mum's birthday" twice: they have adjusted it. The replacement is an UPDATE of the
 * existing row plus a rewrite of its blooms, not a delete and re-insert, so the id survives --
 * a bookmarked link to a design should not break because its owner changed the ribbon.
 *
 * The blooms are deleted and re-inserted rather than reconciled. A mix is a short list that is
 * rewritten wholesale, and matching up which of four rows changed is work whose only product
 * is a bug.
 *
 * @param array<string, mixed> $design
 */
function saveDesign(int $userId, string $name, array $design): int
{
    $connection = getDatabaseConnection();
    $existingId = findSavedDesignByName($userId, $name);

    if ($existingId === null && countSavedDesigns($userId) >= SAVED_DESIGN_MAX) {
        throw new RuntimeException(
            'You have ' . SAVED_DESIGN_MAX . ' saved bouquets, which is as many as we keep. '
            . 'Delete one you no longer need, and this one will save.'
        );
    }

    $connection->beginTransaction();

    try {
        if ($existingId === null) {
            $statement = $connection->prepare(
                'INSERT INTO saved_designs (user_id, name, wire_id, wrap_id, accent_id, foliage_id, arrangement)
                 VALUES (:user, :name, :wire, :wrap, :accent, :foliage, :arrangement)'
            );
            bindDesignValues($statement, $design);
            $statement->bindValue(':user', $userId, PDO::PARAM_INT);
            $statement->bindValue(':name', $name, PDO::PARAM_STR);
            $statement->execute();

            $designId = (int) $connection->lastInsertId();
        } else {
            $designId = $existingId;

            /*
             * user_id is in the WHERE as well as the id. It cannot fail here -- the id came
             * from a query already scoped to this account -- and it stays because an UPDATE
             * on somebody's saved design should be impossible to write without naming whose
             * design it is.
             */
            $statement = $connection->prepare(
                'UPDATE saved_designs
                    SET wire_id = :wire, wrap_id = :wrap, accent_id = :accent,
                        foliage_id = :foliage, arrangement = :arrangement
                  WHERE id = :id AND user_id = :user'
            );
            bindDesignValues($statement, $design);
            $statement->bindValue(':id', $designId, PDO::PARAM_INT);
            $statement->bindValue(':user', $userId, PDO::PARAM_INT);
            $statement->execute();

            $clear = $connection->prepare('DELETE FROM saved_design_blooms WHERE design_id = :id');
            $clear->execute(['id' => $designId]);
        }

        $insertBloom = $connection->prepare(
            'INSERT INTO saved_design_blooms (design_id, component_id, quantity, sort_order)
             VALUES (:design, :component, :quantity, :sort)'
        );

        $sort = 0;

        foreach ($design['blooms'] ?? [] as $entry) {
            $componentId = (int) ($entry['part']['id'] ?? 0);

            if ($componentId < 1) {
                continue;
            }

            $insertBloom->execute([
                'design' => $designId,
                'component' => $componentId,
                'quantity' => (int) $entry['quantity'],
                // Ten apart, so the order is the order they were chosen in and there is room
                // to slot one between two later without renumbering the rest.
                'sort' => $sort += 10,
            ]);
        }

        $connection->commit();
    } catch (PDOException $exception) {
        $connection->rollBack();

        /*
         * 23000 is an integrity constraint, and the only one reachable here is
         * uq_saved_designs_user_name -- two tabs saving the same name at the same moment, so
         * findSavedDesignByName() found nothing in both and the second INSERT lost. That is a
         * race, not a fault, and it deserves the sentence the form would have shown.
         *
         * Everything else is rethrown: the page's own catch turns it into the 503 it turns
         * every other database failure into, and swallowing it here would show a customer
         * "name taken" for a table that is missing.
         */
        if ($exception->getCode() === '23000') {
            throw new RuntimeException(
                'You just saved another bouquet under that name. Try saving this one again.'
            );
        }

        throw $exception;
    }

    return $designId;
}

/**
 * The three single-part columns and the arrangement, bound for either write.
 *
 * Split out for the reason bindComponentValues() is: the INSERT and the UPDATE name the same
 * four values, and writing them twice is how the two come to disagree about one of them.
 *
 * A missing part is bound as NULL rather than 0. The columns are NULL-able exactly so a
 * bouquet with no ribbon can be saved, and 0 would be an id that matches no component while
 * still looking like a choice.
 *
 * @param array<string, mixed> $design
 */
function bindDesignValues(PDOStatement $statement, array $design): void
{
    foreach (['wire', 'wrap', 'accent', 'foliage'] as $kind) {
        $id = (int) ($design[$kind]['id'] ?? 0);

        if ($id > 0) {
            $statement->bindValue(':' . $kind, $id, PDO::PARAM_INT);
        } else {
            $statement->bindValue(':' . $kind, null, PDO::PARAM_NULL);
        }
    }

    $statement->bindValue(
        ':arrangement',
        designArrangement($design['arrangement'] ?? null),
        PDO::PARAM_STR
    );
}

/**
 * One account's saved bouquets, most recently changed first, as bare rows.
 *
 * Rows and not designs: this is the list page's query, and turning twenty rows into twenty
 * priced designs would be sixty component lookups to draw a list of names. readSavedDesign()
 * is what does that, one design at a time, when one is actually opened.
 *
 * The count comes from the blooms table with a subquery rather than a stored column, for the
 * reason sql/designs-upgrade.sql gives: a cached sum beside the rows it is the sum of is a
 * number free to be wrong about them. COALESCE because a design whose only bloom has been
 * deleted outright still has a row here, and SUM over no rows is NULL.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchSavedDesigns(int $userId): array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT d.id, d.name, d.arrangement, d.created_at, d.updated_at,
                COALESCE((SELECT SUM(b.quantity) FROM saved_design_blooms b
                           WHERE b.design_id = d.id), 0) AS stem_count
           FROM saved_designs d
          WHERE d.user_id = :user
          ORDER BY d.updated_at DESC, d.id DESC'
    );
    $statement->execute(['user' => $userId]);

    return $statement->fetchAll();
}

/**
 * One saved design's own row, or null if this account does not have one with that id.
 *
 * user_id is in the WHERE, so somebody else's design and a design that does not exist are the
 * same answer -- null. Not because the difference is a secret worth keeping, but because a
 * caller given two outcomes will eventually handle only one of them.
 *
 * @return array<string, mixed>|null
 */
function fetchSavedDesign(int $userId, int $designId): ?array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, wire_id, wrap_id, accent_id, foliage_id, arrangement, created_at, updated_at
           FROM saved_designs
          WHERE id = :id AND user_id = :user
          LIMIT 1'
    );
    $statement->execute(['id' => $designId, 'user' => $userId]);

    return $statement->fetch() ?: null;
}

/**
 * A saved design read back as a live design, priced at today's prices.
 *
 * Returns null when the account has no such design. Everything else about a design that has
 * aged badly is coped with rather than refused:
 *
 *   - A part *withdrawn* by the admin comes back null from fetchComponent(), which returns
 *     nothing for is_active = 0. The design keeps its shape and loses that part, exactly as a
 *     shared URL naming a withdrawn part does.
 *   - A part *deleted* outright left NULL in the column, via ON DELETE SET NULL. Same answer,
 *     one step earlier.
 *   - A bloom row whose component is gone is dropped, and its quantity with it. A design that
 *     was six stems of one withdrawn rose becomes an empty mix, and the builder then shows
 *     what is on offer today -- which is the only honest thing to draw.
 *
 * Everything goes through makeDesign(), so the clamps apply on the way out as well as in. A
 * design saved when the ceiling was thirty is trimmed to twenty-four rather than drawn as
 * something the picker cannot express.
 *
 * @return array<string, mixed>|null
 */
function readSavedDesign(int $userId, int $designId): ?array
{
    $row = fetchSavedDesign($userId, $designId);

    if ($row === null) {
        return null;
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT component_id, quantity
           FROM saved_design_blooms
          WHERE design_id = :id
          ORDER BY sort_order ASC, id ASC'
    );
    $statement->execute(['id' => $designId]);

    $blooms = [];

    foreach ($statement->fetchAll() as $bloom) {
        $componentId = (int) ($bloom['component_id'] ?? 0);
        $part = $componentId > 0 ? fetchComponent($componentId) : null;

        if ($part !== null) {
            $blooms[] = ['part' => $part, 'quantity' => (int) $bloom['quantity']];
        }
    }

    return makeDesign(
        $blooms,
        savedDesignPart($row['wire_id'] ?? null),
        savedDesignPart($row['wrap_id'] ?? null),
        savedDesignPart($row['accent_id'] ?? null),
        savedDesignPart($row['foliage_id'] ?? null),
        (string) ($row['arrangement'] ?? DESIGN_ARRANGEMENT_DEFAULT)
    );
}

/**
 * A component id column read back as a part, or null.
 *
 * Three lines with a name because the alternative is the NULL check written three times in
 * readSavedDesign()'s argument list, where one of them would eventually be written wrong.
 *
 * @return array<string, mixed>|null
 */
function savedDesignPart(mixed $id): ?array
{
    $id = (int) ($id ?? 0);

    return $id > 0 ? fetchComponent($id) : null;
}

/**
 * Rename a saved design. True when a row was changed.
 *
 * False rather than an exception for "no such design", because the only ways to get there are
 * an id that was never this account's and a design deleted in another tab -- neither of which
 * is exceptional on a page with a list of links on it.
 *
 * The unique index is what refuses a name this account already uses, and the violation is
 * turned into a sentence for the same reason saveDesign() does it: two designs called
 * "Wedding" is a thing a customer tried to do, not a fault.
 */
function renameSavedDesign(int $userId, int $designId, string $name): bool
{
    $statement = getDatabaseConnection()->prepare(
        'UPDATE saved_designs SET name = :name WHERE id = :id AND user_id = :user'
    );

    try {
        $statement->execute(['name' => $name, 'id' => $designId, 'user' => $userId]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            throw new RuntimeException('You already have a bouquet called "' . $name . '".');
        }

        throw $exception;
    }

    /*
     * rowCount() is 0 both for a design that is not this account's and for a rename to the
     * name it already had -- MariaDB reports no rows changed when nothing changed. The second
     * is not a failure, so it is checked for first: the caller gets true, the flash says
     * renamed, and the name is indeed what they asked for.
     */
    if ($statement->rowCount() > 0) {
        return true;
    }

    return fetchSavedDesign($userId, $designId) !== null;
}

/**
 * Delete a saved design. True when there was one to delete.
 *
 * The blooms go with it through ON DELETE CASCADE rather than a second statement here: a bloom
 * row with no design is a fragment, and the database is a better place to state that than a
 * function that could be called from somewhere that forgets the second half.
 */
function deleteSavedDesign(int $userId, int $designId): bool
{
    $statement = getDatabaseConnection()->prepare(
        'DELETE FROM saved_designs WHERE id = :id AND user_id = :user'
    );
    $statement->execute(['id' => $designId, 'user' => $userId]);

    return $statement->rowCount() > 0;
}
