<?php
/**
 * SSOT для путей workspace API.
 * Меняем базовые директории только здесь.
 */

// Кандидаты корня bproc для разных схем деплоя.
const WS_BPROC_ROOT_CANDIDATES = [
    '/local/bproc',
    '/local/ws/bproc',
    '/local/ws/php/bproc',
];

/**
 * Преобразует веб-путь в абсолютный путь на файловой системе.
 */
function wsDocPath(string $webPath): string {
    return rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $webPath;
}

/**
 * Возвращает актуальный web-root для bproc.
 * Ищем по ключевому файлу lib/BpLog.php.
 */
function wsBprocRoot(): string {
    foreach (WS_BPROC_ROOT_CANDIDATES as $candidate) {
        if (file_exists(wsDocPath($candidate . '/lib/BpLog.php'))) {
            return $candidate;
        }
    }

    // Фолбэк на исторический путь.
    return '/local/bproc';
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

