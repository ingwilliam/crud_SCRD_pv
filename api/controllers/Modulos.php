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

// Recupera todos los registros
$app->get('/select', function () use ($app) {

    try {
        $phql = 'SELECT * FROM Modulos WHERE active = true ORDER BY nombre';

        $robots = $app->modelsManager->executeQuery($phql);

        echo json_encode($robots);
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

//Permite cargar el HTML para la seguridad de los roles, perfiles y modulos
$app->get('/seguridad', function () use ($app) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Consultar todos los modulos
            $phql = 'SELECT * FROM Modulos WHERE active = true ORDER BY nombre';
            $modulos = $app->modelsManager->executeQuery($phql);

            //Consultar todos los perfiles
            $phql = 'SELECT * FROM Perfiles WHERE active = true ORDER BY nombre';
            $perfiles = $app->modelsManager->executeQuery($phql);

            //Consultar todos los permisos
            $phql = 'SELECT * FROM Permisos WHERE active = true ORDER BY nombre';
            $permisos = $app->modelsManager->executeQuery($phql);

            //Creo el html
            foreach ($modulos as $clave => $valor) {
                ?>
                <tr>
                    <td><?php echo $valor->nombre; ?></td>
                    <?php
                    foreach ($perfiles as $claveint => $valorint) {
                        ?>
                        <td>
                            <select title="<?php echo $valor->id; ?>" dir="<?php echo $valorint->id; ?>" class="select_permisos">
                                <option value="0">::Seleccionar Permiso::</option>
                                <?php
                                foreach ($permisos as $claveint2 => $valorint2) {
                                    $permiso_actual = 0;

                                    $permiso_actual = Moduloperfilpermisos::findFirst("perfil = '" . $valorint->id . "' AND modulo = '" . $valor->id . "'");

                                    if ($permiso_actual->permiso == $valorint2->id)
                                        $selected = "selected";
                                    else
                                        $selected = "";
                                    ?>
                                    <option value="<?php echo $valorint2->id; ?>" <?php echo $selected; ?>><?php echo $valorint2->nombre ?></option>
                                    <?php
                                }
                                ?>
                            </select>                                        
                        </td>
                        <?php
                    }
                    ?>
                </tr>
                <?php
            }
        }
        else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

// Recupera todos los registros
$app->get('/select_user/{id:[0-9]+}', function ($id) use ($app) {

    $phql = 'SELECT p.id,p.nombre,up.id AS checked FROM Modulos AS p LEFT JOIN Usuariosperfiles AS up ON p.id = up.perfil AND up.usuario=' . $id . ' WHERE p.active = true ORDER BY p.nombre';

    $robots = $app->modelsManager->executeQuery($phql);

    echo json_encode($robots);
}
);

// Recupera todos los registros
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
                0 => 'u.nombre',
            );

            $where .= " WHERE u.active=true";
            //Condiciones para la consulta

            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Modulos AS u";
            $sqlRec = "SELECT " . $columns[0] . " , concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit(',u.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button><button type=\"button\" class=\"btn btn-danger\" onclick=\"form_del(',u.id,')\"><span class=\"glyphicon glyphicon-remove\"></span></button>') as acciones FROM Modulos AS u";

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
        echo json_encode(null);
    }
}
);

// Crear registro
$app->post('/new', function () use ($app,$config) {
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
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();
                $modulo = new Modulos();
                $modulo->creado_por = $user_current["id"];
                $modulo->fecha_creacion = date("Y-m-d H:i:s");
                $modulo->active = true;
                if ($modulo->save($post) === false) {
                    echo "error";
                } else {
                    echo $modulo->id;
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

// Editar registro
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
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $put = $app->request->getPut();
                // Consultar el usuario que se esta editando
                $modulo = Modulos::findFirst(json_decode($id));
                $modulo->actualizado_por = $user_current["id"];
                $modulo->fecha_actualizacion = date("Y-m-d H:i:s");
                if ($modulo->save($put) === false) {
                    echo "error";
                } else {
                    echo $id;
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

// Editar registro
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
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el usuario que se esta editando
                $user = Modulos::findFirst(json_decode($id));
                $user->active = false;
                if ($user->save($user) === false) {
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
            $modulo = Modulos::findFirst($id);
            if (isset($modulo->id)) {
                echo json_encode($modulo);
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
}
);


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>