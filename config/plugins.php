<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plugins Directory
    |--------------------------------------------------------------------------
    |
    | The absolute path for loading plugins.
    | Defaults to `base_path()."/plugins"`.
    |
    */
    'directory' => menv('PLUGINS_DIR'),

    /*
    |--------------------------------------------------------------------------
    | Plugins Assets URL
    |--------------------------------------------------------------------------
    |
    | The URL to access plugin's assets (CSS, JavaScript etc.).
    | Defaults to `http://site_url/plugins`.
    |
    */
    'url' => menv('PLUGINS_URL'),

    /*
    |--------------------------------------------------------------------------
    | Plugins Market Source
    |--------------------------------------------------------------------------
    |
    | Specify where to get plugins' metadata for plugin merket.
    |
    */
    'registry' => menv('PLUGINS_REGISTRY', 'https://work.prinzeugen.net/blessing-skin-server/plugins.json'),
];
