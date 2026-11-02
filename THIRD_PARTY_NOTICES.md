# Third-party notices

juphyter's PHP and Python code contains no code from other projects. Its
Composer and pip dependencies are installed by those tools and are not
included in this repository.

The rendered documentation under `docs/` includes front-end files that Quarto
1.10.18 copied into `docs/site_libs/` when it rendered the site. They are
listed below with their licenses. The scripts that draw phiz plots in the
rendered pages (Vega, Vega-Lite, vega-embed, jQuery, and RequireJS) are loaded
from a CDN and are not included.

The MIT entries are under the license text in
[LICENSES/MIT.txt](LICENSES/MIT.txt), with each entry's copyright line. Fuse.js
is under the license text in [LICENSES/Apache-2.0.txt](LICENSES/Apache-2.0.txt).

| Project | Version | License | Copyright | Files |
|---|---|---|---|---|
| [Quarto](https://github.com/quarto-dev/quarto-cli) | 1.10.18 | MIT | Copyright (c) 2020-2024 Posit Software, PBC | `docs/site_libs/quarto-html/quarto.js`, `docs/site_libs/quarto-html/tabsets/tabsets.js`, `docs/site_libs/quarto-html/quarto-syntax-highlighting-*.css`, `docs/site_libs/quarto-nav/quarto-nav.js`, `docs/site_libs/quarto-search/quarto-search.js` |
| [Bootstrap](https://github.com/twbs/bootstrap) | 5.3.1 | MIT | Copyright (c) 2011-2023 The Bootstrap Authors | `docs/site_libs/bootstrap/bootstrap.min.js`, `docs/site_libs/bootstrap/bootstrap-*.min.css` |
| [Bootswatch](https://github.com/thomaspark/bootswatch) (Cosmo theme) | As bundled with Quarto 1.10.18 | MIT | Copyright (c) 2013 Thomas Park | `docs/site_libs/bootstrap/bootstrap-*.min.css` |
| [Bootstrap Icons](https://github.com/twbs/icons) | 1.13.1 | MIT | Copyright (c) 2019-2024 The Bootstrap Authors | `docs/site_libs/bootstrap/bootstrap-icons.css`, `docs/site_libs/bootstrap/bootstrap-icons.woff` |
| [Tippy.js](https://github.com/atomiks/tippyjs) | 6.3.7 | MIT | Copyright (c) 2017-present atomiks | `docs/site_libs/quarto-html/tippy.umd.min.js`, `docs/site_libs/quarto-html/tippy.css` |
| [Popper](https://github.com/floating-ui/floating-ui/tree/v2.x) | 2.11.7 | MIT | Copyright (c) 2019 Federico Zivolo | `docs/site_libs/quarto-html/popper.min.js` |
| [AnchorJS](https://github.com/bryanbraun/anchorjs) | 5.0.0 | MIT | Copyright (c) 2021 Bryan Braun | `docs/site_libs/quarto-html/anchor.min.js` |
| [headroom.js](https://github.com/WickyNilliams/headroom.js) | 0.12.0 | MIT | Copyright (c) 2013 Nick Williams | `docs/site_libs/quarto-nav/headroom.min.js` |
| [Autocomplete](https://github.com/algolia/autocomplete) | 1.19.1 | MIT | Copyright (c) 2015-present Algolia, Inc. | `docs/site_libs/quarto-search/autocomplete.umd.js` |
| [clipboard.js](https://github.com/zenorocha/clipboard.js) | 2.0.11 | MIT | Copyright (c) Zeno Rocha | `docs/site_libs/clipboard/clipboard.min.js` |
| [Fuse.js](https://github.com/krisk/Fuse) | 6.6.2 | Apache-2.0 | Copyright (c) 2022 Kiro Risk (from the file header; the upstream LICENSE has no copyright line, and the project has no NOTICE file) | `docs/site_libs/quarto-search/fuse.min.js` |

`scripts/build-phar.php` can build a PHAR that bundles juphyter's production
Composer dependency, [nikic/php-parser](https://github.com/nikic/PHP-Parser)
(BSD-3-Clause), with its own `LICENSE` file from `vendor/nikic/php-parser/`.
No PHAR is published.
