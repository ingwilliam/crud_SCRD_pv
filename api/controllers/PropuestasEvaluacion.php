<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

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
                                    . " AND estado = 5 " //5	convocatorias	Publicada
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
$app->get('/select_convocatorias_dev', function () use ($app, $logger) {

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

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            $estado_postulacion = Estados::findFirst("tipo_estado='jurados' and nombre='Evaluado'");

            //Si existe consulto la convocatoria
            if ($request->get('entidad') && $request->get('anio')) {


                /*
                 * 22-06-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora consulta para listar unicamente las convocatorias a las que 
                 * el jurado este postulado y ademas que haya sido seleccionado
                 */

                $query = 'SELECT
                            j.convocatoria
                            FROM
                            Juradospostulados as j
                            INNER JOIN Propuestas as p
                            on j.propuesta = p.id
                            INNER JOIN Participantes as par
                            on p.participante = par.id
                            INNER JOIN Usuariosperfiles as up
                            on par.usuario_perfil = up.id and up.usuario =' . $user_current["id"]
                        . " INNER JOIN Convocatorias as c
                            on j.convocatoria = c.id"
                        . " AND j.active = true"
                        . " AND j.estado =" . $estado_postulacion->id;


                $postulaciones = $this->modelsManager->executeQuery($query);

                $i = 0;
                $valores = "(";
                foreach ($postulaciones as $postulacion) {
                    if ($i == 0) {
                        $valores = $valores . $postulacion->convocatoria;
                    } else {
                        $valores = $valores . "," . $postulacion->convocatoria;
                    }
                    $i++;
                }

                $valores = $valores . ")";


                $rs = Convocatorias::find(
                                [
                                    " entidad = " . $request->get('entidad')
                                    . " AND anio = " . $request->get('anio')
                                    . " AND estado = 5 " //5	convocatorias	Publicada
                                    . " AND modalidad != 2 " //2	Jurados
                                    . " AND active = true "
                                    . " AND id in " . $valores
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
        $logger->info('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas ' . json_encode($request->get()) . '"',
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

                    /*
                     * 28-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se ajusta la consulta para evitar que se presenten errores
                     */
                    $estado_postulacion = Estados::findFirst("tipo_estado='jurados' and nombre='Evaluado'");

                    $query = 'SELECT
                                j.*
                            FROM
                                Juradospostulados as j
                                INNER JOIN Propuestas as p
                                on j.propuesta = p.id
                                INNER JOIN Participantes as par
                                on p.participante = par.id
                                INNER JOIN Usuariosperfiles as up
                                on par.usuario_perfil = up.id and up.usuario = ' . $user_current["id"]
                            . " WHERE j.convocatoria = " . $ronda->convocatoria
                            . " AND j.active = true"
                            . " AND j.estado =" . $estado_postulacion->id;


                    $postulacion = $this->modelsManager->executeQuery($query)->getFirst();

                    // echo json_encode($postulacion);
                    if (isset($postulacion->id) && isset($ronda->id)) {


                        /*
                         * 25-02-2021
                         * Wilmer Gustavo Mogollón Duque
                         * Se crea el objeto $gruposevaluadoresrondas para ajustar la búsqueda
                         * obedeciendo al nuevo requerimiento de crear N grupos de evaluación por ronda
                         */

                        $gruposevaluadoresrondas = Gruposevaluadoresrondas::find(
                                        [
                                            ' convocatoriaronda = ' . $ronda->id
                                        ]
                        );



                        if ($gruposevaluadoresrondas->count() > 0) {

                            $idgrupos = array();

                            foreach ($gruposevaluadoresrondas as $grupoevaluadorronda) {
                                //Se construye un array con la información de id de cada $gruposevaluadoresrondas
                                array_push($idgrupos, $grupoevaluadorronda->grupoevaluador);
                            }

                            //valida si el usuario pertenece al grupo de evaluación de la ronda
                            $evaluador = Evaluadores::findFirst(
                                            [
                                                'juradopostulado = ' . $postulacion->id
                                                . ' AND grupoevaluador IN ({idgrupos:array}) ', //25-02-2021 pueden ser N grupos de evaluación
                                                'bind' => [
                                                    'idgrupos' => $idgrupos
                                                ]
                                            ]
                            );
                        } else {

                            $evaluador = Evaluadores::findFirst(
                                            [
                                                'juradopostulado = ' . $postulacion->id
                                                . ' AND grupoevaluador = ' . $ronda->grupoevaluador //02-03-2021 para los grupos que fueron creados anteriormente
                                            ]
                            );
                        }




                        if (isset($evaluador->id)) {

                            /**
                             * Cesar Augusto Britto, 18-04-2020
                             * Agregar propuestas a evaluar
                             */
                            /*
                             * Si es la primera ronda trae los datos desde la tabla propuesta,
                             * en caso contrario lo trae de la tabla evaluacionpropuestas
                             */

                            //Array de rondas de la convocatoria
                            $rondas = Convocatoriasrondas::find(
                                            [
                                                " convocatoria = " . $ronda->convocatoria
                                                . " AND active = true ",
                                                'order' => 'id ASC',
                                            ]
                            );

                            /*
                             * 23-04-2021
                             * Wilmer Gustavo Mogollón Duque
                             * Se ajusta el método solo para que se listen las evaluacionespropuestas creadas previamente en el metodo habilitar_evaluaciones
                             */
                            /**
                             * Listar las propuestas que estan registradas para evaluar
                             */
                            //fase de evaluación o de deliberación
                            $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                                    ( $ronda->getEstado_nombre() == "En deliberación" ? 'Deliberación' : "" ) );

                            $evaluacionpropuestas = Evaluacionpropuestas::find(
                                            [
                                                'ronda = ' . $ronda->id
                                                . ' AND evaluador = ' . $evaluador->id
                                                . ' AND fase = "' . $fase . '"'
                                                . ( $request->get('estado') ? ' AND estado = ' . $request->get('estado') : '' ),
                                                'order' => 'propuesta ASC',
                                                'limit' => $request->get('length'),
                                                'offset' => $request->get('start'),
                                            ]
                            );

                            $allevaluacionpropuestas = Evaluacionpropuestas::find(
                                            [
                                                'ronda = ' . $ronda->id
                                                . ' AND evaluador = ' . $evaluador->id
                                                . ' AND fase = "' . $fase . '"'
                                                . ( $request->get('estado') ? ' AND estado = ' . $request->get('estado') : '' ),
                                                'order' => 'propuesta ASC',
                                            ]
                            );

                            if ($evaluacionpropuestas->count() > 0) {

                                foreach ($evaluacionpropuestas as $evaluacionpropuesta) {

                                    /* Ajuste de william supervisado por wilmer */
                                    /* 2020-04-28 */
                                    $array_estado_actual_2 = Estados::findFirst('id = ' . $evaluacionpropuesta->estado);

                                    array_push($response, [
                                        "id_evaluacion" => $evaluacionpropuesta->id,
                                        "total_evaluacion" => $evaluacionpropuesta->total,
                                        "estado_evaluacion" => $array_estado_actual_2->nombre,
                                        "id_propuesta" => $evaluacionpropuesta->Propuestas->id,
                                        "codigo_propuesta" => $evaluacionpropuesta->Propuestas->codigo,
                                        "nombre_propuesta" => $evaluacionpropuesta->Propuestas->nombre,
                                    ]);
                                }
                            }
                        } else {
                            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_evaluador"',
                                    ['user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();
                            return "error_evaluador";
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
                "recordsTotal" => intval(count($allevaluacionpropuestas)),
                "recordsFiltered" => intval(count($allevaluacionpropuestas)),
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
        //return "error_metodo".$ex->getMessage();
        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_metodo ' . json_encode($ex) . '"',
                ['user' => '', 'token' => $request->get('token')]);
        $logger->close();
        return "error_metodo";
    }
}
);

/**
 * Retorna información de la propuesta que el usuario que inicio sesion
 * puede evaluar
 */
$app->get('/propuestas/{id:[0-9]+}', function ($id) use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));



        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            $propuesta = Propuestas::findFirst(' id = ' . $id);

            if ($propuesta) {

                //parametros extra
                $parametros = array();

                foreach ($propuesta->Propuestasparametros as $propuestaparametro) {
                    array_push($parametros,
                            [
                                "nombre_parametro" => $propuestaparametro->Convocatoriaspropuestasparametros->label,
                                "valor_parametro" => $propuestaparametro->valor
                            ]
                    );
                }


                /*
                 * 14-09-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora el id de la evaluación para determinar la ronda
                 */
                //Para las convocatorias que no tengan el valor de tipo_evaluacion --- Anteriores
                $tipo_requisito = 'Tecnicos';
                if ($request->get('evaluacion')) {

                    $id_evaluacion = $request->get('evaluacion');

                    $evaluacion = Evaluacionpropuestas::findFirst(
                                    [
                                        'id = ' . $id_evaluacion
                                    ]
                    );

                    $ronda = Convocatoriasrondas::findFirst(
                                    [
                                        'id = ' . $evaluacion->ronda
                                    ]
                    );


                    switch ($ronda->tipo_evaluacion) {
                        case 'Técnica':
                            $tipo_requisito = 'Tecnicos';

                            break;

                        case 'Administrativa':
                            $tipo_requisito = 'Administrativos';

                            break;

                        default:
                            break;
                    }
                }




                //Documentos técnicos de la propuesta
                //Propuestasdocumentos
                $query = " SELECT
                                p.nombre,
                                p.id_alfresco,
                                c.descripcion as descripcion_requisito,
                            	r.nombre as requisito
                          FROM
                            	Propuestasdocumentos AS p
                            	INNER JOIN Convocatoriasdocumentos AS c
                            	ON p.convocatoriadocumento = c.id
                             	INNER JOIN  Requisitos AS r
                             	ON c.requisito = r.id
                             	AND r.tipo_requisito LIKE " . "'" . $tipo_requisito . "'" .
                        " WHERE
                              	p.propuesta = " . $propuesta->id
                        . " AND p.active=true ";

                $documentos = $this->modelsManager->executeQuery($query);

                //Propuestaslinks
                $query = "SELECT
                                p.link as nombre,
                                '' as id_alfresco,
                            	c.descripcion as descripcion_requisito,
                            	r.nombre as requisito
                          FROM
                            	Propuestaslinks AS p
                            	INNER JOIN  Convocatoriasdocumentos AS c
                            	ON p.convocatoriadocumento = c.id
                             	INNER JOIN  Requisitos AS r
                             	ON c.requisito = r.id
                             	AND r.tipo_requisito LIKE 'Tecnicos'
                          WHERE
                              	p.propuesta = " . $propuesta->id;

                $links = $this->modelsManager->executeQuery($query);


                return json_encode(
                        [
                            "propuesta" => $propuesta,
                            "parametros" => $parametros,
                            "documentos" => $documentos,
                            "links" => $links,
                ]);
            } else {

                return null;
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
    }
}
);

/**
 * Carga los datos relacionados con los criterios de evaluación
 * asociados con la evaluación propuesta
 * ronda, postulacion, criterios
 */
$app->get('/evaluacionpropuestas/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if ($user_current["id"]) {

                $evaluacionpropuesta = Evaluacionpropuestas::findFirst(' id = ' . $id);

                $ronda = Convocatoriasrondas::findFirst('id = ' . $evaluacionpropuesta->ronda);

                if ($ronda->active) {

                    //se construye el array de grupos d ecriterios
                    $grupo_criterios = array();
                    //se cronstruye el array de criterios
                    $criterios = array();

                    //Se crea el array en el orden de los criterios
                    foreach ($ronda->Convocatoriasrondascriterios as $criterio) {
                        if ($criterio->active) {
                            $grupo_criterios[$criterio->grupo_criterio] = $criterio->orden;
                        }
                    }

                    //de acuerdo con el orden, se crea al array de criterios
                    foreach ($grupo_criterios as $categoria => $orden) {

                        //$obj = ["grupo" => $categoria, "criterios"=> array()];
                        $obj = array();
                        $obj[$categoria] = array();

                        foreach ($ronda->Convocatoriasrondascriterios as $criterio) {

                            if ($criterio->active && $criterio->grupo_criterio === $categoria) {

                                // $obj[$categoria][$criterio->orden]=  $criterio;
                                $obj[$categoria][$criterio->orden] = [
                                    "id" => $criterio->id,
                                    "descripcion_criterio" => $criterio->descripcion_criterio,
                                    "puntaje_minimo" => $criterio->puntaje_minimo,
                                    "puntaje_maximo" => $criterio->puntaje_maximo,
                                    "orden" => $criterio->orden,
                                    "grupo_criterio" => $criterio->grupo_criterio,
                                    "exclusivo" => $criterio->exclusivo,
                                    "evaluacion" => Evaluacioncriterios::findFirst([
                                        "criterio = " . $criterio->id
                                        . " AND evaluacionpropuesta = " . $evaluacionpropuesta->id
                                    ])
                                ];
                            }
                        }


                        $criterios[$orden] = $obj;
                    }


                    $response[$ronda->numero_ronda] = ["ronda" => $ronda, "ronda_nombre_estado" => $ronda->getEstado_nombre(), "evaluacion" => $evaluacionpropuesta, "evaluacion_nombre_estado" => $evaluacionpropuesta->getEstado_nombre(), "criterios" => $criterios];
                }
            }
            //retorno el array en json
            return json_encode($response);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

//Guarda la evaluación de los criterios
$app->post('/evaluar_criterios', function () use ($app, $config) {
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

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Se consulta la evalaucaión
                $evaluacion = Evaluacionpropuestas::findFirst(" id = " . $request->getPost('evaluacion'));

                if (isset($evaluacion->id)) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $evaluacion->ronda);

                    if (isset($ronda->id)) {

                        /**
                         * Cesar Britto, 20-04-2020
                         * Se modifica para el manejo de los estados
                         */
                        //Si la ronda esta evaluada no se permite la actualización de la evaluación
                        if ($ronda->getEstado_nombre() == "Evaluada") {

                            return 'deshabilitado';
                        }

                        //En la fase de evaluación
                        if ($ronda->getEstado_nombre() == "Habilitada" && ( $ronda->fecha_fin_evaluacion >= date("Y-m-d H:i:s") )) {
                            $fase = 'Evaluación';
                        }

                        //En la fase de deliberación
                        if ($ronda->getEstado_nombre() == "En deliberación" && ( $ronda->fecha_deliberacion >= date("Y-m-d") )) {
                            $fase = 'Deliberación';
                        }

                        //Si la evaluación esta habilitada para modificarse
                        //evaluacion_propuesta	Sin evaluar
                        //evaluacion_propuesta	En evaluación
                        //05-06-2020 Wilmer Mogollón --- Se agrega el estado Deliberación para quew pueda ajustar puntajes

                        if ($evaluacion->fase == $fase && ($evaluacion->getEstado_nombre() == "Sin evaluar" || $evaluacion->getEstado_nombre() == "En evaluación" || $evaluacion->getEstado_nombre() == "En deliberación")) {




                            //Criterios de evaluación de la ronda
                            $criterios = Convocatoriasrondascriterios::find(
                                            [
                                                "convocatoria_ronda = " . $ronda->id
                                                . " AND active = true"
                                            ]
                            );

                            // Start a transaction
                            $this->db->begin();

                            //Se registra los valores por cada criterio evaluado
                            foreach ($criterios as $criterio) {

                                //Consulto el criterio
                                $evaluacioncriterio = Evaluacioncriterios::findFirst(
                                                [
                                                    ' evaluacionpropuesta = ' . $evaluacion->id
                                                    . ' AND criterio = ' . $criterio->id
                                                ]
                                );

                                //Si no existe el criterioevaluacion se crea
                                if (!isset($evaluacioncriterio->id)) {
                                    $evaluacioncriterio = new Evaluacioncriterios();
                                    $evaluacioncriterio->evaluacionpropuesta = $evaluacion->id;
                                    $evaluacioncriterio->criterio = $criterio->id;
                                    $evaluacioncriterio->active = true;
                                    $evaluacioncriterio->creado_por = $user_current["id"];
                                    $evaluacioncriterio->fecha_creacion = date("Y-m-d H:i:s");
                                } else {
                                    // se actualiza los campos
                                    $evaluacioncriterio->actualizado_por = $user_current["id"];
                                    $evaluacioncriterio->fecha_actualizacion = date("Y-m-d H:i:s");
                                }

                                $evaluacioncriterio->puntaje = $request->getPost('puntuacion_' . $criterio->id);
                                $evaluacioncriterio->observacion = $request->getPost('observacion_' . $criterio->id);

                                // The model failed to save, so rollback the transaction
                                if ($evaluacioncriterio->save() === false) {
                                    //Para auditoria en versión de pruebas
                                    foreach ($evaluacioncriterio->getMessages() as $message) {
                                        echo $message;
                                    }

                                    $this->db->rollback();
                                    return "error";
                                }

                                $total_evaluacion = $total_evaluacion + $evaluacioncriterio->puntaje;
                            }

                            $evaluacion->total = $total_evaluacion;
                            $evaluacion->actualizado_por = $user_current["id"];
                            $evaluacion->fecha_actualizacion = date("Y-m-d H:i:s");
                            /**
                             * Cesar Britto, 20-04-2020
                             * Se modifica para el manejo de los estados
                             */
                            //evaluacion_propuesta	En evaluación

                            /* Ajuste de william supervisado por wilmer */
                            /* 2020-04-28 */
                            $array_estado_actual_3 = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'En evaluación'");
                            $evaluacion->estado = $array_estado_actual_3->id;

                            // The model failed to save, so rollback the transaction
                            if ($evaluacion->save() === false) {
                                //Para auditoria en versión de pruebas
                                foreach ($evaluacion->getMessages() as $message) {
                                    echo $message;
                                }

                                $this->db->rollback();
                                return "error";
                            }

                            // Commit the transaction
                            $this->db->commit();

                            return (string) $evaluacion->id;
                        } else {
                            return 'deshabilitado';
                        }
                    } else {
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
}
);

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

/**
 * Confirma la evaluación de los criterios
 */
$app->post('/confirmar_evaluacion', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase = '';

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Se consulta la evaluación
                $evaluacion = Evaluacionpropuestas::findFirst(" id = " . $request->getPost('evaluacion'));

                if ($evaluacion) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $evaluacion->ronda);

                    if ($ronda) {

                        //Si la ronda está evaluada no se permite la actualización de la evaluación
                        if ($ronda->getEstado_nombre() == "Evaluada") {

                            return 'deshabilitado';
                        }


                        //En la fase de evaluación
                        if ($ronda->getEstado_nombre() == "Habilitada" && ( $ronda->fecha_fin_evaluacion >= date("Y-m-d H:i:s") )) {
                            $fase = 'Evaluación';
                        }

                        //En la fase de deliberación
                        if ($ronda->getEstado_nombre() == "En deliberación" && ( $ronda->fecha_deliberacion >= date("Y-m-d") )) {
                            $fase = 'Deliberación';
                        }

                        //Si la evaluación esta habilitada para modificarse
                        //28	evaluacion_propuesta	Sin evaluar
                        //29	evaluacion_propuesta	En evaluación
                        if ($evaluacion->fase == $fase && ($evaluacion->getEstado_nombre() == "Sin evaluar" || $evaluacion->getEstado_nombre() == "En evaluación")) {

                            /*
                             * 03-03-2021
                             * Wilmer Gustavo Mogollón Duque
                             * Se agrega este condicional pq no se puede confirmar una evaluación que no se haya comenzado
                             * (Estado sin evaluar)
                             */

                            if ($evaluacion->getEstado_nombre() == "Sin evaluar") {
                                return "sin_evaluar";
                            }

                            //Criterios de evaluación de la ronda
                            $criterios = Convocatoriasrondascriterios::find(
                                            [
                                                "convocatoria_ronda = " . $ronda->id
                                                . " AND active = true"
                                            ]
                            );

                            //Se registra los valores por cada criterio evaluado
                            foreach ($criterios as $criterio) {



                                /*
                                 * 02-03-2021
                                 * Wilmer Gustavo Mogollón Duque
                                 * Se agregan validaciones al momento de confirmar la evaluación y garantizar que todos los 
                                 * criterios que deban ser evaluados efectivamente sean evaluados
                                 */

                                //Consulto el criterio
                                $evaluacioncriterio = Evaluacioncriterios::findFirst(
                                                [
                                                    ' evaluacionpropuesta = ' . $evaluacion->id
                                                    . ' AND criterio = ' . $criterio->id
                                                ]
                                );

                                //Si no existe el criterioevaluacion se retorna mensaje
                                //preguntamos si el criterio viene con puntaje null
                                if ($evaluacioncriterio->puntaje == null) {

                                    //Validar si el criterio pertenece a un grupo y si es exclusivo
                                    if ($criterio->grupo_criterio == "") {

                                        return 'criterio_null';
                                    } else {

                                        //Si el criterio no es exclusivo debe tener puntaje
                                        if ($criterio->exclusivo == false) {

                                            return 'criterio_null';
                                        } else {

                                            $crterios_grupo = Convocatoriasrondascriterios::find(
                                                            [
                                                                "convocatoria_ronda = " . $ronda->id
                                                                . " AND active = true"
                                                                . " AND grupo_criterio = '" . $criterio->grupo_criterio . "'"
                                                            ]
                                            );

                                            //Para verificar si hay por lo menos un criterio del grupo fue seleccionado
                                            foreach ($crterios_grupo as $crterio_grupo) {

                                                $evaluacioncriteriogrupo = Evaluacioncriterios::findFirst(
                                                                [
                                                                    ' evaluacionpropuesta = ' . $evaluacion->id
                                                                    . ' AND criterio = ' . $crterio_grupo->id
                                                                    . " AND active = true"
                                                                ]
                                                );

                                                if ($evaluacioncriteriogrupo->puntaje >= 0) {
                                                    break;
                                                }
                                            }

                                            if ($evaluacioncriteriogrupo->puntaje == null) {
                                                return 'criterio_null';
                                            }
                                        }
                                    }
                                }
                            }

                            $evaluacion->actualizado_por = $user_current["id"];
                            $evaluacion->fecha_actualizacion = date("Y-m-d H:i:s");
                            //propuestas_evaluacion	Evaluada

                            /* Ajuste de william supervisado por wilmer */
                            /* 2020-04-28 */
                            $array_estado_actual_4 = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Evaluada' ");

                            $evaluacion->estado = $array_estado_actual_4->id;

                            if ($evaluacion->save() === false) {
                                //Para auditoria en versión de pruebas
                                /* foreach ($evaluacion->getMessages() as $message) {
                                  echo $message;
                                  } */

                                return "error";
                            }

                            return (string) $evaluacion->id;
                        } else {
                            return 'deshabilitado';
                        }
                    } else {
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
}
);

/**
 * Carga los datos relacionados con el evaluador (Jurado) asociado a la evaluación
 * de la  propuesta
 */
$app->get('/evaluacionpropuestas/{id:[0-9]+}/evaluadores', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if ($user_current["id"]) {

                $evaluacionpropuesta = Evaluacionpropuestas::findFirst(' id = ' . $id);

                $evaluador = Evaluadores::findFirst('id = ' . $evaluacionpropuesta->evaluador);

                $juradopostulado = Juradospostulados::findFirst(' id = ' . $evaluador->juradopostulado);

                //retorno el array en json
                $participante = $juradopostulado->Propuestas->Participantes;

                $response = ["tipo_documento" => $juradopostulado->Propuestas->Participantes->Tiposdocumentos->nombre,
                    "numero_documento" => $participante->numero_documento,
                    "nombre" => $participante->primer_nombre . " " . $participante->segundo_nombre . " " . $participante->primer_apellido . " " . $participante->segundo_apellido,
                    "correo_electronico" => $participante->correo_electronico
                ];

                return json_encode($response);
            } else {
                return 'error';
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
});


/**
 * Carga los datos relacionados con el evaluador (Jurado) asociado a la evaluación
 * de la  propuesta
 */
$app->get('/evaluacionpropuestas/{id:[0-9]+}/impedimentos', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if ($user_current["id"]) {

                $evaluacionpropuesta = Evaluacionpropuestas::findFirst(' id = ' . $id);

                $evaluador = Evaluadores::findFirst('id = ' . $evaluacionpropuesta->evaluador);

                $juradopostulado = Juradospostulados::findFirst(' id = ' . $evaluador->juradopostulado);

                //retorno el array en json
                $participante = $juradopostulado->Propuestas->Participantes;

                //Creo el cuerpo del messaje html del email
                $html_jurado_notificacion_impedimento = Tablasmaestras::find("active=true AND nombre='html_jurado_notificacion_impedimento'")[0]->valor;
                $html_jurado_notificacion_impedimento = str_replace("**fecha_creacion**", "<span id='fecha_creacion'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**nombre_jurado**", "<span id='nombre_jurado'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**nombre_jurado_2**", "<span id='nombre_jurado_2'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**tipo_documento**", "<span id='tipo_documento'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**numero_documento**", "<span id='numero_documento'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**codigo_propuesta**", "<span id='notificacion_codigo_propuesta'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**nombre_propuesta**", "<span id='notificacion_nombre_propuesta'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**correo_jurado**", "<span id='correo_jurado'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**motivo_impedimento**", "<span id='motivo_impedimento'></span>", $html_jurado_notificacion_impedimento);

                $response = [
                    "fecha_creacion" => date("d/m/Y"),
                    "tipo_documento" => $juradopostulado->Propuestas->Participantes->Tiposdocumentos->nombre,
                    "numero_documento" => $participante->numero_documento,
                    "nombre_jurado" => $participante->primer_nombre . " " . $participante->segundo_nombre . " " . $participante->primer_apellido . " " . $participante->segundo_apellido,
                    "correo_jurado" => $participante->correo_electronico,
                    "codigo_propuesta" => $evaluacionpropuesta->Propuestas->codigo,
                    "nombre_propuesta" => $evaluacionpropuesta->Propuestas->nombre,
                    "notificacion" => $html_jurado_notificacion_impedimento,
                    "motivo_impedimento" => $evaluacionpropuesta->observacion,
                    "evaluacion" => $evaluacionpropuesta,
                        //"evaluacion_estado_nombre"=>(Estados::findFirst('id ='.$evaluacionpropuesta->estado))->nombre
                ];

                return json_encode($response);
            } else {
                return 'error';
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
});


/**
 * Establece el impedimento de la evaluación y envia una notificacion al correo_jurado
 * del jurado evaluador y al misional
 */
$app->put('/evaluacionpropuestas/{id:[0-9]+}/impedimentos', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase = '';
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Se consulta la evaluación
                $evaluacion = Evaluacionpropuestas::findFirst(" id = " . $id);

                if ($evaluacion) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $evaluacion->ronda);

                    if ($ronda) {

                        //Si la ronda está evaluada no se permite la actualización de la evaluación
                        if ($ronda->getEstado_nombre() == 'Evaluada') {

                            return 'deshabilitado';
                        }

                        //En la fase de evaluación
                        //rondas	Habilitada
                        if ($ronda->getEstado_nombre() == 'Habilitada' && ( $ronda->fecha_fin_evaluacion >= date("Y-m-d H:i:s") )) {
                            $fase = 'Evaluación';
                        }

                        //En la fase de deliberación
                        //rondas	En deliberación
                        if ($ronda->getEstado_nombre() == 'En deliberación' && ( $ronda->fecha_deliberacion >= date("Y-m-d H:i:s") )) {
                            $fase = 'Deliberación';
                        }

                        //Si la evaluación esta habilitada para modificarse
                        //28	evaluacion_propuesta	Sin evaluar
                        //29	evaluacion_propuesta	En evaluación
                        //30	evaluacion_propuesta	Evaluada



                        if ($evaluacion->fase == $fase && ( $evaluacion->getEstado_nombre() == 'Sin evaluar' || $evaluacion->getEstado_nombre() == 'En evaluación' || $evaluacion->getEstado_nombre() == 'Evaluada' )) {

                            // Start a transaction
                            $this->db->begin();


                            $evaluador = Evaluadores::findFirst('id = ' . $evaluacion->evaluador);


                            $juradopostulado = Juradospostulados::findFirst(' id = ' . $evaluador->juradopostulado);

                            //retorno el array en json
                            $participante = $juradopostulado->Propuestas->Participantes;

                            $evaluacion->observacion = $request->getPut('observacion_impedimento');
                            $evaluacion->actualizado_por = $user_current["id"];
                            $evaluacion->fecha_actualizacion = date("Y-m-d H:i:s");
                            //propuestas_evaluacion	Impedimento

                            /* Ajuste de william supervisado por wilmer */
                            /* 2020-04-28 */
                            $array_estado_actual_5 = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Impedimento' ");

                            $evaluacion->estado = $array_estado_actual_5->id;

//                            return json_encode($evaluacion->id);
                            // The model failed to save, so rollback the transaction
                            if ($evaluacion->save() === false) {
                                //Para auditoria en versión de pruebas
                                foreach ($evaluacion->getMessages() as $message) {
                                    echo $message;
                                }

                                $this->db->rollback();

                                return "error";
                            } else {

                                //Creo el cuerpo del messaje html del email
//                                $html_jurado_notificacion_impedimento = Tablasmaestras::find("active=true AND nombre='html_jurado_notificacion_impedimento'")[0]->valor;
//                                $html_jurado_notificacion_impedimento = str_replace("**fecha_creacion**", date("d/m/Y"), $html_jurado_notificacion_impedimento);
//                                $html_jurado_notificacion_impedimento = str_replace("**nombre_jurado**", $participante->primer_nombre . " " . $participante->primer_apellido, $html_jurado_notificacion_impedimento);
//                                $html_jurado_notificacion_impedimento = str_replace("**nombre_jurado_2**", $participante->primer_nombre . " " . $participante->primer_apellido, $html_jurado_notificacion_impedimento);
//                                $html_jurado_notificacion_impedimento = str_replace("**tipo_documento**", $participante->Tiposdocumentos->nombre, $html_jurado_notificacion_impedimento);
//                                $html_jurado_notificacion_impedimento = str_replace("**numero_documento**", $participante->numero_documento, $html_jurado_notificacion_impedimento);
//                                $html_jurado_notificacion_impedimento = str_replace("**codigo_propuesta**", $evaluacion->Propuestas->codigo, $html_jurado_notificacion_impedimento);
//                                $html_jurado_notificacion_impedimento = str_replace("**nombre_propuesta**", $evaluacion->Propuestas->nombre, $html_jurado_notificacion_impedimento);
//                                $html_jurado_notificacion_impedimento = str_replace("**correo_jurado**", $participante->correo_electronico, $html_jurado_notificacion_impedimento);
//                                $html_jurado_notificacion_impedimento = str_replace("**motivo_impedimento**", $request->getPut('observacion_impedimento'), $html_jurado_notificacion_impedimento);
                                //servidor smtp ambiente de prueba
//                                  $mail = new PHPMailer();
//                                  $mail->IsSMTP();
//                                  $mail->SMTPAuth = true;
//                                  $mail->Host = "smtp.gmail.com";
//                                  $mail->SMTPSecure = 'ssl';
//                                  $mail->Username = "cesar.augusto.britto@gmail.com";
//                                  $mail->Password = "Guarracuco2016";
//                                  $mail->Port = 465;//25 o 587 (algunos alojamientos web bloquean el puerto 25)
//                                  $mail->CharSet = "UTF-8";
//                                  $mail->IsHTML(true); // El correo se env  a como HTML
//                                  $mail->From = "convocatorias@scrd.gov.co";
//                                  //$mail->From = "cesar.augusto.britto@gmail.com";
//                                  $mail->FromName = "Sistema de Convocatorias";
////                                  $mail->AddAddress($participante->correo_electronico);//direccion de correo del jurado participante
//                                  $mail->AddAddress("ejercol45@hotmail.com");//direccion de prueba
//                                  //$mail->AddBCC($user_current["username"]); //con copia al misional que realiza la invitación
//                                  $mail->AddBCC("ejercol45@hotmail.com");//direccion de prueba
//                                  $mail->Subject = "Sistema de Convocatorias - Invitación designación de jurado";
//                                  $mail->Body = $html_jurado_notificacion_impedimento;
//                                /* Servidor SMTP producción */
//                                $mail = new PHPMailer();
//                                $mail->IsSMTP();
//                                $mail->Host = "smtp-relay.gmail.com";
//                                $mail->Port = 25;
//                                $mail->CharSet = "UTF-8";
//                                $mail->IsHTML(true); // El correo se env  a como HTML
//                                $mail->From = "convocatorias@scrd.gov.co";
//                                $mail->FromName = "Sistema de Convocatorias";
//                                $mail->AddAddress($participante->correo_electronico);
//                                $mail->AddBCC($user_current["username"]); //con copia al misional que realiza la invitación
//                                $mail->Subject = "Sistema de Convocatorias - Invitación designación de jurado";
//                                $mail->Body = $html_solicitud_usuario;
//
//                                // Env  a el correo.
//                                if ($mail->Send()) {
//
//                                    $this->db->commit();
//                                    return "exito";
//                                } else {
//                                    $this->db->rollback();
//                                    return "error_email";
//                                }
                            }

                            // Commit the transaction
                            $this->db->commit();
                        } else {
                            return 'deshabilitado';
                        }
                    } else {
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
}
);

/**
 * Confirmar Top individual por ronda
 */
$app->put('/confirmar_top_individual/ronda/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase = '';

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/confirmar_top_individual/ronda/{id:[0-9]+} ' . json_encode($request->getPut()) . '"',
                ['user' => '', 'token' => $request->getPut('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                if ($user_current["id"]) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $id);

                    $query = 'SELECT
                                  j.*
                              FROM
                                  Juradospostulados as j
                                  INNER JOIN Propuestas as p
                                  on j.propuesta = p.id
                                  INNER JOIN Participantes as par
                                  on p.participante = par.id
                                  INNER JOIN Usuariosperfiles as up
                                  on par.usuario_perfil = up.id and up.usuario = ' . $user_current["id"]
                            . " WHERE j.convocatoria = " . $ronda->convocatoria
                            . " AND j.active = true ";

                    $postulacion = $this->modelsManager->executeQuery($query)->getFirst();

                    if (isset($postulacion->id) && isset($ronda->id)) {

//                        return json_encode($postulacion->id);
                        //valida si el usuario pertenece al grupo de evaluación de la ronda
                        $evaluador = Evaluadores::findFirst(
                                        [
                                            'juradopostulado = ' . $postulacion->id
                                            . ' AND grupoevaluador = ' . $ronda->grupoevaluador
                                            . ' AND active = true'
                                        ]
                        );

//                        return json_encode($evaluador->id);

                        if (isset($evaluador->id)) {

                            /**
                             * Listar las propuestas que estan registradas para evaluar
                             */
                            //fase de evaluación o de deliberación
                            $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                                    ( $ronda->getEstado_nombre() == "En deliberación" ? 'Deliberación' : "" ) );


                            //Estados Sin evaluar En evaluación
                            $estados = Estados:: find([
                                        "tipo_estado = 'propuestas_evaluacion'"
                                        . " AND nombre IN ('Sin evaluar','En evaluación') "
                            ]);
                            $estados_array = array();
                            foreach ($estados as $key => $estado) {
                                array_push($estados_array, $estado->id);
                            }

                            $nohabilitadas = Evaluacionpropuestas::find(
                                            [
                                                'ronda = ' . $ronda->id
                                                . ' AND evaluador = ' . $evaluador->id
                                                . ' AND fase = "' . $fase . '"'
                                                . ' AND estado IN ({estados:array})',
                                                'bind' => [
                                                    'estados' => $estados_array
                                                ]
                                            ]
                            );

                            if ($nohabilitadas->count() > 0) {
                                $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/confirmar_top_individual/ronda/{id:[0-9]+} error_validacion"',
                                        ['user' => $user_current, 'token' => $request->getPut('token')]
                                );
                                $logger->close();

                                return "error_validacion";
                            } else {

                                //Estados Evaluada Impedimento
                                $estados = Estados:: find([
                                            "tipo_estado = 'propuestas_evaluacion'"
                                            . " AND nombre IN ('Evaluada') "
                                ]);
                                $estados_array = array();
                                foreach ($estados as $key => $estado) {
                                    array_push($estados_array, $estado->id);
                                }

                                $habilitadas = Evaluacionpropuestas::find(
                                                [
                                                    'ronda = ' . $ronda->id
                                                    . ' AND evaluador = ' . $evaluador->id
                                                    . ' AND fase = "' . $fase . '"'
                                                    . ' AND estado IN ({estados:array})',
                                                    'bind' => [
                                                        'estados' => $estados_array
                                                    ]
                                                ]
                                );

                                //estado Confirmada
                                $estado_confimada = Estados::findFirst(
                                                [
                                                    "tipo_estado = 'propuestas_evaluacion'"
                                                    . " AND nombre ='Confirmada'"
                                                ]
                                );

                                // Start a transaction
                                $this->db->begin();

                                foreach ($habilitadas as $key => $evaluacion_propuesta) {
                                    $evaluacion_propuesta->estado = $estado_confimada->id;
                                    $evaluacion_propuesta->fecha_actualizacion = date("Y-m-d H:i:s");
                                    $evaluacion_propuesta->actualizado_por = $user_current["id"];

                                    if ($evaluacion_propuesta->save() === false) {
                                        //Para auditoria en versión de pruebas
                                        /* foreach ($evaluacion_propuesta->getMessages() as $message) {
                                          echo $message;
                                          } */

                                        $this->db->rollback();

                                        $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas Error al actualizar la evaluación. '
                                                . json_decode($evaluacion_propuesta->getMessages()) . '"',
                                                ['user' => $user_current, 'token' => $request->getPut('token')]
                                        );
                                        $logger->close();

                                        return "error";
                                    }
                                }

                                $this->db->commit();
                                $logger->info('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/confirmar_top_individual/ronda/{id:[0-9]+} Exito al actualizar la evaluación. ',
                                        ['user' => $user_current, 'token' => $request->getPut('token')]
                                );
                                $logger->close();
                                return "exito";
                            }
                        } else {
                            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_evaluador"',
                                    ['user' => $user_current, 'token' => $request->getPut('token')]
                            );
                            $logger->close();
                            return "error_evaluador";
                        }
                    }
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
 * 10-06-2020
 * Wilmer Gustavo Mogollón Duque
 * Se agrega nuevo método en el controlador con el fin de poder confirmar top individual
 * sin necesidad de confirmar nuevamente cada evaluación en la etapa de deliberación.
 */
$app->put('/confirmar_top_individual_deliberacion/ronda/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase = '';

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/confirmar_top_individual/ronda/{id:[0-9]+} ' . json_encode($request->getPut()) . '"',
                ['user' => '', 'token' => $request->getPut('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                if ($user_current["id"]) {

                    $ronda = Convocatoriasrondas::findFirst('id = ' . $id);

                    $query = 'SELECT
                                  j.*
                              FROM
                                  Juradospostulados as j
                                  INNER JOIN Propuestas as p
                                  on j.propuesta = p.id
                                  INNER JOIN Participantes as par
                                  on p.participante = par.id
                                  INNER JOIN Usuariosperfiles as up
                                  on par.usuario_perfil = up.id and up.usuario = ' . $user_current["id"]
                            . " WHERE j.convocatoria = " . $ronda->convocatoria
                            . " AND j.active = true ";
                    $postulacion = $this->modelsManager->executeQuery($query)->getFirst();

                    if (isset($postulacion->id) && isset($ronda->id)) {

                        //valida si el usuario pertenece al grupo de evaluación de la ronda
                        $evaluador = Evaluadores::findFirst(
                                        [
                                            'juradopostulado = ' . $postulacion->id
                                            . ' AND grupoevaluador = ' . $ronda->grupoevaluador
                                            . ' AND active = true'
                                        ]
                        );

                        if (isset($evaluador->id)) {

                            /**
                             * Listar las propuestas que estan registradas para evaluar
                             */
                            //fase de evaluación o de deliberación
                            $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación' :
                                    ( $ronda->getEstado_nombre() == "En deliberación" ? 'Deliberación' : "" ) );


                            //Estados Sin evaluar En evaluación
                            $estados = Estados:: find([
                                        "tipo_estado = 'propuestas_evaluacion'"
                                        . " AND nombre IN ('Sin evaluar','En evaluación') "
                            ]);
                            $estados_array = array();
                            foreach ($estados as $key => $estado) {
                                array_push($estados_array, $estado->id);
                            }

//                            $nohabilitadas = Evaluacionpropuestas::find(
//                                            [
//                                                'ronda = ' . $ronda->id
//                                                . ' AND evaluador = ' . $evaluador->id
//                                                . ' AND fase = "' . $fase . '"'
//                                                . ' AND estado IN ({estados:array})',
//                                                'bind' => [
//                                                    'estados' => $estados_array
//                                                ]
//                                            ]
//                            );
//
//                            if ($nohabilitadas->count() > 0) {
//                                $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/confirmar_top_individual/ronda/{id:[0-9]+} error_validacion"',
//                                        ['user' => $user_current, 'token' => $request->getPut('token')]
//                                );
//                                $logger->close();
//
//                                return "error_validacion";
//                            } else {
                            //Estados Evaluada Impedimento
                            $estados = Estados:: find([
                                        "tipo_estado = 'propuestas_evaluacion'"
                                        . " AND nombre IN ('Evaluada', 'En evaluación') "
                            ]);
                            $estados_array = array();
                            foreach ($estados as $key => $estado) {
                                array_push($estados_array, $estado->id);
                            }

                            $habilitadas = Evaluacionpropuestas::find(
                                            [
                                                'ronda = ' . $ronda->id
                                                . ' AND evaluador = ' . $evaluador->id
                                                . ' AND fase = "' . $fase . '"'
                                                . ' AND estado IN ({estados:array})',
                                                'bind' => [
                                                    'estados' => $estados_array
                                                ]
                                            ]
                            );

                            //estado Confirmada
                            $estado_confimada = Estados::findFirst(
                                            [
                                                "tipo_estado = 'propuestas_evaluacion'"
                                                . " AND nombre ='Confirmada'"
                                            ]
                            );

                            // Start a transaction
                            $this->db->begin();

                            foreach ($habilitadas as $key => $evaluacion_propuesta) {
                                $evaluacion_propuesta->estado = $estado_confimada->id;
                                $evaluacion_propuesta->fecha_actualizacion = date("Y-m-d H:i:s");
                                $evaluacion_propuesta->actualizado_por = $user_current["id"];

                                if ($evaluacion_propuesta->save() === false) {
                                    //Para auditoria en versión de pruebas
                                    /* foreach ($evaluacion_propuesta->getMessages() as $message) {
                                      echo $message;
                                      } */

                                    $this->db->rollback();

                                    $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas Error al actualizar la evaluación. '
                                            . json_decode($evaluacion_propuesta->getMessages()) . '"',
                                            ['user' => $user_current, 'token' => $request->getPut('token')]
                                    );
                                    $logger->close();

                                    return "error";
                                }
                            }

                            $this->db->commit();
                            $logger->info('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/confirmar_top_individual/ronda/{id:[0-9]+} Exito al actualizar la evaluación. ',
                                    ['user' => $user_current, 'token' => $request->getPut('token')]
                            );
                            $logger->close();
                            return "exito";
//                            }//quitar este
                        } else {
                            $logger->error('"token":"{token}","user":"{user}","message":"PropuestasEvaluacion/all_propuestas error_evaluador"',
                                    ['user' => $user_current, 'token' => $request->getPut('token')]
                            );
                            $logger->close();
                            return "error_evaluador";
                        }
                    }
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

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>
