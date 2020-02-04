<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');

use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

$config = new ConfigIni('../config/config.ini');

// Registramos un autoloader
$loader = new Loader();

$loader->registerDirs(
        [
            APP_PATH . '/models/',
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

$app = new Micro($di);

// Recupera todos los registros, con el fin de cargar la tabla
$app->get('/all', function () use ($app) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //Defino columnas para el orden desde la tabla html
            $columns = array(
                0 => 'u.primer_nombre',
                1 => 'u.segundo_nombre',
                2 => 'u.primer_apellido',
                3 => 'u.segundo_apellido',
                4 => 'u.username',                
            );

            $where .= " WHERE u.active=true";
            //Condiciones para la consulta
            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[4] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Usuarios AS u ";
            $sqlRec = "SELECT " . $columns[0] . "," . $columns[1] . "," . $columns[2] . "," . $columns[3] . "," . $columns[4] . ", concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit(',u.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button><button type=\"button\" class=\"btn btn-danger a_',u.id,'\" onclick=\"form_del(',u.id,')\"><span class=\"glyphicon glyphicon-remove\"></span></button>') as acciones FROM Usuarios AS u ";
            
            //concatenate search sql if value exist
            if (isset($where) && $where != '') {

                $sqlTot .= $where;
                $sqlRec .= $where;
            }

            //Concateno el orden y el limit para el paginador
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
            //retorno el array en json null
            echo json_encode(null);
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo json_encode($ex->getMessage());
    }
}
);

//Crear registro actual
$app->post('/new', function () use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

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
                $post = $app->request->getPost();
                $usuario = new Usuarios();
                $usuario->active = true;
                $post["password"] = $this->security->hash($post["password"]);

                $usuario_validar = Usuarios::findFirst("username = '" . $post["username"] . "'");

                if (isset($usuario_validar->id)) {
                    echo "error_username";
                } else {
                    //Consulto el usuario actual
                    $user_current = json_decode($token_actual->user_current, true);
                    $post["creado_por"] = $user_current["id"];
                    $post["fecha_creacion"] = date("Y-m-d H:i:s");
                    if ($usuario->save($post) === false) {
                        echo "error";
                    } else {
                        echo $usuario->id;
                    }
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

// Editar registro actual
$app->put('/edit/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

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
                $usuario = $app->request->getPut();
                if ($usuario["password"] != null && $usuario["password"] != "" && $usuario["password"] != "undefined") {
                    $usuario["password"] = $this->security->hash($usuario["password"]);
                } else {
                    unset($usuario["password"]);
                }
                // Consultar el usuario que se esta editando
                $usuario_original = Usuarios::findFirst(json_decode($id));

                if (isset($usuario_original->id)) {

                    if ($usuario_original->username != $usuario["username"]) {
                        $usuario_validar = Usuarios::findFirst("username = '" . $usuario["username"] . "'");
                    }

                    if (isset($usuario_validar->id)) {
                        echo "error_username";
                    } else {
                        //Consulto el usuario actual
                        $user_current = json_decode($token_actual->user_current, true);
                        $usuario["actualizado_por"] = $user_current["id"];
                        $usuario["fecha_actualizacion"] = date("Y-m-d H:i:s");
                        if ($usuario_original->save($usuario) === false) {
                            echo "error";
                        } else {
                            echo $id;
                        }
                    }
                } else {
                    echo "error";
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

// Editar registro actual
$app->put('/edit_perfil/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //Cargo el usuario que esta en el metodo put
            $usuario = $app->request->getPut();

            //Valido si coloco una clave nueva
            if ($usuario["password"] != null && $usuario["password"] != "" && $usuario["password"] != "undefined") {
                $usuario["password"] = $this->security->hash($usuario["password"]);
            } else {
                unset($usuario["password"]);
            }
            // Consultar el usuario que se esta editando
            $usuario_original = Usuarios::findFirst(json_decode($id));

            if (isset($usuario_original->id)) {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $usuario["actualizado_por"] = $user_current["id"];
                $usuario["fecha_actualizacion"] = date("Y-m-d H:i:s");
                if ($usuario_original->save($usuario) === false) {
                    echo "error";
                } else {
                    echo $id;
                }
            } else {
                echo "error";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
    }
}
);

// Eliminar registro
$app->delete('/delete/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_eliminar");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el usuario que se esta editando
                $usuario = Usuarios::findFirst(json_decode($id));
                // Paso el usuario a inactivo
                $usuario->active = false;
                if ($usuario->save($usuario) === false) {
                    echo "error";
                } else {
                    echo "ok";
                }
            } else {
                echo "acceso_denegado";
            }

            exit;
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

//Busca el registro
$app->get('/search/{id:[0-9]+}', function ($id) use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            $usuario = Usuarios::findFirst($id);
            $usuario->password = "undefined";
            if (isset($usuario->id)) {
                echo json_encode($usuario);
            } else {
                echo "error";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
});

//Busca mi perfil
$app->get('/mi_perfil', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //Consulto el usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            $usuario = Usuarios::findFirst($user_current["id"]);
            $usuario->password = "undefined";
            if (isset($usuario->id)) {
                echo json_encode($usuario);
            } else {
                echo "error";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
});

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>