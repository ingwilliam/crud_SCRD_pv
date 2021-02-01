<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
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

//Metodo el cual carga el formulario del integrante
//Verifica que que tenga creada la propuestas
$app->get('/formulario_integrante', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador Personasnaturales en el método formulario_integrante, ingresa al formulario de integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Busco si tiene el perfil asociado de acuerdo al parametro
                if ($request->get('m') == "pn") {
                    $tipo_participante = "Persona Natural";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 6");
                }
                if ($request->get('m') == "pj") {
                    $tipo_participante = "Persona Jurídica";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 7");
                }
                if ($request->get('m') == "agr") {
                    $tipo_participante = "Agrupaciones";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 8");
                }

                if (isset($usuario_perfil->id)) {

                    //Consulto el participante inicial
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de acuerdo al parametro
                    if (isset($participante->id)) {

                        //Valido si existe el codigo de la propuesta
                        //De lo contratio creo el participante del cual depende del inicial
                        //Creo la propuesta asociando el participante creado
                        if (is_numeric($request->get('p')) AND $request->get('p') != 0) {
                            //Consulto la propuesta solicitada
                            $conditions = ['id' => $request->get('p'), 'active' => true];
                            $propuesta = Propuestas::findFirst(([
                                        'conditions' => 'id=:id: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            if (isset($propuesta->id)) {

                                //Creo el array de la propuesta
                                $array = array();
                                //Valido si se habilita propuesta por derecho de petición
                                $array["programa"] = $propuesta->getConvocatorias()->programa;
                                $array["estado"] = $propuesta->estado;
                                if ($propuesta->habilitar) {
                                    $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                                    $habilitar_fecha_inicio = strtotime($propuesta->habilitar_fecha_inicio, time());
                                    $habilitar_fecha_fin = strtotime($propuesta->habilitar_fecha_fin, time());
                                    if (($fecha_actual >= $habilitar_fecha_inicio) && ($fecha_actual <= $habilitar_fecha_fin)) {
                                        $array["estado"] = 7;
                                    }
                                }

                                $array["formulario"]["propuesta"] = $propuesta->id;
                                $array["formulario"]["participante"] = $propuesta->participante;
                                //Creo los array de los select del formulario
                                $array["tipo_documento"] = Tiposdocumentos::find("active=true");
                                $array["sexo"] = Sexos::find("active=true");
                                $array["orientacion_sexual"] = Orientacionessexuales::find("active=true");
                                $array["identidad_genero"] = Identidadesgeneros::find("active=true");
                                $array["grupo_etnico"] = Gruposetnicos::find("active=true");
                                $tabla_maestra = Tablasmaestras::find("active=true AND nombre='estrato'");
                                $array["estrato"] = explode(",", $tabla_maestra[0]->valor);
                                $array["discapacidades"] = Tiposdiscapacidades::find("active=true");

                                //Registro la accion en el log de convocatorias
                                $logger->info('"token":"{token}","user":"{user}","message":"Retorna al controlador Personasnaturales en el método formulario_integrante, retorna información al formulario de integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();

                                //Retorno el array
                                echo json_encode($array);
                            } else {
                                //Registro la accion en el log de convocatorias
                                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, la propuesta no existe en el formulario de integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "crear_propuesta";
                                exit;
                            }
                        } else {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, la propuesta no existe en el formulario de integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_cod_propuesta";
                            exit;
                        }
                    } else {
                        //Busco si tiene el perfil asociado de acuerdo al parametro
                        if ($request->get('m') == "pn") {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pn";
                            exit;
                        }
                        if ($request->get('m') == "pj") {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pj";
                            exit;
                        }
                        if ($request->get('m') == "agr") {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_agr";
                            exit;
                        }
                    }
                } else {
                    //Busco si tiene el perfil asociado de acuerdo al parametro
                    if ($request->get('m') == "pn") {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pn";
                        exit;
                    }
                    if ($request->get('m') == "pj") {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pj";
                        exit;
                    }
                    if ($request->get('m') == "agr") {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_agr";
                        exit;
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, acceso denegado en el metodo formulario_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, token caduco en el metodo formulario_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador Personasnaturales en el método formulario_integrante, error en el metodo formulario_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Reemplaza el integrante por uno nuevo
$app->post('/reemplazar_integrante', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto el usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador Personasnaturales en el método reemplazar_integrante, para reemplazar integrante (' . $request->getPost('id_participante_reemplazo') . ') en la convocatoria(' . $request->getPost('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Trae los datos del formulario por post
                $post = $app->request->getPost();

                //reemplazo el id que esta llegando por null para que se cree uno nuevo
                $post['id'] = null;

                //Validar que existe un representante
                $validar_representante = true;

                if ($post["representante"] == "true") {
                    //Valido si enviaron el id del participante
                    $validacion = "";
                    if (is_numeric($post["id"])) {
                        $validacion = "id<>" . $post["id"] . " AND ";
                    }

                    $representante = Participantes::findFirst($validacion . " participante_padre=" . $post["participante"] . " AND representante = true AND active IN (TRUE,FALSE)");
                    if ($representante->id > 0) {
                        $validar_representante = false;
                    }
                }

                if ($validar_representante) {

                    //Consulto el participante padre
                    $participante_padre = Participantes::findFirst($post["participante"]);

                    //se crea el nuevo participante
                    //Creo el objeto del participante de persona natural
                    $participante_nuevo = new Participantes();
                    $participante_nuevo->creado_por = $user_current["id"];
                    $participante_nuevo->fecha_creacion = date("Y-m-d H:i:s");
                    $participante_nuevo->participante_padre = $post["participante"];
                    $participante_nuevo->usuario_perfil = $participante_padre->usuario_perfil;
                    //
                    $participante_nuevo->active = FALSE;
                    $participante_nuevo->fecha_cambio = date("Y-m-d H:i:s");
                    //agrego el id del participante que reemplaza
                    $participante_nuevo->id_participante_reemplazo = $post["id_participante_reemplazo"];
                    $participante_nuevo->aprobacion_cambio = FALSE;

                    //se edita el anterior
//                    $post["actualizado_por"] = $user_current["id"];
//                    $post["fecha_actualizacion"] = date("Y-m-d H:i:s");

                    $post["representante"] = $post["representante"] === 'true' ? true : false;
                    $post["director"] = $post["director"] === 'true' ? true : false;

                    if ($participante_nuevo->save($post) === false) {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"xx  Error en el controlador Personasnaturales en el método reemplazar_integrante, error al crear el integrante como (' . $request->getPost('tipo') . ') en la convocatoria(' . $request->getPost('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error";
                    } else {
//                        //luego de guardar el nuevo participante inactivo el anterior
                        $participante_anterior = Participantes::findFirst($post["id_participante_reemplazo"]);
//                        $participante_anterior->active = TRUE;
                        $participante_anterior->fecha_cambio = date("Y-m-d H:i:s");
                        $participante_anterior->id_participante_reemplazo = $participante_nuevo->id;
                        $participante_anterior->justificacion_cambio = $post["justificacion_cambio"];
                        
                        $participante_nuevo->aprobacion_cambio = FALSE;

                        if ($participante_anterior->save() === false) {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"xx  Error en el controlador Personasnaturales en el método reemplazar_integrante, error al crear el integrante como (' . $request->getPost('tipo') . ') en la convocatoria(' . $request->getPost('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error";
                        } else {
                            $logger->info('"token":"{token}","user":"{user}","message":"xxx Retorno el controlador Personasnaturales en el método reemplazar_integrante, se reemplaza el integrante con exito (' . $request->getPost('tipo') . ') en la convocatoria(' . $request->getPost('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();

//                        echo json_encode($participante_nuevo);
                            echo $participante_nuevo->id;
                        }
                    }
                } else {
                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método reemplazar_integrante, ya existe el representante como (' . $request->getPost('tipo') . ') en la convocatoria(' . $request->getPost('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error_representante";
                }
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método reemplazar_integrante, acceso denegado como (' . $request->getPost('tipo') . ') en la convocatoria(' . $request->getPost('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método reemplazar_integrante, token caduco como (' . $request->getPost('tipo') . ') en la convocatoria(' . $request->getPost('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método reemplazar_integrante, error metodo como (' . $request->get('tipo') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Carga los integrantes de las agrupaciones
$app->get('/cargar_tabla_integrantes_cambio', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador Personasnaturales en el método cargar_tabla_integrantes, carga la tabla de los integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto la propuesta solicitada
                $conditions = ['id' => $request->get('p'), 'active' => true];
                $propuesta = Propuestas::findFirst(([
                            'conditions' => 'id=:id: AND active=:active:',
                            'bind' => $conditions,
                ]));

                //Defino columnas para el orden desde la tabla html
                $columns = array(
                    0 => 'p.tipo_documento',
                    1 => 'p.numero_documento',
                    2 => 'p.primer_nombre',
                    3 => 'p.segundo_nombre',
                    4 => 'p.primer_apellido',
                    5 => 'p.segundo_apellido',
                    6 => 'p.rol',
                    7 => 'p.id',
                    8 => 'p.representante',
                    9 => 'p.director',
                    10 => 'p.id_participante_reemplazo'
                );

                $where .= " INNER JOIN Tiposdocumentos AS td ON td.id=p.tipo_documento";
                $where .= " WHERE p.id <> " . $propuesta->participante . " AND p.participante_padre = " . $propuesta->participante . " AND tipo='" . $request->get('tipo') . "' AND p.active = TRUE AND p.representante = FALSE";
                //Condiciones para la consulta

                if (!empty($request->get("search")['value'])) {
                    $where .= " AND ( UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[4] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[5] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[6] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }

            $href_propuesta = "documentacion.html?m=pn" . "&id=" . $propuesta->convocatoria . "&p=" . $request->get('p');


                $boton_carga_documentos = "<a href=".$href_propuesta."><button style=\"margin: 0 0 5px 0\" type=\"button\" class=\"btn btn-warning btn_tooltip\" title=\"Realizar cambio documentación integrantes de integrante\"><span class=\"fa fa-file-text-o\"></span></button></a>";

                //Defino el sql del total y el array de datos
                $sqlTot = "SELECT count(*) as total FROM Participantes AS p";
                $sqlRec = "SELECT td.descripcion AS tipo_documento," . $columns[1] . "," . $columns[2] . "," . $columns[3] . " ," . $columns[4] . "," . $columns[5] . "," . $columns[6] . "," . $columns[7] . "," . $columns[8] . "," . $columns[9] . "," . $columns[10] . " FROM Participantes AS p";

                //concarnar search sql if value exist
                if (isset($where) && $where != '') {

                    $sqlTot .= $where;
                    $sqlRec .= $where;
                }

                //Concarno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY p.representante DESC  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";

                //ejecuto el total de registros actual
                $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

                //creo el array
                $json_data = array(
                    "draw" => intval($request->get("draw")),
                    "recordsTotal" => intval($totalRecords["total"]),
                    "recordsFiltered" => intval($totalRecords["total"]),
                    "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
                );
                //retorno el array en json
                $logger->info('"token":"{token}","user":"{user}","message":"Retorna en el controlador Personasnaturales en el método cargar_tabla_integrantes, retorna la tabla de los integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();

                echo json_encode($json_data);
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método cargar_tabla_integrantes, acceso denegado en el metodo cargar_tabla_integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método cargar_tabla_integrantes, token caduco en el metodo cargar_tabla_integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método cargar_tabla_integrantes, error en el metodo como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
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

