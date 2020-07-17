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
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $departamento = $request->get('departamento');

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            
            if($departamento>0)
            {            
                $phql = "SELECT * FROM Ciudades AS ciu WHERE ciu.active = true AND ciu.departamento = $departamento  ORDER BY nombre";

                $robots = $app->modelsManager->executeQuery($phql);

                echo json_encode($robots);
            }
            else
            {
                echo json_encode(array());
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
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
        if (isset($token_actual->id)) {

            //Defino columnas para el orden desde la tabla html
            $columns = array(
                0 => 'p.nombre',
                1 => 'd.nombre',
                2 => 'ciu.nombre'
            );

            $where .= " WHERE ciu.active=true";
            //Condiciones para la consulta

            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Ciudades AS ciu "
                    . "INNER JOIN Departamentos AS d ON ciu.departamento=d.id "
                    . "INNER JOIN Paises AS p ON p.id=d.pais "
                    . "";
                    
            $sqlRec = "SELECT " . $columns[0] . " AS  pais," . $columns[1] . " AS departamento ," . $columns[2] . " AS ciudad, concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit(',ciu.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button><button type=\"button\" class=\"btn btn-danger\" onclick=\"form_del(',ciu.id,')\"><span class=\"glyphicon glyphicon-remove\"></span></button>') AS acciones FROM Ciudades AS ciu "
                    . "INNER JOIN Departamentos AS d ON ciu.departamento=d.id "
                    . "INNER JOIN Paises AS p ON p.id=d.pais "
                    . "";

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
$app->post('/new', function () use ($app, $config) {
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
                $post = $app->request->getPost();
                $ciudad = new Ciudades();
                $ciudad->creado_por = $user_current["id"];
                $ciudad->fecha_creacion = date("Y-m-d H:i:s");
                $ciudad->active = true;
                if ($ciudad->save($post) === false) {
                    echo "error";
                } else {
                    echo $ciudad->id;
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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                $put = $app->request->getPut();
                // Consultar el usuario que se esta editando
                $ciudad = Ciudades::findFirst(json_decode($id));
                $ciudad->actualizado_por = $user_current["id"];
                $ciudad->fecha_actualizacion = date("Y-m-d H:i:s");
                if ($ciudad->save($put) === false) {
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

// Eliminar registro
$app->delete('/delete/{id:[0-9]+}', function ($id) use ($app, $config) {
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
                // Consultar el usuario que se esta editando
                $user = Ciudades::findFirst(json_decode($id));
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
        if (isset($token_actual->id)) {
            $ciudad = Ciudades::findFirst($id);
            if (isset($ciudad->id)) {
                $array["ciudad"]=$ciudad;
                $array["departamento"]=$ciudad->departamentos;
                $array["departamentos"]= Departamentos::find("active=true");
                echo json_encode($array);
            } else {
                echo json_encode(new Ciudades());
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

//Busca el registro
$app->get('/autocompletar', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            $array_ciudades = array();
            
            if($request->get('q')!="")
            {            
                foreach (Ciudades::find("active=true AND UPPER(TRANSLATE(nombre,'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun')) LIKE TRANSLATE(UPPER('%".$request->get('q')."%'),'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun')") as $value) {
                    $array_ciudades[] = array("id" => $value->id, "label" => $value->nombre . " - " . $value->getDepartamentos()->nombre . " - " . $value->getDepartamentos()->getPaises()->nombre, "value" => $value->nombre);
                }
            }                        
                                    
            //Retorno el array
            echo $request->get('callback')."(".json_encode($array_ciudades).")";
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo $ex->getMessage();
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