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
            //Registro en el log el token que llega
            $logger->info('"token":"{token}","user":"{user}","message":"El token que ingresa al metodo"', ['user' => 'token@scrd.gov.co', 'token' => $token]);            
            //Fecha actual
            $fecha_actual = date("Y-m-d H:i:s");
            //Consulto y elimino todos los tokens que ya no se encuentren vigentes
            $tokens_eliminar = Tokens::find("date_limit<='" . $fecha_actual . "'");
            $tokens_eliminar->delete();
            //Consulto si el token existe y que este en el periodo de session
            $tokens = Tokens::findFirst("'" . $fecha_actual . "' BETWEEN date_create AND date_limit AND token = '" . $token . "'");
            
            //Registro en el log el token que consulto
            $logger->info('"token":"{token}","user":"{user}","message":"El token que consulto es '. json_encode($tokens).'"', ['user' => 'token@scrd.gov.co', 'token' => $token]);
            
            //Verifico si existe para retornar
            if (isset($tokens->id)) {
                //Registro en el log el token que consulto
                $logger->info('"token":"{token}","user":"{user}","message":"El token que retorno '. json_encode($tokens).'"', ['user' => 'token@scrd.gov.co', 'token' => $token]);
                $logger->close();
                return $tokens;
            } else {
                $logger->error('"token":"{token}","user":"{user}","message":"El token no existe."', ['user' => 'token@scrd.gov.co', 'token' => $token]);
                $logger->close();
                return false;
            }
        } catch (Exception $ex) {
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el metodo verificar_token '.$ex->getMessage().' "', ['user' => 'token@scrd.gov.co', 'token' => $token]);
            $logger->close();
            echo false;
        }
    }

}
