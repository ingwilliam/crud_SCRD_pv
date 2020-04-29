<?php

use Phalcon\Mvc\Model;

class Tokens extends Model {

    public $id;

    function verificar_token($token) {
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
            echo false;
        }
    }

}
