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
$app->get('/select', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            $select = Convocatoriasparticipantes::find(['convocatoria = '.$request->get('convocatoria').' AND tipo_participante IN ('.$request->get('tipo_participante').')','order' => 'orden']);

            echo json_encode($select);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo". $ex->getMessage();
    }
}
);

// Recupera todos los registros
$app->get('/select_form_convocatoria', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            $select = $app->modelsManager->executeQuery("SELECT Tiposparticipantes.id,Tiposparticipantes.nombre,Convocatoriasparticipantes.active,Convocatoriasparticipantes.descripcion_perfil AS descripcion_cp,Convocatoriasparticipantes.id AS id_cp  FROM Tiposparticipantes LEFT JOIN Convocatoriasparticipantes ON Convocatoriasparticipantes.tipo_participante = Tiposparticipantes.id AND Convocatoriasparticipantes.convocatoria= ".$request->get('convocatoria')." WHERE Tiposparticipantes.active=true AND Tiposparticipantes.id <> 4");
            echo json_encode($select);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo". $ex->getMessage();
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
            $sqlTot = "SELECT count(*) as total FROM Convocatoriasparticipantes AS a";
            $sqlRec = "SELECT " . $columns[0] . " , concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit(',a.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button><button type=\"button\" class=\"btn btn-danger\" onclick=\"form_del(',a.id,')\"><span class=\"glyphicon glyphicon-remove\"></span></button>') as acciones FROM Convocatoriasparticipantes AS a";

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

                //Guardar el perfil de jurados
                if($request->getPut('tipo_participante')==4)
                {
                    if( Convocatoriasparticipantes::count("convocatoria=".$request->getPut('convocatoria')." AND tipo_participante=".$request->getPut('tipo_participante')."") < $request->getPut('cantidad_perfil_jurado'))
                    {
                        //Consulto el usuario actual
                        $user_current = json_decode($token_actual->user_current, true);
                        $post = $app->request->getPost();
                        $post["area_conocimiento"] = json_encode($post["area_conocimiento"]);
                        $post["nivel_educativo"] = json_encode($post["nivel_educativo"]);
                        $post["area_perfil"] = json_encode($post["area_perfil"]);
                        $convocatoriasparticipantes = new Convocatoriasparticipantes();
                        $convocatoriasparticipantes->creado_por = $user_current["id"];
                        $convocatoriasparticipantes->fecha_creacion = date("Y-m-d H:i:s");
                        $convocatoriasparticipantes->active = true;
                        if ($convocatoriasparticipantes->save($post) === false) {
                            echo "error";
                        } else {
                            echo $convocatoriasparticipantes->id;
                        }
                    }
                    else
                    {
                        echo "error_maximo_jurados";
                    }
                }
                else
                {
                    //Consulto si el registro existe con el fin de activarlo para personas naurales juridicas y agrupaciones
                    $convocatoriasparticipantes = Convocatoriasparticipantes::findFirst("convocatoria=".$request->getPut('convocatoria')." AND tipo_participante=".$request->getPut('tipo_participante'));
                    if(isset($convocatoriasparticipantes->id))
                    {
                        $convocatoriasparticipantes->active = true;
                        $post=$convocatoriasparticipantes;
                    }
                    else
                    {
                        //Consulto el usuario actual
                        $user_current = json_decode($token_actual->user_current, true);
                        $post = $app->request->getPost();
                        $convocatoriasparticipantes = new Convocatoriasparticipantes();
                        $convocatoriasparticipantes->creado_por = $user_current["id"];
                        $convocatoriasparticipantes->fecha_creacion = date("Y-m-d H:i:s");
                        $convocatoriasparticipantes->active = true;
                    }

                    if ($convocatoriasparticipantes->save($post) === false) {
                        echo "error";
                    } else {
                        echo $convocatoriasparticipantes->id;
                    }
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo".$ex->getMessage();
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
                $put = $app->request->getPut();
                unset($put["id"]);
                $put["area_conocimiento"] = json_encode($put["area_conocimiento"]);
                $put["nivel_educativo"] = json_encode($put["nivel_educativo"]);
                $put["area_perfil"] = json_encode($put["area_perfil"]);
                // Consultar el usuario que se esta editando
                $convocatoriasparticipantes = Convocatoriasparticipantes::findFirst(json_decode($id));
                $convocatoriasparticipantes->actualizado_por = $user_current["id"];
                $convocatoriasparticipantes->fecha_actualizacion = date("Y-m-d H:i:s");
                if ($convocatoriasparticipantes->save($put) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método edit, error al editar la convocatoria participante"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close(); 
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Convocatoriasparticipantes en el método edit, edito con éxito la convocatoria participante"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método edit, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();  
                
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método edit, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close(); 
            
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método edit, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();    
        
        echo "error_metodo";
    }
}
);

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

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
                $convocatoriasparticipantes = Convocatoriasparticipantes::findFirst("convocatoria=".$request->getPut('convocatoria')." AND tipo_participante=".$request->getPut('tipo_participante'));
                if($convocatoriasparticipantes->active==true)
                {
                    $convocatoriasparticipantes->active=false;
                    $retorna="No";
                }
                else
                {
                    $convocatoriasparticipantes->active=true;
                    $retorna="Si";
                }

                if ($convocatoriasparticipantes->save($convocatoriasparticipantes) === false) {
                    echo "error";
                } else {
                    echo $retorna;
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

//Elimina lor perfiles de los jurados
$app->delete('/delete_perfil_jurado/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Consulto el usuario actual
        $user_current = json_decode($token_actual->user_current, true);
        
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

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
                $convocatoriasparticipantes = Convocatoriasparticipantes::findFirst(json_decode($id));
                if($convocatoriasparticipantes->active==true)
                {
                    $convocatoriasparticipantes->active=false;
                    $retorna="No";
                }
                else
                {
                    $convocatoriasparticipantes->active=true;
                    $retorna="Si";
                }

                if ($convocatoriasparticipantes->save($convocatoriasparticipantes) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método delete_perfil_jurado, error al editar la convocatoria participante"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();  
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Convocatoriaspublicas en el método delete_perfil_jurado, edito con éxito la convocatoria participante"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $retorna;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método delete_perfil_jurado, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();  
                
                echo "acceso_denegado";
            }

            exit;
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método delete_perfil_jurado, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close(); 
            
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método delete_perfil_jurado, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();    
        
        echo "error_metodo";
    }
});

//Busca el registro
$app->get('/search/{id:[0-9]+}', function ($id) use ($app,$logger) {
    
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->get('token'));
        
    //Consulto el usuario actual
    $user_current = json_decode($token_actual->user_current, true);
    
    try {
        

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            $convocatoriasparticipantes = Convocatoriasparticipantes::findFirst($id);
            if (isset($convocatoriasparticipantes->id)) {
                echo json_encode($convocatoriasparticipantes);
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método edit, error al editar la convocatoria recurso"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close(); 
                    
                echo "error";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método edit, token caduco"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close(); 
            
            echo "error_token";            
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasparticipantes en el método edit, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
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
