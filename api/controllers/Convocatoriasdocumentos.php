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

// Recupera todos las modalidades dependiendo el programa
$app->get('/select', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {            
            $array = Convocatoriasdocumentos::find("active = true");            
            echo json_encode($array);
        } else {
            echo "error";
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

        //Si el token exisr y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {            
            //Defino columnas para el orden desde la tabla html
            $columns = array(
                0 => 'r.nombre',
                1 => 'cd.subsanable',
                2 => 'cd.obligatorio',
                3 => 'cd.descripcion',
                4 => 'cd.active',
                5 => 'cd.convocatoria',
                6 => 'c.nombre',
                7 => 'cd.orden',
            );

            //Array para consultar las posibles categorias de la convocatoria
            $conditions = ['convocatoria_padre_categoria' => $request->get("convocatoria"), 'active' => true];
            $categorias = Convocatorias::find([
                        'conditions' => 'convocatoria_padre_categoria=:convocatoria_padre_categoria: AND active=:active:',
                        'bind' => $conditions,
                        "order" => 'orden',
            ]);
            $array_categorias="";
            foreach ($categorias as $categoria) {
                $array_categorias= $array_categorias.$categoria->id.",";
            }            
            $array_categorias=$array_categorias.$request->get("convocatoria");
            
            $where .= " INNER JOIN Requisitos AS r ON r.id=cd.requisito";
            $where .= " LEFT JOIN Convocatorias AS c ON c.id=cd.convocatoria";
            $where .= " LEFT JOIN Convocatorias AS cpad ON cpad.id=c.convocatoria_padre_categoria";            
            $where .= " WHERE cd.active IN (true,false) AND r.tipo_requisito='".$request->get('tipo_requisito')."' AND cd.convocatoria IN (".$array_categorias.")";
            //Condiciones para la consulta

            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[6] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }                                

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Convocatoriasdocumentos AS cd";
            $sqlRec = "SELECT " . $columns[0] . " AS requisito," . $columns[1] . "," . $columns[2] . "," . $columns[3] . "," . $columns[4] . " ,c.nombre AS categoria, cpad.nombre AS convocatoria," . $columns[7] . ",concat('<input title=\"',cd.id,'\" type=\"checkbox\" class=\"check_activar_',cd.active,' activar_registro\" />') as activar_registro , concat('<button title=\"',cd.id,'\" type=\"button\" class=\"btn btn-warning btn_cargar\" data-toggle=\"modal\" data-target=\"#nuevo_evento\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones FROM Convocatoriasdocumentos AS cd";

            //concarnar search sql if value exist
            if (isset($where) && $where != '') {

                $sqlTot .= $where;
                $sqlRec .= $where;
            }

            //Concarno el orden y el limit para el paginador
            $sqlRec .= " ORDER BY cd.orden   " . $request->get('order')[0]['dir'] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";
            
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
                
                $convocatoriadocumento = new Convocatoriasdocumentos();
                //Valido si el usuario selecciono una categoria, con el fin de asignarle la convocatoria principal
                if( $post["convocatoria"] == "" ){
                    $post["convocatoria"]=$post["convocatoria_padre_categoria"];                    
                }
                $post["archivos_permitidos"] = json_encode($post["archivos_permitidos"]);
                $convocatoriadocumento->creado_por = $user_current["id"];
                $convocatoriadocumento->fecha_creacion = date("Y-m-d H:i:s");
                $convocatoriadocumento->active = true;
                if ($convocatoriadocumento->save($post) === false) {
                    echo "error";
                } else {
                    echo $convocatoriadocumento->id;
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
$app->put('/edit/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
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
                $convocatoriadocumento = Convocatoriasdocumentos::findFirst(json_decode($id));
                //Valido si el usuario selecciono una categoria, con el fin de asignarle la convocatoria principal
                if( $put["convocatoria"] == "" ){
                    $put["convocatoria"]=$put["convocatoria_padre_categoria"];                    
                }
                $put["archivos_permitidos"] = json_encode($put["archivos_permitidos"]);
                $convocatoriadocumento->actualizado_por = $user_current["id"];
                $convocatoriadocumento->fecha_actualizacion = date("Y-m-d H:i:s");
                if ($convocatoriadocumento->save($put) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método edit, error al editar el documento"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();  
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Convocatoriasdocumentos en el método edit, edito con éxito el documento"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método edit, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();   
                
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método edit, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();                 
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método edit, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();   
        
        echo "error_metodo";
    }
}
);

// Editar registro
$app->put('/edit_publico/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
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
                $convocatoriadocumento = Convocatoriasdocumentos::findFirst(json_decode($id));                                
                $put["archivos_permitidos"] = json_encode($put["archivos_permitidos"]);
                $convocatoriadocumento->actualizado_por = $user_current["id"];
                $convocatoriadocumento->fecha_actualizacion = date("Y-m-d H:i:s");
                if ($convocatoriadocumento->save($put) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método edit, error al editar el documento"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();  
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Convocatoriasdocumentos en el método edit, edito con éxito el documento"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método edit, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();   
                
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método edit, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();                 
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método edit, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();   
        
        echo "error_metodo";
    }
}
);

// Eliminar registro de los perfiles de las convocatorias
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
                // Consultar el registro
                $convocatoriadocumento = Convocatoriasdocumentos::findFirst(json_decode($id));                
                if($convocatoriadocumento->active==true)
                {
                    $convocatoriadocumento->active=false;
                    $retorna="No";
                }
                else
                {
                    $convocatoriadocumento->active=true;
                    $retorna="Si";
                }
                
                if ($convocatoriadocumento->save($convocatoriadocumento) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método delete, error al editar el documento"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close(); 
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Inactivo en el controlador Convocatoriasdocumentos en el método delete, edito con éxito el documento"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $retorna;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método delete, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();         
            
                echo "acceso_denegado";
            }           
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método delete, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close(); 
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriasdocumentos en el método delete, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();         
        echo "error_metodo";
    }
});

//Busca el registro
$app->get('/search', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Si existe consulto la convocatoria
            if($request->get('id'))
            {    
                $convocatoriadocumento = Convocatoriasdocumentos::findFirst($request->get('id'));                                
            }
            else 
            {
                $convocatoriadocumento = new Convocatoriasdocumentos();
            }
            //Cargo la convocatoria actual
            $convocatoria= Convocatorias::findFirst($request->get('convocatoria'));
            //Creo todos los array de la convocatoria cronograma
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='tipos_archivos_tecnicos'");
            $array["tipos_archivos_tecnicos"] = explode(",", $tabla_maestra[0]->valor);
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='tipos_archivos_administrativos'");
            $array["tipos_archivos_administrativos"] = explode(",", $tabla_maestra[0]->valor);            
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='tipos_tamano_archivos'");
            $array["tamanos_permitidos"] = explode(",", $tabla_maestra[0]->valor);            
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='etapas_participantes'");
            $array["etapas"] = explode(",", $tabla_maestra[0]->valor);                        
            $array["convocatoriadocumento"]=$convocatoriadocumento;
            $programa=$convocatoria->programa;
            //Documentos administrativos para LEP
            if($convocatoria->modalidad==6){
                $programa=$convocatoria->modalidad;
            }
            
            $array["requisitos"]= Requisitos::find([
                                                        'conditions' => "active=true AND programas LIKE '%".$programa."%' AND tipo_requisito='".$request->get('tipo_requisito')."'",
                                                        "order" => 'orden',
                                                    ]);
            //Retorno el array
            echo json_encode($array);       
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

// Recupera todos las modalidades dependiendo el programa
$app->get('/pruebaxyz', function () use ($app,$config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();        
        //Si el token existe y esta activo entra a realizar la tabla
        if ($request->get('keyy') == 'WMx2') {                       
            //echo json_encode($config->database);
            echo json_encode("A");
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo". $ex->getMessage();
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