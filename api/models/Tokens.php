<?php

use Phalcon\Mvc\Model;
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;
use Phalcon\Config\Adapter\Ini as ConfigIni;

class Tokens extends Model {

    public $id;

    function verificar_token($token) {

        $config = new ConfigIni('../config/config.ini');
        $formatter = new Line('{"date":"%date%","type":"%type%",%message%},');
        $formatter->setDateFormat('Y-m-d H:i:s');
        $logger = new FileAdapter($config->sistema->path_log . "convocatorias." . date("Y-m-d") . ".log");
        $logger->setFormatter($formatter);

        try {
            //Fecha actual
            $fecha_actual = date("Y-m-d H:i:s");
            //Consulto y elimino todos los tokens que ya no se encuentren vigentes
            $tokens_eliminar = Tokens::find("date_limit<='" . $fecha_actual . "'");
            $tokens_eliminar->delete();
            //Consulto si el token existe y que este en el periodo de session
            $tokens = Tokens::findFirst("'" . $fecha_actual . "' BETWEEN date_create AND date_limit AND token = '" . $token . "'");
            //Verifico si existe para retornar
            if (isset($tokens->id)) {
                return $tokens;
            } else {
                return false;
            }
        } catch (Exception $ex) {
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el metodo verificar_token ' . $ex->getMessage() . ' "', ['user' => 'token@scrd.gov.co', 'token' => $token]);
            $logger->close();
            echo false;
        }
    }

    function permiso_lectura($user_current,$modulo) {

        try {                                  

            //Consultar todos los permisos
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='" . $modulo . "' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=" . $user_current . ")";
            $permisos = $this->modelsManager->executeQuery($phql);

            if (count($permisos) > 0) {
                return "ok";
            } else {
                return "acceso_denegado";
            } 
            
        } catch (Exception $ex) {
            return "error_metodo" . $ex;
        }
    }

}
