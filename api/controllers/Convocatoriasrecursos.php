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

// Recupera todos los registros
$app->get('/select_convocatoria', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));
        
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            
            $convocatoria= Convocatorias::findFirst($request->get('convocatoria'));
            
            //Valido si es especie con el fin de mostrar el nombre del recurso no pecuniario
            if($request->get('tipo_recurso')=="Especie")
            {
                $array_especie=$convocatoria->getConvocatoriasrecursos([
                                                                                        'tipo_recurso = :tipo_recurso:',
                                                                                        'bind' => [
                                                                                            'tipo_recurso' => $request->get('tipo_recurso')
                                                                                        ],
                                                                                        'order'      => 'orden ASC',
                                                                                    ]);                 
                $array = array();
            
                foreach ($array_especie as $especie) {                
                    $array_interno=array();
                    $array_interno["id"]=$especie->id;
                    $array_interno["orden"]=$especie->orden;
                    $array_interno["recurso_no_pecuniario"]=$especie->recurso_no_pecuniario;
                    $array_interno["nombre_recurso_no_pecuniario"]=$especie->getRecursosnopecuniarios()->nombre;
                    $array_interno["valor_recurso"]=$especie->valor_recurso;
                    $array_interno["descripcion_recurso"]=$especie->descripcion_recurso;
                    $array_interno["active"]=$especie->active;
                    $array[]=$array_interno;
                }
                                
            }
            else
            {
                $array=$convocatoria->getConvocatoriasrecursos([
                                                                                        'tipo_recurso = :tipo_recurso:',
                                                                                        'bind' => [
                                                                                            'tipo_recurso' => $request->get('tipo_recurso')
                                                                                        ],
                                                                                        'order'      => 'orden ASC',                                                                                    ]); 
            }
            
                       
            
            //SE QUEDO AL MOMENTO DE GUARDARLO QUE CARGUE EL NOMBRE BIEN
            echo json_encode($array);
        }
        else
        {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo".$ex->getMessage();
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
                0 => 'a.nombre',
            );

            $where .= " WHERE a.active=true";
            //Condiciones para la consulta

            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Convocatoriasrecursos AS a";
            $sqlRec = "SELECT " . $columns[0] . " , concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit(',a.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button><button type=\"button\" class=\"btn btn-danger\" onclick=\"form_del(',a.id,')\"><span class=\"glyphicon glyphicon-remove\"></span></button>') as acciones FROM Convocatoriasrecursos AS a";

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
$app->post('/new', function () use ($app, $config,$logger) {
    
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->getPut('token'));

    //Consulto el usuario actual
    $user_current = json_decode($token_actual->user_current, true);
    
    try {                
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                $post = $app->request->getPost();
                $area = new Convocatoriasrecursos();
                $area->creado_por = $user_current["id"];
                $area->fecha_creacion = date("Y-m-d H:i:s");
                $area->active = true;
                if ($area->save($post) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método new, error al crear la convocatoria recurso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();  
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Creo en el controlador Convocatoriasrecursos en el método new, creo con éxito la convocatoria recurso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $area->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método new, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();  
                
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método new, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();                 
            
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método new, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();    
        
        echo "error_metodo";
    }
}
);

// Editar registro
$app->put('/edit/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->getPut('token'));
    
    //Consulto el usuario actual
    $user_current = json_decode($token_actual->user_current, true);    
    
    try {        

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                
                $put = $app->request->getPut();
                // Consultar el usuario que se esta editando
                $convocatoriasrecursos = Convocatoriasrecursos::findFirst(json_decode($id));
                $convocatoriasrecursos->actualizado_por = $user_current["id"];
                $convocatoriasrecursos->fecha_actualizacion = date("Y-m-d H:i:s");
                if ($convocatoriasrecursos->save($put) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método edit, error al editar la convocatoria recurso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();  
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Convocatoriasrecursos en el método edit, edito con éxito la convocatoria recurso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método edit, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();  
                
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método edit, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();   
            
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método edit, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();    
        
        echo "error_metodo";
    }
}
);

// Eliminar registro
$app->delete('/delete/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->getPut('token'));
        
    //Consulto el usuario actual
    $user_current = json_decode($token_actual->user_current, true);    
    
    try {
        

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el usuario que se esta editando
                $user = Convocatoriasrecursos::findFirst(json_decode($id));
                if($user->active==true)
                {
                    $user->active=false;
                    $retorna="No";
                }
                else
                {
                    $user->active=true;
                    $retorna="Si";
                }                
                if ($user->save($user) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método delete, error al eliminar la convocatoria recurso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Convocatoriasrecursos en el método delete, elimino con éxito la convocatoria recurso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $retorna;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método delete, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();  
                
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método delete, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();   
            
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasrecursos en el método delete, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();
        
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
            $area = Convocatoriasrecursos::findFirst($id);
            if (isset($area->id)) {
                echo json_encode($area);
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