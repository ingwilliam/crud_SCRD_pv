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

//Valida el acceso a la convocatoria
//Que este antes de la fecha de cierre
//Confirmar el total de posibles numero de propuesta inscritas por la convocatoria
//Verificar que no tenga mas de 2 estimulos ganados
$app->post('/validar_acceso/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a validar acceso a la convocatoria"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);

            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst("id=" . $id . " AND active=TRUE");

            //Valido si la convocatoria tiene categorias y tiene diferentes requisitos con el fin de buscar la fecha de cierre
            $id_convocatoria = $convocatoria->id;

            //Si la convocatoria seleccionada es categoria y no es especial invierto los id
            if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                $id_convocatoria = $convocatoria->getConvocatorias()->id;
            }

            //Consulto la fecha de cierre del cronograma de la convocatoria
            $conditions = ['convocatoria' => $id_convocatoria, 'active' => true, 'tipo_evento' => 12];
            $fecha_cierre_real = Convocatoriascronogramas::findFirst(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                        'bind' => $conditions,
            ]));
            $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
            $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
            if ($fecha_actual > $fecha_cierre) {
                echo "ingresar";
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La convocatoria(' . $id . ') no ha cerrado, la fecha de cierre es (' . $fecha_cierre_real->fecha_fin . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "error_fecha_cierre";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo validar_acceso ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/select_convocatorias', function () use ($app, $config, $logger) {
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

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $array_convocatorias = Convocatorias::find("anio=" . $request->get('anio') . " AND entidad=" . $request->get('entidad') . " AND convocatoria_padre_categoria IS NULL AND estado > 4 AND modalidad <> 2 AND active=TRUE");

                $array_interno = array();
                foreach ($array_convocatorias as $convocatoria) {
                    $array_interno[$convocatoria->id]["id"] = $convocatoria->id;
                    $array_interno[$convocatoria->id]["nombre"] = $convocatoria->nombre;
                    $array_interno[$convocatoria->id]["tiene_categorias"] = $convocatoria->tiene_categorias;
                    $array_interno[$convocatoria->id]["diferentes_categorias"] = $convocatoria->diferentes_categorias;
                }

                //Registro la accion en el log de convocatorias
                $logger->info('"token":"{token}","user":"{user}","message":"El controller PropuestasGanadoras retorna en el método select_convocatorias, creo el select de convocatorias"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();

                echo json_encode($array_interno);
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método select_convocatorias, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->get('token')]);               
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias                       
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método select_convocatorias, token caduco"', ['user' => "", 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método select_convocatorias, ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/select_categorias', function () use ($app, $config, $logger) {
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

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $array_convocatorias = Convocatorias::find("convocatoria_padre_categoria=" . $request->get('conv') . " AND active=TRUE");

                $array_interno = array();
                foreach ($array_convocatorias as $convocatoria) {
                    $array_interno[$convocatoria->id]["id"] = $convocatoria->id;
                    $array_interno[$convocatoria->id]["nombre"] = $convocatoria->nombre;
                }

                //Registro la accion en el log de convocatorias                
                $logger->info('"token":"{token}","user":"{user}","message":"El controller PropuestasGanadoras retorna en el método select_categorias, creo el select de categorias"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();

                echo json_encode($array_interno);
            } else {
                //Registro la accion en el log de convocatorias                           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método select_categorias, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->get('token')]);               
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método select_categorias, token caduco"', ['user' => "", 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método select_categorias, ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/buscar_propuestas', function () use ($app, $config, $logger) {
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

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $user_current = json_decode($token_actual->user_current, true);
                
                $params = json_decode($request->get('params'), true);
                $consultar = true;

                //Valido la fecha de cierre de la propuesta buscada por el codigo
                if ($params["codigo"] != "") {

                    //Consulto la propuesta por codigo
                    $conditions = ['codigo' => $params["codigo"], 'active' => true];
                    $propuesta = Propuestas::findFirst(([
                                'conditions' => 'codigo=:codigo: AND active=:active:',
                                'bind' => $conditions,
                    ]));

                    if ($propuesta->id != null) {
                        $convocatoria = Convocatorias::findFirst("id=" . $propuesta->convocatoria . " AND active=TRUE");

                        //Valido si la convocatoria tiene categorias y tiene diferentes requisitos con el fin de buscar la fecha de cierre
                        $id_convocatoria = $convocatoria->id;
                        $seudonimo = $convocatoria->seudonimo;

                        //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                        if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                            $id_convocatoria = $convocatoria->getConvocatorias()->id;
                            $seudonimo = $convocatoria->getConvocatorias()->seudonimo;
                        }

                        //Consulto la fecha de cierre del cronograma de la convocatoria
                        $conditions = ['convocatoria' => $id_convocatoria, 'active' => true, 'tipo_evento' => 12];
                        $fecha_cierre_real = Convocatoriascronogramas::findFirst(([
                                    'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                                    'bind' => $conditions,
                        ]));

                        $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                        $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                        if ($fecha_actual > $fecha_cierre) {
                            $consultar = true;
                        } else {
                            $consultar = false;
                        }
                    } else {
                        $consultar = false;
                    }
                } else {
                    //Consulto la convocatoria
                    $convocatoria = Convocatorias::findFirst("id=" . $params["convocatoria"] . " AND active=TRUE");

                    //Valido si la convocatoria tiene categorias y tiene diferentes requisitos con el fin de buscar la fecha de cierre
                    $id_convocatoria = $convocatoria->id;
                    $seudonimo = $convocatoria->seudonimo;

                    //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                    if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                        $id_convocatoria = $convocatoria->getConvocatorias()->id;
                        $seudonimo = $convocatoria->getConvocatorias()->seudonimo;
                    }


                    //Consulto la fecha de cierre del cronograma de la convocatoria
                    $conditions = ['convocatoria' => $id_convocatoria, 'active' => true, 'tipo_evento' => 12];
                    $fecha_cierre_real = Convocatoriascronogramas::findFirst(([
                                'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                                'bind' => $conditions,
                    ]));

                    $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                    $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                    if ($fecha_actual > $fecha_cierre) {
                        $consultar = true;
                    } else {
                        $consultar = false;
                    }
                }

                if ($consultar == true) {
                    //Consulto el usuario actual                    
                    $user_current = Usuarios::findFirst($user_current["id"]);
                    //Creo array de entidades que puede acceder el usuario
                    $array_usuarios_entidades = "";
                    foreach ($user_current->getUsuariosentidades() as $usuario_entidad) {
                        $array_usuarios_entidades = $array_usuarios_entidades . $usuario_entidad->entidad . ",";
                    }
                    $array_usuarios_entidades = substr($array_usuarios_entidades, 0, -1);

                    //Creo array de areas que puede acceder el usuario
                    $array_usuarios_areas = "";
                    foreach ($user_current->getUsuariosareas() as $usuario_area) {
                        $array_usuarios_areas = $array_usuarios_areas . $usuario_area->area . ",";
                    }
                    $array_usuarios_areas = substr($array_usuarios_areas, 0, -1);

                    $where .= " WHERE p.active=true AND p.estado IN (24,33,34,44)  AND ( c.area IN ($array_usuarios_areas) OR c.area IS NULL) ";

                    if ($params["convocatoria"] != "") {
                        $convocatoria = Convocatorias::findFirst("id=" . $params["convocatoria"] . " AND active=TRUE");

                        //Valido si la convocatoria tiene categorias y tiene diferentes requisitos con el fin de buscar la fecha de cierre
                        $id_convocatoria = $convocatoria->id;
                        $seudonimo = $convocatoria->seudonimo;

                        $where .= " AND p.convocatoria=$id_convocatoria ";
                    }


                    if ($params["estado"] != "") {
                        $where .= " AND p.estado=" . $params["estado"];
                    }

                    if ($params["codigo"] != "") {
                        $where .= " AND p.codigo='" . $params["codigo"] . "'";
                    }

                    $participante = "CONCAT(par.primer_nombre,' ',par.segundo_nombre,' ',par.primer_apellido,' ',par.segundo_apellido)";
                    if ($seudonimo) {
                        $participante = "p.codigo";
                    }

                    //Defino el sql del total y el array de datos
                    $sqlTot = "SELECT count(*) as total FROM Propuestas AS p "
                            . "INNER JOIN Estados AS est ON est.id=p.estado "
                            . "INNER JOIN Participantes AS par ON par.id=p.participante "
                            . "INNER JOIN Convocatorias AS c ON c.id=p.convocatoria "
                            . "INNER JOIN Entidades AS e ON e.id=c.entidad  AND e.id IN ($array_usuarios_entidades)"
                            . "LEFT JOIN Convocatorias AS cat ON cat.id=c.convocatoria_padre_categoria "
                            . "INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil "
                            . "INNER JOIN Perfiles AS per ON per.id=up.perfil ";

                    $sqlRec = "SELECT "
                            . "est.nombre AS estado,"
                            . "c.anio ,"
                            . "e.nombre AS entidad ,"
                            . "c.nombre AS convocatoria,"
                            . "cat.nombre AS categoria,"
                            . "p.id AS id_propuesta,"
                            . "p.convocatoria AS id_convocatoria,"
                            . "p.nombre AS propuesta,"
                            . "p.codigo,"
                            . "per.id AS perfil ,"
                            . "per.nombre AS tipo_participante ,"
                            . "$participante AS participante,"
                            . "concat('<button title=\"',p.id,'\" type=\"button\" class=\"btn btn-warning cargar_formulario\" data-toggle=\"modal\" data-target=\"#ver_propuesta\"><span class=\"glyphicon glyphicon-star \"></span></button>') as ver_propuesta "                            
                            . "FROM Propuestas AS p "
                            . "INNER JOIN Estados AS est ON est.id=p.estado "
                            . "INNER JOIN Participantes AS par ON par.id=p.participante "
                            . "INNER JOIN Convocatorias AS c ON c.id=p.convocatoria "
                            . "INNER JOIN Entidades AS e ON e.id=c.entidad  AND e.id IN ($array_usuarios_entidades)"
                            . "LEFT JOIN Convocatorias AS cat ON cat.id=c.convocatoria_padre_categoria "
                            . "INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil "
                            . "INNER JOIN Perfiles AS per ON per.id=up.perfil ";

                    //concatenate search sql if value exist
                    if (isset($where) && $where != '') {

                        $sqlTot .= $where;
                        $sqlRec .= $where;
                    }

                    //Concateno el orden y el limit para el paginador
                    $sqlRec .= " ORDER BY p.codigo LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";

                    //ejecuto el total de registros actual
                    $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

                    //creo el array
                    $json_data = array(
                        "draw" => intval($request->get("draw")),
                        "recordsTotal" => intval($totalRecords["total"]),
                        "recordsFiltered" => intval($totalRecords["total"]),
                        "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
                    );
                    
                    $logger->info('"token":"{token}","user":"{user}","message":"El controller PropuestasGanadoras retorna en el método buscar_propuestas, creo el select de las propuestas"', ['user' => $user_current->username, 'token' => $request->get('token')]);
                    $logger->close();
                    
                    //retorno el array en json
                    echo json_encode($json_data);
                } else {
                    //creo el array
                    $json_data = array(
                        "draw" => intval($request->get("draw")),
                        "recordsTotal" => null,
                        "recordsFiltered" => null,
                        "data" => array()   // total data array
                    );
                    //retorno el array en json
                    echo json_encode($json_data);
                }
            } else {
                //Registro la accion en el log de convocatorias                           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método buscar_propuestas, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->get('token')]);               
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias                       
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método buscar_propuestas, token caduco"', ['user' => "", 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método buscar_propuestas, ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);        
        $logger->close();
        echo "error_metodo " . $ex->getMessage();
    }
}
);

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

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto el participante inicial
                $propuesta = Propuestas::findFirst("id=" . $request->get('idp'));

                //Creo el array de la propuesta
                $array = array();
                $array["participante"] = $propuesta->participante;
                $array["codigo"] = $propuesta->codigo;
                //Creo los array de los select del formulario
                $array["tipo_documento"] = Tiposdocumentos::find("active=true");
                //Registro la accion en el log de convocatorias                
                $logger->info('"token":"{token}","user":"{user}","message":"El controller PropuestasGanadoras retorna en el método formulario_integrante, creo el select de convocatorias"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();

                //Retorno el array
                echo json_encode($array);
                    
            } else {
                //Registro la accion en el log de convocatorias                
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método formulario_integrante, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->get('token')]);               
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias            
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método formulario_integrante, token caduco"', ['user' => "", 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias        
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método formulario_integrante, ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Carga los integrantes de las agrupaciones
$app->get('/cargar_tabla_integrantes', function () use ($app, $config, $logger) {
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

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la propuesta solicitada
                $conditions = ['id' => $request->get('propuesta'), 'active' => true];
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
                );

                $where .= " INNER JOIN Tiposdocumentos AS td ON td.id=p.tipo_documento";
                $where .= " WHERE (p.id = " . $propuesta->participante . " OR p.participante_padre = " . $propuesta->participante . ") AND tipo IN ('Junta','Integrante','Participante') AND tipo_documento<>7 AND tipo_documento IS NOT NULL";
                //Condiciones para la consulta

                if (!empty($request->get("search")['value'])) {
                    $where .= " AND ( UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[4] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[5] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[6] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }

                //Defino el sql del total y el array de datos
                $sqlTot = "SELECT count(*) as total FROM Participantes AS p";
                $sqlRec = "SELECT td.descripcion AS tipo_documento," . $columns[1] . "," . $columns[2] . "," . $columns[3] . " ," . $columns[4] . "," . $columns[5] . "," . $columns[6] . "," . $columns[7] . ",concat('<button title=\"',p.id,'\" type=\"button\" class=\"btn btn-warning cargar_formulario\" data-toggle=\"modal\" data-target=\"#nuevo_evento\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones , concat('<input title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_categoria\" />') as activar_registro FROM Participantes AS p";

                //concarnar search sql if value exist
                if (isset($where) && $where != '') {

                    $sqlTot .= $where;
                    $sqlRec .= $where;
                }

                //Concarno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY " . $columns[$request->get('order')[0]['column']] . "   " . $request->get('order')[0]['dir'] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";

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
                echo json_encode($json_data);
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo cargar_tabla_integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo cargar_tabla_integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo cargar_tabla_integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Busca el registro
$app->get('/consultar_propuesta', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Si existe consulto la convocatoria
            if ($request->get('id')) {
                $propuesta = Propuestas::findFirst($request->get('id'));
                //Retorno el array
                echo json_encode($propuesta);
            
            }            
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

// Crear el inetegrante
$app->post('/editar_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

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

                //Trae los datos del formulario por post
                $post = $app->request->getPost();

                //Valido si existe para editar o crear
                if (is_numeric($post["id"])) {
                    $propuesta = Propuestas::findFirst($post["id"]);
                    $post["actualizado_por"] = $user_current["id"];
                    $post["fecha_actualizacion"] = date("Y-m-d H:i:s");                    
                    
                    if($post["fecha_inicio_ejecucion"]=="")
                    {
                        unset($post["fecha_inicio_ejecucion"]);
                    }
                    
                    if($post["fecha_fin_ejecucion"]=="")
                    {
                        unset($post["fecha_fin_ejecucion"]);
                    }                    
                    
                    if ($propuesta->save($post) === false) {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controller PropuestasGanadoras en el método editar_propuesta, al crear y/o editar la propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error";
                    } else {
                        //Registro la accion en el log de convocatorias                    
                        $logger->info('"token":"{token}","user":"{user}","message":"El controller PropuestasGanadoras retorna en el método editar_propuesta, creo y/o edito la propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        

                        $convocatoria = Convocatorias::findFirst($propuesta->convocatoria);
                        //Solo se puede pasar adjudicada si la convocatoria esta publicada
                        if($convocatoria->estado==5){
                            $convocatoria->estado=6;
                            if ($convocatoria->save($convocatoria) === false) {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controller PropuestasGanadoras en el método editar_propuesta, al cambiar el estado de la convocatoria"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                        
                            }
                            else
                            {
                                $phql = "UPDATE Convocatorias SET estado=:estado:,habilitar_cronograma=:habilitar_cronograma: WHERE id IN (:id:,:convocatoria_padre_categoria:)";
                                $convocatoria_padre_categoria=$convocatoria->id;
                                if($convocatoria_padre_categoria!=null && $convocatoria_padre_categoria>0)
                                {
                                    $convocatoria_padre_categoria=$convocatoria->convocatoria_padre_categoria;
                                }
                                
                                $app->modelsManager->executeQuery($phql, array(
                                    'id' => $convocatoria->id,
                                    'convocatoria_padre_categoria' => $convocatoria_padre_categoria,
                                    'estado' => 6,
                                    'habilitar_cronograma' => FALSE
                                ));
                            }
                        }                                                
                        $logger->close();
                        echo $propuesta->id;
                    }                                        
                }                
            } else {
                //Registro la accion en el log de convocatorias                
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método editar_propuesta, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->get('token')]);               
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método editar_propuesta, token caduco"', ['user' => "", 'token' => $request->get('token')]);                        
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias        
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasGanadoras en el método editar_propuesta, ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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