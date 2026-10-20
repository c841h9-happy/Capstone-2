# Vendored dependencies

Nothing in here is ours. Do not edit these files.

## three@0.185.1

    three.module.min.js   365,552 bytes
    three.core.min.js     385,386 bytes

Downloaded 2026-09-05 from `https://unpkg.com/three@0.185.1/build/`. MIT licence, and the
licence header is at the top of each file where it arrived.

**Both files are required.** `three.module.min.js` is the public entry point, but it does
not contain the library — it re-exports the core API (`Scene`, `Group`, every geometry,
the lights, `MeshStandardMaterial`) with `export{...}from"./three.core.min.js"`. The two
have to stay side by side, and neither can be renamed.

Imported by [../customizer.js](../customizer.js) as:

```js
import * as THREE from './vendor/three.module.min.js';
```

A relative specifier on purpose. `import ... from 'three'` is a bare specifier and would
need an import map in every page's `<head>`; a relative path needs nothing, which is what
keeps this project free of a build step. There is no npm here and no `package.json` —
that is deliberate, not an omission.

## Vendored rather than loaded from a CDN

Decided 2026-09-04. This shop is demonstrated on a laptop, sometimes on a school network
and sometimes with no network at all, and a customizer that is a blank grey box when
unpkg is unreachable is worse than 751 KB in the repository. It also means `asset()` in
[../../php/config/paths.php](../../php/config/paths.php) can cache-bust these by
`filemtime()` like every other file the site serves.

## Updating

Three.js moves fast and this project pins one version on purpose. To move it:

1. Fetch both files from `https://unpkg.com/three@<version>/build/` — the `.min` module
   build and the `.min` core build, not the `three.cjs` or `three.webgpu.js` ones.
2. Confirm the new `three.module.min.js` still re-exports from `./three.core.min.js`; if
   upstream ever collapses the split back into one file, the second file is simply gone
   and the import above keeps working.
3. Update the version, the sizes and the date above.
4. Re-run the customizer's Chrome pass. A silent API removal shows up as an empty canvas,
   which no PHP test can see.
