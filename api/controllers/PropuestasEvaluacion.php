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
        "host" => $config->database->host,
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
$app->get('/select_convocatorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //Si existe consulto la convocatoria
            if( $request->get('entidad') && $request->get('anio') )
            {

               $rs= Convocatorias::find(
                  [
                      " entidad = ".$request->get('entidad')
                      ." AND anio = ".$request->get('anio')
                      ." AND estado = 5 " //5	convocatorias	Publicada
                      ." AND modalidad != 2 " //2	Jurados
                      ." AND active = true "
                      ." AND convocatoria_padre_categoria is NULL",
                      'order'=>'nombre'
                  ]
                );

                foreach ( $rs as $convocatoria) {
                    array_push($response, ["id"=> $convocatoria->id, "nombre"=> $convocatoria->nombre ] );
                }


            }

            return json_encode($response);

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

//Retorna información de id y nombre de las categorias de la convocatoria
$app->get('/select_categorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //Si existe consulto la convocatoria
            if( $request->get('convocatoria') )
            {

              $convocatoria =  Convocatorias::findFirst($request->get('convocatoria'));

              if( $convocatoria->tiene_categorias){

                $categorias = Convocatorias::find(
                   [
                       ' convocatoria_padre_categoria = '.$convocatoria->id
                       .' AND active = true ',
                       'order'=>'nombre'

                   ]
                 );

                foreach ( $categorias as $categoria) {
                    array_push($response, ["id"=> $categoria->id, "nombre"=> $categoria->nombre ] );
                }

              }


            }

            return json_encode($response);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

//Retorna información de id y nombre de las categorias de la convocatoria
$app->get('/select_estado', function () use ($app, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response =  array();

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"select_estado, $request->get()->'. json_encode($request->get()).'"',
                      ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"select_estado, $token_actual->'. json_encode($token_actual).'"',
                      ['user' => '', 'token' => $request->get('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //Si existe consulto la convocatoria
            if( $request->get('tipo_estado') )
            {

                $estados =  Estados::find( "tipo_estado = '".$request->get('tipo_estado')."'" );

                if( $estados ){


                    foreach ( $estados as $estado) {
                        array_push($response, ["id"=> $estado->id, "nombre"=> $estado->nombre ] );
                    }

                }


            }

            return json_encode($response);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

//Retorna información de las propuestas que el usuario que inicio sesion  puede evaluar
$app->get('/all_propuestas', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if( $user_current["id"] ){

                if( $request->get('ronda') ){

                    $ronda =  Convocatoriasrondas::findFirst( 'id = '.$request->get('ronda') );

                    $query='SELECT
                                j.*
                            FROM
                                Juradospostulados as j
                                INNER JOIN Propuestas as p
                                on j.propuesta = p.id
                                INNER JOIN Participantes as par
                                on p.participante = par.id
                                INNER JOIN Usuariosperfiles as up
                                on par.usuario_perfil = up.id and up.usuario = '.$user_current["id"]
                                ." WHERE j.convocatoria = ".$ronda->convocatoria;

                    $postulacion =  $this->modelsManager->executeQuery($query)->getFirst();


                    if( isset($postulacion->id) && isset($ronda->id) ){

                        //valida si el usuario pertenece al grupo de evaluación de la ronda
                        $evaluador = Evaluadores::findFirst(
                            [
                                'juradopostulado = '.$postulacion->id
                                .' AND grupoevaluador = '.$ronda->grupoevaluador
                            ]
                        );

                        if( $evaluador ) {


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
                              " convocatoria = ".$ronda->convocatoria
                              ." AND active = true ",
                              'order'=>'id ASC',
                            ]
                          );

                          //si es la primera ronda y ronda estado habilitada, es decir en fase de evaluación
                          if( ($rondas[0])->id == $ronda->id  && $ronda->getEstado_nombre() == "Habilitada"){

                            //propuestas incluidas por el evaluador
                            $propuestas_evaluacion = Evaluacionpropuestas::find(
                              [
                                  "ronda =".$ronda->id
                                  ." AND fase = 'Evaluación' "
                                  ." AND evaluador = ".$evaluador->id
                              ]
                            );

                            $array_propuestas =  array(-1);
                            foreach ($propuestas_evaluacion as $key => $evaluacion) {
                              array_push($array_propuestas, $evaluacion->propuesta);
                            }

                            //24	propuestas	Habilitada
                            $estado_propuesta= Estados::findFirst("tipo_estado = 'propuestas' AND nombre = 'Habilitada'");
                            //propuestas a incluir por parte del evaluador
                            $propuestas_incluir = Propuestas::find(
                              [
                                " convocatoria = ".$ronda->convocatoria
                                .' AND estado = '.$estado_propuesta->id
                                .' AND id NOT IN ({propuestas:array})',
                                'bind' => [
                                    'propuestas' => $array_propuestas
                                ]
                              ]
                            );

                            // se guarda cada propuesta a incluir en la evaluación

                            foreach ($propuestas_incluir as $key => $propuesta) {
                                    $evaluacion_propuesta = new Evaluacionpropuestas();
                                    $evaluacion_propuesta->propuesta = $propuesta->id;
                                    $evaluacion_propuesta->ronda =  $ronda->id;
                                    $evaluacion_propuesta->evaluador = $evaluador->id;
                                    $evaluacion_propuesta->estado = (Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Sin evaluar'"))->id;
                                    //ronda_estado = habilitada
                                    $evaluacion_propuesta->fase = 'Evaluación';
                                    $evaluacion_propuesta->fecha_creacion =  date("Y-m-d H:i:s");
                                    $evaluacion_propuesta->creado_por = $user_current["id"];
                                    $evaluacion_propuesta->active =  true;

                                    if ( $evaluacion_propuesta->save() === false ) {

                                      //Para auditoria en versión de pruebas
                                      foreach ($evaluacion_propuesta->getMessages() as $message) {
                                           echo $message;
                                         }

                                      return "error";
                                    }
                            }//fin foreach

                          }

                          /**
                          * Listar las propuestas que estan registradas para evaluar
                          */
                          //fase de evaluación o de deliberación
                          $fase = ( $ronda->getEstado_nombre() == "Habilitada" ? 'Evaluación':
                                          ( $ronda->getEstado_nombre() == "En deliberación" ? 'Deliberación': "" ) );

                          $evaluacionpropuestas =  Evaluacionpropuestas::find(
                                [
                                    'ronda = '.$ronda->id
                                    .' AND evaluador = '.$evaluador->id
                                    .' AND fase = "'.$fase.'"'
                                    .( $request->get('estado') ? ' AND estado = '.$request->get('estado') : '' ),
                                    'order'=>'propuesta ASC',
                                    'limit' =>  $request->get('length'),
                                    'offset' =>  $request->get('start'),
                                ]
                             );

                             $allevaluacionpropuestas =  Evaluacionpropuestas::find(
                                   [
                                       'ronda = '.$ronda->id
                                       .' AND evaluador = '.$evaluador->id
                                       .' AND fase = "'.$fase.'"'
                                       .( $request->get('estado') ? ' AND estado = '.$request->get('estado') : '' ),
                                       'order'=>'propuesta ASC',
                                   ]
                                );

                          if( $evaluacionpropuestas->count() > 0 ){

                              foreach ( $evaluacionpropuestas as $evaluacionpropuesta) {

                                    array_push($response, [
                                        "id_evaluacion"=>$evaluacionpropuesta->id,
                                        "total_evaluacion"=> $evaluacionpropuesta->total,
                                        "estado_evaluacion"=>(Estados::findFirst('id = '.$evaluacionpropuesta->estado ))->nombre ,
                                        "id_propuesta"=> $evaluacionpropuesta->Propuestas->id,
                                        "codigo_propuesta"=> $evaluacionpropuesta->Propuestas->codigo,
                                        "nombre_propuesta"=> $evaluacionpropuesta->Propuestas->nombre,
                                    ] );

                                }

                            }

                        }else{
                          return "error_evaluador";
                        }

                    }else{
                      return "error";
                    }

                }

            }


           // return json_encode($response);

            //creo el array
            $json_data = array(
            "draw" => intval($request->get("draw")),
            "recordsTotal" => intval( count($allevaluacionpropuestas) ),
            "recordsFiltered" => intval( count($allevaluacionpropuestas) ),
            "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

/**
*Retorna información de la propuesta que el usuario que inicio sesion
*puede evaluar
*/
$app->get('/propuestas/{id:[0-9]+}', function ($id) use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            $propuesta = Propuestas::findFirst(' id = '.$id );

            if($propuesta){

                //parametros extra
                $parametros = array();

                foreach ($propuesta->Propuestasparametros as $propuestaparametro) {
                    array_push($parametros,
                        [
                          "nombre_parametro"=>$propuestaparametro->Convocatoriaspropuestasparametros->label,
                          "valor_parametro"=>$propuestaparametro->valor
                        ]
                      );
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
                             	AND r.tipo_requisito LIKE 'Tecnicos'
                          WHERE
                              	p.propuesta = ".$propuesta->id;

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
                              	p.propuesta = ".$propuesta->id;

                $links = $this->modelsManager->executeQuery($query);


                return json_encode(
                [
                    "propuesta"=> $propuesta,
                    "parametros"=>$parametros,
                    "documentos"=>$documentos,
                    "links"=>$links,

                ]);


            }else{

                return null;
            }


        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

/**
 *Carga los datos relacionados con los criterios de evaluación
 *asociados con la evaluación propuesta
 *ronda, postulacion, criterios
 */
$app->get('/evaluacionpropuestas/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));


        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if( $user_current["id"]){


                $evaluacionpropuesta = Evaluacionpropuestas::findFirst( ' id = '.$id );

                $ronda = Convocatoriasrondas::findFirst('id = '.$evaluacionpropuesta->ronda);

                if( $ronda->active ){

                        //se construye el array de grupos d ecriterios
                        $grupo_criterios = array();
                        //se cronstruye el array de criterios
                        $criterios = array();

                        //Se crea el array en el orden de los criterios
                        foreach ($ronda->Convocatoriasrondascriterios as $criterio) {

                            if($criterio->active){
                                $grupo_criterios[$criterio->grupo_criterio]= $criterio->orden;
                            }

                        }

                        //de acuerdo con el orden, se crea al array de criterios
                        foreach ($grupo_criterios as $categoria => $orden) {

                            //$obj = ["grupo" => $categoria, "criterios"=> array()];
                            $obj= array();
                            $obj[$categoria] = array();

                            foreach ($ronda->Convocatoriasrondascriterios as $criterio) {

                                if( $criterio->active && $criterio->grupo_criterio === $categoria ){
                                    // $obj[$categoria][$criterio->orden]=  $criterio;
                                    $obj[$categoria][$criterio->orden]= [
                                    "id"=>$criterio->id,
                                    "descripcion_criterio"=>$criterio->descripcion_criterio,
                                    "puntaje_minimo"=>$criterio->puntaje_minimo,
                                    "puntaje_maximo"=>$criterio->puntaje_maximo,
                                    "orden"=>$criterio->orden,
                                    "grupo_criterio"=>$criterio->grupo_criterio,
                                    "exclusivo"=>$criterio->exclusivo,
                                    "evaluacion"=> Evaluacioncriterios::findFirst([
                                                "criterio = ".$criterio->id
                                                ." AND evaluacionpropuesta = ".$evaluacionpropuesta->id
                                                ])
                                    ];

                                }

                            }


                            $criterios[$orden]= $obj ;
                        }


                        $response[$ronda->numero_ronda]= ["ronda"=>$ronda,"ronda_nombre_estado"=>$ronda->getEstado_nombre(),"evaluacion"=>$evaluacionpropuesta,"evaluacion_nombre_estado"=>$evaluacionpropuesta->getEstado_nombre() ,"criterios"=>$criterios];

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
        return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

//Guarda la evaluación de los criterios
$app->post('/evaluar_criterios', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $total_evaluacion=0;
        $fase= '';
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));


        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ( $permiso_escritura == "ok" ) {

                $user_current = json_decode($token_actual->user_current, true);

                //Se consulta la evalaucaión
                $evaluacion = Evaluacionpropuestas::findFirst( " id = ". $request->getPost('evaluacion') );

                if( $evaluacion ){

                    $ronda = Convocatoriasrondas::findFirst( 'id = '.$evaluacion->ronda );

                    if( $ronda ){

                      /**
                      * Cesar Britto, 20-04-2020
                      * Se modifica para el manejo de los estados
                      */
                        //Si la ronda esta evaluada no se permite la actualización de la evaluación
                        if( $ronda->getEstado_nombre() == "Evaluada" ){

                            return 'deshabilitado';
                        }

                        //En la fase de evaluación
                        if( $ronda->getEstado_nombre() == "Habilitada" && ( $ronda->fecha_fin_evaluacion >= date("Y-m-d H:i:s") ) ){
                            $fase = 'Evaluación';
                        }

                        //En la fase de deliberación
                        if( $ronda->getEstado_nombre() == "En deliberación" && ( $ronda->fecha_deliberacion >= date("Y-m-d H:i:s") ) ){
                            $fase = 'Deliberación';
                        }

                        //Si la evaluación esta habilitada para modificarse
                        //evaluacion_propuesta	Sin evaluar
                        //evaluacion_propuesta	En evaluación
                        if(  $evaluacion->fase == $fase && ($evaluacion->getEstado_nombre() == "Sin evaluar" || $evaluacion->getEstado_nombre() == "En evaluación") ){

                            //Criterios de evaluación de la ronda
                            $criterios = Convocatoriasrondascriterios::find(
                                [
                                    "convocatoria_ronda = ".$ronda->id
                                    ." AND active = true"
                                ]
                             );

                            // Start a transaction
                            $this->db->begin();

                            //Se registra los valores por cada criterio evaluado
                            foreach ($criterios as $criterio) {

                                //Consulto el criterio
                                $evaluacioncriterio = Evaluacioncriterios::findFirst(
                                    [
                                        ' evaluacionpropuesta = '.$evaluacion->id
                                        .' AND criterio = '.$criterio->id
                                    ]
                                 );

                                //Si no existe el criterioevaluacion se crea
                                if( !$evaluacioncriterio ){
                                    $evaluacioncriterio = new Evaluacioncriterios();
                                    $evaluacioncriterio->evaluacionpropuesta = $evaluacion->id;
                                    $evaluacioncriterio->criterio = $criterio->id;
                                    $evaluacioncriterio->active = true;
                                    $evaluacioncriterio->creado_por = $user_current["id"];
                                    $evaluacioncriterio->fecha_creacion =  date("Y-m-d H:i:s");

                                }else{
                                    // se actualiza los campos
                                    $evaluacioncriterio->actualizado_por = $user_current["id"];
                                    $evaluacioncriterio->fecha_actualizacion =  date("Y-m-d H:i:s");
                                }

                                $evaluacioncriterio->puntaje = $request->getPost('puntuacion_'.$criterio->id);
                                $evaluacioncriterio->observacion = $request->getPost('observacion_'.$criterio->id);

                                // The model failed to save, so rollback the transaction
                                if ($evaluacioncriterio->save() === false) {
                                    //Para auditoria en versión de pruebas
                                    foreach ($evaluacioncriterio->getMessages() as $message) {
                                        echo $message;
                                    }

                                    $this->db->rollback();
                                    return "error";
                                }

                                $total_evaluacion = $total_evaluacion+$evaluacioncriterio->puntaje;

                            }

                            $evaluacion->total = $total_evaluacion;
                            $evaluacion->actualizado_por = $user_current["id"];
                            $evaluacion->fecha_actualizacion =  date("Y-m-d H:i:s");
                            /**
                            * Cesar Britto, 20-04-2020
                            * Se modifica para el manejo de los estados
                            */
                            //evaluacion_propuesta	En evaluación
                            $evaluacion->estado=(Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'En evaluación'") )->id;

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

                            return (string)$evaluacion->id;

                        }else{
                            return 'deshabilitado';
                        }

                    }else{
                        return "error";
                    }

                }else{
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
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());

    }
}
);

/**
*Funcionalidad Descargar archivos
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
        if ($token_actual > 0) {
            echo $chemistry_alfresco->download($request->getPost('cod'));
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        //  echo "error_metodo";

        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

/**
*Confirma la evaluación de los criterios
*/
$app->post('/confirmar_evaluacion', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase= '';

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ( $permiso_escritura == "ok" ) {

                $user_current = json_decode($token_actual->user_current, true);

                //Se consulta la evaluación
                $evaluacion = Evaluacionpropuestas::findFirst( " id = ". $request->getPost('evaluacion') );

                if( $evaluacion ){

                    $ronda = Convocatoriasrondas::findFirst( 'id = '.$evaluacion->ronda );

                    if( $ronda ){

                        //Si la ronda está evaluada no se permite la actualización de la evaluación
                        if( $ronda->getEstado_nombre() == "Evaluada" ){

                            return 'deshabilitado';
                        }


                        //En la fase de evaluación
                        if( $ronda->getEstado_nombre() == "Habilitada" && ( $ronda->fecha_fin_evaluacion >= date("Y-m-d H:i:s") ) ){
                            $fase = 'Evaluación';
                        }

                        //En la fase de deliberación
                        if( $ronda->getEstado_nombre() == "En deliberación" && ( $ronda->fecha_deliberacion >= date("Y-m-d H:i:s") ) ){
                            $fase = 'Deliberación';
                        }

                        //Si la evaluación esta habilitada para modificarse
                        //28	evaluacion_propuesta	Sin evaluar
                        //29	evaluacion_propuesta	En evaluación
                        if(  $evaluacion->fase == $fase && ($evaluacion->getEstado_nombre() == "Sin evaluar" || $evaluacion->getEstado_nombre() == "En evaluación") ){

                            //Criterios de evaluación de la ronda
                            $criterios = Convocatoriasrondascriterios::find(
                                [
                                    "convocatoria_ronda = ".$ronda->id
                                    ." AND active = true"
                                ]
                                );

                            //Se registra los valores por cada criterio evaluado
                            foreach ($criterios as $criterio) {

                                //Consulto el criterio
                                $evaluacioncriterio = Evaluacioncriterios::findFirst(
                                    [
                                        ' evaluacionpropuesta = '.$evaluacion->id
                                        .' AND criterio = '.$criterio->id
                                    ]
                                    );

                                //Si no existe el criterioevaluacion se retorna mensaje
                                if( $evaluacioncriterio->puntaje == null   ){
                                    return 'criterio_null';
                                }


                            }

                            $evaluacion->actualizado_por = $user_current["id"];
                            $evaluacion->fecha_actualizacion =  date("Y-m-d H:i:s");
                            //propuestas_evaluacion	Evaluada
                            $evaluacion->estado = (Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Evaluada' "))->id;

                            if ($evaluacion->save() === false) {
                                //Para auditoria en versión de pruebas
                                /*foreach ($evaluacion->getMessages() as $message) {
                                    echo $message;
                                }*/

                                return "error";
                            }

                            return (string)$evaluacion->id;


                        }else{
                            return 'deshabilitado';
                        }

                    }else{
                        return "error";
                    }

                }else{
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
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());

    }
}
);

/**
 *Carga los datos relacionados con el evaluador (Jurado) asociado a la evaluación
 *de la  propuesta
 */
$app->get('/evaluacionpropuestas/{id:[0-9]+}/evaluadores', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if( $user_current["id"]){

                $evaluacionpropuesta = Evaluacionpropuestas::findFirst( ' id = '.$id );

                $evaluador = Evaluadores::findFirst('id = '.$evaluacionpropuesta->evaluador);

                $juradopostulado = Juradospostulados::findFirst( ' id = '.$evaluador->juradopostulado);

                //retorno el array en json
                $participante = $juradopostulado->Propuestas->Participantes;

                $response = ["tipo_documento" =>$juradopostulado->Propuestas->Participantes->Tiposdocumentos->nombre,
                             "numero_documento" =>$participante->numero_documento,
                             "nombre"=> $participante->primer_nombre." ".$participante->segundo_nombre." ".$participante->primer_apellido." ".$participante->segundo_apellido,
                             "correo_electronico" =>$participante->correo_electronico
                            ];

                return json_encode($response);

            }else{
              return 'error';
            }

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";

        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
    }
});


/**
 *Carga los datos relacionados con el evaluador (Jurado) asociado a la evaluación
 *de la  propuesta
 */
$app->get('/evaluacionpropuestas/{id:[0-9]+}/impedimentos', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if( $user_current["id"]){

                $evaluacionpropuesta = Evaluacionpropuestas::findFirst( ' id = '.$id );

                $evaluador = Evaluadores::findFirst('id = '.$evaluacionpropuesta->evaluador);

                $juradopostulado = Juradospostulados::findFirst( ' id = '.$evaluador->juradopostulado);

                //retorno el array en json
                $participante = $juradopostulado->Propuestas->Participantes;

                //Creo el cuerpo del messaje html del email
                $html_jurado_notificacion_impedimento = Tablasmaestras::find("active=true AND nombre='html_jurado_notificacion_impedimento'")[0]->valor;
                $html_jurado_notificacion_impedimento = str_replace("**fecha_creacion**", "<span id='fecha_creacion'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**nombre_jurado**","<span id='nombre_jurado'></span>" , $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**nombre_jurado_2**","<span id='nombre_jurado_2'></span>" , $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**tipo_documento**","<span id='tipo_documento'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**numero_documento**", "<span id='numero_documento'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**correo_jurado**", "<span id='correo_jurado'></span>", $html_jurado_notificacion_impedimento);
                $html_jurado_notificacion_impedimento = str_replace("**motivo_impedimento**","<span id='motivo_impedimento'></span>", $html_jurado_notificacion_impedimento);

                $response = [
                             "fecha_creacion"=>date("d/m/Y"),
                             "tipo_documento" =>$juradopostulado->Propuestas->Participantes->Tiposdocumentos->nombre,
                             "numero_documento" =>$participante->numero_documento,
                             "nombre_jurado"=> $participante->primer_nombre." ".$participante->segundo_nombre." ".$participante->primer_apellido." ".$participante->segundo_apellido,
                             "correo_jurado" =>$participante->correo_electronico,
                             "notificacion"=>$html_jurado_notificacion_impedimento,
                             "evaluacion"=>$evaluacionpropuesta
                            ];

                return json_encode($response);

            }else{
              return 'error';
            }

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";

        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
    }
});




/**
*Establece el impedimento de la evaluación y envia una notificacion al correo_jurado
* del jurado evaluador y al misional
*/
$app->put('/evaluacionpropuestas/{id:[0-9]+}/impedimentos', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase= '';
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ( $permiso_escritura == "ok" ) {

                $user_current = json_decode($token_actual->user_current, true);

                //Se consulta la evaluación
                $evaluacion = Evaluacionpropuestas::findFirst( " id = ".$id);

                if( $evaluacion ){

                    $ronda = Convocatoriasrondas::findFirst( 'id = '.$evaluacion->ronda );

                    if( $ronda ){

                        //Si la ronda está evaluada no se permite la actualización de la evaluación
                        if( $ronda->estado == 27 ){

                            return 'deshabilitado';
                        }

                        //En la fase de evaluación
                        if( $ronda->estado == 25 && ( $ronda->fecha_fin_evaluacion >= date("Y-m-d H:i:s") ) ){
                            $fase = 'Evaluación';
                        }

                        //En la fase de deliberación
                        if( $ronda->estado == 26 && ( $ronda->fecha_deliberacion >= date("Y-m-d H:i:s") ) ){
                            $fase = 'Deliberación';
                        }

                        //Si la evaluación esta habilitada para modificarse
                        //28	evaluacion_propuesta	Sin evaluar
                        //29	evaluacion_propuesta	En evaluación
                        //30	evaluacion_propuesta	Evaluada
                        if(  $evaluacion->fase == $fase && ( $evaluacion->estado === 28 || $evaluacion->estado === 29 || $evaluacion->estado === 30 ) ){

                          $evaluador = Evaluadores::findFirst('id = '.$evaluacion->evaluador);

                          $juradopostulado = Juradospostulados::findFirst( ' id = '.$evaluador->juradopostulado);

                          //retorno el array en json
                          $participante = $juradopostulado->Propuestas->Participantes;

                            $evaluacion->observacion = $request->getPut('observacion_impedimento');
                            $evaluacion->actualizado_por = $user_current["id"];
                            $evaluacion->fecha_actualizacion =  date("Y-m-d H:i:s");
                            $evaluacion->estado=31;//31	evaluacion_propuesta	Impedimento

                            // Start a transaction
                            $this->db->begin();

                            // The model failed to save, so rollback the transaction
                            if ($evaluacion->save() === false) {
                                //Para auditoria en versión de pruebas
                                foreach ($evaluacion->getMessages() as $message) {
                                    echo $message;
                                }

                                $this->db->rollback();

                                return "error";
                            }else{

                              //Creo el cuerpo del messaje html del email
                              $html_jurado_notificacion_impedimento = Tablasmaestras::find("active=true AND nombre='html_jurado_notificacion_impedimento'")[0]->valor;
                              $html_jurado_notificacion_impedimento = str_replace("**fecha_creacion**", date("d/m/Y"), $html_jurado_notificacion_impedimento);
                              $html_jurado_notificacion_impedimento = str_replace("**nombre_jurado**",$participante->primer_nombre." ".$participante->primer_apellido , $html_jurado_notificacion_impedimento);
                              $html_jurado_notificacion_impedimento = str_replace("**tipo_documento**", $participante->Tiposdocumentos->nombre, $html_jurado_notificacion_impedimento);
                              $html_jurado_notificacion_impedimento = str_replace("**numero_documento**", $participante->numero_documento, $html_jurado_notificacion_impedimento);
                              $html_jurado_notificacion_impedimento = str_replace("**correo_jurado**",$participante->correo_electronico, $html_jurado_notificacion_impedimento);
                              $html_jurado_notificacion_impedimento = str_replace("**motivo_impedimento**",$request->getPut('observacion_impedimento'), $html_jurado_notificacion_impedimento);

                              $mail = new PHPMailer();
                              $mail->IsSMTP();
                              $mail->SMTPAuth = true;
                              $mail->Host = "smtp.gmail.com";
                              $mail->SMTPSecure = 'ssl';
                              $mail->Username = "convocatorias@scrd.gov.co";
                              $mail->Password = "fomento2017";
                              $mail->Port = 465;
                              $mail->CharSet = "UTF-8";
                              $mail->IsHTML(true); // El correo se env  a como HTML
                              $mail->From = "convocatorias@scrd.gov.co";
                              $mail->FromName = "Sistema de Convocatorias";
                              $mail->AddAddress($participante->correo_electronico);//direccion de correo del jurado
                              //$mail->AddBCC($user_current["username"]); //con copia al misional que realiza la invitación
                              $mail->AddBCC("cesar.augusto.britto@gmail.com");//direccion de prueba
                              $mail->Subject = "Declaración de impedimento - Convocatoria".$ronda->Convocatorias->nombre;
                              $mail->Body = $html_jurado_notificacion_impedimento;

                                  // Env  a el correo.
                              if ( $mail->Send() ) {

                                  $this->db->commit();
                                  return "exito";
                              } else {
                                  $this->db->rollback();
                                  return "error_email";
                              }

                            }

                        }else{
                            return 'deshabilitado';
                        }

                    }else{
                        return "error";
                    }


                }else{
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
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());

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
