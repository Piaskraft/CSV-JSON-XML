<?php
if (!defined('_PS_VERSION_')) { exit; }

class Pk_Supplier_HubCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true; // wymuszamy HTTPS jeśli sklep ma SSL

    public function initContent()
    {
        parent::initContent();

        // Nagłówek JSON
        header('Content-Type: application/json; charset=utf-8');

        try {
            // 1) Weryfikacja tokena
            $token = (string)Tools::getValue('token');
            $expected = (string)Configuration::get('PKSH_CRON_TOKEN');
            if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
                http_response_code(401);
                die(json_encode(['ok'=>false, 'error'=>'unauthorized']));
            }

            // 2) Parametry
            $idSource = (int)Tools::getValue('id_source');
            $dry = (int)Tools::getValue('dry', 1) === 1;

            if ($idSource <= 0) {
                http_response_code(400);
                die(json_encode(['ok'=>false, 'error'=>'missing id_source']));
            }

            // 3) Załaduj serwisy (na wszelki wypadek require_once – działa też bez kompozytora)
            $base = _PS_MODULE_DIR_.'pk_supplier_hub/';
            @require_once $base.'classes/RunService.php';
            @require_once $base.'classes/DiffService.php';
            @require_once $base.'classes/GuardService.php';
            @require_once $base.'classes/StockUpdater.php';
            @require_once $base.'classes/PriceUpdater.php';
            @require_once $base.'classes/HttpClient.php';
            @require_once $base.'classes/EcbProvider.php';

            if (!class_exists('PkshRunService')) {
                throw new \RuntimeException('RunService not found');
            }

            $service = new PkshRunService();

            // 4) Lock – brak równoległych uruchomień
            if (!$service->acquireLock()) {
                http_response_code(409);
                die(json_encode(['ok'=>false, 'error'=>'locked']));
            }

            $idRun = 0;
            try {
                // 5) Start run
                $checksum = md5(($dry ? 'dry' : 'real').'-'.$idSource.'-'.time());
                $idRun = $service->startRun($idSource, $dry, $checksum);
                if (!$idRun) {
                    throw new \RuntimeException('Cannot start run');
                }

                // 6) Dry lub Real
                if ($dry) {
                    $stats = $service->runDry($idSource);
                    $service->finishRun($idRun, $stats, 'ok', 'cron dry');
                } else {
                    $stats = $service->runReal($idSource, $idRun);
                    $service->finishRun($idRun, $stats, 'ok', 'cron real');
                }

                // 7) OK
                http_response_code(200);
                echo json_encode([
                    'ok' => true,
                    'id_run' => (int)$idRun,
                    'dry' => $dry,
                    'stats' => $stats
                ]);
            } catch (\Throwable $e) {
                if ($idRun) {
                    $service->finishRun($idRun, ['errors'=>1], 'error', $e->getMessage());
                }
                http_response_code(500);
                echo json_encode(['ok'=>false, 'error'=>'exception', 'message'=>$e->getMessage()]);
            } finally {
                $service->releaseLock();
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok'=>false, 'error'=>'fatal', 'message'=>$e->getMessage()]);
        }
        exit;
    }
}
