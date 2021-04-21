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

//inicializa el formulario
$app->get('/init', function () use ($app, $config) {
    try {

        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $array = array();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if ($user_current["id"]) {

                //Datos select entidades
                $array["entidades"] = Entidades::find("active = true");

                //Datos select año
                for ($i = date("Y"); $i >= 2016; $i--) {
                    $array["anios"][] = $i;
                }

                //Datos select tipos jurados
                //busca los valores de tipos de jurados registrados en tablas maestras
                $tipos_jurado = Tablasmaestras::findFirst(
                                [
                                    "nombre ='tipos_jurado' "
                                    . " AND active = true"
                                ]
                );

                $array["tipos_jurado"] = array();

                foreach (explode(",", $tipos_jurado->valor) as $tipo) {
                    array_push($array["tipos_jurado"], ["id" => $tipo, "nombre" => $tipo]);
                }

                //Retorno el array
                return json_encode($array);
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo: " . $ex->getMessage() . json_encode($ex->getTrace());
    }
});

//Retorna información de id y nombre de las convocatorias
$app->get('/select_convocatorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $convocatorias = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false) {

            //Si existe consulto la convocatoria
            if ($request->get('entidad') && $request->get('anio')) {

                /*
                 * 20-04-2021
                 * Wilmer Gustavo Mogollón Duque
                 * Se agregan estados a la consulta para que liste todas las convocatorias
                 * 5	convocatorias	Publicada
                 * 6	convocatorias	Adjudicada
                 * 32	convocatorias	Cancelada
                 * 43	convocatorias	Desierta
                 * 45	convocatorias	Suspendida
                 */

                $rs = Convocatorias::find(
                                [
                                    " entidad = " . $request->get('entidad')
                                    . " AND anio = " . $request->get('anio')
                                    . " AND estado in (5, 6, 32, 43, 45) "
                                    . " AND modalidad != 2 " //2	Jurados
                                    . " AND active = true "
                                    . " AND convocatoria_padre_categoria is NULL"
                                ]
                );


                foreach ($rs as $convocatoria) {
                    array_push($convocatorias, ["id" => $convocatoria->id, "nombre" => $convocatoria->nombre]);
                }
            }

            return json_encode($convocatorias);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
    }
}
);

//Retorna información de id y nombre de las categorias de la convocatoria
$app->get('/select_categorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false) {

            //Si existe consulto la convocatoria
            if ($request->get('convocatoria')) {

                $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));

                if ($convocatoria->tiene_categorias) {
                    $categorias = Convocatorias::find(
                                    [
                                        " convocatoria_padre_categoria = " . $convocatoria->id
                                        . " AND active = true "
                                    ]
                    );
                }


                //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
                //foreach ( $rs as $key => $value) {
                //      $nucleosbasicos[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                //}

                foreach ($categorias as $categoria) {
                    array_push($response, ["id" => $categoria->id, "nombre" => $categoria->nombre]);
                }
            }

            return json_encode($response);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
    }
}
);

//Retorna información de los jurados preseleccionados (postulados+banco)
$app->get('/all_seleccionados', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //  $juradospostulados =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if ($user_current["id"]) {

                $total_evaluacion = Tablasmaestras::findFirst([" nombre = 'puntaje_minimo_jurado_seleccionar' "]);
                $evaluado = Estados::findFirst(["tipo_estado = 'jurados' AND nombre='Evaluado' "]);


                //busca los que se postularon
                if ($request->get('convocatoria')) {

                    $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));

                    //la convocatoria tiene categorias y son diferentes?, caso 3
                    if ($convocatoria->tiene_categorias && $convocatoria->diferentes_categorias && $request->get('categoria')) {

                        $juradospostulados = Juradospostulados::find(
                                        [
                                            " convocatoria = " . $request->get('categoria')
                                            . " AND total_evaluacion >= " . $total_evaluacion->valor
                                            . " AND estado = " . $evaluado->id
                                        ]
                        );
                    } elseif ($convocatoria->tiene_categorias && !$convocatoria->diferentes_categorias && $request->get('categoria')) {
                        $juradospostulados = Juradospostulados::find(
                                        [
                                            " convocatoria = " . $request->get('categoria')
                                            . " AND total_evaluacion >= " . $total_evaluacion->valor
                                            . " AND estado = " . $evaluado->id
                                        ]
                        );
                    } elseif ($convocatoria->tiene_categorias && !$convocatoria->diferentes_categorias && !$request->get('categoria')) {
                        $juradospostulados = Juradospostulados::find(
                                        [
                                            " convocatoria = " . $request->get('convocatoria')
                                            . " AND total_evaluacion >= " . $total_evaluacion->valor
                                        ]
                        );
                    } else {
                        $juradospostulados = Juradospostulados::find(
                                        [
                                            " convocatoria = " . $request->get('convocatoria')
                                            . " AND total_evaluacion >= " . $total_evaluacion->valor
                                            . " AND estado = " . $evaluado->id
                                        ]
                        );
                    }


                    if ($juradospostulados->count() > 0) {

                        foreach ($juradospostulados as $juradopostulado) {

                            $notificacion_activa = Juradosnotificaciones::findFirst(
                                            [
                                                " active = true"
                                                . " AND juradospostulado = " . $juradopostulado->id
                                            ]
                            );
                            //Validamos que ya haya sido notificado
                            if ($notificacion_activa->id) {
                                // return json_encode($notificacion_activa->estado);

                                array_push($response, [
                                    "postulado" => ( $juradopostulado->tipo_postulacion == 'Inscrita' ? true : false ),
                                    "id" => $juradopostulado->propuestas->participantes->id,
                                    "tipo_documento" => $juradopostulado->propuestas->participantes->tiposdocumentos->nombre,
                                    "numero_documento" => $juradopostulado->propuestas->participantes->numero_documento,
                                    "nombres" => $juradopostulado->propuestas->participantes->primer_nombre . " " . $juradopostulado->propuestas->participantes->segundo_nombre,
                                    "apellidos" => $juradopostulado->propuestas->participantes->primer_apellido . " " . $juradopostulado->propuestas->participantes->segundo_apellido,
                                    "id_postulacion" => $juradopostulado->id,
                                    "puntaje" => $juradopostulado->total_evaluacion,
                                    "aplica_perfil" => $juradopostulado->aplica_perfil,
                                    "estado_postulacion" => $juradopostulado->estado,
                                    "codigo_propuesta" => $juradopostulado->propuestas->codigo,
                                    "estado_notificacion" => ( $notificacion_activa->estado == null ? null : Estados::findFirst($notificacion_activa->estado)->nombre ),
                                    "notificacion" => $notificacion_activa->key
                                ]);
                            }
                        }
                    }
                }
            }

            //return json_encode($juradospostulados);
            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval(count($response)),
                "recordsFiltered" => intval(count($response)),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
    }
}
);




//Busca el registro información básica del participante
$app->get('/search_convocatoria_propuesta', function () use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $a = array();
        $array["participantes"] = array();
        $delimiter = array("[", "]", "\"");

        //  $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if ($user_current["id"]) {

                /*

                  $participante = Participantes::query()
                  ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                  ->join("Propuestas"," Participantes.id = Propuestas.participante")
                  //perfil = 17  perfil de jurado
                  ->where("Usuariosperfiles.perfil = 17 ")
                  ->andWhere("Usuariosperfiles.usuario = ".$participante->usuariosperfiles->usuario )
                  ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                  ->execute()
                  ->getFirst();

                 */
                //$participante = Participantes::findFirst($request->get('participante'));
                $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));
                $array["convocatoria"] = $convocatoria;

                $postulacion = Juradospostulados::findFirst($request->get('participante'));
                $participante = $postulacion->propuestas->participantes;

                //Se establec elos valores del participante
                if ($participante->id != null) {

                    //  $new_participante = clone $old_participante;

                    /* $participante->tipo_documento = Tiposdocumentos::findFirst($participante->tipo_documento)->descripcion;
                      $participante->sexo = Sexos::findFirst($participante->sexo)->nombre;
                      $participante->ciudad_residencia = Ciudades::findFirst($participante->ciudad_residencia)->nombre;
                      $participante->fecha_creacion = null;
                      $participante->participante_padre = null;

                      //Asigno el participante al array
                      $array["participante"] = $participante;
                      $array["perfil"] = $participante->propuestas->resumen; */

                    /* $array["participantes"] =  Convocatoriasparticipantes::find([
                      " convocatoria = ".$request->get('idc')
                      ." AND tipo_participante = 4 "
                      ." AND active = true ",
                      "order" => 'orden ASC',
                      ]);
                     */

                    $participante = Convocatoriasparticipantes::findFirst($postulacion->perfil);

                    //se modifican los valores de algunas propiedades de cada registro

                    $participante->area_perfil = str_replace($delimiter, "", $value->area_perfil);
                    $participante->area_conocimiento = str_replace($delimiter, "", $value->area_conocimiento);
                    $participante->nivel_educativo = str_replace($delimiter, "", $value->nivel_educativo);
                    $participante->formacion_profesional = ($value->formacion_profesional) ? "Si" : "No";
                    $participante->formacion_postgrado = ($value->formacion_postgrado) ? "Si" : "No";
                    $participante->reside_bogota = ($value->reside_bogota) ? "Si" : "No";

                    $array["participante"] = $participante;
                } else {


                    if ($convocatoria->tiene_categorias && $convocatoria->diferentes_categorias) {


                        $participantes = Convocatoriasparticipantes::find([
                                    "convocatoria = " . $request->get('categoria')
                                    . " AND tipo_participante = 4"
                        ]);

                        foreach ($participantes as $key => $value) {
                            $value->area_perfil = str_replace($delimiter, "", $value->area_perfil);
                            $value->area_conocimiento = str_replace($delimiter, "", $value->area_conocimiento);
                            $value->nivel_educativo = str_replace($delimiter, "", $value->nivel_educativo);
                            $value->formacion_profesional = ($value->formacion_profesional) ? "Si" : "No";
                            $value->formacion_postgrado = ($value->formacion_postgrado) ? "Si" : "No";
                            $value->reside_bogota = ($value->reside_bogota) ? "Si" : "No";
                            $a[$key] = $value;
                            array_push($array["participantes"], $value);
                        }
                    } else {



                        $participantes = Convocatoriasparticipantes::find([
                                    " convocatoria = " . $request->get('convocatoria')
                                    . " AND tipo_participante = 4"
                        ]);

                        foreach ($participantes as $key => $value) {
                            $value->area_perfil = str_replace($delimiter, "", $value->area_perfil);
                            $value->area_conocimiento = str_replace($delimiter, "", $value->area_conocimiento);
                            $value->nivel_educativo = str_replace($delimiter, "", $value->nivel_educativo);
                            $value->formacion_profesional = ($value->formacion_profesional) ? "Si" : "No";
                            $value->formacion_postgrado = ($value->formacion_postgrado) ? "Si" : "No";
                            $value->reside_bogota = ($value->reside_bogota) ? "Si" : "No";
                            $a[$key] = $value;
                            array_push($array["participantes"], $value);
                        }
                    }
                }

                return json_encode($array);
            } else {
                echo "error";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
});

/*
 * 27-10-2020
 * Wilmer Gustavo Mogollón Duque
 * registrar_ganador_jurado monto
 */
$app->put('/registrar_ganador_jurado/postulacion/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

//        return json_encode($fase);
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Registroganadoresjurados/registrar_ganador_jurado/postulacion/{id:[0-9]+} ' . json_encode($request->getPut()) . '"',
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


                    $jurado_notificacion = Juradosnotificaciones::maximum(
                                    [
                                        'column' => 'id',
                                        'conditions' => "juradospostulado = " . $id
                                    ]
                    );



                    if (isset($jurado_notificacion)) {


                        // Start a transaction
                        $this->db->begin();


                        // Se crea el objeto notificación
                        $notificacion = Juradosnotificaciones::findFirst(" id = " . $jurado_notificacion);

                        $notificacion->numero_resolucion = $request->getPut('numero_resolucion');
                        $notificacion->fecha_resolucion = $request->getPut('fecha_resolucion');
                        $notificacion->fecha_inicio_ejecucion = $request->getPut('fecha_inicio_ejecucion');
                        $notificacion->fecha_fin_ejecucion = $request->getPut('fecha_fin_ejecucion');
                        $notificacion->nombre_resolucion = $request->getPut('nombre_resolucion');
                        $notificacion->codigo_presupuestal = $request->getPut('codigo_presupuestal');
                        $notificacion->codigo_proyecto_inversion = $request->getPut('codigo_proyecto_inversion');
                        $notificacion->cdp = $request->getPut('cdp');
                        $notificacion->crp = $request->getPut('crp');
                        $notificacion->valor_estimulo = $request->getPut('valor_estimulo');
                        $notificacion->fecha_actualizacion = date("Y-m-d H:i:s");
                        $notificacion->actualizado_por = $user_current["id"];

                        if ($notificacion->save() === false) {
                            //Para auditoria en versión de pruebas
                            /* foreach ($ronda->getMessages() as $message) {
                              echo $message;
                              } */
                            $logger->error('"token":"{token}","user":"{user}","message":"Registroganadoresjurados/registrar_ganador_jurado/postulacion/{id:[0-9]+} error:"' . $notificacion->getMessages(),
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
                        $logger->error('"token":"{token}","user":"{user}","message":"Registroganadoresjurados/registrar_ganador_jurado/postulacion error"',
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
 * Retorna información de la postulación seleccionada
 * puede evaluar
 */
$app->get('/postulacion/{id:[0-9]+}', function ($id) use ($app) {
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


            $jurado_notificacion = Juradosnotificaciones::maximum(
                            [
                                'column' => 'id',
                                'conditions' => "juradospostulado = " . $id
                            ]
            );

            if ($jurado_notificacion) {

                // Se crea el objeto notificación
                $notificacion = Juradosnotificaciones::findFirst(" id = " . $jurado_notificacion);

                return json_encode(
                        [
                            "notificacion" => $notificacion,
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


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>
