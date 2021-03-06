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
            $array = Convocatoriascronogramas::find("active = true");            
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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Defino columnas para el orden desde la tabla html
            $columns = array(
                0 => 'cpad.nombre',                
                1 => 'c.nombre',
                2 => 'te.nombre',
                3 => 'cc.fecha_inicio',
                4 => 'cc.fecha_fin',
                5 => 'cc.descripcion',
                6 => 'cc.active',
                7 => 'cc.convocatoria',                
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
            
            $where .= " INNER JOIN Tiposeventos AS te ON te.id=cc.tipo_evento";
            $where .= " LEFT JOIN Convocatorias AS c ON c.id=cc.convocatoria";
            $where .= " LEFT JOIN Convocatorias AS cpad ON cpad.id=c.convocatoria_padre_categoria";            
            $where .= " WHERE cc.active IN (true,false) AND cc.convocatoria IN (".$array_categorias.")";
            //Condiciones para la consulta

            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[2] . ") LIKE '%" . mb_strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[5] . ") LIKE '%" . mb_strtoupper($request->get("search")['value']) . "%' ";                
                $where .= " OR UPPER(" . $columns[0] . ") LIKE '%" . mb_strtoupper($request->get("search")['value']) . "%' ";                
                $where .= " OR UPPER(" . $columns[1] . ") LIKE '%" . mb_strtoupper($request->get("search")['value']) . "%' )";
            }                                

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Convocatoriascronogramas AS cc";
            $sqlRec = "SELECT " . $columns[2] . " AS tipo_evento," . $columns[3] . "," . $columns[4] . "," . $columns[5] . "," . $columns[6] . " ," . $columns[7] . ",c.nombre AS categoria, cpad.nombre AS convocatoria,concat('<input title=\"',cc.id,'\" type=\"checkbox\" class=\"check_activar_',cc.active,' activar_registro\" />') as activar_registro , concat('<button title=\"',cc.id,'\" type=\"button\" class=\"btn btn-warning btn_cargar\" data-toggle=\"modal\" data-target=\"#nuevo_evento\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones FROM Convocatoriascronogramas AS cc";

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
                //Si es periodo por defecto es hasta la media noche
                $tipo_evento= Tiposeventos::findFirst($post["tipo_evento"]);                
                if($tipo_evento->periodo)
                {
                    $tabla_maestra= Tablasmaestras::find("active=true AND nombre='hora_cierre'");            
                    $post["fecha_fin"]=$post["fecha_fin"]." ".$tabla_maestra[0]->valor;                                                           
                }
                //Valido que es el evento fecha cierre con el fin de asignarle la hora de cierre de la tabla maestra
                if($tipo_evento->id==12)
                {
                    $tabla_maestra= Tablasmaestras::find("active=true AND nombre='hora_cierre'");            
                    $post["fecha_fin"]=$post["fecha_fin"]." ".$tabla_maestra[0]->valor;                    
                    $post["fecha_inicio"]=$post["fecha_inicio"]." ".$tabla_maestra[0]->valor;                    
                }
                
                //Valido si el usuario selecciono una categoria, con el fin de asignarle la convocatoria principal
                if( $post["convocatoria"] == "" ){
                    $post["convocatoria"]=$post["convocatoria_padre_categoria"];                    
                }
                
                $convocatoriacronograma = new Convocatoriascronogramas();
                $convocatoriacronograma->creado_por = $user_current["id"];
                $convocatoriacronograma->fecha_creacion = date("Y-m-d H:i:s");
                $convocatoriacronograma->active = true;
                if ($convocatoriacronograma->save($post) === false) {
                    foreach ($convocatoriacronograma->getMessages() as $message) {
                      echo $message;
                    }
                } else {
                    echo $convocatoriacronograma->id;
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
                $convocatoriacronograma = Convocatoriascronogramas::findFirst(json_decode($id));
                //Si es periodo por defecto es hasta la media noche
                $tipo_evento= Tiposeventos::findFirst($convocatoriacronograma->tipo_evento);                
                if($tipo_evento->periodo)
                {
                    $tabla_maestra= Tablasmaestras::find("active=true AND nombre='hora_cierre'");            
                    $put["fecha_fin"]=$put["fecha_fin"]." ".$tabla_maestra[0]->valor;                                                            
                }
                //Valido que es el evento fecha cierre con el fin de asignarle la hora de cierre de la tabla maestra
                if($tipo_evento->id==12)
                {
                    $tabla_maestra= Tablasmaestras::find("active=true AND nombre='hora_cierre'");            
                    $put["fecha_fin"]=$put["fecha_fin"]." ".$tabla_maestra[0]->valor;                    
                    $put["fecha_inicio"]=$put["fecha_inicio"]." ".$tabla_maestra[0]->valor;                    
                }        
                //Valido si el usuario selecciono una categoria, con el fin de asignarle la convocatoria principal
                if( $put["convocatoria"] == "" ){
                    $put["convocatoria"]=$put["convocatoria_padre_categoria"];                    
                }
                $convocatoriacronograma->actualizado_por = $user_current["id"];
                $convocatoriacronograma->fecha_actualizacion = date("Y-m-d H:i:s");
                
                $convocatoria= Convocatorias::findFirst($put["convocatoria_padre_categoria"]);
                
                //Modifico habilitar cronograma
                if($convocatoria->estado==5)
                {
                    $phql = "UPDATE Convocatorias SET habilitar_cronograma=:habilitar_cronograma: WHERE (convocatoria_padre_categoria=:convocatoria_padre_categoria: OR id=:convocatoria_padre_categoria:)";            
                    $app->modelsManager->executeQuery($phql, array(
                        'convocatoria_padre_categoria' => $put["convocatoria_padre_categoria"],
                        'habilitar_cronograma' => FALSE                    
                    ));                 
                }
                
                if ($convocatoriacronograma->save($put) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriascronogramas en el método edit, error al editar el cronograma"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();  
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Convocatoriascronogramas en el método edit, edito con éxito el cronograma"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriascronogramas en el método edit, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();   
                
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriascronogramas en el método edit, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();                 
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriascronogramas en el método edit, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();   
        
        echo "error_metodo";
    }
}
);

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
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

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));
        
            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el registro
                $convocatoriacronograma = Convocatoriascronogramas::findFirst(json_decode($id));                
                if($convocatoriacronograma->active==true)
                {
                    $convocatoriacronograma->active=false;
                    $retorna="No";
                }
                else
                {
                    $convocatoriacronograma->active=true;
                    $retorna="Si";
                }
                
                if ($convocatoriacronograma->save($convocatoriacronograma) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriascronogramas en el método delete, error al editar el evento"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close(); 
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Inactivo en el controlador Convocatoriascronogramas en el método delete, edito con éxito el evento"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $retorna;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriascronogramas en el método delete, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();         
            
                echo "acceso_denegado";
            }           
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriascronogramas en el método delete, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close(); 
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriascronogramas en el método delete, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
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
                $convocatoriacronograma = Convocatoriascronogramas::findFirst($request->get('id'));                
                $convocatoriacronograma->fecha_inicio = (new DateTime($convocatoriacronograma->fecha_inicio))->format('Y-m-d');
                $convocatoriacronograma->fecha_fin = (new DateTime($convocatoriacronograma->fecha_fin))->format('Y-m-d');                
                $array["es_periodo"] = $convocatoriacronograma->getTiposeventos()->periodo;
            }
            else 
            {
                $convocatoriacronograma = new Convocatoriascronogramas();                
            }
            //Cargo la convocatoria actual
            $convocatoria= Convocatorias::findFirst($request->get('convocatoria_padre_categoria'));
            //Creo todos los array de la convocatoria cronograma
            $array["convocatoriacronograma"]=$convocatoriacronograma;
            $array["tipos_eventos"] = Tiposeventos::find(array("conditions" => "programas LIKE '%" . $convocatoria->programa . "%' AND active=TRUE"));
            
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


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>