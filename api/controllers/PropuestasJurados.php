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

//Busca el registro información básica
$app->get('/search', function () use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);


            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    /*
                     * Si el usuario que inicio sesion tiene perfil de jurado, asi mismo registro de  participante
                     * y  tiene asociada una convocatoria "idc"
                     */
                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil IN (17) ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    if ($participante->id == null) {

                        //busca la información del ultimo perfil creado con el perfil de jurado
                        //17	Jurados
                        $old_participante = Participantes::findFirst(
                                        [
                                            "usuario_perfil = " . $usuario_perfil->id,
                                            "order" => "id DESC" //trae el último
                                        ]
                        );

                        if ($old_participante) {

                            $new_participante = clone $old_participante;
                            $new_participante->id = null;
                            $new_participante->actualizado_por = null;
                            $new_participante->fecha_actualizacion = null;
                            $new_participante->creado_por = $user_current["id"];
                            $new_participante->fecha_creacion = date("Y-m-d H:i:s");
                            $new_participante->participante_padre = $old_participante->id;
                            $new_participante->tipo = "Participante";
                        } else {

                            $usuario_perfil_pn = Usuariosperfiles::findFirst(
                                            [
                                                " usuario = " . $user_current["id"] . " AND perfil = 6"
                                            ]
                            );

                            if ($usuario_perfil_pn->id != null) {
                                //busca la información del ultimo perfil creado con el perfil de jurado
                                //6	Persona Natural
                                $old_participante = Participantes::findFirst(
                                                [
                                                    "usuario_perfil = " . $usuario_perfil_pn->id,
                                                    "order" => "id DESC" //trae el último
                                                ]
                                );

                                if ($old_participante) {

                                    $new_participante = clone $old_participante;
                                    $new_participante->id = null;
                                    $new_participante->actualizado_por = null;
                                    $new_participante->fecha_actualizacion = null;
                                    $new_participante->creado_por = $user_current["id"];
                                    $new_participante->fecha_creacion = date("Y-m-d H:i:s");
                                    $new_participante->participante_padre = $old_participante->id;
                                    $new_participante->tipo = "Participante";
                                    $new_participante->usuario_perfil = $usuario_perfil->id;
                                }
                            }
                        }



                        //Consulto el total de propuesta con el fin de generar el codigo de la propuesta
                        $sql_total_propuestas = "SELECT
                                                         COUNT(p.id) as total_propuestas
                                                 FROM Propuestas AS p
                                                 WHERE
                                                 p.convocatoria=" . $request->get('idc');

                        $total_propuesta = $app->modelsManager->executeQuery($sql_total_propuestas)->getFirst();
                        $codigo_propuesta = $request->get('idc') . "-" . (str_pad($total_propuesta->total_propuestas + 1, 3, "0", STR_PAD_LEFT));


                        $propuesta = new Propuestas();
                        $propuesta->convocatoria = $request->get('idc');
                        $propuesta->creado_por = $user_current["id"];
                        $propuesta->fecha_creacion = date("Y-m-d H:i:s");
                        $propuesta->resumen = $old_participante->Propuestas->resumen;
                        $propuesta->nombre = $new_participante->primer_nombre . ' ' . $new_participante->segundo_nombre . ' ' . $new_participante->primer_apellido . ' ' . $new_participante->segundo_apellido;
                        $propuesta->codigo = $codigo_propuesta;
                        //Estado	9	jurados	Registrado
                        $propuesta->estado = 9;

                        //Educacionformal
                        $educacionformales = array();
                        foreach ($old_participante->Propuestas->Educacionformal as $key => $value) {
                            $value->id = null;
                            array_push($educacionformales, $value);
                        }
                        $propuesta->Educacionformal = $educacionformales;

                        //Educacionnoformal
                        $educacionnoformales = array();
                        foreach ($old_participante->Propuestas->Educacionnoformal as $key => $value) {
                            $value->id = null;
                            array_push($educacionnoformales, $value);
                        }
                        $propuesta->Educacionnoformal = $educacionnoformales;

                        //Experiencialaboral
                        $experiencialaborales = array();
                        foreach ($old_participante->Propuestas->Experiencialaboral as $key => $value) {
                            $value->id = null;
                            array_push($experiencialaborales, $value);
                        }
                        $propuesta->Experiencialaboral = $experiencialaborales;

                        //Experienciajurado
                        $experienciajurados = array();
                        foreach ($old_participante->Propuestas->Experienciajurado as $key => $value) {
                            $value->id = null;
                            array_push($experienciajurados, $value);
                        }
                        $propuesta->Experienciajurado = $experienciajurados;

                        //Propuestajuradoreconocimiento
                        $propuestajuradoreconocimientos = array();
                        foreach ($old_participante->Propuestas->Propuestajuradoreconocimiento as $key => $value) {
                            $value->id = null;
                            array_push($propuestajuradoreconocimientos, $value);
                        }
                        $propuesta->Propuestajuradoreconocimiento = $propuestajuradoreconocimientos;

                        //Propuestajuradopublicacion
                        $propuestajuradopublicaciones = array();
                        foreach ($old_participante->Propuestas->Propuestajuradopublicacion as $key => $value) {
                            $value->id = null;
                            array_push($propuestajuradopublicaciones, $value);
                        }
                        $propuesta->Propuestajuradopublicacion = $propuestajuradopublicaciones;

                        //Propuestajuradodocumento
                        $propuestajuradodocumentos = array();
                        foreach ($old_participante->Propuestas->Propuestajuradodocumento as $key => $value) {
                            $value->id = null;
                            array_push($propuestajuradodocumentos, $value);
                        }
                        $propuesta->Propuestajuradodocumento = $propuestajuradodocumentos;

                        $new_participante->propuestas = $propuesta;

                        if ($new_participante->save() === false) {

                            //echo "error";
                            //Para auditoria en versión de pruebas
                            foreach ($new_participante->getMessages() as $message) {
                                echo $message;
                            }
                        } else {

                            //Se crea la carpeta donde se guardaran los documentos de la propuesta (hoja de vida) del jurado
                            $filepath = "/Sites/convocatorias/" . $propuesta->convocatoria . "/propuestas/";
                            //echo "ruta-->>".$filepath.$new_participante->propuestas->id;
                            $return = $chemistry_alfresco->newFolder($filepath, $new_participante->propuestas->id);
                            //echo $return;
                            if (strpos($return, "Error") !== FALSE) {
                                echo "error_creo_alfresco";
                            }

                            //Asigno el nuevo participante al array
                            $array["participante"] = $new_participante;
                            $array["perfil"] = $new_participante->propuestas->resumen;
                            $participante = $new_participante;
                            $array["estado"] = Estados::findFirst(' id = ' . $participante->propuestas->estado)->nombre;
                        }
                    } else {
                        //echo $participante->propuestas->resumen;
                        //Asigno el participante al array
                        $array["participante"] = $participante;
                        //array_push($array["participante"], ["resumen" => $participante->propuestas->resumen] );
                        $array["perfil"] = $participante->propuestas->resumen;

                        $array["estado"] = Estados::findFirst(' id = ' . $participante->propuestas->estado)->nombre;
                    }
                    //Creo los array de los select del formulario
                    $array["categoria"] = $participante->propuestas->modalidad_participa;
                    $array["ciudad_residencia_name"] = $participante->Ciudadesresidencia->nombre;
                    $array["ciudad_nacimiento_name"] = $participante->Ciudadesnacimiento->nombre;
                    $array["barrio_residencia_name"] = $participante->Barriosresidencia->nombre;

                    //Retorno el array
                    return json_encode($array);
                } else {

                    return json_encode(new Participantes());
                }
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

// Edito registro participante
$app->post('/edit_participante', function () use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a editar el participante"',
                ['user' => '',
                    'token' => $request->get('token')]
        );

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

                $post = $app->request->getPost();

                //(return $request->get('idp');
                $participante = Participantes::findFirst($request->get('idp'));

                if ($participante->tipo == 'Participante') {

                    //valido si existe una propuesta del participante y convocatoria con el estado 9 (registrada)
                    //valido si la propuesta tiene el estado registrada


                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */

//                  if( $participante->propuestas  != null and $participante->propuestas->estado == 9 ){
                    if ($participante->propuestas != null) {

                        $participante->propuestas->modalidad_participa = $request->get('modalidad_participa');
                        //return json_encode($post);
                        $participante->actualizado_por = $user_current["id"];
                        $participante->fecha_actualizacion = date("Y-m-d H:i:s");
                        //$participante->propuestas = $propuesta;
                        $participante->propuestas->resumen = $request->getPost('resumen');

                        if ($participante->save($post) === false) {

                            //Para auditoria en versión de pruebas
                            /* foreach ($participante->getMessages() as $message) {
                              echo $message;
                              }
                             */

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar el participante como jurado"',
                                    ['user' => "", 'token' => $request->get('token')]
                            );
                            $logger->close();

                            echo "error";
                        }
                        echo $participante->id;
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado para modificar el participante como jurado"',
                                ['user' => "", 'token' => $request->get('token')]
                        );
                        $logger->close();

                        echo "deshabilitado";
                    }
                }
            } else {

                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();

                echo "acceso_denegado";
            }
        } else {

            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();

            echo "error_token";
        }
    } catch (Exception $ex) {

        //Para auditoria en versión de pruebas
        //echo "error_metodo" . $ex->getMessage();

        $logger->error('"token":"{token}","user":"{user}","message":"Error método' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();

        echo "error_metodo";
    }
}
);

// Funcionalidad CRUD Educacion formal
//Busca el registro educación formal
$app->get('/search_educacion_formal', function () use ($app, $config) {
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



            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    //cargar los datos del registro
                    // echo "-->>>>".$request->get('idregistro');

                    if ($request->get('idregistro')) {

                        $educacionformal = Educacionformal::findFirst($request->get('idregistro'));
                    }

                    $array["usuario_perfil"] = $usuario_perfil->id;

                    $array["ciudad_name"] = $educacionformal->Ciudad->nombre;

                    $array["nivel_educacion"] = Niveleseducativos::find("active=true");

                    //Select Área de conocimineto*
                    //$array["area_conocimiento"] = Areasconocimientos::find("active=true");
                    $tipos = Categoriajurado::find("active=true AND tipo='formal' and id=nodo");
                    $array["area_conocimiento"] = array();
                    foreach ($tipos as $tipo) {
                        array_push($array["area_conocimiento"], ["id" => $tipo->id, "nombre" => $tipo->nombre]);
                    }

                    $array["educacionformal"] = $educacionformal;

                    //Retorno el array
                    return json_encode($array);
                } else {
                    return json_encode(array());
                }
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        echo "error_metodo";

        //Para auditoria en versión de pruebas
        //echo "error_metodo" . $ex->getMessage();
    }
}
);

//Busca los registros de educacion formal
$app->get('/all_educacion_formal', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    //  echo json_encode($participante->propuestas);

                    $educacionformales = Educacionformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    // echo json_encode($educacionformales);

                    foreach ($educacionformales as $educacionformal) {

                        // $ciudad =  Ciudades::findFirst( ["id=".$educacionformal->ciudad]  );

                        /* Ajuste de william supervisado por wilmer */
                        /* 2020-04-28 */
                        $array_ciudad_1 = Ciudades::findFirst(["id=" . $educacionformal->ciudad]);


                        $educacionformal->ciudad = $array_ciudad_1->nombre;

                        /* Ajuste de william supervisado por wilmer */
                        /* 2020-04-28 */
                        $array_educacion_formal_1 = Niveleseducativos::findFirst("id = " . $educacionformal->nivel_educacion);

                        $educacionformal->nivel_educacion = $array_educacion_formal_1->nombre;
                        $educacionformal->creado_por = null;
                        $educacionformal->actualizado_por = null;
                        array_push($response, $educacionformal);
                    }

                    //resultado sin filtro
                    $teducacionformal = Educacionformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%' )",
                                    ]
                    );
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($teducacionformal->count()),
                "recordsFiltered" => intval($teducacionformal->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

//Busca los registros de educacion formal
$app->get('/all_educacion_formal/active', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    //  echo json_encode($participante->propuestas);

                    $educacionformales = Educacionformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND active= true ",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    // echo json_encode($educacionformales);

                    foreach ($educacionformales as $educacionformal) {

                        // $ciudad =  Ciudades::findFirst( ["id=".$educacionformal->ciudad]  );

                        /* Ajuste de william supervisado por wilmer */
                        /* 2020-04-28 */
                        $array_ciudad_1 = Ciudades::findFirst(["id=" . $educacionformal->ciudad]);
                        $educacionformal->ciudad = $array_ciudad_1->nombre;

                        $educacionformal->nivel_educacion = $array_ciudad_1->nombre;
                        $educacionformal->creado_por = null;
                        $educacionformal->actualizado_por = null;
                        array_push($response, $educacionformal);
                    }

                    //resultado sin filtro
                    $teducacionformal = Educacionformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND active= true"
                                        . " AND ( titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%' )",
                                    ]
                    );
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($teducacionformal->count()),
                "recordsFiltered" => intval($teducacionformal->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);
/* Retorna información de id y nombre del nucleobasico associado  al area_conocimiento */
$app->get('/select_nucleobasico', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $categorias = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Si existe consulto la convocatoria
            if ($request->get('id')) {

                $rs = Nucleosbasicos::find(
                                [
                                    "area_conocimiento = " . $request->get('id')
                                ]
                );

                //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
                //foreach ( $rs as $key => $value) {
                //      $nucleosbasicos[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                //}

                $tipos = Categoriajurado::find("active=true AND tipo='formal' AND nodo=" . $request->get('id'));
                $nucleosbasicos = array();
                foreach ($tipos as $tipo) {
                    array_push($nucleosbasicos, ["id" => $tipo->id, "nombre" => $tipo->nombre]);
                }
            }

            echo json_encode($nucleosbasicos);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
    }
}
);

// Crea el registro de educacion formal
$app->post('/new_educacion_formal', function () use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a crear educación formal."',
                ['user' => '',
                    'token' => $request->get('token')]
        );

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17 "
                                ]
                );

                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $educacionformal = new Educacionformal();
                        $educacionformal->creado_por = $user_current["id"];
                        $educacionformal->fecha_creacion = date("Y-m-d H:i:s");
                        $educacionformal->active = true;
                        //al asignarle un objeto genera error, por tal motivo se envia solo el id
                        $educacionformal->propuesta = $participante->propuestas->id;

                        $post["id"] = null;

                        //echo "educacionformal---->>".json_encode($educacionformal);
                        //  echo "post---->>".json_encode($post);
                        if ($educacionformal->save($post) === false) {

                            //Para auditoria en versión de pruebas
                            /* foreach ($educacionformal->getMessages() as $message) {
                              echo $message;
                              } */

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear educación formal. ' . json_decode($educacionformal->getMessages()) . '"',
                                    ['user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();

                            echo "error";
                        } else {

                            //echo "guardando archivo";
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {

                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);
                                /*
                                  UPLOAD_ERR_OK
                                  Valor: 0; No hay error, fichero subido con éxito.

                                  UPLOAD_ERR_INI_SIZE
                                  Valor: 1; El fichero subido excede la directiva upload_max_filesize de php.ini.

                                  UPLOAD_ERR_FORM_SIZE
                                  Valor: 2; El fichero subido excede la directiva MAX_FILE_SIZE especificada en el formulario HTML.

                                  UPLOAD_ERR_PARTIAL
                                  Valor: 3; El fichero fue sólo parcialmente subido.

                                  UPLOAD_ERR_NO_FILE
                                  Valor: 4; No se subió ningún fichero.

                                  UPLOAD_ERR_NO_TMP_DIR
                                  Valor: 6; Falta la carpeta temporal. Introducido en PHP 5.0.3.

                                  UPLOAD_ERR_CANT_WRITE
                                  Valor: 7; No se pudo escribir el fichero en el disco. Introducido en PHP 5.1.0.

                                  UPLOAD_ERR_EXTENSION
                                  Valor: 8; Una extensión de PHP detuvo la subida de ficheros. PHP no proporciona una forma de determinar la extensión que causó la parada de la subida de ficheros; el examen de la lista de extensiones cargadas con phpinfo() puede ayudar. Introducido en PHP 5.2.0.
                                 */
                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]educacionformal[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)ef(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $educacionformal->propuesta . "ef" . $educacionformal->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $educacionformal->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);

                                    //echo "archivo".$fileName;
                                    //echo "path".$filepath;
                                    if (strpos($return, "Error") !== FALSE) {
                                        //echo "    ".json_encode($return);
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear educación formal. Error alfresco ' . json_encode($return) . '"',
                                                ['user' => $user_current, 'token' => $request->get('token')]
                                        );
                                        $logger->close();
                                        echo "error_creo_alfresco";
                                    } else {

                                        $educacionformal->file = $return;
                                        if ($educacionformal->save() === false) {

                                            //Para auditoria en versión de pruebas
                                            /*  foreach ($educacionformal->getMessages() as $message) {
                                              echo $message;
                                              } */

                                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear educación formal. ' . json_decode($educacionformal->getMessages()) . '"',
                                                    ['user' => $user_current, 'token' => $request->get('token')]
                                            );
                                            $logger->close();

                                            echo "error";
                                        }
                                    }
                                } else {
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear educación formal. UPLOAD_ERROR ' . $valor['error'] . '"',
                                            ['user' => $user_current, 'token' => $request->get('token')]
                                    );
                                    $logger->close();
                                    // echo "error_archivo
                                    //echo "error".$valor['error'];
                                }
                            }

                            return (String) $educacionformal->id;
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado"',
                                ['user' => "", 'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {

                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();
                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();

            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        //  return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();

        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();

        return "error_metodo";
    }
}
);

// Edita el registro de educacion formal
$app->post('/edit_educacion_formal/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {

    try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a editar educación formal"',
                ['user' => '',
                    'token' => $request->get('token')]
        );

        //echo "put-->".json_encode($request->getPost());
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //echo "token--->".json_encode($token_actual);
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $educacionformal = Educacionformal::findFirst($id);
                        $educacionformal->actualizado_por = $user_current["id"];
                        $educacionformal->fecha_actualizacion = date("Y-m-d H:i:s");
                        $educacionformal->nivel_educacion = $request->getPost('nivel_educacion');
                        $educacionformal->titulo = $request->getPost('titulo');
                        $educacionformal->area_conocimiento = $request->getPost('area_conocimiento');
                        $educacionformal->nucleo_basico = $request->getPost('nucleo_basico');
                        $educacionformal->institucion = $request->getPost('institucion');
                        $educacionformal->ciudad = $request->getPost('ciudad');
                        $educacionformal->fecha_graduacion = $request->getPost('fecha_graduacion');
                        $educacionformal->graduado = $request->getPost('graduado');


                        if ($educacionformal->save() === false) {

                            //Para auditoria en versión de pruebas
                            /* foreach ($educacionformal->getMessages() as $message) {
                              echo $message;
                              } */

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar educación formal. ' . json_decode($educacionformal->getMessages()) . '"',
                                    ['user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();

                            return "error";
                        } else {

                            //echo "file-->".json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));

                                /*
                                  UPLOAD_ERR_OK
                                  Valor: 0; No hay error, fichero subido con éxito.

                                  UPLOAD_ERR_INI_SIZE
                                  Valor: 1; El fichero subido excede la directiva upload_max_filesize de php.ini.

                                  UPLOAD_ERR_FORM_SIZE
                                  Valor: 2; El fichero subido excede la directiva MAX_FILE_SIZE especificada en el formulario HTML.

                                  UPLOAD_ERR_PARTIAL
                                  Valor: 3; El fichero fue sólo parcialmente subido.

                                  UPLOAD_ERR_NO_FILE
                                  Valor: 4; No se subió ningún fichero.

                                  UPLOAD_ERR_NO_TMP_DIR
                                  Valor: 6; Falta la carpeta temporal. Introducido en PHP 5.0.3.

                                  UPLOAD_ERR_CANT_WRITE
                                  Valor: 7; No se pudo escribir el fichero en el disco. Introducido en PHP 5.1.0.

                                  UPLOAD_ERR_EXTENSION
                                  Valor: 8; Una extensión de PHP detuvo la subida de ficheros. PHP no proporciona una forma de determinar la extensión que causó la parada de la subida de ficheros; el examen de la lista de extensiones cargadas con phpinfo() puede ayudar. Introducido en PHP 5.2.0.
                                 */
                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]educacionformal[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)ef(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $educacionformal->propuesta . "ef" . $educacionformal->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $educacionformal->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);

                                    if (strpos($return, "Error") !== FALSE) {
                                        //echo "    ".json_encode($return);
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar educación formal. Error alfresco ' . json_encode($return) . '"',
                                                ['user' => $user_current, 'token' => $request->get('token')]
                                        );
                                        $logger->close();
                                        return "error_creo_alfresco";
                                    } else {

                                        $educacionformal->file = $return;
                                        if ($educacionformal->save() === false) {

                                            //Para auditoria en versión de pruebas
                                            /*  foreach ($educacionformal->getMessages() as $message) {
                                              echo $message;
                                              }
                                             */

                                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar educación formal. ' . json_decode($educacionformal->getMessages()) . '"',
                                                    ['user' => $user_current, 'token' => $request->get('token')]
                                            );
                                            $logger->close();

                                            return "error";
                                        }
                                    }
                                } else {
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar educación formal. UPLOAD_ERROR ' . $valor['error'] . '"',
                                            ['user' => $user_current, 'token' => $request->get('token')]
                                    );
                                    $logger->close();
                                    // echo "error_archivo ".$valor['error'];
                                }
                            }

                            return (String) $educacionformal->id;
                        }//fin else
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado"',
                                ['user' => "", 'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();

                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();

            return "error_token";
        }
    } catch (Exception $ex) {
        //  echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        //return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();

        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();

        return "error_metodo";
    }
}
);

//Desactiva el registro de educacion formal
$app->delete('/delete_educacion_formal/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto el usuario actual
                $post = $app->request->getPut();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPut('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
                    if ($participante->propuestas != null) {
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {

                        $educacionformal = Educacionformal::findFirst($id);

                        if ($educacionformal->active == true) {
                            $educacionformal->active = false;
                            $retorna = "No";
                        } else {
                            $educacionformal->active = true;
                            $retorna = "Si";
                        }

                        $educacionformal->actualizado_por = $user_current["id"];
                        $educacionformal->fecha_actualizacion = date("Y-m-d H:i:s");

                        if ($educacionformal->save($post) === false) {
                            //Para auditoria en versión de pruebas
                            foreach ($educacionformal->getMessages() as $message) {
                                echo $message;
                            }
                        } else {
                            return $retorna;
                        }
                    } else {
                        echo "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
    }
});

// Funcionalidad CRUD Educacion no formal
//Busca el registro Educacion no formal
$app->get('/search_educacion_no_formal', function () use ($app, $config) {
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



            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    //cargar los datos del registro
                    // echo "-->>>>".$request->get('idregistro');

                    if ($request->get('idregistro')) {

                        $educacionnoformal = Educacionnoformal::findFirst($request->get('idregistro'));
                    }


                    $array["usuario_perfil"] = $usuario_perfil->id;


                    $array["ciudad_name"] = $educacionnoformal->Ciudad->nombre;

                    $tipos = Tablasmaestras::findFirst("active=true AND nombre='tipo_educacion_no_formal'");
                    $array["tipo"] = array();
                    foreach (explode(",", $tipos->valor) as $nombre) {
                        array_push($array["tipo"], ["id" => $nombre, "nombre" => $nombre]);
                    }

                    $modalidad = Tablasmaestras::findFirst("active=true AND nombre='modalidad'");
                    $array["modalidad"] = array();
                    foreach (explode(",", $modalidad->valor) as $nombre) {
                        array_push($array["modalidad"], ["id" => $nombre, "nombre" => $nombre]);
                    }

                    $array["educacionnoformal"] = $educacionnoformal;

                    //Retorno el array
                    return json_encode($array);
                } else {
                    return json_encode(array());
                }
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        echo "error_metodo";

        //Para auditoria en versión de pruebas
        //echo "error_metodo" . $ex->getMessage();
    }
}
);

//Busca los registros de Educacion no formal
$app->get('/all_educacion_no_formal', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    //  echo json_encode($participante->propuestas);

                    $educacionnoformales = Educacionnoformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($educacionnoformales as $educacionnoformal) {

                        $ciudad = Ciudades::findFirst(
                                        ["active=true AND id=" . $educacionnoformal->ciudad]
                        );

                        $educacionnoformal->ciudad = $ciudad->nombre;
                        $educacionnoformal->creado_por = null;
                        $educacionnoformal->actualizado_por = null;
                        array_push($response, $educacionnoformal);
                    }

                    //resultado sin filtro
                    $teducacionnoformal = Educacionnoformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%' )"
                                    ]
                    );
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($teducacionnoformal->count()),
                "recordsFiltered" => intval($teducacionnoformal->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

//Busca los registros de Educacion no formal
$app->get('/all_educacion_no_formal/active', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    //  echo json_encode($participante->propuestas);

                    $educacionnoformales = Educacionnoformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND active = true",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($educacionnoformales as $educacionnoformal) {

                        $ciudad = Ciudades::findFirst(
                                        ["active=true AND id=" . $educacionnoformal->ciudad]
                        );

                        $educacionnoformal->ciudad = $ciudad->nombre;
                        $educacionnoformal->creado_por = null;
                        $educacionnoformal->actualizado_por = null;
                        array_push($response, $educacionnoformal);
                    }

                    //resultado sin filtro
                    $teducacionnoformal = Educacionnoformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND active = true"
                                    ]
                    );
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($teducacionnoformal->count()),
                "recordsFiltered" => intval($teducacionnoformal->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);


// Crea el registro de Educacion no formal
$app->post('/new_educacion_no_formal', function () use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a editar educación no formal"',
                ['user' => '',
                    'token' => $request->get('token')]
        );

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $educacionnoformal = new Educacionnoformal();
                        $educacionnoformal->creado_por = $user_current["id"];
                        $educacionnoformal->fecha_creacion = date("Y-m-d H:i:s");
                        $educacionnoformal->active = true;
                        //al asignarle un objeto genera error, por tal motivo se envia solo el id
                        $educacionnoformal->propuesta = $participante->propuestas->id;

                        $post["id"] = null;

                        //  echo "educacionnoformal---->>".json_encode($educacionnoformal);
                        //  echo "post---->>".json_encode($post);
                        if ($educacionnoformal->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            /* foreach ($educacionnoformal->getMessages() as $message) {
                              echo $message;
                              } */

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear educación no formal. ' . json_decode($educacionnoformal->getMessages()) . '"',
                                    ['user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();
                            echo "error";
                        } else {

                            echo "guardando archivo";
                            echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);
                                /*
                                  UPLOAD_ERR_OK
                                  Valor: 0; No hay error, fichero subido con éxito.

                                  UPLOAD_ERR_INI_SIZE
                                  Valor: 1; El fichero subido excede la directiva upload_max_filesize de php.ini.

                                  UPLOAD_ERR_FORM_SIZE
                                  Valor: 2; El fichero subido excede la directiva MAX_FILE_SIZE especificada en el formulario HTML.

                                  UPLOAD_ERR_PARTIAL
                                  Valor: 3; El fichero fue sólo parcialmente subido.

                                  UPLOAD_ERR_NO_FILE
                                  Valor: 4; No se subió ningún fichero.

                                  UPLOAD_ERR_NO_TMP_DIR
                                  Valor: 6; Falta la carpeta temporal. Introducido en PHP 5.0.3.

                                  UPLOAD_ERR_CANT_WRITE
                                  Valor: 7; No se pudo escribir el fichero en el disco. Introducido en PHP 5.1.0.

                                  UPLOAD_ERR_EXTENSION
                                  Valor: 8; Una extensión de PHP detuvo la subida de ficheros. PHP no proporciona una forma de determinar la extensión que causó la parada de la subida de ficheros; el examen de la lista de extensiones cargadas con phpinfo() puede ayudar. Introducido en PHP 5.2.0.
                                 */
                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]educacionformal[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)ef(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $educacionnoformal->propuesta . "enf" . $educacionnoformal->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $educacionnoformal->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear educación no formal. Error alfresco ' . json_encode($return) . '"',
                                                ['user' => $user_current, 'token' => $request->get('token')]
                                        );
                                        $logger->close();
                                        echo "error_creo_alfresco";
                                    } else {

                                        $educacionnoformal->file = $return;
                                        if ($educacionnoformal->save() === false) {

                                            //Para auditoria en versión de pruebas
                                            /*  foreach ($educacionnoformal->getMessages() as $message) {
                                              echo $message;
                                              } */

                                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear educación no formal. ' . json_decode($educacionnoformal->getMessages()) . '"',
                                                    ['user' => $user_current, 'token' => $request->get('token')]
                                            );
                                            $logger->close();

                                            echo "error";
                                        }
                                    }
                                } else {

                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear educación no formal. UPLOAD_ERROR ' . $valor['error'] . '"',
                                            ['user' => $user_current, 'token' => $request->get('token')]
                                    );
                                    $logger->close();

                                    //echo "error".$valor['error'];
                                }
                            }

                            return $educacionnoformal->id;
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado"',
                                ['user' => "", 'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {

                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();

                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        //return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();

        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();

        echo "error_metodo";
    }
}
);

// Edita el registro de Educacion no formal
$app->post('/edit_educacion_no_formal/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {

    try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a editar educación no formal"',
                ['user' => '',
                    'token' => $request->get('token')]
        );


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $educacionnoformal = Educacionnoformal::findFirst($id);
                        $educacionnoformal->actualizado_por = $user_current["id"];
                        $educacionnoformal->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                        //echo "post---->>".json_encode($post);
                        if ($educacionnoformal->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            /* foreach ($educacionnoformal->getMessages() as $message) {
                              echo $message;
                              } */

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar educación no formal. ' . json_decode($educacionnoformal->getMessages()) . '"',
                                    ['user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();

                            echo "error";
                        } else {

                            echo "file-->" . json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]educacionnoformal[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)enf(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $educacionnoformal->propuesta . "enf" . $educacionnoformal->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $educacionnoformal->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);

                                    if (strpos($return, "Error") !== FALSE) {
                                        //echo "    ".json_encode($return);
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar educación no formal. Error alfresco ' . json_encode($return) . '"',
                                                ['user' => $user_current, 'token' => $request->get('token')]
                                        );
                                        $logger->close();

                                        echo "error_creo_alfresco";
                                    } else {

                                        $educacionnoformal->file = $return;
                                        if ($educacionnoformal->save() === false) {

                                            //Para auditoria en versión de pruebas
                                            /* foreach ($educacionnoformal->getMessages() as $message) {
                                              echo $message;
                                              } */

                                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar educación no formal. ' . json_decode($educacionnoformal->getMessages()) . '"',
                                                    ['user' => $user_current, 'token' => $request->get('token')]
                                            );
                                            $logger->close();

                                            echo "error";
                                        }
                                    }
                                } else {
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar educación no formal. UPLOAD_ERROR ' . $valor['error'] . '"',
                                            ['user' => $user_current, 'token' => $request->get('token')]
                                    );
                                    $logger->close();
                                    //echo "error".$valor['error'];
                                }
                            }

                            return $educacionnoformal->id;
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado"',
                                ['user' => "", 'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();

                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        //  return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();

        echo "error_metodo";
    }
}
);

// Eliminar registro de Educacion no formal
$app->delete('/delete_educacion_no_formal/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto el usuario actual
                $post = $app->request->getPut();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPut('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $educacionnoformal = Educacionnoformal::findFirst($id);

                        if ($educacionnoformal->active == true) {
                            $educacionnoformal->active = false;
                            $retorna = "No";
                        } else {
                            $educacionnoformal->active = true;
                            $retorna = "Si";
                        }

                        $educacionnoformal->actualizado_por = $user_current["id"];
                        $educacionnoformal->fecha_actualizacion = date("Y-m-d H:i:s");

                        if ($educacionnoformal->save($post) === false) {
                            //Para auditoria en versión de pruebas
                            foreach ($educacionnoformal->getMessages() as $message) {
                                echo $message;
                            }
                        } else {
                            return $retorna;
                        }
                    } else {
                        echo "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
    }
});

// Funcionalidad CRUD Experiencia_laboral
//Busca el registro experiencia_laboral
$app->get('/search_experiencia_laboral', function () use ($app, $config) {
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



            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    //cargar los datos del registro
                    // echo "-->>>>".$request->get('idregistro');

                    if ($request->get('idregistro')) {

                        $experiencialaboral = Experiencialaboral::findFirst($request->get('idregistro'));
                    }


                    $array["usuario_perfil"] = $usuario_perfil->id;


                    $array["ciudad_name"] = $experiencialaboral->Ciudad->nombre;


                    $tipos = Tablasmaestras::findFirst("active=true AND nombre='tipo_entidad'");
                    $array["tipo_entidad"] = array();
                    foreach (explode(",", $tipos->valor) as $nombre) {
                        array_push($array["tipo_entidad"], ["id" => $nombre, "nombre" => $nombre]);
                    }

                    $lineas = Lineasestrategicas::find("active=true");
                    $array["linea"] = array();
                    foreach ($lineas as $linea) {
                        array_push($array["linea"], ["id" => $linea->id, "nombre" => $linea->nombre]);
                    }

                    $array["experiencialaboral"] = $experiencialaboral;

                    //Retorno el array
                    return json_encode($array);
                } else {
                    return json_encode(array());
                }
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        echo "error_metodo";

        //Para auditoria en versión de pruebas
        //echo "error_metodo" . $ex->getMessage();
    }
}
);

//Busca los registros de experiencia_laboral
$app->get('/all_experiencia_laboral', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {



                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();


                    $experiencialaborales = Experiencialaboral::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND ( entidad LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR cargo LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($experiencialaborales as $experiencialaboral) {

                        $ciudad = Ciudades::findFirst(
                                        ["id=" . $experiencialaboral->ciudad]
                        );
                        $experiencialaboral->ciudad = $ciudad->nombre;

                        $linea = Lineasestrategicas::findFirst(
                                        ["id = " . $experiencialaboral->linea]
                        );
                        $experiencialaboral->linea = $linea->nombre;

                        $experiencialaboral->creado_por = null;
                        $experiencialaboral->actualizado_por = null;
                        array_push($response, $experiencialaboral);
                    }

                    //resultado sin filtro
                    $texperiencialaboral = Experiencialaboral::find([
                                " propuesta= " . $participante->propuestas->id
                                . " AND ( entidad LIKE '%" . $request->get("search")['value'] . "%'"
                                . " OR cargo LIKE '%" . $request->get("search")['value'] . "%')"
                    ]);
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($texperiencialaboral->count()),
                "recordsFiltered" => intval($texperiencialaboral->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
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

//Busca los registros de experiencia_laboral
$app->get('/all_experiencia_laboral/active', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    $experiencialaborales = Experiencialaboral::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND active = true",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($experiencialaborales as $experiencialaboral) {

                        $ciudad = Ciudades::findFirst(
                                        ["id=" . $experiencialaboral->ciudad]
                        );
                        $experiencialaboral->ciudad = $ciudad->nombre;

                        $linea = Lineasestrategicas::findFirst(
                                        ["id = " . $experiencialaboral->linea]
                        );
                        $experiencialaboral->linea = $linea->nombre;

                        $experiencialaboral->creado_por = null;
                        $experiencialaboral->actualizado_por = null;
                        array_push($response, $experiencialaboral);
                    }

                    //resultado sin filtro
                    $texperiencialaboral = Experiencialaboral::find([
                                " propuesta= " . $participante->propuestas->id
                                . " AND active = true",
                    ]);
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($texperiencialaboral->count()),
                "recordsFiltered" => intval($texperiencialaboral->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
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

// Crea el registro de experiencia_laboral
$app->post('/new_experiencia_laboral', function () use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a crear experiencia laboral"',
                ['user' => '',
                    'token' => $request->get('token')]
        );


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $experiencialaboral = new Experiencialaboral();
                        $experiencialaboral->creado_por = $user_current["id"];
                        $experiencialaboral->fecha_creacion = date("Y-m-d H:i:s");
                        $experiencialaboral->active = true;
                        //al asignarle un objeto genera error, por tal motivo se envia solo el id
                        $experiencialaboral->propuesta = $participante->propuestas->id;
                        $experiencialaboral->usuario_perfil = $participante->usuario_perfil;

                        $post["id"] = null;

                        //  echo "educacionnoformal---->>".json_encode($educacionnoformal);
                        //  echo "post---->>".json_encode($post);
                        if ($experiencialaboral->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            /* foreach ($experiencialaboral->getMessages() as $message) {
                              echo $message;
                              }
                             */

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear experiencia laboral. ' . json_decode($experiencialaboral->getMessages()) . '"',
                                    ['user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();

                            echo "error";
                        } else {

                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]experiencialaboral[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)el(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $experiencialaboral->propuesta . "el" . $experiencialaboral->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $experiencialaboral->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear experiencia laboral. Error alfresco ' . json_encode($return) . '"',
                                                ['user' => $user_current, 'token' => $request->get('token')]
                                        );
                                        $logger->close();
                                        echo "error_creo_alfresco";
                                    } else {

                                        $experiencialaboral->file = $return;
                                        if ($experiencialaboral->save() === false) {

                                            //Para auditoria en versión de pruebas
                                            /*  foreach ($experiencialaboral->getMessages() as $message) {
                                              echo $message;
                                              } */

                                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear experiencia laboral. ' . json_decode($experiencialaboral->getMessages()) . '"',
                                                    ['user' => $user_current, 'token' => $request->get('token')]
                                            );
                                            $logger->close();

                                            echo "error";
                                        }
                                    }
                                } else {
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear experiencia laboral. UPLOAD_ERROR ' . $valor['error'] . '"',
                                            ['user' => $user_current, 'token' => $request->get('token')]
                                    );
                                    $logger->close();
                                    //echo "error".$valor['error'];
                                }
                            }

                            echo $experiencialaboral->id;
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado"',
                                ['user' => "", 'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();

                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        //  echo "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();
        echo "error_metodo ";
    }
}
);

// Edita el registro de experiencia_laboral
$app->post('/edit_experiencia_laboral/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {

    try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a editar experiencia laboral."',
                ['user' => '',
                    'token' => $request->get('token')]
        );


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $experiencialaboral = Experiencialaboral::findFirst($id);
                        $experiencialaboral->actualizado_por = $user_current["id"];
                        $experiencialaboral->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                        //echo "post---->>".json_encode($post);
                        if ($experiencialaboral->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            /* foreach ($experiencialaboral->getMessages() as $message) {
                              echo $message;
                              } */

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar experiencia laboral. ' . json_decode($experiencialaboral->getMessages()) . '"',
                                    ['user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();

                            echo "error";
                        } else {

                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]experiencialaboral[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)el(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $experiencialaboral->propuesta . "el" . $experiencialaboral->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $experiencialaboral->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar experiencia laboral. Error alfresco ' . json_encode($return) . '"',
                                                ['user' => $user_current, 'token' => $request->get('token')]
                                        );
                                        $logger->close();
                                        echo "error_creo_alfresco";
                                    } else {

                                        $experiencialaboral->file = $return;
                                        if ($experiencialaboral->save() === false) {
                                            echo "error";
                                            //Para auditoria en versión de pruebas
                                            /* foreach ($experiencialaboral->getMessages() as $message) {
                                              echo $message;
                                              } */

                                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar experiencia laboral. ' . json_decode($experiencialaboral->getMessages()) . '"',
                                                    ['user' => $user_current, 'token' => $request->get('token')]
                                            );
                                            $logger->close();

                                            echo "error";
                                        }
                                    }
                                } else {
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar experiencia laboral. UPLOAD_ERROR ' . $valor['error'] . '"',
                                            ['user' => $user_current, 'token' => $request->get('token')]
                                    );
                                    $logger->close();
                                    //echo "error".$valor['error'];
                                }
                            }

                            return (String) $experiencialaboral->id;
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado"',
                                ['user' => "", 'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();

                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();

            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        //return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();

        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();

        return "error_metodo";
    }
}
);

// Eliminar registro de experiencia_laboral
$app->delete('/delete_experiencia_laboral/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto el usuario actual
                $post = $app->request->getPut();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {
                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPut('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $experiencialaboral = Experiencialaboral::findFirst($id);

                        if ($experiencialaboral->active == true) {
                            $experiencialaboral->active = false;
                            $retorna = "No";
                        } else {
                            $experiencialaboral->active = true;
                            $retorna = "Si";
                        }

                        $experiencialaboral->actualizado_por = $user_current["id"];
                        $experiencialaboral->fecha_actualizacion = date("Y-m-d H:i:s");

                        if ($experiencialaboral->save($post) === false) {
                            //Para auditoria en versión de pruebas
                            foreach ($experiencialaboral->getMessages() as $message) {
                                echo $message;
                            }
                        } else {
                            return $retorna;
                        }
                    } else {
                        echo "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
});

//Funcionalidad CRUD Experiencia jurado
//Busca el registro experiencia jurado
$app->get('/search_experiencia_jurado', function () use ($app, $config) {
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



            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    //cargar los datos del registro
                    // echo "-->>>>".$request->get('idregistro');

                    if ($request->get('idregistro')) {

                        $experienciajurado = Experienciajurado::findFirst($request->get('idregistro'));
                    }


                    $array["usuario_perfil"] = $usuario_perfil->id;

                    $array["ciudad_name"] = $experienciajurado->Ciudad->nombre;

                    $ambitos = Categoriajurado::find("active=true AND tipo='jurado_ambito'");
                    $array["ambito"] = array();
                    foreach ($ambitos as $ambito) {
                        array_push($array["ambito"], ["id" => $ambito->id, "nombre" => $ambito->nombre]);
                    }

                    $array["experienciajurado"] = $experienciajurado;

                    //Retorno el array
                    return json_encode($array);
                } else {
                    return json_encode(array());
                }
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        return "error_metodo";

        //Para auditoria en versión de pruebas
        //echo "error_metodo" . $ex->getMessage();
    }
}
);

//Busca los registros de educacion formal
$app->get('/all_experiencia_jurado', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    $experienciajurados = Experienciajurado::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR entidad LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($experienciajurados as $experienciajurado) {

                        $ciudad = Ciudades::findFirst(
                                        ["active=true AND id=" . $experienciajurado->ciudad]
                        );
                        $experienciajurado->ciudad = $ciudad->nombre;

                        $ambito = Categoriajurado::findFirst(
                                        ["active=true AND id=" . $experienciajurado->ambito]
                        );
                        $experienciajurado->ambito = $ambito->nombre;

                        $experienciajurado->creado_por = null;
                        $experienciajurado->actualizado_por = null;
                        array_push($response, $experienciajurado);
                    }

                    //resultado sin filtro
                    $texperienciajurado = Experienciajurado::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR entidad LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%')",
                                    ]
                    );
                }
            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($texperienciajurado->count()),
                "recordsFiltered" => intval($experienciajurados->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
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

//Busca los registros de educacion formal
$app->get('/all_experiencia_jurado/active', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    $experienciajurados = Experienciajurado::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND active = true ",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($experienciajurados as $experienciajurado) {

                        $ciudad = Ciudades::findFirst(
                                        ["active=true AND id=" . $experienciajurado->ciudad]
                        );
                        $experienciajurado->ciudad = $ciudad->nombre;

                        $ambito = Categoriajurado::findFirst(
                                        ["active=true AND id=" . $experienciajurado->ambito]
                        );
                        $experienciajurado->ambito = $ambito->nombre;

                        $experienciajurado->creado_por = null;
                        $experienciajurado->actualizado_por = null;
                        array_push($response, $experienciajurado);
                    }

                    //resultado sin filtro
                    $texperienciajurado = Experienciajurado::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND active = true "
                                    ]
                    );
                }
            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($texperienciajurado->count()),
                "recordsFiltered" => intval($experienciajurados->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
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

// Crea el registro de experiencia jurado
$app->post('/new_experiencia_jurado', function () use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a crear experiencia como jurado."',
                ['user' => '',
                    'token' => $request->get('token')]
        );

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $experienciajurado = new Experienciajurado();
                        $experienciajurado->creado_por = $user_current["id"];
                        $experienciajurado->fecha_creacion = date("Y-m-d H:i:s");
                        $experienciajurado->active = true;
                        //al asignarle un objeto genera error, por tal motivo se envia solo el id
                        $experienciajurado->propuesta = $participante->propuestas->id;

                        $post["id"] = null;

                        //echo "educacionnoformal---->>".json_encode($experienciajurado);
                        //echo "post---->>".json_encode($post);
                        if ($experienciajurado->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            foreach ($experienciajurado->getMessages() as $message) {
                                echo $message;
                            }

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear experiencia como jurado. ' . json_decode($experienciajurado->getMessages()) . '"',
                                    ['user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();

                            echo "error";
                        } else {
                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]experienciajurado[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)ej(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $experienciajurado->propuesta . "ej" . $experienciajurado->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $experienciajurado->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear experiencia como jurado. Error alfresco ' . json_encode($return) . '"',
                                                ['user' => $user_current, 'token' => $request->get('token')]
                                        );
                                        $logger->close();
                                        echo "error_creo_alfresco";
                                    } else {

                                        $experienciajurado->file = $return;
                                        if ($experienciajurado->save() === false) {

                                            //Para auditoria en versión de pruebas
                                            /* foreach ($experienciajurado->getMessages() as $message) {
                                              echo $message;
                                              } */

                                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear experiencia como jurado. ' . json_decode($experienciajurado->getMessages()) . '"',
                                                    ['user' => $user_current, 'token' => $request->get('token')]
                                            );
                                            $logger->close();

                                            echo "error";
                                        }
                                    }
                                } else {
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear experiencia como jurado. UPLOAD_ERROR ' . $valor['error'] . '"',
                                            ['user' => $user_current, 'token' => $request->get('token')]
                                    );
                                    $logger->close();
                                    //echo "error".$valor['error'];
                                }
                            }

                            return (String) $experienciajurado->id;
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado"',
                                ['user' => "", 'token' => $request->get('token')]
                        );
                        $logger->close();
                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();
                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        //return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();

        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();

        return "error_metodo";
    }
}
);

// Edita el registro de experiencia jurado
$app->post('/edit_experiencia_jurado/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {

    try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a editar experiencia como  jurado"',
                ['user' => '',
                    'token' => $request->get('token')]
        );

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {


                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $experienciajurado = Experienciajurado::findFirst($id);
                        $experienciajurado->actualizado_por = $user_current["id"];
                        $experienciajurado->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                        //echo "post---->>".json_encode($post);
                        if ($experienciajurado->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            /* foreach ($experienciajurado->getMessages() as $message) {
                              echo $message;
                              } */

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar experiencia como jurado. ' . json_decode($educacionformal->getMessages()) . '"',
                                    ['user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();

                            return "error";
                        } else {

                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]experienciajurado[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)ej(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $experienciajurado->propuesta . "ej" . $experienciajurado->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $experienciajurado->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar experiencia como jurado. Error alfresco ' . json_encode($return) . '"',
                                                ['user' => $user_current, 'token' => $request->get('token')]
                                        );
                                        $logger->close();

                                        return "error_creo_alfresco";
                                    } else {

                                        $experienciajurado->file = $return;
                                        if ($experienciajurado->save() === false) {

                                            //Para auditoria en versión de pruebas
                                            /* foreach ($experienciajurado->getMessages() as $message) {
                                              echo $message;
                                              } */

                                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar experiencia como jurado. ' . json_decode($experienciajurado->getMessages()) . '"',
                                                    ['user' => $user_current, 'token' => $request->get('token')]
                                            );
                                            $logger->close();
                                            return "error";
                                        }
                                    }
                                } else {
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar experiencia como jurado. UPLOAD_ERROR ' . $valor['error'] . '"',
                                            ['user' => $user_current, 'token' => $request->get('token')]
                                    );
                                    $logger->close();
                                    //echo "error".$valor['error'];
                                }
                            }

                            return (String) $experienciajurado->id;
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado"',
                                ['user' => "", 'token' => $request->get('token')]
                        );
                        $logger->close();
                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();

                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();

            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        //return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();
    }
}
);

// Eliminar registro de experiencia jurado
$app->delete('/delete_experiencia_jurado/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto el usuario actual
                $post = $app->request->getPut();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPut('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $experienciajurado = Experienciajurado::findFirst($id);

                        if ($experienciajurado->active == true) {
                            $experienciajurado->active = false;
                            $retorna = "No";
                        } else {
                            $experienciajurado->active = true;
                            $retorna = "Si";
                        }

                        $experienciajurado->actualizado_por = $user_current["id"];
                        $experienciajurado->fecha_actualizacion = date("Y-m-d H:i:s");

                        if ($experienciajurado->save($post) === false) {
                            //Para auditoria en versión de pruebas
                            foreach ($experienciajurado->getMessages() as $message) {
                                echo $message;
                            }
                        } else {
                            return $retorna;
                        }
                    } else {
                        echo "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
});

//Funcionalidad CRUD Reconocimiento
//Busca el registro reconocimiento
$app->get('/search_reconocimiento', function () use ($app, $config) {
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



            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    //cargar los datos del registro
                    // echo "-->>>>".$request->get('idregistro');

                    if ($request->get('idregistro')) {
                        $reconocimiento = Propuestajuradoreconocimiento::findFirst($request->get('idregistro'));
                    }


                    $array["usuario_perfil"] = $usuario_perfil->id;

                    $array["ciudad_name"] = $reconocimiento->Ciudad->nombre;

                    $tipos = Categoriajurado::find("active=true AND tipo='reconocimiento_tipo'");
                    $array["tipo"] = array();
                    foreach ($tipos as $tipo) {
                        array_push($array["tipo"], ["id" => $tipo->id, "nombre" => $tipo->nombre]);
                    }

                    $array["reconocimiento"] = $reconocimiento;

                    //Retorno el array
                    return json_encode($array);
                } else {
                    return json_encode(array());
                }
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        echo "error_metodo";

        //Para auditoria en versión de pruebas
        //echo "error_metodo" . $ex->getMessage();
    }
}
);

//Busca los registros de reconocimiento
$app->get('/all_reconocimiento', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();


                    $reconocimientos = Propuestajuradoreconocimiento::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($reconocimientos as $reconocimiento) {

                        $ciudad = Ciudades::findFirst(
                                        ["active=true AND id=" . $reconocimiento->ciudad]
                        );
                        $reconocimiento->ciudad = $ciudad->nombre;

                        $tipo = Categoriajurado::findFirst(
                                        ["active=true AND id=" . $reconocimiento->tipo]
                        );
                        $reconocimiento->tipo = $tipo->nombre;

                        $reconocimiento->creado_por = null;
                        $reconocimiento->actualizado_por = null;
                        array_push($response, $reconocimiento);
                    }

                    //resultado sin filtro
                    $treconocimiento = Propuestajuradoreconocimiento::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%')"
                                    ]
                    );
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($treconocimiento->count()),
                "recordsFiltered" => intval($reconocimientos->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

//Busca los registros de reconocimiento
$app->get('/all_reconocimiento/active', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();


                    $reconocimientos = Propuestajuradoreconocimiento::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND active = true",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($reconocimientos as $reconocimiento) {

                        $ciudad = Ciudades::findFirst(
                                        ["active=true AND id=" . $reconocimiento->ciudad]
                        );
                        $reconocimiento->ciudad = $ciudad->nombre;

                        $tipo = Categoriajurado::findFirst(
                                        ["active=true AND id=" . $reconocimiento->tipo]
                        );
                        $reconocimiento->tipo = $tipo->nombre;

                        $reconocimiento->creado_por = null;
                        $reconocimiento->actualizado_por = null;
                        array_push($response, $reconocimiento);
                    }

                    //resultado sin filtro
                    $treconocimiento = Propuestajuradoreconocimiento::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND active = true"
                                    ]
                    );
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($treconocimiento->count()),
                "recordsFiltered" => intval($reconocimientos->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);


// Crea el registro de reconocimiento
$app->post('/new_reconocimiento', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $reconocimiento = new Propuestajuradoreconocimiento();
                        $reconocimiento->creado_por = $user_current["id"];
                        $reconocimiento->fecha_creacion = date("Y-m-d H:i:s");
                        $reconocimiento->active = true;
                        //al asignarle un objeto genera error, por tal motivo se envia solo el id
                        $reconocimiento->propuesta = $participante->propuestas->id;

                        $post["id"] = null;

                        //echo "educacionnoformal---->>".json_encode($reconocimiento);
                        //  echo "post---->>".json_encode($post);
                        if ($reconocimiento->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            foreach ($reconocimiento->getMessages() as $message) {
                                echo $message;
                            }
                        } else {

                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]reconocimiento[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)rj(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $reconocimiento->propuesta . "rj" . $reconocimiento->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $reconocimiento->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        echo "error_creo_alfresco";
                                    } else {

                                        $reconocimiento->file = $return;
                                        if ($reconocimiento->save() === false) {
                                            echo "error";
                                            //Para auditoria en versión de pruebas
                                            foreach ($reconocimiento->getMessages() as $message) {
                                                echo $message;
                                            }
                                        }
                                    }
                                } else {
                                    //echo "error".$valor['error'];
                                }
                            }

                            return $reconocimiento->id;
                        }
                    } else {
                        return "deshabilitado";
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

// Edita el registro de reconocimiento
$app->post('/edit_reconocimiento/{id:[0-9]+}', function ($id) use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {


                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $reconocimiento = Propuestajuradoreconocimiento::findFirst($id);
                        $reconocimiento->actualizado_por = $user_current["id"];
                        $reconocimiento->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                        //echo "post---->>".json_encode($post);
                        if ($reconocimiento->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            foreach ($reconocimiento->getMessages() as $message) {
                                echo $message;
                            }
                        } else {

                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]reconocimiento[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)rj(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $reconocimiento->propuesta . "rj" . $reconocimiento->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $reconocimiento->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        echo "error_creo_alfresco";
                                    } else {

                                        $reconocimiento->file = $return;
                                        if ($reconocimiento->save() === false) {
                                            echo "error";
                                            //Para auditoria en versión de pruebas
                                            foreach ($reconocimiento->getMessages() as $message) {
                                                echo $message;
                                            }
                                        }
                                    }
                                } else {
                                    //echo "error".$valor['error'];
                                }
                            }

                            return $reconocimiento->id;
                        }
                    } else {
                        return "deshabilitado";
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

// Eliminar registro de reconocimiento
$app->delete('/delete_reconocimiento/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto el usuario actual
                $post = $app->request->getPut();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPut('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $reconocimiento = Propuestajuradoreconocimiento::findFirst($id);

                        if ($reconocimiento->active == true) {
                            $reconocimiento->active = false;
                            $retorna = "No";
                        } else {
                            $reconocimiento->active = true;
                            $retorna = "Si";
                        }

                        $reconocimiento->actualizado_por = $user_current["id"];
                        $reconocimiento->fecha_actualizacion = date("Y-m-d H:i:s");

                        if ($reconocimiento->save($post) === false) {
                            //Para auditoria en versión de pruebas
                            foreach ($reconocimiento->getMessages() as $message) {
                                echo $message;
                            }
                        } else {
                            return $retorna;
                        }
                    } else {
                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                return "acceso_denegado";
            }
        } else {
            return "error";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
});

//Funcionalidad CRUD Publicaciones
//Busca el registro publicacion
$app->get('/search_publicacion', function () use ($app, $config) {
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

            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    //cargar los datos del registro
                    // echo "-->>>>".$request->get('idregistro');

                    if ($request->get('idregistro')) {
                        $publicacion = Propuestajuradopublicacion::findFirst($request->get('idregistro'));
                    }

                    $array["usuario_perfil"] = $usuario_perfil->id;
                    $array["ciudad_name"] = $publicacion->Ciudad->nombre;

                    $tipos = Categoriajurado::find("active=true AND tipo='publicaciones_tipo'");
                    $array["tipo"] = array();
                    foreach ($tipos as $tipo) {
                        array_push($array["tipo"], ["id" => $tipo->id, "nombre" => $tipo->nombre]);
                    }

                    $formatos = Categoriajurado::find("active=true AND tipo='publicaciones_formato'");
                    $array["formato"] = array();
                    foreach ($formatos as $formato) {
                        array_push($array["formato"], ["id" => $formato->id, "nombre" => $formato->nombre]);
                    }

                    $array["publicacion"] = $publicacion;

                    //Retorno el array
                    return json_encode($array);
                } else {
                    return json_encode(array());
                }
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        echo "error_metodo";

        //Para auditoria en versión de pruebas
        //echo "error_metodo" . $ex->getMessage();
    }
}
);

//Busca los registros de educacion formal
$app->get('/all_publicacion', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();


                    $publicaciones = Propuestajuradopublicacion::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND ( titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR tema LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($publicaciones as $publicacion) {

                        $ciudad = Ciudades::findFirst(
                                        ["active=true AND id=" . $publicacion->ciudad]
                        );
                        $publicacion->ciudad = $ciudad->nombre;

                        $tipo = Categoriajurado::findFirst(
                                        ["active=true AND id=" . $publicacion->tipo]
                        );
                        $publicacion->tipo = $tipo->nombre;

                        $formato = Categoriajurado::findFirst(
                                        ["active=true AND id=" . $publicacion->formato]
                        );
                        $publicacion->formato = $formato->nombre;

                        $publicacion->creado_por = null;
                        $publicacion->actualizado_por = null;
                        array_push($response, $publicacion);
                    }

                    //resultado sin filtro
                    $tpublicacion = Propuestajuradopublicacion::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND (titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR tema LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%')"
                                    ]
                    );
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($tpublicacion->count()),
                "recordsFiltered" => intval($publicaciones->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
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

//Busca los registros de educacion formal
$app->get('/all_publicacion/active', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    $publicaciones = Propuestajuradopublicacion::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND active = true",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($publicaciones as $publicacion) {

                        $ciudad = Ciudades::findFirst(
                                        ["active=true AND id=" . $publicacion->ciudad]
                        );
                        $publicacion->ciudad = $ciudad->nombre;

                        $tipo = Categoriajurado::findFirst(
                                        ["active=true AND id=" . $publicacion->tipo]
                        );
                        $publicacion->tipo = $tipo->nombre;

                        $formato = Categoriajurado::findFirst(
                                        ["active=true AND id=" . $publicacion->formato]
                        );
                        $publicacion->formato = $formato->nombre;

                        $publicacion->creado_por = null;
                        $publicacion->actualizado_por = null;
                        array_push($response, $publicacion);
                    }

                    //resultado sin filtro
                    $tpublicacion = Propuestajuradopublicacion::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND active = true"
                                    ]
                    );
                }
            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($tpublicacion->count()),
                "recordsFiltered" => intval($publicaciones->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
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


// Crea el registro
$app->post('/new_publicacion', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $publicacion = new Propuestajuradopublicacion();
                        $publicacion->creado_por = $user_current["id"];
                        $publicacion->fecha_creacion = date("Y-m-d H:i:s");
                        $publicacion->active = true;
                        //al asignarle un objeto genera error, por tal motivo se envia solo el id
                        $publicacion->propuesta = $participante->propuestas->id;
                        $publicacion->usuario_perfil = $participante->usuario_perfil;

                        $post["id"] = null;

                        //echo "educacionnoformal---->>".json_encode($publicacion);
                        //echo "post---->>".json_encode($post);
                        if ($publicacion->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            foreach ($publicacion->getMessages() as $message) {
                                echo $message;
                            }
                        } else {

                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]publicacion[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)pj(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $publicacion->propuesta . "pj" . $publicacion->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $publicacion->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        echo "error_creo_alfresco";
                                    } else {

                                        $publicacion->file = $return;
                                        if ($publicacion->save() === false) {
                                            echo "error";
                                            //Para auditoria en versión de pruebas
                                            foreach ($publicacion->getMessages() as $message) {
                                                echo $message;
                                            }
                                        }
                                    }
                                } else {
                                    //echo "error".$valor['error'];
                                }
                            }


                            echo $publicacion->id;
                        }
                    } else {
                        return "deshabilitado";
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        echo "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

// Edita el registro
$app->post('/edit_publicacion/{id:[0-9]+}', function ($id) use ($app, $config) {

    try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {


                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $publicacion = Propuestajuradopublicacion::findFirst($id);
                        $publicacion->actualizado_por = $user_current["id"];
                        $publicacion->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                        //echo "post---->>".json_encode($post);
                        if ($publicacion->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            foreach ($publicacion->getMessages() as $message) {
                                echo $message;
                            }
                        } else {


                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]publicacion[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)pj(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $publicacion->propuesta . "pj" . $publicacion->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $publicacion->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        echo "error_creo_alfresco";
                                    } else {

                                        $publicacion->file = $return;
                                        if ($publicacion->save() === false) {
                                            echo "error";
                                            //Para auditoria en versión de pruebas
                                            foreach ($publicacion->getMessages() as $message) {
                                                echo $message;
                                            }
                                        }
                                    }
                                } else {
                                    //echo "error".$valor['error'];
                                }
                            }

                            return $publicacion->id;
                        }
                    } else {
                        return "deshabilitado";
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

// Eliminar registro
$app->delete('/delete_publicacion/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto el usuario actual
                $post = $app->request->getPut();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPut('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $publicacion = Propuestajuradopublicacion::findFirst($id);

                        if ($publicacion->active == true) {
                            $publicacion->active = false;
                            $retorna = "No";
                        } else {
                            $publicacion->active = true;
                            $retorna = "Si";
                        }

                        $publicacion->actualizado_por = $user_current["id"];
                        $publicacion->fecha_actualizacion = date("Y-m-d H:i:s");

                        if ($publicacion->save($post) === false) {
                            //Para auditoria en versión de pruebas
                            foreach ($publicacion->getMessages() as $message) {
                                echo $message;
                            }
                        } else {
                            return $retorna;
                        }
                    } else {
                        echo "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
});

//Funcionalidad Descargar archivos
//descargar archivos
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

//Funcionalidad Postular hoja de vida
// Accion de postular la hoja de vida del perfil jurado
$app->get('/postular', function () use ($app, $config, $logger) {

    try {

        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a inscribir la hoja de vida."',
                ['user' => '',
                    'token' => $request->get('token')]
        );
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->get();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {

                        if (!$participante->propuestas->modalidad_participa || $participante->propuestas->modalidad_participa == '') {

                            $logger->error('"token":"{token}","user":"{user}","message":"Error al inscribir la hoja de vida, no se especifica modalidad en que participa."',
                                    ['user' => $user_current,
                                        'token' => $request->get('token')]
                            );
                            $logger->close();

                            return "error_modalidad";
                        }

                        $documentos = Propuestajuradodocumento::query()
                                ->join("Convocatoriasdocumentos", "Propuestajuradodocumento.requisito = Convocatoriasdocumentos.id")
                                ->join("Requisitos", " Convocatoriasdocumentos.requisito = Requisitos.id")
                                //perfil = 17  perfil de jurado
                                ->where("Propuestajuradodocumento.propuesta = " . $participante->propuestas->id)
                                ->andWhere("Convocatoriasdocumentos.etapa = 'Registro'")
                                ->andWhere("Propuestajuradodocumento.active = true")
                                ->execute();

                        if ($documentos->count() == 0) {
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al inscribir la hoja de vida, no se ha cargado documento administrativo."',
                                    ['user' => $user_current,
                                        'token' => $request->get('token')]
                            );
                            $logger->close();

                            return "error_documento_administrativo";
                        }

                        //10	jurados	Inscrito
                        $participante->propuestas->estado = 10; //inscrita
                        $participante->propuestas->actualizado_por = $user_current["id"];
                        $participante->propuestas->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                        //echo "post---->>".json_encode($post);
                        if ($participante->propuestas->save() === false) {

                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            /* foreach ($participante->propuestas->getMessages() as $message) {
                              echo $message;
                              }
                             */
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al modificar la propuesta. ' . json_decode($participante->propuestas->getMessages()) . '"',
                                    ['user' => $user_current,
                                        'token' => $request->get('token')]
                            );
                            $logger->close();

                            return "error";
                        } else {

                            $logger->info(
                                    '"token":"{token}","user":"{user}","message":"Se incribió la hoja de vida."',
                                    ['user' => $user_current,
                                        'token' => $request->get('token')]
                            );
                            $logger->close();

                            return (String) $participante->propuestas->id;
                        }
                    } else {

                        $logger->error('"token":"{token}","user":"{user}","message":"Deshabilitado"',
                                ['user' => $user_current,
                                    'token' => $request->get('token')]
                        );
                        $logger->close();

                        return "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();

                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();

            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        //return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "",
                    'token' => $request->get('token')]
        );
        $logger->close();

        return "error_metodo";
    }
}
);

// lista la propuesta asociada a la convocatoria
$app->get('/propuesta', function () use ($app, $config) {

    try {

        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $array = array();


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->get();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    $array["propuesta"] = $participante->propuestas;

                    $participante->tipo_documento = $participante->Tiposdocumentos->nombre;
                    $participante->sexo = $participante->Sexos->nombre;
                    $participante->orientacion_sexual = $participante->Orientacionessexuales->nombre;
                    $participante->identidad_genero = $participante->Identidadesgeneros->nombre;
                    $participante->grupo_etnico = $participante->Gruposetnicos->nombre;

                    $array["participante"] = $participante;

                    //echo json_encode($participante->propuestas);
                    echo json_encode($array);
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

//Funcionalidad CRUD Documentos
//Busca el registro documento
$app->get('/search_documento', function () use ($app, $config) {
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

            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    //cargar los datos del registro
                    // echo "-->>>>".$request->get('idregistro');

                    if ($request->get('idregistro')) {
                        $documento = Propuestajuradodocumento::findFirst($request->get('idregistro'));
                    }


                    $array["usuario_perfil"] = $usuario_perfil->id;


                    //$tipos =Categoriajurado::find("active=true AND tipo='anexo'");
                    $tipos = Convocatoriasdocumentos::find(
                                    " convocatoria=" . $request->get('idc') . " and active=true AND etapa='Registro'"
                    );

                    $array["tipo"] = array();
                    foreach ($tipos as $tipo) {
                        array_push($array["tipo"], ["id" => $tipo->id, "nombre" => $tipo->Requisitos->nombre]);
                    }

                    $array["documento"] = $documento;

                    //Retorno el array
                    return json_encode($array);
                } else {
                    return json_encode(array());
                }
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //echo "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage();
    }
}
);

//Busca los registros de documento
$app->get('/all_documento', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();


                    $documentos = Propuestajuradodocumento::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id,
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($documentos as $documento) {

                        if (isset($documento->categoria_jurado)) {
                            $tipo = Categoriajurado::findFirst(
                                            ["active=true AND id=" . $documento->categoria_jurado]
                            );
                            $documento->categoria_jurado = $tipo->nombre;
                        }

                        if (isset($documento->requisito)) {

                            $tipo = Convocatoriasdocumentos::findFirst(
                                            [" active=true AND id =" . $documento->requisito]
                            );

                            $documento->categoria_jurado = $tipo->Requisitos->nombre;
                        }

                        $documento->creado_por = null;
                        $documento->actualizado_por = null;
                        array_push($response, $documento);
                    }

                    //resultado sin filtro
                    $tdocumento = Propuestajuradodocumento::find([
                                " propuesta = " . $participante->propuestas->id,
                    ]);
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($tdocumento->count()),
                "recordsFiltered" => intval($documentos->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
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

//Busca los registros de documento
$app->get('/all_documento/active', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();


                    $documentos = Propuestajuradodocumento::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND active = true",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    foreach ($documentos as $documento) {

                        if (isset($documento->categoria_jurado)) {
                            $tipo = Categoriajurado::findFirst(
                                            ["id=" . $documento->categoria_jurado]
                            );
                            $documento->categoria_jurado = $tipo->nombre;
                        }

                        if (isset($documento->requisito)) {

                            $tipo = Convocatoriasdocumentos::findFirst(
                                            ["id =" . $documento->requisito]
                            );

                            $documento->categoria_jurado = $tipo->Requisitos->nombre;
                        }

                        $documento->creado_por = null;
                        $documento->actualizado_por = null;
                        array_push($response, $documento);
                    }

                    //resultado sin filtro
                    $tdocumento = Propuestajuradodocumento::find([
                                " propuesta = " . $participante->propuestas->id
                                . " AND active = true"
                    ]);
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($tdocumento->count()),
                "recordsFiltered" => intval($documentos->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
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


// Crea el registro de documento
$app->post('/new_documento', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $documento = new Propuestajuradodocumento();
                        $documento->creado_por = $user_current["id"];
                        $documento->fecha_creacion = date("Y-m-d H:i:s");
                        $documento->active = true;
                        //al asignarle un objeto genera error, por tal motivo se envia solo el id
                        $documento->propuesta = $participante->propuestas->id;

                        $post["id"] = null;

                        //echo "educacionnoformal---->>".json_encode($publicacion);
                        //echo "post---->>".json_encode($post);
                        if ($documento->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            foreach ($documento->getMessages() as $message) {
                                echo $message;
                            }
                        } else {

                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]documento[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)dj(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $documento->propuesta . "dj" . $documento->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $documento->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    //  echo "    ".json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        //  echo "    ".json_encode($return);
                                        echo "error_creo_alfresco";
                                    } else {

                                        $documento->file = $return;
                                        if ($documento->save() === false) {
                                            echo "error";
                                            //Para auditoria en versión de pruebas
                                            foreach ($documento->getMessages() as $message) {
                                                echo $message;
                                            }
                                        }
                                    }
                                } else {
                                    //echo "error".$valor['error'];
                                }
                            }


                            echo $documento->id;
                        }
                    } else {
                        return "deshabilitado";
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        echo "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

// Edita el registro de documento
$app->post('/edit_documento/{id:[0-9]+}', function ($id) use ($app, $config) {

    try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {


                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPost('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $documento = Propuestajuradodocumento::findFirst($id);
                        $documento->actualizado_por = $user_current["id"];
                        $documento->fecha_actualizacion = date("Y-m-d H:i:s");

                        //echo "educacionformal---->>".json_encode($documento);
                        //echo "post---->>".json_encode($post);
                        if ($documento->save($post) === false) {
                            //  return json_encode($user_current);
                            //Para auditoria en versión de pruebas
                            foreach ($documento->getMessages() as $message) {
                                echo $message;
                            }
                        } else {

                            //echo "guardando archivo";
                            //echo json_encode($_FILES);
                            //Recorro todos los posibles archivos
                            foreach ($_FILES as $clave => $valor) {
                                $fileTmpPath = $valor['tmp_name'];
                                $fileType = $valor['type'];
                                $fileNameCmps = explode(".", $valor["name"]);
                                $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                if ($valor['error'] == 0) {
                                    /*
                                     * propuesta[codigo]documento[codigo]usuario[codigo]fecha[YmdHis].extension
                                     * p(cod)dj(cod)u(cod)f(YmdHis).(ext)
                                     */
                                    $fileName = "p" . $documento->propuesta . "dj" . $documento->id . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $fileExtension;
                                    $filepath = "/Sites/convocatorias/" . $request->getPost('idc') . "/propuestas/" . $documento->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                    echo "    " . json_encode($return);
                                    if (strpos($return, "Error") !== FALSE) {
                                        echo "    " . json_encode($return);
                                        echo "error_creo_alfresco";
                                    } else {

                                        $documento->file = $return;
                                        if ($documento->save() === false) {
                                            echo "error";
                                            //Para auditoria en versión de pruebas
                                            foreach ($documento->getMessages() as $message) {
                                                echo $message;
                                            }
                                        }
                                    }
                                } else {
                                    //echo "error".$valor['error'];
                                }
                            }

                            return $documento->id;
                        }
                    } else {
                        return "deshabilitado";
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

// Eliminar registro de documento
$app->delete('/delete_documento/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto el usuario actual
                $post = $app->request->getPut();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );


                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPut('idc'))
                            ->execute()
                            ->getFirst();

                    //valido si la propuesta tiene el estado registrada
                    //9	jurados	Registrado
                    /*
                     * 04-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se modifica el condicional con el fin de permitir actualizaciones 
                     * en la hoja de vida luego de que se haya inscrito, esto con el 
                     * fin de solucionar casos de soporte. Esta decisión se toma de común acuerdo en comite.
                     */
//                    if ($participante->propuestas != null and $participante->propuestas->estado == 9) {
                    if ($participante->propuestas != null) {

                        $documento = Propuestajuradodocumento::findFirst($id);

                        if ($documento->active == true) {
                            $documento->active = false;
                            $retorna = "No";
                        } else {
                            $documento->active = true;
                            $retorna = "Si";
                        }

                        $documento->actualizado_por = $user_current["id"];
                        $documento->fecha_actualizacion = date("Y-m-d H:i:s");

                        if ($documento->save($post) === false) {
                            //Para auditoria en versión de pruebas
                            foreach ($documento->getMessages() as $message) {
                                echo $message;
                            }
                        } else {
                            return $retorna;
                        }
                    } else {
                        echo "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
});


//Funcionalidad Postular
//Busca los registros de postulaciones
$app->get('/postulacion_search_convocatorias', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $id_convocatorias_postuladas = array(0);//lleva un cero para cuando no tiene ninguna postulación tenga un valor 

        //  $fecha_actual = date("d-m-Y");
        $fecha_actual = date("Y-m-d H:i:s");

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));


        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    $postulaciones = $participante->propuestas->juradospostulados;

                    //se crea el array con los id de las convocatorias a las cuales aplicó el jurado
                    foreach ($postulaciones as $postulacion) {

                        //si la postulacion está activa se agrega la postulación
                        if ($postulacion->active) {

                            if ($postulacion->convocatorias->tiene_categorias && $postulacion->convocatorias->diferentes_categorias) {

                                $perfil = Convocatoriasparticipantes::findFirst($postulacion->perfil);

                                array_push($id_convocatorias_postuladas, $perfil->convocatorias->id);
                            } else {

                                array_push($id_convocatorias_postuladas, $postulacion->convocatorias->id);
                            }
                        }
                    }//fin foreach


                    $query = Convocatorias::query();

                    //$convocatorias = Convocatorias::query()
                    $query->join("Convocatoriascronogramas", "Convocatoriascronogramas.convocatoria = Convocatorias.id")
                            ->where(" Convocatorias.id NOT IN ({idConvocatoria:array}) ");

                    if ($request->get('enfoque')) {
                        $query->andWhere(" Convocatorias.enfoque = " . $request->get('enfoque'));
                    }

                    if ($request->get('linea')) {
                        $query->andWhere(" Convocatorias.linea_estrategica = " . $request->get('linea'));
                    }

                    if ($request->get('area')) {
                        $query->andWhere(" Convocatorias.area = " . $request->get('area'));
                    }


                    //palabra clave tabla
                    //->andWhere(" ( Convocatorias.nombre LIKE '%".$request->get("search")['value']."%' OR Convocatorias.descripcion LIKE '%".$request->get("search")['value']."%') " )
                    //palabra clave formulario
                    /*
                     * 26-03-2021
                     * Wilmer Gustavo Mogollín Duque
                     * Se modifica la consulta con el fin de permitir búsquedas sin tildes y en minúsculas 
                     * o mayúsculas con palabra clave en la descripción o en el nombre de la convocatoria
                     */
                    $query->andWhere(" UPPER(TRANSLATE(Convocatorias.nombre,'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun')) LIKE TRANSLATE(UPPER('%" . $request->get("pclave") . "%'),'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun') ")
                            ->orWhere(" UPPER(TRANSLATE(Convocatorias.descripcion,'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun')) LIKE TRANSLATE(UPPER('%" . $request->get("pclave") . "%'),'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun') ")
                            //5	convocatorias	Publicada
                            ->andWhere(" Convocatorias.estado = 5 ")
                            ->andWhere(" Convocatorias.active = true  ")
                            ->andWhere(" Convocatorias.modalidad != 2  ")
                            //  ->andWhere(" Convocatorias.convocatoria_padre_categoria = null  ")
                            ->andWhere(" Convocatoriascronogramas.tipo_evento = 12 ")
                            ->andWhere(" Convocatoriascronogramas.active = true  ")
                            //Donde la fecha de cierre tiene mas de 48 horas (2 dias)
                            ->andWhere(" Convocatoriascronogramas.fecha_fin >= '" . date("Y-m-d H:i:s", strtotime($fecha_actual . "+ 2 days")) . "'")
                            ->order(' Convocatorias.id ASC ')
                            ->limit("" . $request->get('length'), "" . $request->get('start'))
                            ->bind(["idConvocatoria" => $id_convocatorias_postuladas]);


                    $convocatorias = $query->execute();

                    //  echo json_encode($convocatorias);

                    foreach ($convocatorias as $convocatoria) {

                        //echo json_encode($convocatoria->Convocatorias);

                        if ($convocatoria->Convocatorias && $convocatoria->Convocatorias->diferentes_categorias) {
                            $convocatoria->nombre = $convocatoria->Convocatorias->nombre . " - " . $convocatoria->nombre;
                        }

                        $area = Areas::findFirst(
                                        ["id=" . $convocatoria->area]
                        );
                        $convocatoria->area = $area->nombre;

                        $lineaestrategica = Lineasestrategicas::findFirst(
                                        ["id=" . $convocatoria->linea_estrategica]
                        );
                        $convocatoria->linea_estrategica = $lineaestrategica->nombre;

                        $programa = Programas::findFirst(
                                        ["id=" . $convocatoria->programa]
                        );
                        $convocatoria->programa = $programa->nombre;

                        $entidad = Entidades::findFirst(
                                        ["id=" . $convocatoria->entidad]
                        );
                        $convocatoria->entidad = $entidad->nombre;

                        $enfoque = Enfoques::findFirst(
                                        ["id=" . $convocatoria->enfoque]
                        );
                        $convocatoria->enfoque = $enfoque->nombre;
                        $convocatoria->creado_por = null;
                        $convocatoria->actualizado_por = null;

                        array_push($response, $convocatoria);
                    }

                    //resultado sin filtro
                    /* $tconvocatorias = Convocatorias::find(
                      [
                      "id NOT IN ({idConvocatoria:array}) " //las postuladas
                      ." AND area = ".$request->get('area')
                      ." AND estado = 5 " //estado publicada
                      ." AND active = true " //activa
                      ." AND modalidad != 2 ", //jurados
                      "bind" => [
                      "idConvocatoria" => $id_convocatorias_postuladas
                      ],
                      ]
                      ); */


                    // $tconvocatorias = Convocatorias::query()
                    $tquery = Convocatorias::query();

                    $tquery->join("Convocatoriascronogramas", "Convocatoriascronogramas.convocatoria = Convocatorias.id")
                            ->where(" Convocatorias.id NOT IN ({idConvocatoria:array}) ");


                    if ($request->get('enfoque')) {
                        $tquery->andWhere(" Convocatorias.enfoque = " . $request->get('enfoque'));
                    }

                    if ($request->get('linea')) {
                        $tquery->andWhere(" Convocatorias.linea_estrategica = " . $request->get('linea'));
                    }

                    if ($request->get('area')) {
                        $tquery->andWhere(" Convocatorias.area = " . $request->get('area'));
                    }
                    //palabra clave
                    //->andWhere(" ( Convocatorias.nombre LIKE '%".$request->get("search")['value']."%' OR Convocatorias.descripcion LIKE '%".$request->get("search")['value']."%') " )
                    $tquery->andWhere(" Convocatorias.estado = 5 ")
                            ->andWhere(" Convocatorias.active = true  ")
                            ->andWhere(" Convocatorias.modalidad != 2  ")
                            //  ->andWhere(" Convocatorias.convocatoria_padre_categoria = null  ")
                            ->andWhere(" Convocatoriascronogramas.tipo_evento = 12 ")
                            ->andWhere(" Convocatoriascronogramas.active = true  ")
                            //Donde la fecha de cierre tiene mas de 48 horas (2 dias)
                            ->andWhere(" Convocatoriascronogramas.fecha_fin >= '" . date("Y-m-d H:i:s", strtotime($fecha_actual . "+ 2 days")) . "'")
                            ->order(' Convocatorias.id ASC ')
                            ->bind(["idConvocatoria" => $id_convocatorias_postuladas]);

                    $tconvocatorias = $tquery->execute();
                }
            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($tconvocatorias->count()),
                "recordsFiltered" => ( $request->get("search")['value'] != null ? intval($convocatorias->count()) : intval($tconvocatorias->count()) ),
                "data" => $response   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

//Busca los registros de postulaciones
$app->get('/postulacion_perfiles_convocatoria', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $perfiles = Convocatoriasparticipantes::find(
                                    [
                                        "convocatoria = " . $request->get('idregistro')
                                        . " AND tipo_participante = 4 " //jurados
                                        . " AND active = true ", //jurados
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );

                    //  echo json_encode($convocatorias);

                    foreach ($perfiles as $perfil) {
                        $perfil->creado_por = null;
                        $perfil->fecha_creacion = null;
                        $perfil->actualizado_por = null;
                        $perfil->fecha_actualizacion = null;
                        array_push($response, $perfil);
                    }
                }
            }


            //retorno el array en json
            echo json_encode($response);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

// Crea el registro de postulacion
$app->post('/new_postulacion', function () use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        $contador = 0;
        $contador1 = 0;
        $contador2 = 0;


        $logger->info(
                '"token":"{token}","user":"{user}","message":"Ingresa a crear una postulación."',
                ['user' => '',
                    'token' => $request->get('token')]
        );


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();




                $banco_jurados = Convocatorias::findFirst(
                                [
                                    ' id = ' . $request->getPut('idc')
                                ]
                );


                if (json_encode($banco_jurados->anio) == date("Y")) {

                    // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                    $usuario_perfil = Usuariosperfiles::findFirst(
                                    [
                                        " usuario = " . $user_current["id"] . " AND perfil =17"
                                    ]
                    );

                    if ($usuario_perfil->id != null) {

                        $participante = Participantes::query()
                                ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                                ->join("Propuestas", " Participantes.id = Propuestas.participante")
                                //perfil = 17  perfil de jurado
                                ->where("Usuariosperfiles.perfil = 17 ")
                                ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                                ->andWhere("Propuestas.convocatoria = " . $request->getPut('idc'))
                                ->execute()
                                ->getFirst();

                        //9	jurados	Registrado
                        if ($participante->propuestas->estado == 9) {
                            return "error";
                        }

                        $postulaciones = $participante->propuestas->juradospostulados;

                        //Calcula el numero de postulaciones del jurado
                        //echo "cantidad-->".$postulaciones->count();
                        foreach ($postulaciones as $postulacion) {

                            //la convocatoria está activa y está publicada
                            if ($postulacion->convocatorias->active && $postulacion->convocatorias->estado == 5 && $postulacion->active && $postulacion->convocatorias->convocatoria_padre_categoria == null) {
                                $contador1++;
                            }
                        }




                        $postulaciones_categorias = Juradospostulados::query()
                                ->join("Convocatorias", "Convocatorias.id=Juradospostulados.convocatoria")
                                ->where("Juradospostulados.propuesta = " . $participante->propuestas->id)
                                ->andWhere("Juradospostulados.active")
                                ->andWhere("Convocatorias.active")
                                ->andWhere("Convocatorias.estado=5")
                                ->andWhere("Convocatorias.convocatoria_padre_categoria is not null")
                                ->groupBy("Convocatorias.convocatoria_padre_categoria")
                                ->columns("count(*)")
                                ->execute();


//                     return json_encode($postulaciones_categorias);



                        foreach ($postulaciones_categorias as $postulaciones_categoria) {
                            $contador2++;
                        }


                        $contador = $contador1 + $contador2;


                        $nummax = Tablasmaestras::findFirst(
                                        [
                                            " nombre = 'numero_maximo_postulaciones_jurado'"
                                        ]
                        );


                        //Controla el límite de postulaciones
                        //limite tabla maestra
                        if ($contador < (int) $nummax->valor) {


                            $juradopostulado = new Juradospostulados();
                            $juradopostulado->propuesta = $participante->propuestas->id;
                            $juradopostulado->estado = 9; //estado de la propuesta del jurado, 9 jurados	Registrado
                            $juradopostulado->creado_por = $user_current["id"];
                            $juradopostulado->fecha_creacion = date("Y-m-d H:i:s");
                            $juradopostulado->tipo_postulacion = 'Inscrita';
                            $juradopostulado->perfil = $request->getPut('perfil');
                            $juradopostulado->active = true;

                            $convocatoria = Convocatorias::findFirst($request->getPut('idregistro'));

                            //caso 3,la convocatoria tiene categoria y las categorias tienen diferente cronograma
                            if ($convocatoria->tiene_categorias && $convocatoria->diferentes_categorias) {

                                // $juradopostulado->convocatoria = $convocatoria->convocatoria_padre_categoria;
                                $juradopostulado->convocatoria = $convocatoria->id;
                            }//caso 2,la convocatoria tiene categoria y las categorias tienen igual cronograma
                            elseif ($convocatoria->tiene_categorias && !$convocatoria->diferentes_categorias) {

                                $juradopostulado->convocatoria = $convocatoria->id;
                            }//caso 1, la convocatoria  no tiene categoria
                            elseif (!$convocatoria->tiene_categorias) {

                                $juradopostulado->convocatoria = $request->getPut('idregistro');
                            }//end elseif (!$convocatoria->tiene_categorias)
                            //guardar registro
                            if ($juradopostulado->save() === false) {

                                //return "error";
                                //Para auditoria en versión de pruebas
                                /*  foreach ($juradopostulado->getMessages() as $message) {
                                  echo $message;
                                  } */

                                $logger->error('"token":"{token}","user":"{user}","message":"Error al crear la postulación. ' . json_decode($juradopostulado->getMessages()) . '"',
                                        ['user' => $user_current, 'token' => $request->get('token')]
                                );
                                $logger->close();

                                return "error";
                            }

                            return $juradopostulado->id;
                        } else {
                            $logger->error('"token":"{token}","user":"{user}","message":"Supera el maximo de postulaciones."', [
                                'user' => $user_current, 'token' => $request->get('token')]
                            );
                            $logger->close();
                            return "error_limite";
                        }
                    } else {
                        return "error";
                    }
                } else {
                    return "error_banco";
                }
                //
            } else {
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',
                        ['user' => "", 'token' => $request->get('token')]
                );
                $logger->close();
                return "acceso_denegado";
            }
        } else {
            $logger->error('"token":"{token}","user":"{user}","message":"Token caducó"', [
                'user' => "", 'token' => $request->get('token')]
            );
            $logger->close();
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo"
        //Para auditoria en versión de pruebas
        echo "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();

        $logger->error('"token":"{token}","user":"{user}","message":"Error método ' . $ex->getMessage() . '"',
                ['user' => "", 'token' => $request->get('token')]
        );
        $logger->close();
        return "error_metodo";
    }
}
);

//Busca los registros de postulaciones
$app->get('/search_postulacion', function () use ($app, $config) {
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

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $convocatoria = array();

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->get('idc'))
                            ->execute()
                            ->getFirst();

                    //$postulaciones = $participante->propuestas->juradospostulados;

                    $postulaciones = Juradospostulados::find(
                                    [
                                        'propuesta =' . $participante->propuestas->id
                                        . ' AND active = true '
                                    ]
                    );

                    //  echo json_encode($convocatorias);

                    foreach ($postulaciones as $postulacion) {

                        $convocatoria['id'] = $postulacion->convocatorias->id;

                        if ($postulacion->Convocatorias && $postulacion->Convocatorias->Convocatorias->diferentes_categorias) {

                            $perfil = Convocatoriasparticipantes::findFirst($postulacion->perfil);

                            $convocatoria['nombre'] = $postulacion->convocatorias->convocatorias->nombre . " - " . $perfil->Convocatorias->nombre;
                        } else {
                            $convocatoria['nombre'] = $postulacion->convocatorias->nombre;
                        }

                        $area = Areas::findFirst(
                                        ["id=" . $postulacion->convocatorias->area]
                        );
                        $postulacion->convocatorias->area = $area->nombre;
                        $convocatoria['area'] = $area->nombre;

                        $lineaestrategica = Lineasestrategicas::findFirst(
                                        ["id=" . $postulacion->convocatorias->linea_estrategica]
                        );
                        $postulacion->convocatorias->linea_estrategica = $lineaestrategica->nombre;
                        $convocatoria['linea_estrategica'] = $lineaestrategica->nombre;

                        $programa = Programas::findFirst(
                                        ["id=" . $postulacion->convocatorias->programa]
                        );
                        $postulacion->convocatorias->programa = $programa->nombre;
                        $convocatoria['programa'] = $programa->nombre;

                        $entidad = Entidades::findFirst(
                                        ["id=" . $postulacion->convocatorias->entidad]
                        );
                        $postulacion->convocatorias->entidad = $entidad->nombre;
                        $convocatoria['entidad'] = $entidad->nombre;

                        $enfoque = Enfoques::findFirst(
                                        ["id=" . $postulacion->convocatorias->enfoque]
                        );
                        $postulacion->convocatorias->enfoque = $enfoque->nombre;
                        $convocatoria['enfoque'] = $enfoque->nombre;

                        $postulacion->convocatorias->creado_por = null;
                        $postulacion->convocatorias->actualizado_por = null;


                        // array_push($response,$postulacion->convocatorias);
                        //   array_push($response,["postulacion"=>$postulacion, "convocatoria"=>$postulacion->convocatorias]);
                        array_push($response, ["postulacion" => $postulacion, "convocatoria" => $convocatoria]);
                    }
                }
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($postulaciones->count()),
                "recordsFiltered" => intval($postulaciones->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

// Eliminar registro de postulacion
$app->delete('/delete_postulacion/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto el usuario actual
                $post = $app->request->getPut();

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                if ($usuario_perfil->id != null) {

                    $participante = Participantes::query()
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            ->andWhere("Propuestas.convocatoria = " . $request->getPut('idc'))
                            ->execute()
                            ->getFirst();

                    $juradospostulado = Juradospostulados::findFirst($id);

                    //9	jurados	Registrado
                    if ($participante->propuestas->estado == 9) {
                        return "error";
                    }

                    $postulaciones = $participante->propuestas->juradospostulados;

                    //Calcula el numero de postulaciones del jurado
                    //echo "cantidad-->".$postulaciones->count();
                    /* 7  foreach ($postulaciones as $postulacion) {

                      //la convocatoria está activa y está publicada
                      if( $postulacion->convocatorias->active && $postulacion->convocatorias->estado == 5 && $postulacion->active){
                      $contador++;
                      }

                      }
                     */

                    /*     $nummax = Tablasmaestras::findFirst(
                      [
                      " nombre = 'numero_maximo_postulaciones_jurado'"
                      ]
                      );
                     */
                    //9	jurados	Registrado
                    if ($juradospostulado != null and $juradospostulado->estado == 9) {

                        if ($juradospostulado->active == true) {
                            $juradospostulado->active = false;
                            $retorna = "No";
                        }/* else{

                          //Controla el límite de postulaciones
                          //limite tabla maestra
                          if( $contador < (int)$nummax->valor){
                          $juradospostulado->active=true;
                          $retorna="Si";

                          }else {
                          return "error_limite";
                          }
                          } */


                        $juradospostulado->actualizado_por = $user_current["id"];
                        $juradospostulado->fecha_actualizacion = date("Y-m-d H:i:s");

                        if ($juradospostulado->save($post) === false) {
                            //Para auditoria en versión de pruebas
                            foreach ($juradospostulado->getMessages() as $message) {
                                echo $message;
                            }
                        } else {
                            return $retorna;
                        }
                    } else {
                        echo "deshabilitado";
                    }
                } else {
                    return "error";
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
    }
});

//Funcionalidad Descargar archivos
//descargar archivos
$app->get('/download_condiciones', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //  $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo
        if (isset($token_actual->id)) {

            $condiciones = Tablasmaestras::findFirst("active=true AND nombre='Condiciones de participación jurados'");

            echo json_encode(["archivo" => str_replace("/view?usp=sharing", "/preview", $condiciones->valor)]);
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

/*
 * 12-02-2021
 * Wilmer Gustavo Mogollón Duque
 * //Se agrega para mostrar documento de tratamiento de datos
  $("#tratamiento_datos_pdf").attr("src", json.archivo);
 */

$app->get('/download_tratamiento', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //  $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo
        if (isset($token_actual->id)) {

            $condiciones = Tablasmaestras::findFirst("active=true AND nombre='Tratamiento de datos'");

            echo json_encode(["archivo" => str_replace("/view?usp=sharing", "/preview", $condiciones->valor)]);
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



//Busca los registros de postulaciones
$app->get('/select_categoria', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();


        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));


        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            $categorias = Tablasmaestras::findFirst([" active=true  AND nombre = 'jurado_categoria_participa'"]);

            foreach (explode(",", $categorias->valor) as $categoria) {
                array_push($response, ["id" => $categoria, "nombre" => $categoria]);
            }

            echo json_encode($response);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

//Lista las hojas de vida (propuestas) del jurado
$app->get('/listar', function () use ($app, $config) {
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
            $array = array();
            $tpropuestas = array();

            if ($user_current["id"]) {

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                $usuario_perfil = Usuariosperfiles::findFirst(
                                [
                                    " usuario = " . $user_current["id"] . " AND perfil =17"
                                ]
                );

                // return json_encode($usuario_perfil);
                if ($usuario_perfil->id != null) {

                    $participantes = Participantes::query()
                            //->columns("Participantes.id")
                            ->join("Usuariosperfiles", "Participantes.usuario_perfil = Usuariosperfiles.id")
                            ->join("Propuestas", " Participantes.id = Propuestas.participante")
                            //perfil = 17  perfil de jurado
                            ->where("Usuariosperfiles.perfil = 17 ")
                            ->andWhere("Usuariosperfiles.usuario = " . $user_current["id"])
                            //->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                            ->execute();
                    //->getFirst();
                    //  return json_encode($participantes);;;;

                    if ($participantes->count() > 0) {

                        foreach ($participantes as $value) {
                            array_push($array, $value->id);
                        }

                        $propuestas = Propuestas::find(
                                        [
                                            " participante IN ({participante:array})  ",
                                            "order" => 'id ASC',
                                            'bind' => [
                                                'participante' => $array
                                            ],
                                            "limit" => $request->get('length'),
                                            "offset" => $request->get('start'),
                                        ]
                        );

                        foreach ($propuestas as $propuesta) {

                            /* Ajuste de william supervisado por wilmer */
                            /* 2020-04-28 */
                            $array_convocatoria_1 = Convocatorias::findFirst("id = " . $propuesta->convocatoria);

                            /* Ajuste de william supervisado por wilmer */
                            /* 2020-04-28 */
                            $array_estado_1 = Estados::findFirst("id = " . $propuesta->estado);


                            array_push($response, [
                                "id" => $propuesta->id,
                                "codigo" => $propuesta->codigo,
                                "id_convocatoria" => $propuesta->convocatoria,
                                "convocatoria" => $array_convocatoria_1->nombre,
                                "modalidad_participa" => $propuesta->modalidad_participa,
                                "estado" => $array_estado_1->nombre
                            ]);
                        }

                        //resultado sin filtro
                        $tpropuestas = Propuestas::find(
                                        [
                                            " participante IN ({participante:array})  ",
                                            'bind' => [
                                                'participante' => $array
                                            ],
                                        ]
                        );
                    }
                }
            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval(count($tpropuestas)),
                "recordsFiltered" => intval(count($tpropuestas)),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
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

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage() . $e->getTraceAsString();
}
?>
