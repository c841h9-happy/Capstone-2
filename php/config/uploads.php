<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

/**
 * Taking a picture off a form and putting it somewhere safe.
 *
 * Its own file rather than part of products-admin.php, because product photos are not
 * the only thing that will be uploaded -- the customizer's components have pictures too,
 * and the two must not each grow their own idea of what a safe file is.
 *
 * The rules, and why each one is here:
 *
 *   - The file's name as submitted is never used. Not sanitised, not partly kept:
 *     ignored. This project stores photos inside the document root, so a name is a URL,
 *     and every trick for turning an upload into code is a trick about a name --
 *     "shell.php", "shell.jpg.php", "shell.php%00.jpg", a name with a slash in it. A
 *     generated random name has none of that surface, and nothing is lost, because the
 *     original name was never shown to anybody.
 *   - The submitted content type is never used either. $_FILES['x']['type'] is copied
 *     straight from the browser's request and a request is written by whoever is asking.
 *     The type is decided here, by reading the bytes.
 *   - The bytes have to parse as an image of a type we allow. finfo says what libmagic
 *     thinks they are, getimagesize() says the same thing independently, and both have
 *     to agree -- so a file that is a valid JPEG with a ZIP glued to the end still only
 *     ever gets served as the JPEG it says it is.
 *   - The extension is written from the type that was detected, not from what arrived.
 *
 * uploads/.htaccess is the second lock on all of this: PHP is switched off for that
 * whole subtree, so even a file that got past every check above comes back as bytes.
 * Neither half is trusted to be the only one.
 */

/** The most a photo may weigh, in bytes. 2 MB is a generous phone photo. */
const UPLOAD_MAX_BYTES = 2 * 1024 * 1024;

/**
 * The types allowed, as detected-MIME => extension.
 *
 * JPEG, PNG and WebP: what a photograph of a handmade flower actually is. GIF is left
 * out because an animation is not a product photo, and SVG is left out because it is a
 * document that can carry JavaScript rather than a picture -- served from this origin it
 * would run with the admin's session.
 */
const UPLOAD_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

/**
 * The same three as constants out of getimagesize(), so the two checks can be compared.
 *
 * IMAGETYPE_* is what getimagesize() reports in element 2; the MIME strings above are
 * what finfo reports. A file has to come back as the same type from both.
 */
const UPLOAD_IMAGE_SIGNATURES = [
    IMAGETYPE_JPEG => 'image/jpeg',
    IMAGETYPE_PNG => 'image/png',
    IMAGETYPE_WEBP => 'image/webp',
];

/** Smallest picture worth keeping. Below this it is a spacer or a mistake, not a photo. */
const UPLOAD_MIN_PIXELS = 80;

/** Largest, in pixels per side. Guards the browser rather than the disk. */
const UPLOAD_MAX_PIXELS = 6000;

/**
 * Was a file actually chosen?
 *
 * An untouched file input still arrives in $_FILES, with error UPLOAD_ERR_NO_FILE. That
 * is not a failure -- it is an admin editing a price and leaving the photo alone -- so it
 * has to be told apart from a file that was chosen and went wrong.
 */
function uploadWasAttempted(array $file): bool
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return false;
    }

    return true;
}

/**
 * Did the whole request arrive, or did PHP throw it away for being too big?
 *
 * The trap this catches: when a POST body is larger than post_max_size, PHP discards the
 * entire body. $_POST and $_FILES both come back empty, there is no error code anywhere
 * to read, and the page sees a form submitted with every field blank. Without this the
 * admin would get "enter a name, enter a price, write a description" for having picked a
 * 60 MB photo.
 *
 * Only meaningful on a POST.
 */
function uploadPostWasDiscarded(): bool
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    return $length > 0 && $_POST === [] && $_FILES === [];
}

/**
 * Turn one of PHP's upload error codes into something an admin can act on.
 *
 * UPLOAD_ERR_INI_SIZE is php.ini's own limit, which is far above ours, so both size
 * messages name our limit rather than the server's -- being told the file exceeds 40 MB
 * when we refuse anything over 2 MB is not useful.
 */
function uploadErrorMessage(int $code): string
{
    $megabytes = (int) (UPLOAD_MAX_BYTES / 1024 / 1024);

    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That photo is too big. Pick one under ' . $megabytes . ' MB.',
        UPLOAD_ERR_PARTIAL => 'The photo only partly uploaded. Try it again.',
        UPLOAD_ERR_NO_FILE => 'No photo was chosen.',
        // The next three are the server, not the file: no temp folder, disk write
        // refused, an extension blocking it. The admin cannot fix any of them, so the
        // message says so instead of blaming the photo, and the detail goes to the log.
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The server could not accept that photo. Nothing has been changed.',
        default => 'Something went wrong with that photo. Nothing has been changed.',
    };
}

/**
 * Check an uploaded file and, if it is a real image, move it into place.
 *
 * $folder is under uploads/, e.g. 'products'. The returned path is project-relative and
 * ready for a column and for productImageUrl() -- 'uploads/products/<32 hex>.jpg'.
 *
 * Returns ['path' => string, 'error' => string]. Exactly one of the two is set, so a
 * caller cannot accidentally treat a refusal as a success.
 *
 * @return array{path: string, error: string}
 */
function storeUploadedImage(array $file, string $folder): array
{
    $fail = static fn (string $message): array => ['path' => '', 'error' => $message];

    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($code !== UPLOAD_ERR_OK) {
        if (!in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE], true)) {
            error_log('storeUploadedImage: upload error code ' . $code);
        }

        return $fail(uploadErrorMessage($code));
    }

    $temporary = (string) ($file['tmp_name'] ?? '');

    /*
     * is_uploaded_file before anything else touches the path.
     *
     * It is the only thing that says this really is a file PHP received on this request,
     * rather than a path that arrived by some other route. Without it, a tmp_name
     * pointing at, say, a config file elsewhere on disk would get copied into a folder
     * the whole internet can read.
     */
    if ($temporary === '' || !is_uploaded_file($temporary)) {
        error_log('storeUploadedImage: tmp_name is not an uploaded file');

        return $fail('That photo could not be read. Try choosing it again.');
    }

    $size = (int) @filesize($temporary);

    if ($size <= 0) {
        return $fail('That photo is empty. Try choosing it again.');
    }

    // Our own cap, checked against the file on disk rather than the size the browser
    // claimed in the request.
    if ($size > UPLOAD_MAX_BYTES) {
        return $fail(uploadErrorMessage(UPLOAD_ERR_FORM_SIZE));
    }

    $detected = detectImageMime($temporary);

    if ($detected === null) {
        return $fail('That does not look like a photo. Use a JPEG, PNG or WebP image.');
    }

    /*
     * The dimensions, from the same read that identified the type.
     *
     * A 1x1 pixel "photo" is a tracking dot or a mistake, and a 20,000 pixel one will
     * stall the browser it is drawn in long before it troubles the disk.
     */
    [$width, $height] = $detected['size'];

    if ($width < UPLOAD_MIN_PIXELS || $height < UPLOAD_MIN_PIXELS) {
        return $fail('That image is too small to show. It needs to be at least ' . UPLOAD_MIN_PIXELS . ' pixels on each side.');
    }

    if ($width > UPLOAD_MAX_PIXELS || $height > UPLOAD_MAX_PIXELS) {
        return $fail('That image is too large to show. Resize it to under ' . UPLOAD_MAX_PIXELS . ' pixels on each side.');
    }

    $directory = uploadDirectory($folder);

    if ($directory === null) {
        error_log('storeUploadedImage: cannot use upload folder ' . $folder);

        return $fail('The server has nowhere to put that photo. Nothing has been changed.');
    }

    /*
     * The name. Sixteen random bytes as hex, plus the extension for the type that was
     * actually detected.
     *
     * random_bytes and not uniqid() or a counter: the value ends up in a URL that anyone
     * may hold on to, and a guessable one would let a photo be found again after the
     * product it belonged to was deleted. It also makes a collision with an existing file
     * not worth writing code for -- but the loop below covers it anyway, because "will
     * never happen" and "silently overwrites another product's photo" is a bad pair.
     */
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $filename = bin2hex(random_bytes(16)) . '.' . $detected['extension'];
        $absolute = $directory . '/' . $filename;

        if (!file_exists($absolute)) {
            if (!move_uploaded_file($temporary, $absolute)) {
                error_log('storeUploadedImage: move_uploaded_file failed for ' . $absolute);

                return $fail('The server could not save that photo. Nothing has been changed.');
            }

            // 0644, not whatever the temp file had. It has to be readable by Apache to
            // be served and by nobody else to be written.
            @chmod($absolute, 0644);

            return ['path' => 'uploads/' . $folder . '/' . $filename, 'error' => ''];
        }
    }

    error_log('storeUploadedImage: could not find a free filename in ' . $directory);

    return $fail('The server could not save that photo. Nothing has been changed.');
}

/**
 * What a file really is: its MIME type, matching extension and pixel size.
 *
 * Two independent reads have to agree. finfo asks libmagic, which looks at the file's
 * signature; getimagesize() parses the image header itself. Either one alone has been
 * fooled before -- a file can be crafted so that one of them sees a picture and the
 * other sees something else -- so a disagreement is a refusal.
 *
 * Returns null for anything that is not one of UPLOAD_IMAGE_TYPES.
 *
 * @return array{mime: string, extension: string, size: array{int, int}}|null
 */
function detectImageMime(string $absolutePath): ?array
{
    $info = @getimagesize($absolutePath);

    // Element 2 is an IMAGETYPE_* constant. Anything not in the map -- a GIF, an ICO, a
    // TIFF, or a file getimagesize() cannot read at all -- stops here.
    if ($info === false || !isset(UPLOAD_IMAGE_SIGNATURES[$info[2]])) {
        return null;
    }

    $mime = UPLOAD_IMAGE_SIGNATURES[$info[2]];

    $finfo = @finfo_open(FILEINFO_MIME_TYPE);

    if ($finfo === false) {
        // fileinfo is compiled in on this install, so this is a broken PHP rather than a
        // bad file. Refusing is the safe answer: the alternative is trusting one check
        // where the design calls for two.
        error_log('detectImageMime: finfo_open failed');

        return null;
    }

    $sniffed = finfo_file($finfo, $absolutePath);
    finfo_close($finfo);

    if ($sniffed !== $mime || !isset(UPLOAD_IMAGE_TYPES[$mime])) {
        return null;
    }

    return [
        'mime' => $mime,
        'extension' => UPLOAD_IMAGE_TYPES[$mime],
        'size' => [(int) $info[0], (int) $info[1]],
    ];
}

/**
 * The absolute path of an upload folder, made if it is not there yet.
 *
 * $folder is one path segment and is checked to be exactly that, so a caller cannot pass
 * '../../php' and have this hand back a writable directory inside the source tree.
 * Returns null if the folder cannot be used.
 */
function uploadDirectory(string $folder): ?string
{
    if (preg_match('/^[a-z0-9-]{1,32}$/', $folder) !== 1) {
        return null;
    }

    $directory = dirname(__DIR__, 2) . '/uploads/' . $folder;

    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        return null;
    }

    return is_writable($directory) ? $directory : null;
}

/**
 * Remove a stored photo from disk. True if there is no longer a file at that path.
 *
 * Takes the project-relative value out of an image_path column, and is deliberately
 * quiet: a photo that has already gone is a success, not an error. The one thing it will
 * not do is delete outside uploads/, so a column holding 'php/config/database.php' --
 * however it got there -- does nothing.
 */
function deleteStoredImage(string $path): bool
{
    $path = trim($path);

    if ($path === '') {
        return true;
    }

    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $uploads = $root . '/uploads/';

    $absolute = $root . '/' . ltrim(str_replace('\\', '/', $path), '/');
    $real = realpath($absolute);

    if ($real === false) {
        // Nothing there. The column is out of step with the disk, which is what this
        // function was going to fix anyway.
        return true;
    }

    $real = str_replace('\\', '/', $real);

    /*
     * The containment check, on the resolved path rather than the stored one.
     *
     * realpath() has already followed any '..' and any symlink, so this compares where
     * the file actually is with where files are allowed to be. Doing it on the raw string
     * would pass 'uploads/products/../../php/index.php' as being under uploads/.
     *
     * stripos, not strpos: Windows is case-insensitive about the same directory.
     */
    if (stripos($real, $uploads) !== 0 || !is_file($real)) {
        error_log('deleteStoredImage: refused to delete outside uploads/: ' . $path);

        return false;
    }

    return @unlink($real);
}
