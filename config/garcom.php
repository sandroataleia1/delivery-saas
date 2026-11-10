<?php

/*
| App do garçom (Android). Ao publicar uma versão que exige atualização, suba
| GARCOM_MIN_VERSION_CODE para o versionCode dela: versões anteriores pedem para atualizar.
*/

return [
    'min_version_code' => (int) env('GARCOM_MIN_VERSION_CODE', 1),
];
