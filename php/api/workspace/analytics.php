<?php
/**
 * GET /local/api/workspace/analytics.php
 * Аналитика: воронка по статусам + дисциплина по шагам.
 *
 * Параметры:
 *   process_key (required)
 *   period      (optional) 'month' | 'year'
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once __DIR__ . '/_paths.php';
require_once wsDocPath('/bitrix/modules/main/include/prolog_before.php');
require_once wsDocPath(wsBprocLibRoot() . '/BpLog.php');
require_once wsDocPath(wsBprocLibRoot() . '/BpStorage.php');
require_once wsDocPath(wsBprocRoot() . '/config_bp_constants.php');
require_once wsDocPath(wsBprocRoot() . '/config_process_steps.php');
require_once __DIR__ . '/_shared.php';

BpLog::registerFatalHandler('ws_analytics');
BpLog::init(fn(string $m) => null, BpLog::LEVEL_OFF, BpLog::LEVEL_DEBUG);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

global $USER;
if (!$USER->IsAuthorized()) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'unauthorized']); exit; }

$currentUserId = (int)$USER->GetID();
define('WS_DEBUG', !empty($_GET['debug']) && $_GET['debug'] === 'Y' && $USER->IsAdmin());

try {
    $processKey = preg_replace('/[^a-z0-9_]/', '', $_GET['process_key'] ?? '');
    $period     = in_array($_GET['period'] ?? '', ['month','year']) ? $_GET['period'] : 'month';

    if (!$processKey) wsJsonErr('process_key_required');

    [$wsCfg, $config] = wsRequireProcessConfigs($processKey);

    $entityTypeId = $config['match']['entityTypeId'] ?? 2;
    $categoryId   = $config['match']['categoryId'] ?? 1;
    $amountField  = $wsCfg['amount_field'] ?? 'UF_CONTRACT_AMOUNT';
    $stepsConfig  = $config['steps'] ?? [];
    $fields       = collectFieldsFromConfig($config, $wsCfg);

    // Период
    if ($period === 'year') {
        $periodFilter = ['start' => date('Y-01-01 00:00:00'), 'end' => date('Y-12-31 23:59:59'), 'label' => date('Y') . ' год'];
    } else {
        $months = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
        $periodFilter = [
            'start' => date('Y-m-01 00:00:00'),
            'end'   => date('Y-m-t 23:59:59'),
            'label' => $months[(int)date('n') - 1] . ' ' . date('Y'),
        ];
    }

    // Все сделки периода
    \Bitrix\Main\Loader::includeModule('crm');

    // Базовый фильтр для выборки.
    $crmFilter = [
        'CATEGORY_ID'       => $categoryId,
        '>=DATE_CREATE'     => (string)$periodFilter['start'],
        '<=DATE_CREATE'     => (string)$periodFilter['end'],
        'CHECK_PERMISSIONS' => 'N',
    ];
    WsDiag::add('analytics_query_filter_primary', ['filter' => $crmFilter], 2);

    try {
        $res = \CCrmDeal::GetListEx(
            ['DATE_CREATE' => 'DESC'],
            $crmFilter,
            false, ['nPageSize' => 500],
            $fields
        );
    } catch (\Bitrix\Main\DB\SqlQueryException $e) {
        // На некоторых инсталляциях встречается SQL-ошибка по DATE_CREATE.
        // Деградируем мягко: повторяем запрос без диапазона даты, чтобы не падать 500.
        WsDiag::add('analytics_query_fallback_triggered', [
            'reason' => $e->getMessage(),
            'primary_filter' => $crmFilter,
        ], 1);

        $fallbackFilter = [
            'CATEGORY_ID'       => $categoryId,
            'CHECK_PERMISSIONS' => 'N',
        ];
        WsDiag::add('analytics_query_filter_fallback', ['filter' => $fallbackFilter], 1);

        $res = \CCrmDeal::GetListEx(
            ['DATE_CREATE' => 'DESC'],
            $fallbackFilter,
            false, ['nPageSize' => 500],
            $fields
        );
    }

    $deals = [];
    while ($row = $res->Fetch()) $deals[] = $row;

    // Воронка
    $funnelConfig = $wsCfg['funnel_statuses'] ?? [];
    $counts  = array_fill_keys(array_keys($funnelConfig), 0);
    $amounts = array_fill_keys(array_keys($funnelConfig), 0);

    // Маппинг стадий берём из process-конфига (SSOT):
    // crmStagesSpecial: [funnel_key => stage_id], нам нужен [stage_id => funnel_key].
    $crmStagesSpecial = $config['crmStagesSpecial'] ?? [];
    $stageToFunnel = array_flip($crmStagesSpecial); // stage_id => funnel_key
    WsDiag::add('analytics_stage_mapping', [
        'crmStagesSpecial_keys' => array_keys($crmStagesSpecial),
        'mapped_stage_ids' => array_keys($stageToFunnel),
    ], 2);

    foreach ($deals as $deal) {
        $semantic = $deal['STAGE_SEMANTIC_ID'] ?? '';
        $stageId  = $deal['STAGE_ID'] ?? '';
        $amount   = (float)($deal[$amountField] ?? 0);

        if ($semantic === 'S') { // WON
            $counts['won']  = ($counts['won'] ?? 0) + 1;
            $amounts['won'] = ($amounts['won'] ?? 0) + $amount;
        } elseif ($semantic === 'P') { // В процессе
            $counts['active']  = ($counts['active'] ?? 0) + 1;
            $amounts['active'] = ($amounts['active'] ?? 0) + $amount;
        } elseif (isset($stageToFunnel[$stageId])) {
            $fKey = $stageToFunnel[$stageId];
            $counts[$fKey]  = ($counts[$fKey] ?? 0) + 1;
            $amounts[$fKey] = ($amounts[$fKey] ?? 0) + $amount;
        }
    }

    $funnel = [];
    foreach ($funnelConfig as $key => $def) {
        $funnel[] = ['key'=>$key,'label'=>$def['label'],'color'=>$def['color'],'count'=>$counts[$key]??0,'amount'=>(int)($amounts[$key]??0)];
    }

    // Средние времена по шагам
    // Берём активные сделки и считаем из их state
    $stepTotals = []; $stepCounts = [];
    foreach ($deals as $deal) {
        if (($deal['STAGE_SEMANTIC_ID'] ?? '') === 'F' && !in_array($deal['STAGE_SEMANTIC_ID'], ['P','S'], true)) continue;
        $fCode = getFieldCode($entityTypeId, 'json');
        $state = BpStorage::readJson($entityTypeId, (int)$deal['ID'], $fCode);
        if (empty($state)) continue;

        foreach ($stepsConfig as $stepKey => $stepCfg) {
            if (!($stepCfg['deadline_hours'] ?? null)) continue;
            $history = $state['stages'][$stepKey]['history'] ?? [];
            $startTs = null; $endTs = null;
            foreach ($history as $ev) {
                if ($ev['status']==='work' && !$startTs) $startTs = strtotime($ev['date']??'');
                if (in_array($ev['status'],['done','fail'],true)) $endTs = strtotime($ev['date']??'');
            }
            if (!$startTs || !$endTs) continue;
            $h = ($endTs - $startTs) / 3600;
            $stepTotals[$stepKey] = ($stepTotals[$stepKey] ?? 0) + $h;
            $stepCounts[$stepKey] = ($stepCounts[$stepKey] ?? 0) + 1;
        }
    }

    $stepAverages = [];
    foreach ($stepsConfig as $stepKey => $stepCfg) {
        $planH = $stepCfg['deadline_hours'] ?? null;
        if (!$planH) continue;
        $cnt  = $stepCounts[$stepKey] ?? 0;
        $avgH = $cnt > 0 ? round($stepTotals[$stepKey] / $cnt, 1) : null;
        $stepAverages[] = [
            'step_key'   => $stepKey,
            'label'      => $stepCfg['label'] ?? $stepKey,
            'plan_hours' => $planH,
            'avg_hours'  => $avgH,
            'state'      => $avgH === null ? 'no_data' : ($avgH > $planH ? 'overdue' : 'ok'),
        ];
    }

    // Детализация по активным заявкам
    $breakdown = [];
    foreach (array_slice($deals, 0, 10) as $deal) {
        if (($deal['STAGE_SEMANTIC_ID'] ?? '') !== 'P') continue;
        $fCode  = getFieldCode($entityTypeId, 'json');
        $state  = BpStorage::readJson($entityTypeId, (int)$deal['ID'], $fCode);
        $steps  = [];
        foreach ($stepsConfig as $stepKey => $stepCfg) {
            if (!($stepCfg['deadline_hours'] ?? null)) continue;
            $history  = $state['stages'][$stepKey]['history'] ?? [];
            $startTs  = null; $endTs = null;
            $stepState_inner = $state['stages'][$stepKey]['status'] ?? 'wait';
            foreach ($history as $ev) {
                if ($ev['status']==='work' && !$startTs) $startTs = strtotime($ev['date']??'');
                if (in_array($ev['status'],['done','fail'],true)) $endTs = strtotime($ev['date']??'');
            }
            $isRunning = $startTs && !$endTs;
            $hours     = $startTs ? round(($endTs ?: time()) - $startTs) / 3600 : null;
            $steps[$stepKey] = [
                'hours' => $hours !== null ? round($hours, 1) : null,
                'state' => $isRunning ? 'running' : ($hours === null ? 'pending' : ($hours > ($stepCfg['deadline_hours']??999) ? 'overdue' : 'ok')),
            ];
        }
        $breakdown[] = ['entity_id'=>(int)$deal['ID'],'title'=>'#'.$deal['ID'].' '.(mb_substr($deal['TITLE']??'',0,30)),'steps'=>$steps];
    }

    wsJsonOk([
        'period'        => $period,
        'period_label'  => $periodFilter['label'],
        'funnel'        => $funnel,
        'step_averages' => $stepAverages,
        'deal_breakdown'=> $breakdown,
    ]);

} catch (\Throwable $e) {
    wsHandleThrowable('ws_analytics', $e);
}
