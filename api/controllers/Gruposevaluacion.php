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

                $rs = Convocatorias::find(
                                [
                                    " entidad = " . $request->get('entidad')
                                    . " AND anio = " . $request->get('anio')
                                    . " AND estado = 5 "
                                    . " AND modalidad != 2 " //2	Jurados
                                    . " AND active = true "
                                    . " AND convocatoria_padre_categoria is NULL"
                                ]
                );

                //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
                //foreach ( $rs as $key => $value) {
                //      $nucleosbasicos[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                //}

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

//Retorna información de id y nombre de las rondas de evaluación de la convocatoria
$app->get('/select_rondas', function () use ($app) {
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

                /*
                 * 22-04-2020
                 * WILMER GUSTAVO MOGOLLÓN DUQUE
                 * Se agrega un if para definir el código de la convocatoria que se relaciona con la ronda.
                 */
                if ($request->get('categoria')) {
                    $convocatoria = Convocatorias::findFirst($request->get('categoria'));
                } else {
                    $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));
                }



                $rondas = Convocatoriasrondas::query()
                        /*
                         * 23-02-2021
                         * Wilmer GUstavo Mogollón Duque
                         * Se cambian las condiciones de la consulta para mostrar todas las rondas
                         */
//                        ->andWhere("Convocatoriasrondas.grupoevaluador IS NULL")
                        ->where("Convocatoriasrondas.active = true ")
                        ->andWhere("Convocatoriasrondas.convocatoria = " . $convocatoria->id)
                        ->orderBy("Convocatoriasrondas.numero_ronda")
                        ->execute();

                if ($rondas) {

                    //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
                    //foreach (  $convocatoria->Convocatoriasrondas as $ronda) {
                    foreach ($rondas as $ronda) {
                        array_push($response, ["id" => $ronda->id, "nombre" => $ronda->nombre_ronda]);
                    }
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

//Retorna información de id y nombre de los grupos de evaluación de la tabla maestra
$app->get('/select_grupos', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false) {

            $grupos = Tablasmaestras::findFirst(
                            [
                                "nombre = 'grupos_evaluacion'"
                            ]
            );

            if ($grupos) {

                //Se construye un array con la información de id y nombre de cada grupo para establecer el componente select
                foreach (explode(",", $grupos->valor) as $grupo) {
                    array_push($response, ["id" => $grupo, "nombre" => $grupo]);
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

//Retorna información de los grupos de evaluación
$app->get('/all_grupos_evaluacion', function () use ($app) {

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
            $idgrupos = array();

            if ($user_current["id"]) {

                //busca los que se postularon
                if ($request->get('convocatoria')) {

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
                        $idgrupos = array();

                        if ($user_current["id"]) {

                            //  $total_evaluacion = Tablasmaestras::findFirst([" nombre = 'puntaje_minimo_jurado_seleccionar' "]);
                            //busca los que se postularon
                            if ($request->get('convocatoria')) {

                                /*
                                 * 22-04-2020
                                 * WILMER GUSTAVO MOGOLLÓN DUQUE
                                 * Se agrega un if para definir el código de la convocatoria que se relaciona con la ronda.
                                 */
                                if ($request->get('categoria')) {
                                    $convocatoria = Convocatorias::findFirst($request->get('categoria'));
                                } else {
                                    $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));
                                }

                                //$convocatoria = Convocatorias::findFirst($request->get('convocatoria'));
                                //id de los gruposevaluacion que estan relacionados con la convocatoria
//                                $result = $this->modelsManager->createQuery('SELECT distinct Convocatoriasrondas.grupoevaluador'
//                                                . ' FROM	Convocatoriasrondas '
//                                                . ' WHERE Convocatoriasrondas.convocatoria =' . $convocatoria->id
//                                                . ' AND Convocatoriasrondas.grupoevaluador IS NOT NULL')->execute();
                                /*
                                 * 23-02-2021
                                 * WILMER GUSTAVO MOGOLLÓN DUQUE
                                 * Se modifica la consulta para mostrar los grupos de evaluación relacionados 
                                 * con los registros de la tabla gruposevaluadoresrondas.
                                 */

                                $result = $this->modelsManager->createQuery('SELECT distinct Convocatoriasrondas.id'
                                                . ' FROM	Convocatoriasrondas '
                                                . ' WHERE Convocatoriasrondas.convocatoria =' . $convocatoria->id
                                                . ' AND active=true'
                                                . ' AND Convocatoriasrondas.grupoevaluador IS NOT NULL')->execute();


                                //return json_encode($idgrupos);
                                //return print_r($idgrupos);
                                if ($result->count() > 0) {

                                    foreach ($result as $row) {

                                        /*
                                         * 23-02-2021
                                         * Se crea el objeto $gruposevaluadoresrondas para construir el array $idgrupos
                                         */

                                        $gruposevaluadoresrondas = Gruposevaluadoresrondas::find(
                                                        [
                                                            ' convocatoriaronda = ' . $row->id
                                                            . ' AND active = true '
                                                        ]
                                        );

                                        foreach ($gruposevaluadoresrondas as $grupoevaluadorronda) {
                                            array_push($idgrupos, $grupoevaluadorronda->grupoevaluador);
                                        }
                                    }

                                    $gruposevaluadores = Gruposevaluadores::find(
                                                    [
                                                        'id IN ({idgrupos:array})',
                                                        'bind' => [
                                                            'idgrupos' => $idgrupos
                                                        ],
                                                        'order' => 'id',
                                                        'limit' => $request->get('length'),
                                                        'offset' => $request->get('start')
                                                    ]
                                    );

                                    foreach ($gruposevaluadores as $grupoevaluador) {

                                        $suplentes = Evaluadores::query()
                                                ->join("Juradospostulados", "Evaluadores.juradopostulado = Juradospostulados.id")
                                                ->where("Evaluadores.grupoevaluador = " . $grupoevaluador->id)
                                                ->andWhere("Juradospostulados.rol = 'Suplente' ")
                                                ->execute();

                                        $principales = Evaluadores::query()
                                                ->join("Juradospostulados", "Evaluadores.juradopostulado = Juradospostulados.id")
                                                ->where("Evaluadores.grupoevaluador = " . $grupoevaluador->id)
                                                ->andWhere("Juradospostulados.rol = 'Principal' ")
                                                ->execute();

                                        $estado = Estados::findFirst('id = ' . $grupoevaluador->estado);

                                        array_push($response, [
                                            "id" => $grupoevaluador->id,
                                            "nombre_grupo" => $grupoevaluador->nombre,
                                            "nombre_estado" => $estado->nombre,
                                            "numero_principales" => $principales->count(),
                                            "numero_suplentes" => $suplentes->count(),
                                            "numero_total" => ( $principales->count() + $suplentes->count() ),
                                            "estado" => $grupoevaluador->estado
                                        ]);
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
                    }
                }
            }

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

//Retorna información de los jurados que aceptaron la notificacion
$app->get('/jurados_aceptaron', function () use ($app) {
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

                //busca los que se postularon
                if ($request->get('convocatoria')) {

                    /*
                     * 22-04-2020
                     * WILMER GUSTAVO MOGOLLÓN DUQUE
                     * Se agrega un if para definir el código de la convocatoria o la categoría que se relaciona con los jurados.
                     */


                    if ($request->get('categoria')) {
                        $convocatoria = Convocatorias::findFirst($request->get('categoria'));
                    } else {
                        $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));
                    }

                    //$convocatoria =  Convocatorias::findFirst($request->get('convocatoria'));

                    $juradospostulados = Juradospostulados::query()
                            ->join("Juradosnotificaciones", "Juradospostulados.id = Juradosnotificaciones.juradospostulado")
                            ->where("Juradosnotificaciones.active = true ")
                            ->andWhere("Juradosnotificaciones.estado = 15 ")//15	jurado_notificaciones	Aceptada
                            //->andWhere("Juradospostulados.convocatoria = ".$request->get('convocatoria') )
                            ->andWhere("Juradospostulados.convocatoria = " . $convocatoria->id) //Se modifica para que sea dinámica
                            ->limit($request->get('start'), $request->get('length'))
                            ->execute();

                    if ($juradospostulados->count() > 0) {

                        foreach ($juradospostulados as $juradopostulado) {

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
                                "rol" => $juradopostulado->rol
                            ]);
                        }
                    }

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
                    return "error";
                }
            } else {
                return "error";
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

//Crea un grupo de evaluación
$app->put('/new', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
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


//                $suplentes = Juradospostulados::find(
//                                [
//                                    ' id IN ({seleccionados:array})'
//                                    . ' AND rol = "Suplente" '
//                                    . ' AND active = true ',
//                                    'bind' => [
//                                        'seleccionados' => $request->getPut('seleccionados')
//                                    ]
//                                ]
//                );

                // Start a transaction
                $this->db->begin();

                $grupoevaluador = new Gruposevaluadores();
                $grupoevaluador->nombre = $request->getPut('grupos');
                $grupoevaluador->fecha_creacion = date("Y-m-d H:i:s");
                $grupoevaluador->creado_por = $user_current["id"];
                $grupoevaluador->active = true;
                $grupoevaluador->estado = 18; //18	grupos_evaluacion	Sin confirmar

                if ($grupoevaluador->save() === false) {

                    //Para auditoria en versión de pruebas
                    foreach ($grupoevaluador->getMessages() as $message) {
                        echo $message;
                    }

                    $this->db->rollback();
                    return "error";
                } else {

                    //se crea los evaluadores del grupo
                    foreach ($request->getPut('seleccionados') as $juradopostulado) {

                        $evaluador = new Evaluadores();
                        $evaluador->grupoevaluador = $grupoevaluador->id;
                        $evaluador->juradopostulado = $juradopostulado;
                        $evaluador->fecha_creacion = date("Y-m-d H:i:s");
                        $evaluador->creado_por = $user_current["id"];
                        $evaluador->active = true;

                        if ($evaluador->save() === false) {

                            //Para auditoria en versión de pruebas
                            foreach ($evaluador->getMessages() as $message) {
                                echo $message;
                            }

                            $this->db->rollback();
                            return "error";
                        } else {

                            //se actualiza el grupo de evaluación de la ronda
                            $rondas = Convocatoriasrondas::find(
                                            [
                                                ' id IN ({rondas:array})',
                                                'bind' => [
                                                    'rondas' => $request->getPut('rondas')
                                                ]
                                            ]
                            );

                            foreach ($rondas as $key => $ronda) {

                                $ronda->grupoevaluador = $grupoevaluador->id;

                                if ($ronda->save() === false) {

                                    //Para auditoria en versión de pruebas
                                    foreach ($ronda->getMessages() as $message) {
                                        echo $message;
                                    }

                                    $this->db->rollback();
                                    return "error";
                                }
                            }//fin foreach
                        }
                    }

                    /*
                     * 23-02-2021
                     * Wilmer Gustavo Mogollón Duque
                     * Se guarda el registro en la tabla gruposevaluadoresrondas
                     */
                    $grupoevaluadorronda = new Gruposevaluadoresrondas();
                    $grupoevaluadorronda->convocatoriaronda = $ronda->id;
                    $grupoevaluadorronda->grupoevaluador = $grupoevaluador->id;
                    $grupoevaluadorronda->fecha_creacion = date("Y-m-d H:i:s");
                    $grupoevaluadorronda->creado_por = $user_current["id"];
                    $grupoevaluadorronda->active = true;

                    if ($grupoevaluadorronda->save() === false) {

                        //Para auditoria en versión de pruebas
                        foreach ($grupoevaluadorronda->getMessages() as $message) {
                            echo $message;
                        }

                        $this->db->rollback();
                        return "error";
                    }
                }


                // Commit the transaction
                $this->db->commit();

                return $grupoevaluador->id;
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

//Retorna información de id y nombre de las rondas de evaluación de la convocatoria
$app->get('/select_rondas_editar', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false) {

            //Si existe consulto el grupo
            if ($request->get('grupo') && $request->get('convocatoria')) {

                /*
                 * 22-04-2020
                 * WILMER GUSTAVO MOGOLLÓN DUQUE
                 * Se agrega un if para definir el código de la convocatoria o la categoría que se relaciona.
                 */


                if ($request->get('categoria')) {
                    $convocatoria = Convocatorias::findFirst($request->get('categoria'));
                } else {
                    $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));
                }



                /*
                 * 23-02-2021
                 * Wilmer Gustavo Mogollón Duque
                 * Se modifica la consulta para que muestre todas las rondas de evaluación
                 */
                $rondas = Convocatoriasrondas::query()
//                        ->where("Convocatoriasrondas.grupoevaluador IS NULL")
                        ->where("Convocatoriasrondas.active = true ")
                        ->andWhere("Convocatoriasrondas.convocatoria = " . $convocatoria->id)
                        ->orderBy("Convocatoriasrondas.numero_ronda")
                        ->execute();

                //rondas sin grupo
                if ($rondas) {

                    //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
                    //foreach (  $convocatoria->Convocatoriasrondas as $ronda) {
                    foreach ($rondas as $ronda) {
                        if ($ronda->grupoevaluador == $request->get('grupo')) {
                            array_push($response, ["id" => $ronda->id, "nombre" => $ronda->nombre_ronda, "grupo" => $ronda->grupoevaluador, "seleccionado" => true]);
                        } else {
                            array_push($response, ["id" => $ronda->id, "nombre" => $ronda->nombre_ronda, "grupo" => $ronda->grupoevaluador]);
                        }
                    }
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

//Retorna información de los jurados que aceptaron la notificacion y los que son evaluadores
$app->get('/jurados_aceptaron_and_evaluadores', function () use ($app) {
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


                //busca los que se postularon
                if ($request->get('convocatoria')) {


                    /*
                     * 23-04-2020
                     * WILMER GUSTAVO MOGOLLÓN DUQUE
                     * Se agrega un if para definir el código de la convocatoria o la categoría que se relaciona con los jurados.
                     */

                    if ($request->get('categoria')) {
                        $convocatoria = Convocatorias::findFirst($request->get('categoria'));
                    } else {
                        $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));
                    }


                    /*   $juradospostulados = Juradospostulados::query()
                      ->join("Juradosnotificaciones","Juradospostulados.id = Juradosnotificaciones.juradospostulado")
                      ->where("Juradosnotificaciones.active = true ")
                      ->andWhere("Juradosnotificaciones.estado = 15 ")//15	jurado_notificaciones	Aceptada
                      ->andWhere("Juradospostulados.convocatoria = ".$request->get('convocatoria') )
                      ->execute();
                     */

                    $result = $this->modelsManager->createBuilder()
                            //->columns(['Juradospostulados.*', 'Evaluadores.id as id_evaluador'])
                            //->columns(['Juradospostulados.*, Evaluadores.id as id_evaluador'])
                            ->columns(['Juradospostulados.*', 'Evaluadores.id as id_evaluador'])
                            ->from('Juradospostulados')
                            ->join("Juradosnotificaciones", "Juradospostulados.id = Juradosnotificaciones.juradospostulado")
                            ->leftJoin("Evaluadores", "Juradospostulados.id = Evaluadores.juradopostulado AND Evaluadores.grupoevaluador = " . $request->get('grupo'))
                            ->Where("Juradosnotificaciones.estado = 15 ")//15	jurado_notificaciones	Aceptada
                            ->andWhere("Juradospostulados.convocatoria = " . $convocatoria->id)//Se modifica para hacer la consulta con categorías tambien
                            ->orderBy('Juradospostulados.id')
                            ->getQuery()
                            ->execute();

                    //return json_encode( $result );

                    if ($result->count() > 0) {

                        foreach ($result as $row) {

                            //echo json_encode( $result );
                            // return json_encode($notificacion_activa->estado);
                            array_push($response, [
                                "postulado" => ( $row->juradospostulados->tipo_postulacion == 'Inscrita' ? true : false ),
                                "id" => $row->juradospostulados->propuestas->participantes->id,
                                "tipo_documento" => $row->juradospostulados->propuestas->participantes->tiposdocumentos->nombre,
                                "numero_documento" => $row->juradospostulados->propuestas->participantes->numero_documento,
                                "nombres" => $row->juradospostulados->propuestas->participantes->primer_nombre . " " . $juradopostulado->propuestas->participantes->segundo_nombre,
                                "apellidos" => $row->juradospostulados->propuestas->participantes->primer_apellido . " " . $juradopostulado->propuestas->participantes->segundo_apellido,
                                "id_postulacion" => $row->juradospostulados->id,
                                "puntaje" => $row->juradospostulados->total_evaluacion,
                                "aplica_perfil" => $row->juradospostulados->aplica_perfil,
                                "estado_postulacion" => $row->juradospostulados->estado,
                                "codigo_propuesta" => $row->juradospostulados->propuestas->codigo,
                                "rol" => $row->juradospostulados->rol,
                                "id_evaluador" => $row->id_evaluador
                            ]);
                        }
                    }


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
                    return "error";
                }
            } else {
                return "error";
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

//Retorna información sobre el grupo de evaluación
$app->get('/grupo/{id:[0-9]+}', function ($id) use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo
        if ($token_actual != false) {

            $grupoevaluador = Gruposevaluadores::findFirst($id);

            return json_encode($grupoevaluador);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
    }
}
);

//Actualiza el grupo de evaluación
$app->put('/grupo/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //return json_encode($request->getPut());
            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $grupoevaluador = Gruposevaluadores::findFirst($id);

                //18	grupos_evaluacion	Sin confirmar
                if ($grupoevaluador->estado == 18) {


                    // Start a transaction
                    $this->db->begin();

                    $grupoevaluador->fecha_actualizacion = date("Y-m-d H:i:s");
                    $grupoevaluador->actualizado_por = $user_current["id"];

                    if ($grupoevaluador->save() === false) {

                        //Para auditoria en versión de pruebas
                        foreach ($grupoevaluador->getMessages() as $message) {
                            echo $message;
                        }

                        $this->db->rollback();
                        return "error";
                    } else {

                        //evaluadores no seleccionados a eliminar
                        $evaluadores_eliminar = Evaluadores::find(
                                        [
                                            " juradopostulado NOT IN ({seleccionados:array})"
                                            . " AND grupoevaluador = " . $grupoevaluador->id,
                                            'bind' => [
                                                'seleccionados' => $request->getPut('seleccionados_editar')
                                            ]
                                        ]
                        );

                        //Elimiar los evaluadores no seleccionados
                        if ($evaluadores_eliminar->count() > 0) {

                            foreach ($evaluadores_eliminar as $evaluador) {

                                if ($evaluador->delete() === false) {

                                    foreach ($evaluador->getMessages() as $message) {
                                        echo $message;
                                    }

                                    $this->db->rollback();
                                    return "error";
                                }
                            }
                        }


                        //Crear los nuevos evaluadores
                        foreach ($request->getPut('seleccionados_editar') as $juradopostulado) {

                            $evaluador = Evaluadores::findFirst(
                                            [
                                                ' juradopostulado = ' . $juradopostulado
                                                . ' AND grupoevaluador = ' . $grupoevaluador->id
                                            ]
                            );

                            if ($evaluador === false) {
                                $evaluador = new Evaluadores();
                                $evaluador->grupoevaluador = $grupoevaluador->id;
                                $evaluador->juradopostulado = $juradopostulado;
                                $evaluador->fecha_creacion = date("Y-m-d H:i:s");
                                $evaluador->creado_por = $user_current["id"];
                                $evaluador->active = true;

                                if ($evaluador->save() === false) {

                                    //Para auditoria en versión de pruebas
                                    foreach ($evaluador->getMessages() as $message) {
                                        echo $message;
                                    }

                                    $this->db->rollback();
                                    return "error";
                                }
                            }
                        }//fin foreach
                        //se actualiza el grupo de evaluación de la ronda no seleccionada
                        $rondas = Convocatoriasrondas::find(
                                        [
                                            ' id NOT IN ({rondas:array})'
                                            . ' AND convocatoria = ' . $request->getPut('convocatoria'),
                                            'bind' => [
                                                'rondas' => $request->getPut('rondas_editar')
                                            ]
                                        ]
                        );


                        //se actualiza el grupo de evaluación de la ronda seelccionada
                        $rondas = Convocatoriasrondas::find(
                                        [
                                            ' id IN ({rondas:array})',
                                            'bind' => [
                                                'rondas' => $request->getPut('rondas_editar')
                                            ]
                                        ]
                        );

                        foreach ($rondas as $key => $ronda) {

                            $ronda->grupoevaluador = $grupoevaluador->id;

                            if ($ronda->save() === false) {

                                //Para auditoria en versión de pruebas
                                foreach ($ronda->getMessages() as $message) {
                                    echo $message;
                                }

                                $this->db->rollback();
                                return "error";
                            }
                        }//fin foreach
                    }


                    // Commit the transaction
                    $this->db->commit();
                    return $grupoevaluador->id;
                } else {
                    return "deshabilitado";
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

//Confirma el grupo de evaluación
$app->put('/confirmar/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //return json_encode($request->getPut());
            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                // Start a transaction
                $this->db->begin();

                $grupoevaluador = Gruposevaluadores::findFirst($id);

                //18	grupos_evaluacion	Sin confirmar
                if ($grupoevaluador->estado == 18) {

                    $grupoevaluador->estado = 19; //19	grupos_evaluacion	Confirmado
                    $grupoevaluador->fecha_actualizacion = date("Y-m-d H:i:s");
                    $grupoevaluador->actualizado_por = $user_current["id"];

                    if ($grupoevaluador->save() === false) {

                        //Para auditoria en versión de pruebas
                        foreach ($grupoevaluador->getMessages() as $message) {
                            echo $message;
                        }

                        $this->db->rollback();

                        return "error";
                    } else {

                        /**
                         * Cesar Britto, 25-04-2020
                         * Se agrega para establecer el estado Habiliatada de la ronda
                         * para proceder a evaluar las propuestas
                         */
                        //se actualiza el grupo de evaluación de la ronda
                        $rondas = Convocatoriasrondas::find(
                                        [
                                            ' grupoevaluador = ' . $grupoevaluador->id
                                        ]
                        );

                        //Se habiita la ronda para ser evaluada convocatorias_rondas	Habilitada
                        $estado = Estados::findFirst(
                                        [
                                            " tipo_estado = 'convocatorias_rondas' "
                                            . " AND nombre = 'Habilitada' "
                                        ]
                        );

                        foreach ($rondas as $key => $ronda) {

                            $ronda->estado = $estado->id;

                            if ($ronda->save() === false) {

                                //Para auditoria en versión de pruebas
                                foreach ($ronda->getMessages() as $message) {
                                    echo $message;
                                }

                                $this->db->rollback();

                                return "error";
                            }
                        }//fin foreach
                    }

                    // Commit the transaction
                    $this->db->commit();

                    return (String) $grupoevaluador->id;
                } else {
                    return "deshabilitado";
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
 * Retorna la información sobre el evaluador de la ronda
 */
$app->get('/evaluador/ronda/{id:[0-9]+}', function ($id) use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            $ronda = Convocatoriasrondas::findFirst('id = ' . $id);

            if (isset($ronda->id)) {


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

//            echo json_encode($postulacion);

                if (isset($postulacion->id)) {

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


                    echo json_encode($evaluador);
                    exit;
                    return json_encode($evaluador);
                } else {
                    return 'error';
                }
            } else {
                return 'error';
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
        //return "error_metodo";
    }
}
);

//Distribuye las propuestas deacuerdo con los grupos creados por ronda
$app->put('/habilitar_evaluaciones/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //return json_encode($request->getPut());
            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                /*
                 * 10-03-2021
                 * Wilmer Gustavo Mogollón Duque
                 * Se establecen los parametros para la creación de las evaluaciones de propuestas
                 */

                $convocatoria = Convocatorias::findFirst(
                                [
                                    ' id = ' . $id
                                ]
                );

                //Traemos la primera ronda para crear las evaluaciones 
                $ronda = Convocatoriasrondas::findFirst(
                                [
                                    ' convocatoria = ' . $convocatoria->id
                                    . ' AND numero_ronda = 1'
                                ]
                );

                if ($ronda) {

                    $gruposevaluadoresrondas = Gruposevaluadoresrondas::find(
                                    [
                                        ' convocatoriaronda = ' . $ronda->id
                                        . ' AND active = true'
                                    ]
                    );

                    if ($gruposevaluadoresrondas->count() > 0) {

                        $idgrupos = array();

                        foreach ($gruposevaluadoresrondas as $grupoevaluadorronda) {
                            //Se construye un array con la información de id de cada $gruposevaluadoresrondas
                            array_push($idgrupos, $grupoevaluadorronda->grupoevaluador);
                        }

                        //Estado de los Grupos de evaluación sin confirmar
                        $estado_grupo_sin_confirmar = Estados::findFirst(
                                        [
                                            " tipo_estado = 'grupos_evaluacion' "
                                            . " AND nombre = 'Sin confirmar' "
                                        ]
                        );

                        //Grupos de evaluación sin confirmar
                        $grupos_sin_confirmar = Gruposevaluadores::find(
                                        [
                                            ' active = true'
                                            . ' AND  estado = ' . $estado_grupo_sin_confirmar->id
                                            . ' AND id IN ({idgrupos:array}) ', //25-02-2021 pueden ser N grupos de evaluación
                                            'bind' => [
                                                'idgrupos' => $idgrupos
                                            ]
                                        ]
                        );

                        if ($grupos_sin_confirmar->count() == 0) {


                            //Grupos de evaluación confirmados
                            $grupos_confirmados = Gruposevaluadores::find(
                                            [
                                                ' active = true'
                                                . ' AND id IN ({idgrupos:array}) ', //25-02-2021 pueden ser N grupos de evaluación
                                                'bind' => [
                                                    'idgrupos' => $idgrupos
                                                ]
                                            ]
                            );


                            //para validar los registros de evaluacion_propuestas existentes
                            $propuestas_evaluacion = Evaluacionpropuestas::find(
                                            [
                                                "ronda =" . $ronda->id
                                                . " AND fase = 'Evaluación' "
                                            ]
                            );



                            $array_propuestas = array(0); //es para agregar el 0 al array para que no quede vacio

                            foreach ($propuestas_evaluacion as $key => $evaluacion) {
                                array_push($array_propuestas, $evaluacion->propuesta);
                            }


                            //24	propuestas	Habilitada
                            $estado_propuesta = Estados::findFirst("tipo_estado = 'propuestas' AND nombre = 'Habilitada'");
                            //propuestas a incluir por parte del evaluador

                            $propuestas_incluir = Propuestas::find(
                                            [
                                                " convocatoria = " . $ronda->convocatoria
                                                . ' AND estado = ' . $estado_propuesta->id
                                                . ' AND id NOT IN ({propuestas:array})',
                                                'bind' => [
                                                    'propuestas' => $array_propuestas
                                                ]
                                            ]
                            );

                            /*
                             * 05-04-2021
                             * Fredy Bejarano - Wilmer Mogollón
                             * Se cambia la forma de hacer el recorrido para distribuir las propuestas
                             */

                            $i = 0;

                            foreach ($propuestas_incluir as $propuesta) {

                                if ($i > $grupos_confirmados->count() - 1) {
                                    $i = 0;
                                }

                                //objeto que guarda los evaluadores que conforman los grupos de evaluación para ésta ronda
                                $evaluadores = Evaluadores::find(
                                                [
                                                    ' active = true '
                                                    . ' AND grupoevaluador = ' . $grupos_confirmados[$i]->id
                                                ]
                                );

                                foreach ($evaluadores as $evaluador) {

                                    $juradopostulado = Juradospostulados::findFirst(
                                                    [
                                                        ' id = ' . $evaluador->juradopostulado
                                                        . ' AND active = true '
                                                    ]
                                    );

                                    if (isset($juradopostulado)) {

                                        // Start a transaction
                                        $this->db->begin();

                                        $evaluacion_propuesta = new Evaluacionpropuestas();
                                        $evaluacion_propuesta->propuesta = $propuesta->id;
                                        $evaluacion_propuesta->ronda = $ronda->id;
                                        $evaluacion_propuesta->evaluador = $evaluador->id;
                                        $array_estado_actual = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Sin evaluar'");
                                        $evaluacion_propuesta->estado = $array_estado_actual->id;
                                        //ronda_estado = habilitada
                                        $evaluacion_propuesta->fase = 'Evaluación';
                                        $evaluacion_propuesta->fecha_creacion = date("Y-m-d H:i:s");
                                        $evaluacion_propuesta->creado_por = $user_current["id"];

                                        if ($juradopostulado->rol == 'Principal') {
                                            $evaluacion_propuesta->active = true;
                                        } else {
                                            $evaluacion_propuesta->active = false;
                                        }



                                        if ($evaluacion_propuesta->save() === false) {

//                                          //Para auditoria en versión de pruebas
                                            foreach ($evaluacion_propuesta->getMessages() as $message) {
                                                echo $message;
                                            }

                                            $this->db->rollback();
//
                                            return "error";
                                        }

                                        // Commit the transaction
                                        $this->db->commit();
                                    } else {

                                        return "error_postulacion";
                                    }
                                }

                                $i++;
                            }
                        } else {
                            return "error_grupos_confirmados";
                        }
                    } else {
                        return "error_grupos_evaluadores_rondas";
                    }
                } else {
                    return "error_rondas";
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
