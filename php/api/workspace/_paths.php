<?php
/**
 * SSOT для путей workspace API.
 * Меняем базовые директории только здесь.
 */

// SSOT: конфиги и lib bproc живут тут.
const WS_BPROC_ROOT = '/local/bproc';

/**
 * Преобразует веб-путь в абсолютный путь на файловой системе.
 */
function wsDocPath(string $webPath): string {
    return rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $webPath;
}

function wsBprocRoot(): string {
    return WS_BPROC_ROOT;
}

function wsBprocLibRoot(): string {
    return wsBprocRoot() . '/lib';
}

function wsBprocProcessesDir(): string {
    return wsBprocRoot() . '/processes';
}

function wsBprocRolesDir(): string {
    return wsBprocRoot() . '/workspace_roles';
}

