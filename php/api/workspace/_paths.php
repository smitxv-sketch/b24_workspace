<?php
/**
 * SSOT для путей workspace API.
 * Меняем базовые директории только здесь.
 */

// Runtime-корень (lib и базовые конфиги ядра bproc).
// Обычно в проде это исторический путь.
const WS_BPROC_RUNTIME_ROOT = '/local/bproc';

// Корень process-конфигов и workspace_roles (проектный SSOT).
const WS_BPROC_CONFIG_ROOT_PRIMARY  = '/local/ws/bproc';
const WS_BPROC_CONFIG_ROOT_FALLBACK = '/local/bproc';

/**
 * Преобразует веб-путь в абсолютный путь на файловой системе.
 */
function wsDocPath(string $webPath): string {
    return rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $webPath;
}

function wsBprocRoot(): string {
    return WS_BPROC_RUNTIME_ROOT;
}

function wsBprocLibRoot(): string {
    return wsBprocRoot() . '/lib';
}

function wsBprocConfigRoot(): string {
    // Ручной override для диагностики:
    // ?ws_cfg_root=/local/ws/php/bproc
    $forced = (string)($_GET['ws_cfg_root'] ?? '');
    // Безопасность: разрешаем override только в admin-debug режиме.
    $allowOverride = defined('WS_DEBUG') && WS_DEBUG;
    if ($allowOverride && $forced !== '' && str_starts_with($forced, '/local/')) {
        $forcedRoot = rtrim($forced, '/');
        // Защита: применяем override только если каталог процессов реально существует.
        if (is_dir(wsDocPath($forcedRoot . '/processes'))) {
            return $forcedRoot;
        }
    }

    // Для process-конфигов приоритет у проектной папки /local/ws/php/bproc.
    if (is_dir(wsDocPath(WS_BPROC_CONFIG_ROOT_PRIMARY . '/processes'))) {
        return WS_BPROC_CONFIG_ROOT_PRIMARY;
    }
    return WS_BPROC_CONFIG_ROOT_FALLBACK;
}

function wsBprocProcessesDir(): string {
    return wsBprocConfigRoot() . '/processes';
}

function wsBprocRolesDir(): string {
    return wsBprocConfigRoot() . '/workspace_roles';
}

