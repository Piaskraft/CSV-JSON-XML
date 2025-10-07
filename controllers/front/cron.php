<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Pk_Supplier_HubCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        header('Content-Type: application/json; charset=utf-8');

        $token     = Tools::getValue('token');
        $idSource  = (int)Tools::getValue('id_source');
        $dry       = (int)Tools::getValue('dry', 1) === 1;

        // Token: z konfiguracji, a jak brak, użyj bezpiecznego fallbacku
        $cfgToken = Configuration::get('PKSH_CRON_TOKEN');
        if (empty($cfgToken)) {
            // Fallback: fragment _COOKIE_KEY_ (możesz potem ustawić stały token w BO)
            $cfgToken = Tools::substr(_COOKIE_KEY_, 8, 24);
        }

        if (!$token || $token !== $cfgToken) {
            http_response_code(403);
            die(json_encode(['ok'=>false, 'error'=>'forbidden']));
        }
        if ($idSource <= 0) {
            http_response_code(400);
            die(json_encode(['ok'=>false, 'error'=>'missing id_source']));
        }

        $svc = new PkshRunService();

        if (!$svc->acquireLock()) {
            http_response_code(409);
            die(json_encode(['ok'=>false, 'error'=>'lock']));
        }

        $idRun = 0;
        try {
            $checksum = md5(($dry ? 'dry' : 'real').'-'.$idSource.'-'.time());
            $idRun = $svc->startRun($idSource, $dry, $checksum);
            if (!$idRun) {
                throw new \RuntimeException('Cannot start run');
            }

            if ($dry) {
                $stats = $svc->runDry($idSource);
            } else {
                $stats = $svc->runReal($idSource, $idRun);
            }

            $svc->finishRun($idRun, $stats, 'success');

            echo json_encode(['ok'=>true, 'id_run'=>$idRun, 'stats'=>$stats]);
        } catch (\Throwable $e) {
            if ($idRun) {
                $svc->finishRun($idRun, ['errors'=>1], 'failed');
            }
            http_response_code(500);
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        } finally {
            $svc->releaseLock();
        }
        exit;
    }
}
