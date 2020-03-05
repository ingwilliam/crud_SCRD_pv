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

//Defino las variables principales de conexion
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
        "host" => $config->database->host,"port" => $config->database->port,
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

//Metodo que consulta el participante, con el cual va a registar la propuesta
//Se realiza la busqueda del participante
//Si no existe en inicial lo enviamos a crear el perfil
//Si existe el participante asociado a la propuesta se retorna
$app->get('/buscar_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->get('modulo') . "&token=" . $request->get('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

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

                    $logger->info('"token":"{token}","user":"{user}","message":"El usuario ya tiene el perfil (' . $request->get('m') . ') asociado a la propuesta (' . $request->get('p') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

                    //Consulto el participante inicial
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de acuerdo al parametro
                    if (isset($participante->id)) {

                        $logger->info('"token":"{token}","user":"{user}","message":"El usuario ya tiene el participante inicial (' . $request->get('m') . ') asociado a la propuesta (' . $request->get('p') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

                        //Consulto la convocatoria
                        $convocatoria = Convocatorias::findFirst($request->get('conv'));

                        //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                        $nombre_convocatoria = $convocatoria->nombre;
                        $nombre_categoria = "";
                        $modalidad = $convocatoria->modalidad;
                        if ($convocatoria->convocatoria_padre_categoria > 0) {
                            $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                            $nombre_categoria = $convocatoria->nombre;
                            $modalidad = $convocatoria->getConvocatorias()->modalidad;
                        }

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

                                $logger->info('"token":"{token}","user":"{user}","message":"Se consulta la propuesta propuesta (' . $propuesta->id . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

                                //Consulto los parametros adicionales para el formulario de la propuesta
                                $conditions = ['convocatoria' => $convocatoria->id, 'active' => true];
                                $parametros = Convocatoriaspropuestasparametros::find(([
                                    'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                    'bind' => $conditions,
                                    'order' => 'orden ASC',
                                ]));
                                $propuestaparametros = Propuestasparametros::find("propuesta=" . $propuesta->id);


                                //Creo el array de la propuesta
                                $array = array();
                                $array["estado"] = $propuesta->estado;
                                $array["propuesta"]["nombre_participante"] = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;
                                $array["propuesta"]["tipo_participante"] = $tipo_participante;
                                $array["propuesta"]["nombre_convocatoria"] = $nombre_convocatoria;
                                $array["propuesta"]["nombre_categoria"] = $nombre_categoria;
                                $array["propuesta"]["modalidad"] = $modalidad;
                                $array["propuesta"]["estado"] = $propuesta->getEstados()->nombre;
                                $array["propuesta"]["nombre"] = $propuesta->nombre;
                                $array["propuesta"]["resumen"] = $propuesta->resumen;
                                $array["propuesta"]["objetivo"] = $propuesta->objetivo;
                                $array["propuesta"]["bogota"] = $propuesta->bogota;
                                $array["propuesta"]["localidad"] = $propuesta->localidad;
                                $array["propuesta"]["upz"] = $propuesta->upz;
                                $array["propuesta"]["barrio"] = $propuesta->barrio;
                                $array["propuesta"]["ejecucion_menores_edad"] = $propuesta->ejecucion_menores_edad;
                                $array["propuesta"]["porque_medio"] = $propuesta->porque_medio;
                                $array["propuesta"]["id"] = $propuesta->id;
                                //Recorro los valores de los parametros con el fin de ingresarlos al formulario
                                foreach ($propuestaparametros as $pp) {
                                    $array["propuesta"]["parametro[" . $pp->convocatoriapropuestaparametro . "]"] = $pp->valor;
                                }
                                $array["localidades"] = Localidades::find("active=true");
                                $array["parametros"] = $parametros;
                                $tabla_maestra = Tablasmaestras::find("active=true AND nombre='medio_se_entero'");
                                $array["medio_se_entero"] = explode(",", $tabla_maestra[0]->valor);

                                //Creo los parametros obligatorios del formulario
                                $options = array(
                                    "fields" => array(
                                        "nombre" => array(
                                            "validators" => array(
                                                "notEmpty" => array("message" => "El nombre de la propuesta es requerido.")
                                            )
                                        ),
                                        "porque_medio[]" => array(
                                            "validators" => array(
                                                "notEmpty" => array("message" => "El medio por el cual se enteró de esta convocatoria es requerido.")
                                            )
                                        )
                                    )
                                );

                                if ($modalidad != 4) {
                                    $options["fields"] += array(
                                        "resumen" => array(
                                            "validators" => array(
                                                "notEmpty" => array("message" => "El resumen de la propuesta es requerido.")
                                            )
                                        ),
                                        "objetivo" => array(
                                            "validators" => array(
                                                "notEmpty" => array("message" => "El objetivo de la propuesta es requerido.")
                                            )
                                        )
                                    );
                                }

                                foreach ($parametros as $k => $v) {
                                    if ($v->obligatorio) {
                                        $options["fields"] += array(
                                            "parametro[" . $v->id . "]" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El campo es requerido.")
                                                )
                                            )
                                        );
                                    }
                                }


                                $array["validator"] = $options;

                                $array["upzs"] = array();
                                $array["barrios"] = array();
                                if (isset($propuesta->localidad)) {
                                    $array["upzs"] = Upzs::find("active=true AND localidad=" . $propuesta->localidad);
                                    $array["barrios"] = Barrios::find("active=true AND localidad=" . $propuesta->localidad);
                                }

                                //Registro la accion en el log de convocatorias
                                $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();

                                //Retorno el array
                                echo json_encode($array);
                            } else {
                                //Registro la accion en el log de convocatorias           
                                $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PN asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "error_cod_propuesta";
                                exit;
                            }
                        } else {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PN asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_cod_propuesta";
                            exit;
                        }
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_perfil";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/buscar_propuesta_visualizar_formulario', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->get('modulo') . "&token=" . $request->get('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                //Consulto la convocatoria
                $convocatoria = Convocatorias::findFirst($request->get('id'));

                //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                $nombre_convocatoria = $convocatoria->nombre;
                $nombre_categoria = "";
                $modalidad = $convocatoria->modalidad;
                if ($convocatoria->convocatoria_padre_categoria > 0) {
                    $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                    $nombre_categoria = $convocatoria->nombre;
                    $modalidad = $convocatoria->getConvocatorias()->modalidad;
                }

                //Consulto los parametros adicionales para el formulario de la propuesta
                $conditions = ['convocatoria' => $convocatoria->id, 'active' => true];
                $parametros = Convocatoriaspropuestasparametros::find(([
                    'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                    'bind' => $conditions,
                    'order' => 'orden ASC',
                ]));
                
                //Creo el array de la propuesta
                $array = array();
                $array["estado"] = "";
                $array["propuesta"]["nombre_participante"] = "";
                $array["propuesta"]["tipo_participante"] = "";
                $array["propuesta"]["nombre_convocatoria"] = $nombre_convocatoria;
                $array["propuesta"]["nombre_categoria"] = $nombre_categoria;
                $array["propuesta"]["modalidad"] = $modalidad;
                $array["propuesta"]["estado"] = "";
                $array["propuesta"]["nombre"] = "";
                $array["propuesta"]["resumen"] = "";
                $array["propuesta"]["objetivo"] = "";
                $array["propuesta"]["bogota"] = true;
                $array["propuesta"]["localidad"] = "";
                $array["propuesta"]["upz"] = "";
                $array["propuesta"]["barrio"] = "";
                $array["propuesta"]["ejecucion_menores_edad"] = true;
                $array["propuesta"]["porque_medio"] = "";
                $array["propuesta"]["id"] = "";
                $array["localidades"] = Localidades::find("active=true");
                $array["parametros"] = $parametros;
                $tabla_maestra = Tablasmaestras::find("active=true AND nombre='medio_se_entero'");
                $array["medio_se_entero"] = explode(",", $tabla_maestra[0]->valor);

                //Creo los parametros obligatorios del formulario
                $options = array(
                    "fields" => array(
                        "nombre" => array(
                            "validators" => array(
                                "notEmpty" => array("message" => "El nombre de la propuesta es requerido.")
                            )
                        ),
                        "porque_medio[]" => array(
                            "validators" => array(
                                "notEmpty" => array("message" => "El medio por el cual se enteró de esta convocatoria es requerido.")
                            )
                        )
                    )
                );

                if ($modalidad != 4) {
                    $options["fields"] += array(
                        "resumen" => array(
                            "validators" => array(
                                "notEmpty" => array("message" => "El resumen de la propuesta es requerido.")
                            )
                        ),
                        "objetivo" => array(
                            "validators" => array(
                                "notEmpty" => array("message" => "El objetivo de la propuesta es requerido.")
                            )
                        )
                    );
                }

                foreach ($parametros as $k => $v) {
                    if ($v->obligatorio) {
                        $options["fields"] += array(
                            "parametro[" . $v->id . "]" => array(
                                "validators" => array(
                                    "notEmpty" => array("message" => "El campo es requerido.")
                                )
                            )
                        );
                    }
                }


                $array["validator"] = $options;

                $array["upzs"] = array();
                $array["barrios"] = array();
                if (isset($propuesta->localidad)) {
                    $array["upzs"] = Upzs::find("active=true AND localidad=" . $propuesta->localidad);
                    $array["barrios"] = Barrios::find("active=true AND localidad=" . $propuesta->localidad);
                }

                //Registro la accion en el log de convocatorias
                $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();

                //Retorno el array
                echo json_encode($array);
                    
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/editar_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo editar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

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

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                //parametros de la peticion
                $post = $app->request->getPost();
                $propuesta = Propuestas::findFirst($post["id"]);
                $post["porque_medio"] = json_encode($post["porque_medio"]);
                $post["actualizado_por"] = $user_current["id"];
                $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                
                if($post["localidad"]=="")
                {
                    unset($post["localidad"]);
                }
                
                if($post["upz"]=="")
                {
                    unset($post["upz"]);
                }
                
                if($post["barrio"]=="")
                {
                    unset($post["barrio"]);
                }

                if ($propuesta->save($post) === false) {
                    $logger->error('"token":"{token}","user":"{user}","message":"Se genero un error al editar la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error";
                } else {

                    //Recorrmos los parametros dinamicos                    
                    foreach ($post["parametro"] as $k => $v) {
                        //Consulto si exite el parametro a la propuestas
                        $parametro_actual = Propuestasparametros::findFirst("convocatoriapropuestaparametro=" . $k . " AND propuesta = " . $propuesta->id);
                        if (isset($parametro_actual->id)) {
                            $parametro = $parametro_actual;
                        } else {
                            $parametro = new Propuestasparametros();
                        }

                        //Cargo lo valores actuales
                        $array_save = array();
                        $array_save["convocatoriapropuestaparametro"] = $k;
                        $array_save["propuesta"] = $propuesta->id;
                        $array_save["valor"] = $v;

                        //Valido si existe para relacionar los campos de usuario
                        if (isset($parametro->id)) {
                            $parametro->actualizado_por = $user_current["id"];
                            $parametro->fecha_actualizacion = date("Y-m-d H:i:s");
                        } else {
                            $parametro->creado_por = $user_current["id"];
                            $parametro->fecha_creacion = date("Y-m-d H:i:s");
                        }

                        //Guardo los parametros de la convocatoria
                        if ($parametro->save($array_save) == false) {
                            foreach ($parametro->getMessages() as $message) {
                                $logger->info('"token":"{token}","user":"{user}","message":"Se genero un error al editar el parametro (' . $parametro->id . ') en la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')" (' . $message . ').', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                        } else {
                            $logger->info('"token":"{token}","user":"{user}","message":"Se edito con exito el parametro (' . $parametro->id . ') en la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        }
                    }

                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Se edito con exito la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo $propuesta->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo editar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo editar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo editar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/inscribir_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo inscribir_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

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

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                //Consulto la convocatoria
                $id=$request->getPut('conv');
                $convocatoria = Convocatorias::findFirst($id);

                //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                    $id = $convocatoria->getConvocatorias()->id;                    
                }                
                
                //Consulto la fecha de cierre del cronograma de la convocatoria
                $conditions = ['convocatoria' => $id, 'active' => true, 'tipo_evento' => 12];
                $fecha_cierre_real = Convocatoriascronogramas::findFirst(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                            'bind' => $conditions,
                ]));
                $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                if ($fecha_actual > $fecha_cierre) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"La convocatoria(' . $request->getPut('conv') . ') no esta activa, la fecha de cierre es (' . $fecha_cierre_real->fecha_fin . ')", en el metodo inscribir_propuesta', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error_fecha_cierre";
                } else {
                    //parametros de la peticion
                    $propuesta = Propuestas::findFirst($request->getPut('id'));
                    if ($propuesta->estado == 7) {

                        //Consulto el total de propuesta con el fin de generar el codigo de la propuesta
                        $sql_total_propuestas = "SELECT 
                                                    COUNT(p.id) as total_propuestas
                                            FROM Propuestas AS p                                
                                            WHERE
                                            p.estado = 8 AND p.convocatoria=" . $convocatoria->id;

                        $total_propuesta = $app->modelsManager->executeQuery($sql_total_propuestas)->getFirst();
                        $codigo_propuesta = $convocatoria->id . "-" . (str_pad($total_propuesta->total_propuestas + 1, 3, "0", STR_PAD_LEFT));

                        $post["estado"] = 8;
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                        $propuesta->codigo = $codigo_propuesta;

                        if ($propuesta->save($post) === false) {
                            $logger->error('"token":"{token}","user":"{user}","message":"Se genero un error al editar la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error";
                        } else {

                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Se inscribio la propuesta con exito (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo $propuesta->id;
                        }
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no esta en estado Registrada en el metodo inscribir_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error_estado";
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo inscribir_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo inscribir_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo inscribir_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/subsanar_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo subsanar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

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

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                //Consulto la convocatoria
                $id=$request->getPut('conv');
                $convocatoria = Convocatorias::findFirst($id);

                //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                    $id = $convocatoria->getConvocatorias()->id;                    
                }                
                                
                //Consulto la propuesta
                $propuesta = Propuestas::findFirst($request->getPut('id'));
                
                $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                $fecha_inicio_subsanacion = strtotime($propuesta->fecha_inicio_subsanacion, time());
                $fecha_fin_subsanacion = strtotime($propuesta->fecha_fin_subsanacion, time());
                
                if (($fecha_actual >= $fecha_inicio_subsanacion) && ($fecha_actual <= $fecha_fin_subsanacion))
                {
                    if ($propuesta->estado == 22) {

                        $post["estado"] = 31;
                        $post["fecha_subsanacion"] = date("Y-m-d H:i:s");                        

                        if ($propuesta->save($post) === false) {
                            $logger->error('"token":"{token}","user":"{user}","message":"Se genero un error al editar la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error";
                        } else {

                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Se inscribio la propuesta con exito (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo $propuesta->id;
                        }
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no esta en estado Subsanación Recibida en el metodo subsanar_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error_estado";
                    }
                                        
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"La convocatoria(' . $request->getPut('conv') . ') no esta activa, el periodo de subsanacion es  (' . $propuesta->fecha_inicio_subsanacion . ' a ' . $propuesta->fecha_fin_subsanacion . ')", en el metodo subsanar_propuesta', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error_fecha_cierre";                   
                    
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo subsanar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo subsanar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo subsanar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/anular_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo anular_propuesta"', ['user' => '', 'token' => $request->getPost('token')]);

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

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                //parametros de la peticion
                $propuesta = Propuestas::findFirst($request->getPost('propuesta'));
                
                if ($propuesta->estado == 7) {

                    //Consulto el total de propuesta con el fin de generar el codigo de la propuesta
                    
                    $post["estado"] = 20;
                    $post["actualizado_por"] = $user_current["id"];
                    $post["fecha_actualizacion"] = date("Y-m-d H:i:s");                    

                    if ($propuesta->save($post) === false) {
                        $logger->error('"token":"{token}","user":"{user}","message":"Se genero un error al editar la propuesta (' . $request->getPost('propuesta') . ') en el metodo anular_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error";
                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Se anulo la propuesta con exito (' . $request->getPost('propuesta') . ') en el metodo anular_propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo $propuesta->id;
                    }
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPost('propuesta') . ') no esta en estado Registrada en el metodo anular_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error_estado";
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo anular_propuesta al anular la propuesta (' . $request->getPut('propuesta') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo anular_propuesta al anular la propuesta (' . $request->getPut('propuesta') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo anular_propuesta al anular la propuesta (' . $request->getPost('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
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