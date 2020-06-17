<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
use Phalcon\Mvc\Model\Query;
use Phalcon\Db\RawValue;
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

$config = new ConfigIni('../config/config.ini');

// Registramos un autoloader
$loader = new Loader();

$loader->registerDirs(
        [
            APP_PATH . '/models/',
            APP_PATH . '/library/class/',
        ]
);

$loader->register();

// Crear un DI
$di = new FactoryDefault();

//Set up the database service
$di->set('db', function () use ($config) {
    return new DbAdapter(
            array(
        "host" => $config->database->host, "port" => $config->database->port,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});

//Funcionalidad para crear los log de la aplicación
//la carpeta debe tener la propietario y usuario
//sudo chown -R www-data:www-data log/
//https://docs.phalcon.io/3.4/es-es/logging
$formatter = new Line('{"date":"%date%","type":"%type%",%message%},');
$formatter->setDateFormat('Y-m-d H:i:s');
$logger = new FileAdapter($config->sistema->path_log . "convocatorias." . date("Y-m-d") . ".log");
$logger->setFormatter($formatter);


$app = new Micro($di);

//Retorna información de id y nombre de las convocatorias
$app->get('/select_convocatorias', function () use ($app, $logger) {

    try {

        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_convocatorias ' . json_encode($request->get()) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Si existe consulto la convocatoria
            if ($request->get('entidad') && $request->get('anio')) {

                $rs = Convocatorias::find(
                                [
                                    " entidad = " . $request->get('entidad')
                                    . " AND anio = " . $request->get('anio')
//                    ." AND estado = 5 " //5	convocatorias	Publicada
                                    . " AND estado in (5, 43) " //5	convocatorias	Publicada y desierta
                                    . " AND modalidad != 2 " //2	Jurados
                                    . " AND active = true "
                                    . " AND convocatoria_padre_categoria is NULL",
                                    'order' => 'nombre'
                                ]
                );

                foreach ($rs as $convocatoria) {
                    array_push($response, ["id" => $convocatoria->id, "nombre" => $convocatoria->nombre]);
                }
            }

            return json_encode($response);
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_convocatorias Error token"',
                    ['user' => '', 'token' => $request->get('token')]);
            $logger->close();

            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        //return "error_metodo".$ex->getMessage();
        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_convocatorias error_metodo ' . json_encode($ex) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        return "error_metodo";
    }
}
);

//Retorna información de id y nombre de las categorias de la convocatoria
$app->get('/select_categorias', function () use ($app, $logger) {

    try {

        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_categorias ' . json_encode($request->get()) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla

        if (isset($token_actual->id)) {

            //Si existe consulto la convocatoria
            if ($request->get('convocatoria')) {

                $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));

                if ($convocatoria->tiene_categorias) {

                    $categorias = Convocatorias::find(
                                    [
                                        ' convocatoria_padre_categoria = ' . $convocatoria->id
                                        . ' AND active = true ',
                                        'order' => 'nombre'
                                    ]
                    );

                    foreach ($categorias as $categoria) {
                        array_push($response, ["id" => $categoria->id, "nombre" => $categoria->nombre]);
                    }
                }
            }

            return json_encode($response);
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_categorias Error token"',
                    ['user' => '', 'token' => $request->get('token')]);
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        //return "error_metodo".$ex->getMessage();
        //return "error_metodo".$ex->getMessage();
        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_categorias error_metodo ' . json_encode($ex) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        return "error_metodo";
    }
}
);

//Retorna información de id y nombre de las categorias de la convocatoria
$app->get('/select_estado', function () use ($app, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_estado ' . json_encode($request->get()) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_estado::token_actual->' . json_encode($token_actual) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        //Si el token existe y esta activo entra a realizar la tabla
        //if (isset($token_actual->id)) {
        if (isset($token_actual->id)) {

            //Si existe consulto la convocatoria
            if ($request->get('tipo_estado')) {
                $estados = Estados::find("tipo_estado = '" . $request->get('tipo_estado') . "'");

                if ($estados) {

                    foreach ($estados as $estado) {
                        array_push($response, ["id" => $estado->id, "nombre" => $estado->nombre]);
                    }
                }
            }

            return json_encode($response);
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_estado error_token"',
                    ['user' => '', 'token' => $request->get('token')]);
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {

        //return "error_metodo".$ex->getMessage();
        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/select_estado error_metodo' . json_encode($ex) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();
        return "error_metodo";
    }
}
);

//Retorna información de las propuestas que el usuario que inicio sesion  puede evaluar
$app->get('/all_propuestas', function () use ($app, $logger) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Deliberacion/all_propuestas ' . json_encode($request->get()) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if ($user_current["id"]) {

                if ($request->get('ronda')) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $request->get('ronda'));

                    if (isset($ronda->id)) {

                        /**
                         * Listar las propuestas que estan registradas para evaluar
                         */
                        //fase de evaluación o de deliberación
                        $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                                /* Cuando el estado de la ronda es En deliberación, muestra los registros
                                  de evaluacionpropuesta donde fase=Deliberación */
                                /* Cuando el estado de la ronda es Evaluada, muestra los registros de
                                  evaluacionpropuesta donde fase=Deliberación debido a que fue la última calificacón registrada */
                                ( ($ronda->getEstado_nombre() == "En deliberación" || $ronda->getEstado_nombre() == "Evaluada") ? 'Deliberación' : "" ) );

                        //Propuestas habilitadas
                        //Estado propuestas	Habilitada
                        $estado_habilitada = Estados::findFirst(" tipo_estado = 'propuestas' AND nombre = 'Habilitada' ");

                        //todas las propuestas
                        $allpropuestas = Propuestas::find(
                                        [
                                            ' convocatoria = ' . $ronda->convocatoria
//                           .' AND estado = '.$estado_habilitada->id,
                                            . ' AND estado in (24,33)',
                                            'order' => 'id ASC',
                                        ]
                        );

                        $response = array();
                        // propuestas_evaluacion	Confirmada
                        $estado_confirmada = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Confirmada' ");
//            $estado_confirmada = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Confirmada'  or nombre='En evaluación' or nombre='Evaluada'");

                        $query = " SELECT
                                  distinct p.id,p.codigo,p.nombre,
                                  (	SELECT
                                      sum(total) AS total
                                    FROM
                                      Evaluacionpropuestas e
                                   WHERE
                                      e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS suma,
                                  (	SELECT
                                      count(e.id) AS cantidad
                                    FROM
                                      Evaluacionpropuestas e
                                    WHERE
                                        e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS cantidad,
                                  (	SELECT
                                      avg(total) AS promedio
                                    FROM
                                      Evaluacionpropuestas e
                                    WHERE
                                    e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS promedio,
                              p.estado
                              FROM
                                Propuestas AS p
                                INNER JOIN
                                   Evaluacionpropuestas as ep2
                                ON p.id = ep2.propuesta
                              WHERE
                                p.convocatoria  = " . $ronda->convocatoria . " AND p.estado in (24,33) "; //.$estado_habilitada->id;
                        $query .= "  AND ep2.estado = " . $estado_confirmada->id . " AND fase = '" . $fase . "' AND ep2.ronda = " . $ronda->id;
                        $query .= "  ORDER BY promedio DESC limit " . $request->get('length');
                        $query .= " offset " . $request->get('start');

                        $resultset = $this->modelsManager->executeQuery($query);

                        //10-06-2020 - Wilmer Mogollón - Se redondea a 1 cifras el promedio
                        foreach ($resultset as $key => $row) {
                            $row->promedio = round($row->promedio, 1);
                            $row->estado = (Estados::findFirst("id = " . $row->estado))->nombre;
                            array_push($response, $row);
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error"',
                                ['user' => $user_current, 'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "error";
                    }
                }
            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval(count($allpropuestas)),
                "recordsFiltered" => intval(count($allpropuestas)),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_token"',
                    ['user' => '', 'token' => $request->get('token')]);
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        return "error_metodo" . $ex->getMessage();
        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_metodo ' . json_encode($ex) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();
        //return "error_metodo";
    }
});


//Retorna información de las propuestas que el usuario que inicio sesion  puede evaluar
$app->get('/all_evaluaciones/propuesta/{id:[0-9]+}', function ($id) use ($app, $logger) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"/all_evaluaciones/propuesta/{id:[0-9]+}/ronda/{ronda:[0-9]+} ' . json_encode($request->get()) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            $ronda = Convocatoriasrondas::findFirst('id = ' . $request->get("ronda"));

            if (isset($ronda->id)) {

                //fase de evaluación o de deliberación
                $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                        ( $ronda->getEstado_nombre() == "En deliberación" ? 'Deliberación' : "" ) );

                $evaluaciones = Evaluacionpropuestas::find(" propuesta = " . $id . " AND ronda = " . $ronda->id . " AND fase = '" . $fase . "'");

                foreach ($evaluaciones as $key => $evaluacion) {

                    $evaluador = Evaluadores::findFirst('id = ' . $evaluacion->evaluador);
                    $juradopostulado = Juradospostulados::findFirst(' id = ' . $evaluador->juradopostulado);
                    $participante = $juradopostulado->Propuestas->Participantes;
                    array_push($response, [
                        "jurado_codigo" => $juradopostulado->Propuestas->codigo,
                        "jurado_nombre" => $participante->primer_nombre
                        . " " . $participante->segundo_nombre
                        . " " . $participante->primer_apellido
                        . " " . $participante->segundo_apellido,
                        "evaluacion_total" => $evaluacion->total,
                        "evaluacion_estado" => (Estados::findFirst(' id = ' . $evaluacion->estado))->nombre,
                        "evaluacion_fase" => $evaluacion->fase
                            ]
                    );
                }

                return json_encode($response);
            }
        }
    } catch (Exception $ex) {
        return "error_metodo" . $ex->getMessage();
        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_metodo ' . json_encode($ex) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();
        //return "error_metodo";
    }
});


//Guarda la evaluación de los criterios
$app->post('/deliberar/ronda/{ronda:[0-9]+}', function ($ronda) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $total_evaluacion = 0;
        $fase = '';
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));


        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $user_current = json_decode($token_actual->user_current, true);

                $ronda = Convocatoriasrondas::findFirst('id = ' . $ronda);

                if (isset($ronda->id)) {

                    //fase de evaluación o de deliberación
                    $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                            ( $ronda->getEstado_nombre() == "En deliberación" ? 'Deliberación' : "" ) );

                    /*
                     * Se actualiza el estado de la ronda, pasa a deliberación.
                     * Se crean las evaluaciones de la fase de deliberación
                     */
                    if ($fase == 'Evaluación') {

                        // Start a transaction
                        $this->db->begin();

                        $evaluaciones = Evaluacionpropuestas::find(" ronda = " . $ronda->id . " AND fase = '" . $fase . "'");

                        //se cambia el estado a la ronda
                        $estado_ronda = Estados::findFirst(" tipo_estado = 'convocatorias_rondas' AND nombre = 'En deliberación' ");
                        $ronda->estado = $estado_ronda->id;

                        if (!$ronda->save()) {
                            $this->db->rollback();
                            return "error_ronda";
                        }

                        //se crean las evaluaciones en fase de deliberación
                        foreach ($evaluaciones as $key => $evaluacion) {

                            $new_evaluacion = clone $evaluacion;

                            $new_evaluacion->id = null;
                            $new_evaluacion->fase = 'Deliberación';
                            $new_evaluacion->fecha_creacion = date("Y-m-d H:i:s");
                            $new_evaluacion->creado_por = $user_current["id"];
                            $new_evaluacion->fecha_actualizacion = null;
                            $new_evaluacion->actualizado_por = null;
                            /*
                             * Se establece el estado en evaluación de la evaluacion en fase de Deliberación,
                              para que pueda ser evaluada
                             */
                            $new_evaluacion->estado = (Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'En evaluación' "))->id;

                            if (!$new_evaluacion->save()) {

                                //Para auditoria en versión de pruebas
                                foreach ($new_evaluacion->getMessages() as $message) {
                                    echo $message;
                                }
                                $this->db->rollback();
                                return "error";
                            } else {

                                $evaluacioncriterios = Evaluacioncriterios::find(" evaluacionpropuesta = " . $evaluacion->id);

                                //por cada evaluacion se crean los criterios de la fase de deliberacion
                                foreach ($evaluacioncriterios as $key => $evaluacioncriterio) {

                                    $new_evaluacioncriterio = clone $evaluacioncriterio;

                                    $new_evaluacioncriterio->id = null;
                                    $new_evaluacioncriterio->evaluacionpropuesta = $new_evaluacion->id;
                                    $new_evaluacioncriterio->fecha_creacion = date("Y-m-d H:i:s");
                                    $new_evaluacioncriterio->creado_por = $user_current["id"];
                                    $new_evaluacioncriterio->fecha_actualizacion = null;
                                    $new_evaluacioncriterio->actualizado_por = null;

                                    if (!$new_evaluacioncriterio->save()) {

                                        //Para auditoria en versión de pruebas
                                        foreach ($new_evaluacioncriterio->getMessages() as $message) {
                                            echo $message;
                                        }

                                        $this->db->rollback();
                                        return "error";
                                    }
                                }//Fin foreach, crear los criterios de la evaluacion
                            }
                        }//Fin foreach, crear evaluaciones
                        // Commit the transaction
                        $this->db->commit();

                        return "exito";
                    }

                    if ($fase == 'Deliberación') {

                        return "deliberacion";
                    }
                }//fin if
            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
}
);

//Retorna información de las propuestas que el usuario que inicio sesion  puede evaluar
$app->get('/validar_confirmacion/ronda/{id:[0-9]+}', function ($id) use ($app, $logger) {

    try {



        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        $allpropuestas = array();
        $resultset = array();

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Deliberacion/all_propuestas ' . json_encode($request->get()) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);


            if ($user_current["id"]) {


                $ronda = Convocatoriasrondas::findFirst('id = ' . $id);

                if (isset($ronda->id)) {

                    /**
                     * Listar las propuestas que estan registradas para evaluar
                     */
                    //fase de evaluación o de deliberación
                    $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                            ( ($ronda->getEstado_nombre() == "En deliberación" || $ronda->getEstado_nombre() == "Evaluada") ? 'Deliberación' : "" ) );

                    //Propuestas habilitadas
                    //Estado propuestas	Habilitada
                    $estado_habilitada = Estados::findFirst(" tipo_estado = 'propuestas' AND nombre = 'Habilitada' ");

                    //todas las propuestas
                    $allpropuestas = Propuestas::find(
                                    [
                                        ' convocatoria = ' . $ronda->convocatoria
                                        . ' AND estado = ' . $estado_habilitada->id,
                                        'order' => 'id ASC',
                                        'offset' => 0,
                                        'limit' => ( intval($request->get('total_ganadores')) + intval($request->get('total_suplentes')) )
                                    ]
                    );

                    $response = array();
                    // propuestas_evaluacion	Confirmada
                    $estado_confirmada = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Confirmada' ");
                    $estado_impedimento = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Impedimento' ");


                    //se verifica que todas las evaluaciones esten confirmadas
                    $propuestas_evaluacion = Evaluacionpropuestas::find([
                                " ronda = " . $ronda->id
                                . " AND fase = '" . $fase . "'"
                    ]);
                    foreach ($propuestas_evaluacion as $key => $evaluacion) {

                        if ($evaluacion->estado != $estado_confirmada->id && $evaluacion->estado != $estado_impedimento->id) {
                            return "error_confirmacion";
                        }
                    }

                    return "exito";
                } else {
                    $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error"',
                            ['user' => $user_current, 'token' => $request->get('token')]
                    );
                    $logger->close();

                    return "error";
                }
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_token"',
                    ['user' => '', 'token' => $request->get('token')]);
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        return "error_metodo" . $ex->getMessage();
        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_metodo ' . json_encode($ex) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();
        //return "error_metodo";
    }
});


//Retorna información de las propuestas que el usuario que inicio sesion  puede evaluar
$app->get('/recomendacion_ganadores', function () use ($app, $logger) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        $allpropuestas = array();
        $resultset = array();

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Deliberacion/all_propuestas ' . json_encode($request->get()) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if ($user_current["id"]) {

                if ($request->get('total_ganadores')) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $request->get('ronda'));

                    if (isset($ronda->id)) {

                        /**
                         * Listar las propuestas que estan registradas para evaluar
                         */
                        //fase de evaluación o de deliberación
                        $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                                ( ($ronda->getEstado_nombre() == "En deliberación" || $ronda->getEstado_nombre() == "Evaluada") ? 'Deliberación' : "" ) );

                        //Propuestas habilitadas
                        //Estado propuestas	Habilitada
                        $estado_habilitada = Estados::findFirst(" tipo_estado = 'propuestas' AND nombre = 'Habilitada' ");

                        //todas las propuestas
                        $allpropuestas = Propuestas::find(
                                        [
                                            ' convocatoria = ' . $ronda->convocatoria
                                            . ' AND estado = ' . $estado_habilitada->id,
                                            'order' => 'id ASC',
                                            'offset' => 0,
                                            'limit' => ( intval($request->get('total_ganadores')) + intval($request->get('total_suplentes')) )
                                        ]
                        );

                        $response = array();
                        // propuestas_evaluacion	Confirmada
                        $estado_confirmada = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Confirmada' ");

                        $query = " SELECT
                                  distinct p.id,p.codigo,p.nombre,
                                  (	SELECT
                                      sum(total) AS total
                                    FROM
                                      Evaluacionpropuestas e
                                   WHERE
                                      e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS suma,
                                  (	SELECT
                                      count(e.id) AS cantidad
                                    FROM
                                      Evaluacionpropuestas e
                                    WHERE
                                        e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS cantidad,
                                  (	SELECT
                                      avg(total) AS promedio
                                    FROM
                                      Evaluacionpropuestas e
                                    WHERE
                                    e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS promedio,
                            p.estado,
                          'ganador' as rol
                              FROM
                                Propuestas AS p
                                INNER JOIN
                                   Evaluacionpropuestas as ep2
                                ON p.id = ep2.propuesta
                              WHERE
                                p.convocatoria  = " . $ronda->convocatoria . " AND p.estado in (24,33)"; //.$estado_habilitada->id;
                        $query .= "  AND ep2.estado = " . $estado_confirmada->id . " AND fase = '" . $fase . "' AND ep2.ronda = " . $ronda->id;
                        $query .= "  ORDER BY promedio DESC limit " . $request->get('total_ganadores');
                        $query .= " offset " . $request->get('start');

                        $ganadores = $this->modelsManager->executeQuery($query);

                        $query2 = " SELECT
                                  distinct p.id,p.codigo,p.nombre,
                                  (	SELECT
                                      sum(total) AS total
                                    FROM
                                      Evaluacionpropuestas e
                                   WHERE
                                      e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query2 .= " ) AS suma,
                                  (	SELECT
                                      count(e.id) AS cantidad
                                    FROM
                                      Evaluacionpropuestas e
                                    WHERE
                                        e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query2 .= " ) AS cantidad,
                                  (	SELECT
                                      avg(total) AS promedio
                                    FROM
                                      Evaluacionpropuestas e
                                    WHERE
                                    e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query2 .= " ) AS promedio,
                            p.estado,
                          'suplente' as rol
                              FROM
                                Propuestas AS p
                                INNER JOIN
                                   Evaluacionpropuestas as ep2
                                ON p.id = ep2.propuesta
                              WHERE
                                p.convocatoria  = " . $ronda->convocatoria . " AND p.estado in (24,33)"; //.$estado_habilitada->id;
                        $query2 .= "  AND ep2.estado = " . $estado_confirmada->id . " AND fase = '" . $fase . "' AND ep2.ronda = " . $ronda->id;
                        $query2 .= "  ORDER BY promedio DESC limit " . $request->get('total_suplentes');
                        $query2 .= " offset " . ( intval($request->get('total_ganadores')) );

                        $suplentes = $this->modelsManager->executeQuery($query2);

                        //$resultset = array_merge($ganadores, $suplentes);
                        //Se redondea a 3 cifras el promedio
                        foreach ($ganadores as $key => $row) {
                            $row->promedio = round($row->promedio, 1);
                            $row->estado = (Estados::findFirst("id = " . $row->estado))->nombre;
                            array_push($response, $row);
                        }
                        //Se redondea a 3 cifras el promedio
                        foreach ($suplentes as $key => $row) {
                            $row->promedio = round($row->promedio, 1);
                            $row->estado = (Estados::findFirst("id = " . $row->estado))->nombre;
                            array_push($response, $row);
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error"',
                                ['user' => $user_current, 'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "error";
                    }
                }
            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => count($allpropuestas),
                "recordsFiltered" => count($allpropuestas),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_token"',
                    ['user' => '', 'token' => $request->get('token')]);
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        return "error_metodo" . $ex->getMessage();
        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_metodo ' . json_encode($ex) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();
        //return "error_metodo";
    }
});


$app->get('/recomendacion_ganadores_asignar', function () use ($app, $logger) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        $allpropuestas = array();
        $resultset = array();

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Deliberacion/all_propuestas ' . json_encode($request->get()) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if ($user_current["id"]) {

                if ($request->get('total_ganadores')) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $request->get('ronda'));

                    if (isset($ronda->id)) {

                        /**
                         * Listar las propuestas que estan registradas para evaluar
                         */
                        //fase de evaluación o de deliberación
                        $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                                ( ($ronda->getEstado_nombre() == "En deliberación" || $ronda->getEstado_nombre() == "Evaluada") ? 'Deliberación' : "" ) );

                        //Propuestas habilitadas
                        //Estado propuestas	Habilitada
                        $estado_habilitada = Estados::findFirst(" tipo_estado = 'propuestas' AND nombre = 'Habilitada' ");

                        //todas las propuestas
                        $allpropuestas = Propuestas::find(
                                        [
                                            ' convocatoria = ' . $ronda->convocatoria
                                            . ' AND estado = ' . $estado_habilitada->id,
                                            'order' => 'id ASC',
                                            'offset' => 0,
                                            'limit' => ( intval($request->get('total_ganadores')) + intval($request->get('total_suplentes')) )
                                        ]
                        );

                        $response = array();
                        // propuestas_evaluacion	Confirmada
                        $estado_confirmada = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Confirmada' ");

                        $query = " SELECT
                                  distinct p.id,p.codigo,p.nombre,
                                  (	SELECT
                                      sum(total) AS total
                                    FROM
                                      Evaluacionpropuestas e
                                   WHERE
                                      e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS suma,
                                  (	SELECT
                                      count(e.id) AS cantidad
                                    FROM
                                      Evaluacionpropuestas e
                                    WHERE
                                        e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS cantidad,
                                  (	SELECT
                                      avg(total) AS promedio
                                    FROM
                                      Evaluacionpropuestas e
                                    WHERE
                                    e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS promedio,
                            p.estado,
                          'ganador' as rol
                              FROM
                                Propuestas AS p
                                INNER JOIN
                                   Evaluacionpropuestas as ep2
                                ON p.id = ep2.propuesta
                              WHERE
                                p.convocatoria  = " . $ronda->convocatoria . " AND p.estado in (24,33)"; //.$estado_habilitada->id;
                        $query .= "  AND ep2.estado = " . $estado_confirmada->id . " AND fase = '" . $fase . "' AND ep2.ronda = " . $ronda->id;
                        $query .= "  ORDER BY promedio DESC limit " . $request->get('total_ganadores');
                        $query .= " offset " . $request->get('start');

                        $ganadores = $this->modelsManager->executeQuery($query);


                        //$resultset = array_merge($ganadores, $suplentes);
                        //Se redondea a 3 cifras el promedio
                        foreach ($ganadores as $key => $row) {
                            $row->promedio = round($row->promedio, 1);
                            $row->estado = (Estados::findFirst("id = " . $row->estado))->nombre;
                            array_push($response, $row);
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error"',
                                ['user' => $user_current, 'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "error";
                    }
                }
            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => count($allpropuestas),
                "recordsFiltered" => count($allpropuestas),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_token"',
                    ['user' => '', 'token' => $request->get('token')]);
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        return "error_metodo" . $ex->getMessage();
        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_metodo ' . json_encode($ex) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();
        //return "error_metodo";
    }
});


/**
 * Confirmar Top individual por ronda
 */
$app->put('/confirmar_top_general/ronda/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase = '';

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Deliberacion/confirmar_top_general/ronda/{id:[0-9]+} ' . json_encode($request->getPut()) . '"',
                ['user' => '', 'token' => $request->getPut('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $user_current = json_decode($token_actual->user_current, true);

                if ($user_current["id"]) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $id);

                    if (isset($ronda->id)) {

                        /**
                         * Listar las propuestas que estan registradas para evaluar
                         */
                        //fase de evaluación o de deliberación
                        $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                                ( ($ronda->getEstado_nombre() == "En deliberación" || $ronda->getEstado_nombre() == "Evaluada") ? 'Deliberación' : "" ) );

                        //Propuestas habilitadas
                        //Estado propuestas	Habilitada
                        $estado_habilitada = Estados::findFirst(" tipo_estado = 'propuestas' AND nombre = 'Habilitada' ");


                        //todas las propuestas
                        $allpropuestas = Propuestas::find(
                                        [
                                            ' convocatoria = ' . $ronda->convocatoria
                                            . ' AND estado in (24,33) ', //.$estado_habilitada->id,
                                            'order' => 'id ASC',
                                        ]
                        );

                        $response = array();
                        // propuestas_evaluacion	Confirmada
                        $estado_confirmada = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Confirmada' ");

                        $query = " SELECT
                                          distinct p.id,p.codigo,p.nombre,
                                          (	SELECT
                                              sum(total) AS total
                                            FROM
                                              Evaluacionpropuestas e
                                           WHERE
                                              e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS suma,
                                          (	SELECT
                                              count(e.id) AS cantidad
                                            FROM
                                              Evaluacionpropuestas e
                                            WHERE
                                                e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS cantidad,
                                          (	SELECT
                                              avg(total) AS promedio
                                            FROM
                                              Evaluacionpropuestas e
                                            WHERE
                                            e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                                . " AND fase = '" . $fase . "'";
                        $query .= " ) AS promedio,
                                    p.estado,
                                  'ganador' as rol
                                      FROM
                                        Propuestas AS p
                                        INNER JOIN
                                           Evaluacionpropuestas as ep2
                                        ON p.id = ep2.propuesta
                                      WHERE
                                        p.convocatoria  = " . $ronda->convocatoria . " AND p.estado = " . $estado_habilitada->id;
                        $query .= "  AND ep2.estado = " . $estado_confirmada->id . " AND fase = '" . $fase . "' ";
                        $query .= "  ORDER BY promedio DESC limit " . $request->getPut('total_ganadores');
                        $query .= " offset 0";

                        $ganadores = $this->modelsManager->executeQuery($query);

                        // Start a transaction
                        $this->db->begin();

                        //Se establece los datos de la siguiente ronda
                        $ronda_siguiente = new Convocatoriasrondas();

                        $rondas = Convocatoriasrondas::find(
                                        [
                                            " convocatoria = " . $ronda->convocatoria
                                            . " AND active = true",
                                            "order" => "numero_ronda"
                                        ]
                        );

                        foreach ($rondas as $r) {
                            if ($r->id == $ronda->id) {
                                $rondas->next();
                                //se establece la siguiente ronda
                                $ronda_siguiente = $rondas->current(); //Asigno a $ronda_siguiente $rondas->next() para determinar si hay una siguiente ronda
                                break;
                            }
                        }


//                        return json_encode($ronda_siguiente->id);
                        
                        
                        //se le cambia al ganador el estado
                        //33	propuestas	Recomendada como Ganadora
                        foreach ($ganadores as $key => $row) {

                            $propuesta = Propuestas::findFirst(" id = " . $row->id);
                            $propuesta->estado = (Estados::findFirst(" tipo_estado = 'propuestas' AND	nombre = 'Recomendada como Ganadora'"))->id;
                            $propuesta->fecha_actualizacion = date("Y-m-d H:i:s");
                            $propuesta->actualizado_por = $user_current["id"];

                            if ($propuesta->save() === false) {
                                //Para auditoria en versión de pruebas
                                /* foreach ($propuesta->getMessages() as $message) {
                                  echo $message;
                                  } */

                                $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/confirmar_top_general error:"' . $propuesta->getMessages(),
                                        ['user' => $user_current, 'token' => $request->getPut('token')]
                                );
                                $logger->close();

                                $this->db->rollback();
                                return "error";
                            }


                            //si existe la ronda siguiente
                            if (isset($ronda_siguiente->id) && ($ronda_siguiente->id != null)) {

                                //se establece el grupo evaluador de la siguiente ronda
                                $grupo_evaluador_ronda_siguiente = Gruposevaluadores::findFirst(" id = " . $ronda_siguiente->grupoevaluador);

                                //por cada propuesta ganadora se crean las evaluación de la siguiente ronda
                                foreach ($grupo_evaluador_ronda_siguiente->Evaluadores as $evaluador) {

                                    $evaluacion_propuesta = new Evaluacionpropuestas();
                                    $evaluacion_propuesta->propuesta = $propuesta->id;
                                    $evaluacion_propuesta->ronda = $ronda_siguiente->id;
                                    $evaluacion_propuesta->evaluador = $evaluador->id;
                                    $evaluacion_propuesta->estado = (Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Sin evaluar'"))->id;
                                    $evaluacion_propuesta->fase = 'Evaluación';
                                    $evaluacion_propuesta->fecha_creacion = date("Y-m-d H:i:s");
                                    $evaluacion_propuesta->creado_por = $user_current["id"];
                                    $evaluacion_propuesta->active = true;

                                    if ($evaluacion_propuesta->save() === false) {
                                        //Para auditoria en versión de pruebas
                                        /* foreach ($evaluacion_propuesta->getMessages() as $message) {
                                          echo $message;
                                          } */
                                        $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/confirmar_top_general Error al crear la evaluación. '
                                                . json_decode($evaluacion_propuesta->getMessages()) . '"',
                                                ['user' => $user_current, 'token' => $request->get('token')]
                                        );
                                        $logger->close();

                                        $this->db->rollback();
                                        return "error";
                                    }
                                }//fin foreach
                            }//fin del if
                        }

                        //Se actualiza la ronda
                        //35	convocatorias_rondas	Evaluada
                        $ronda->estado = ( Estados::findFirst(" tipo_estado = 'convocatorias_rondas' AND	nombre = 'Evaluada' "))->id;
                        $ronda->total_ganadores = $request->getPut('total_ganadores');
                        $ronda->total_suplentes = $request->getPut('total_suplentes');
                        $ronda->aspectos = $request->getPut('aspectos');
                        $ronda->recomendaciones = $request->getPut('recomendaciones');
                        $ronda->comentarios = $request->getPut('comentarios');
                        $ronda->fecha_actualizacion = date("Y-m-d H:i:s");
                        $ronda->actualizado_por = $user_current["id"];


                        if ($ronda->save() === false) {
                            //Para auditoria en versión de pruebas
                            /* foreach ($ronda->getMessages() as $message) {
                              echo $message;
                              } */
                            $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/confirmar_top_general error:"' . $ronda->getMessages(),
                                    ['user' => $user_current, 'token' => $request->getPut('token')]
                            );
                            $logger->close();

                            $this->db->rollback();
                            return "error";
                        }

                        /* Crear las evaluacionespropuestas por cada propuesta ganadora y por cada evaluador,
                          la evaluacionpropuesta se asocia la ronda siguiente */

                        // Commit the transaction
                        $this->db->commit();

                        return "exito";
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/confirmar_top_general error"',
                                ['user' => $user_current, 'token' => $request->getPut('token')]
                        );
                        $logger->close();

                        return "error";
                    }
                } else {
                    return "error";
                }
            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
});

/**
 * Declarar desierta la convocatoria
 */
$app->put('/declarar_desierta_convocatoria/ronda/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase = '';

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Deliberacion/declarar_desierta_convocatoria/ronda/{id:[0-9]+} ' . json_encode($request->getPut()) . '"',
                ['user' => '', 'token' => $request->getPut('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $user_current = json_decode($token_actual->user_current, true);

                if ($user_current["id"]) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $id);

                    if (isset($ronda->id)) {
                        // Start a transaction
                        $this->db->begin();


                        /**
                         * Listar las propuestas que estan registradas para evaluar
                         */
                        //fase de evaluación o de deliberación
                        $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                                ( $ronda->getEstado_nombre() == "En deliberación" ? 'Deliberación' : "" ) );

                        //actualizo la ronda
                        //35	convocatorias_rondas	Evaluada
                        $ronda->estado = ( Estados::findFirst(" tipo_estado = 'convocatorias_rondas' AND	nombre = 'Evaluada' "))->id;
                        $ronda->total_ganadores = $request->getPut('total_ganadores');
                        $ronda->total_suplentes = $request->getPut('total_suplentes');
                        $ronda->aspectos = $request->getPut('aspectos');
                        $ronda->recomendaciones = $request->getPut('recomendaciones');
                        $ronda->comentarios = $request->getPut('comentarios');
                        $ronda->fecha_actualizacion = date("Y-m-d H:i:s");
                        $ronda->actualizado_por = $user_current["id"];

                        if ($ronda->save() === false) {
                            //Para auditoria en versión de pruebas
                            /* foreach ($ronda->getMessages() as $message) {
                              echo $message;
                              } */
                            $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/declarar_desierta_convocatoria/ronda/{id:[0-9]+} error:"' . $ronda->getMessages(),
                                    ['user' => $user_current, 'token' => $request->getPut('token')]
                            );
                            $logger->close();

                            $this->db->rollback();

                            return "error";
                        }

                        // Actualizar la convocatoria
                        $convocatoria = Convocatorias::findFirst(" id = " . $ronda->convocatoria);
                        //43 convocatorias	Desierta
                        $convocatoria->estado = (Estados::findFirst(" tipo_estado = 'convocatorias' AND	nombre = 'Desierta'  "))->id;
                        $convocatoria->fecha_actualizacion = date("Y-m-d H:i:s");
                        $convocatoria->actualizado_por = $user_current["id"];

                        if ($convocatoria->save() === false) {
                            //Para auditoria en versión de pruebas
                            /* foreach ($ronda->getMessages() as $message) {
                              echo $message;
                              } */
                            $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/declarar_desierta_convocatoria/ronda/{id:[0-9]+} error:"' . $convocatoria->getMessages(),
                                    ['user' => $user_current, 'token' => $request->getPut('token')]
                            );
                            $logger->close();

                            $this->db->rollback();

                            return "error";
                        }


                        // Commit the transaction
                        $this->db->commit();

                        return "exito";
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/declarar_desierta_convocatoria/ronda/{id:[0-9]+}"',
                                ['user' => $user_current, 'token' => $request->getPut('token')]
                        );
                        $logger->close();

                        return "error";
                    }
                } else {
                    return "error";
                }
            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
});






/*
 * 15-06-2020
 * Wilmer Gustavo Mogollón Duque
 * Asignar monto
 */
$app->put('/asignar_monto/propuesta/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase = '';

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Deliberacion/asignar_monto/propuesta/{id:[0-9]+} ' . json_encode($request->getPut()) . '"',
                ['user' => '', 'token' => $request->getPut('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $user_current = json_decode($token_actual->user_current, true);

                if ($user_current["id"]) {

//                    $ronda = Convocatoriasrondas::findFirst('id = ' . $id);
                    $prop = Propuestas::findFirst('id = ' . $id);

                    if (isset($prop->id)) {
                        // Start a transaction
                        $this->db->begin();


                        // Actualizar la convocatoria
                        $propuesta = Propuestas::findFirst(" id = " . $prop->id);
                        //43 convocatorias	Desierta
                        $propuesta->monto_asignado = $request->getPut('monto_asignado');
                        $propuesta->fecha_actualizacion = date("Y-m-d H:i:s");
                        $propuesta->actualizado_por = $user_current["id"];

                        if ($propuesta->save() === false) {
                            //Para auditoria en versión de pruebas
                            /* foreach ($ronda->getMessages() as $message) {
                              echo $message;
                              } */
                            $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/asignar_monto/propuesta/{id:[0-9]+} error:"' . $propuesta->getMessages(),
                                    ['user' => $user_current, 'token' => $request->getPut('token')]
                            );
                            $logger->close();

                            $this->db->rollback();

                            return "error";
                        }


                        // Commit the transaction
                        $this->db->commit();

                        return "exito";
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/confirmar_top_general error"',
                                ['user' => $user_current, 'token' => $request->getPut('token')]
                        );
                        $logger->close();

                        return "error";
                    }
                } else {
                    return "error";
                }
            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
});



$app->put('/asignar_monto1/propuesta/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase = '';

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Deliberacion/asignar_monto/propuesta/{id:[0-9]+} ' . json_encode($request->getPut()) . '"',
                ['user' => '', 'token' => $request->getPut('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

//            return $permiso_escritura;
            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $user_current = json_decode($token_actual->user_current, true);

                if ($user_current["id"]) {

                    $prop = Propuestas::findFirst(['id = ' . $id]);

                    if (isset($prop->id)) {
                        // Start a transaction
                        $this->db->begin();


                        // Actualizar la convocatoria
                        $propuesta = Propuestas::findFirst(" id = " . $prop->id);
                        //43 convocatorias	Desierta
                        $propuesta->monto_asignado = $request->getPut('monto_asignado');
                        $propuesta->fecha_actualizacion = date("Y-m-d H:i:s");
                        $propuesta->actualizado_por = $user_current["id"];

                        if ($propuesta->save() === false) {
                            //Para auditoria en versión de pruebas
                            /* foreach ($ronda->getMessages() as $message) {
                              echo $message;
                              } */
                            $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/asignar_monto/propuesta/{id:[0-9]+} error:"' . $propuesta->getMessages(),
                                    ['user' => $user_current, 'token' => $request->getPut('token')]
                            );
                            $logger->close();

                            $this->db->rollback();

                            return "error";
                        }


                        // Commit the transaction
                        $this->db->commit();

                        return "exito";
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deliberacion/asignar_monto/propuesta/{id:[0-9]+}"',
                                ['user' => $user_current, 'token' => $request->getPut('token')]
                        );
                        $logger->close();

                        return "error";
                    }
                } else {
                    return "error";
                }
            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
});

/**
 * Funcionalidad Descargar archivos
 */
$app->post('/download_file', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo
        if (isset($token_actual->id)) {
            echo $chemistry_alfresco->download($request->getPost('cod'));
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        //  echo "error_metodo";

        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

/* * ************************************************************************************** */

/**
 * funcion de ordenamiento
 */
function build_sorter($clave) {
    return function ($a, $b) use ($clave) {
        return strnatcmp($b[$clave], $a[$clave]);
    };
}

/* prueba */
$app->get('/evaluacionpropuestas/ronda/{ronda:[0-9]+}', function ($ronda) use ($app, $config) {
    try {


        //ordenar
        $phql = 'SELECT
                  distinct (p.id), p.*
               FROM
                  Propuestas p
                  inner join Evaluacionpropuestas ep ON p.id = ep.propuesta
              WHERE
              ep.ronda = ' . $ronda;

        $rs = $this->modelsManager->createQuery($phql)->execute();

        //  echo json_encode($rs->count());

        foreach ($rs as $row) {
            //echo json_encode($rs);

            $evaluacionpropuestas = Evaluacionpropuestas::find(
                            [
                                ' propuesta = ' . $row->p->id
                                . ' AND ronda = ' . $ronda
                            ]
            );

            echo "<b>Código propuesta: " . $row->p->codigo . "<br>";
            echo "Total evaluación: " . $evaluacionpropuesta->total . "</b></br></br>";

            foreach ($evaluacionpropuestas as $evaluacionpropuesta) {

                //criterios de la ronda

                $criterios = Convocatoriasrondascriterios::find(
                                [
                                    'convocatoria_ronda = ' . $ronda,
                                    'order' => 'orden ASC'
                                ]
                );

                echo "<table style='border: 1px solid black;'>
                        <tr >
                          <td style='border: 1px solid black;background-color:#00FF00'>Criterio</td>
                          <td style='border: 1px solid black;background-color:#00FF00'>Puntaje máximo</td>
                          <td style='border: 1px solid black;background-color:#00FF00'>Calificación</td>
                          <td style='border: 1px solid black;background-color:#00FF00'>Observación</td>
                        </tr>";

                foreach ($criterios as $criterio) {

                    $evaluacioncriterio = Evaluacioncriterios::findFirst(
                                    [
                                        'evaluacionpropuesta = ' . $evaluacionpropuesta->id
                                        . ' AND criterio = ' . $criterio->id
                                        . ' AND active= true'
                                    ]
                    );

                    echo "<tr>
                            <td style='border: 1px solid black;'>" . $criterio->descripcion_criterio . "</td>
                            <td style='border: 1px solid black;'>" . $criterio->puntaje_maximo . "</td>
                            <td style='border: 1px solid black;'>" . $evaluacioncriterio->puntaje . "</td>
                            <td style='border: 1px solid black;'>" . $evaluacioncriterio->observacion . "</td>
                          </tr>";
                }

                echo "</table>";

                $evaluador = Evaluadores::findFirst('id = ' . $evaluacionpropuesta->evaluador);
                $juradopostulado = Juradospostulados::findFirst('id = ' . $evaluador->juradopostulado);

                echo "</br></br>";
                echo "<b>Código del jurado :" . $juradopostulado->Propuestas->codigo;
                echo "<br>Nombre del jurado:" . $juradopostulado->Propuestas->Participantes->primer_nombre;
                echo "</b></br></br>";
                echo "<hr>";
            }
        }

        //hasta aqui
    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
}
);


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>
